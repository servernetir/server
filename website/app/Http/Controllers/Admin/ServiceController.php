<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * فروش و مدیریت سرویس‌های مشتری — سمت کارکنان.
 *
 * جریان کارفرما: در پروندهٔ مشتری یک سرویس می‌سازد (نام، توضیحات، مبلغ،
 * دوره). سیستم همان لحظه یک **پیش‌فاکتور** می‌سازد؛ سرویس در حالت «منتظر
 * پرداخت» می‌ماند تا مشتری پرداخت کند و آن‌گاه خودکار فعال می‌شود
 * (در PaymentService، هنگام تسویه).
 */
class ServiceController extends Controller
{
    /**
     * فروش سرویس به یک مشتری + ساخت پیش‌فاکتور، همه در یک تراکنش.
     */
    public function store(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price'       => ['required', 'integer', 'min:0', 'max:100000000000'],
            'tax_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'cycle'       => ['required', \Illuminate\Validation\Rule::in(\App\Models\Service::cycles())],
            // تحویلِ خودکار (اختیاری): اگر سروری انتخاب شود، پس از پرداخت خودکار
            // روی آن ساخته می‌شود. نام‌کاربری/رمز اگر خالی باشند خودکار ساخته می‌شوند.
            'server_id'   => ['nullable', 'integer', 'exists:servers,id'],
            'plan'        => ['nullable', 'string', 'max:80'],
            'username'    => ['nullable', 'string', 'max:64', 'regex:/^[a-z][a-z0-9]{0,15}$/'],
            'domain'      => ['nullable', 'string', 'max:190'],

            /*
            | تاریخِ صدور به **شمسی** وارد می‌شود و **میلادی** ذخیره می‌شود.
            |
            | ⚠️ سه فیلدِ جدا و نه یک رشتهٔ «۱۴۰۵/۰۵/۲۰»: پارسِ رشتهٔ تاریخ یعنی
            | تصمیم‌گیری دربارهٔ جداکننده، رقمِ فارسی/لاتین، و صفرِ ابتدایی —
            | سه جای اضافه برای اشتباه، روی فیلدی که سندِ حسابداری می‌سازد.
            */
            'issued_jy'   => ['nullable', 'integer', 'min:1300', 'max:1500'],
            'issued_jm'   => ['nullable', 'integer', 'min:1', 'max:12'],
            'issued_jd'   => ['nullable', 'integer', 'min:1', 'max:31'],

            // تخفیفِ درصدی روی مبلغِ سرویس
            'discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ], [], [
            'name' => 'نام سرویس', 'price' => 'مبلغ', 'cycle' => 'دوره',
            'username' => 'نام‌کاربری', 'domain' => 'دامنه',
            'issued_jy' => 'سال صدور', 'issued_jm' => 'ماه صدور',
            'issued_jd' => 'روز صدور', 'discount_pct' => 'درصد تخفیف',
        ]);

        /*
        |----------------------------------------------------------------------
        | شمسی → میلادی، **فقط این‌جا**
        |----------------------------------------------------------------------
        |
        | کارفرما: «شمسی وارد کنم ولی میلادی در دیتابیس ذخیره شود.»
        |
        | ⚠️ تبدیل در PHP انجام می‌شود و نه در مرورگر — قاعدهٔ ثبت‌شدهٔ پروژه:
        | دو پیاده‌سازیِ جلالی روزی یک روز اختلاف پیدا می‌کنند، و این‌جا آن یک
        | روز روی تاریخِ سندِ حسابداری می‌نشیند.
        |
        | ⚠️ `Jalali::toGregorian` تاریخِ ناموجود را نمی‌شناسد (مثلاً ۳۱ اسفند)،
        | پس **پیش از تبدیل** با `daysInMonth` سنجیده می‌شود. بی‌این، ۳۱ اسفند
        | بی‌صدا به فروردین سُر می‌خورد و فاکتور تاریخِ اشتباه می‌گرفت.
        */
        $issuedAt = null;

        if (filled($data['issued_jy'] ?? null) && filled($data['issued_jm'] ?? null) && filled($data['issued_jd'] ?? null)) {
            [$jy, $jm, $jd] = [(int) $data['issued_jy'], (int) $data['issued_jm'], (int) $data['issued_jd']];

            if ($jd > \App\Support\Jalali::daysInMonth($jy, $jm)) {
                return back()->withInput()->withErrors([
                    'issued_jd' => 'این روز در ماهِ انتخاب‌شده وجود ندارد.',
                ]);
            }

            $issuedAt = \App\Support\Jalali::startOfDay(
                $jy, $jm, $jd, config('calendar.display_timezone', 'Asia/Tehran')
            );

            // ⚠️ همان قاعدهٔ قبلی: فاکتورِ **آینده** سندی است که هنوز صادر نشده.
            if ($issuedAt->isAfter(now())) {
                return back()->withInput()->withErrors([
                    'issued_jd' => 'تاریخِ صدور نمی‌تواند در آینده باشد.',
                ]);
            }
        }

        $taxPct = (int) ($data['tax_percent'] ?? 0);

        /*
        |----------------------------------------------------------------------
        | 🔴 تخفیف روی **قیمتِ سرویس** می‌نشیند، نه فقط روی فاکتورِ اول
        |----------------------------------------------------------------------
        |
        | وسوسه‌انگیز است که تخفیف را فقط از مبلغِ فاکتور کم کنیم. ولی
        | `services.price` همان عددی است که `services:renew-due` هر دوره
        | فاکتور می‌کند. اگر تخفیف بیرونِ آن بماند، مشتری دورهٔ اول را با
        | تخفیف می‌دهد و از دورهٔ دوم **بی‌خبر** قیمتِ کامل می‌گیرد — و آن را
        | ما هیچ‌جا اعلام نکرده‌ایم.
        |
        | پس قیمتِ ذخیره‌شده همان قیمتِ تخفیف‌خورده است و توضیحِ سرویس می‌گوید
        | تخفیف چقدر بوده. اگر روزی تخفیفِ «فقط دورهٔ اول» لازم شد، باید یک
        | فیلدِ جدا با تاریخِ انقضا باشد، نه دستکاریِ همین عدد.
        |
        | ⚠️ گردکردن به **پایین** (`floor`) به نفعِ مشتری است و از عددِ اعشاری
        | روی فاکتور جلوگیری می‌کند.
        */
        $discountPct = (float) ($data['discount_pct'] ?? 0);
        $listPrice   = (int) $data['price'];
        $price       = $discountPct > 0
            ? (int) floor($listPrice * (100 - $discountPct) / 100)
            : $listPrice;

        $note = $discountPct > 0
            ? 'قیمتِ پایه '.fa_num(number_format($listPrice)).' تومان با '
                .fa_num(rtrim(rtrim(number_format($discountPct, 2, '.', ''), '0'), '.')).'٪ تخفیف'
            : null;

        $service = DB::transaction(function () use ($customer, $data, $taxPct, $request, $price, $note, $issuedAt) {
            $service = Service::create([
                'customer_id'   => $customer->id,
                'name'          => $data['name'],
                'description'   => trim(($data['description'] ?? '')."\n".($note ?? '')) ?: null,
                'currency_code' => 'IRT',
                'price'         => $price,
                'tax_percent'   => $taxPct,
                'cycle'         => $data['cycle'],
                'status'        => 'pending',
                'created_by'    => $request->user()?->id,
                'server_id'     => $data['server_id'] ?? null,
                'plan'          => $data['plan'] ?? null,
                'username'      => $data['username'] ?? null,
                'domain'        => $data['domain'] ?? null,
            ]);

            $this->issueInvoice($service, $issuedAt);

            return $service;
        });

        \App\Models\ActivityLog::forService($service, 'purchase',
            'سرویس «'.$service->name.'» توسط مدیر ('.($request->user()?->name ?: 'مدیر').') فروخته و پیش‌فاکتور صادر شد',
            'staff', $request);

        return redirect("/admin/customers/{$customer->id}")
            ->with('ok', 'سرویس «'.$service->name.'» ساخته شد و پیش‌فاکتور صادر گردید. پس از پرداخت مشتری، خودکار فعال می‌شود.');
    }

    /**
     * حذفِ کاملِ یک سرویسِ لغوشده‌ای که هرگز ساخته نشده.
     *
     * ⚠️ گیت از **مدل** می‌آید. تکرارِ شرط‌ها این‌جا یعنی دو تعریف که روزی
     * واگرا می‌شوند — و یک طرفِ واگرایی، پاک‌کردنِ سابقهٔ مالی است.
     */
    public function destroy(Request $request, Service $service): RedirectResponse
    {
        $customerId = $service->customer_id;

        if (! $service->isDeletable()) {
            return back()->with('err',
                'این سرویس قابلِ حذف نیست: یا هنوز زنده است، یا تحویل شده، یا پرداختی روی آن ثبت است.');
        }

        $name = $service->name;

        DB::transaction(function () use ($service) {
            /*
            | فاکتورهای **پرداخت‌نشده** با سرویس می‌روند: بی‌سرویس، فاکتوری که
            | به هیچ‌چیز اشاره نمی‌کند در فهرستِ مشتری می‌مانَد و او را گیج
            | می‌کند. فاکتورِ پرداخت‌شده اصلاً به این‌جا نمی‌رسد — `isDeletable()`
            | جلویش را گرفته.
            */
            Invoice::where('service_id', $service->id)->where('paid', '<=', 0)->delete();

            $service->delete();
        });

        // ⚠️ ردیفِ سرویس رفته، پس لاگ به **مشتری** می‌چسبد نه به سرویس؛
        //    وگرنه تنها سندِ این حذف به یک شناسهٔ ناموجود اشاره می‌کرد.
        \App\Models\ActivityLog::record($customerId, 'service_delete',
            'سرویسِ «'.$name.'» توسط مدیر ('.($request->user()?->name ?: 'مدیر').') حذف شد',
            $request, 'staff');

        return redirect("/admin/customers/{$customerId}")
            ->with('ok', 'سرویس «'.$name.'» حذف شد.');
    }

    /**
     * صدور یک فاکتور برای یک دورهٔ سرویس (اولین صدور یا تمدید).
     *
     * public و static-مانند تا فرمان تمدیدِ دوره‌ای هم بتواند از همین منطق
     * استفاده کند — یک جای واحد برای «فاکتور یک سرویس چه شکلی است».
     */
    /**
     * @param  \Illuminate\Support\Carbon|null  $issuedAt  تاریخِ صدورِ دلخواه (فقط گذشته)
     */
    public function issueInvoice(Service $service, $issuedAt = null): Invoice
    {
        $subtotal = $service->price;
        $tax      = $service->taxAmount();
        $total    = $subtotal + $tax;

        /*
        |----------------------------------------------------------------------
        | 🔴 تاریخِ صدور عقب می‌رود، ولی سررسیدِ سرویس **نه**
        |----------------------------------------------------------------------
        |
        | کارفرما خواست فاکتور تاریخِ سه روزِ پیش بخورد. عقب‌بردنِ تاریخِ صدور
        | بی‌خطر است — یک سندِ حسابداری است. ولی اگر **سررسیدِ سرویس** هم با آن
        | عقب برود، زنجیرهٔ کرون بی‌رحم است:
        |
        |     ۰۷:۰۰ services:renew-due   → فاکتورِ تمدید برای سرویسِ سررسیدگذشته
        |     ۰۷:۳۰ services:lifecycle   → همان فاکتورِ پرداخت‌نشده → تعلیقِ واقعی
        |
        | یعنی مدیر یک فروشِ سه‌روزِ پیش را ثبت می‌کرد و نیم‌ساعت بعد سرویسِ
        | مشتری خاموش می‌شد، با پیامکِ «سرویس شما غیرفعال شد». دقیقاً همان
        | تله‌ای که یک بار در `/admin/cloud/attach` رخ داد و همان‌جا هم ثبت شد.
        |
        | پس `issued_at` عقب می‌رود و `next_due_at` دست‌نخورده از **امروز**
        | شمرده می‌شود. اگر روزی «دورهٔ گذشته» واقعاً لازم شد، باید صریح و با
        | محافظِ خودش ساخته شود، نه به‌عنوان عارضهٔ جانبیِ تاریخِ فاکتور.
        */
        $issued = $issuedAt !== null && $issuedAt->lt(now()) ? $issuedAt : now();

        $invoice = Invoice::create([
            'customer_id'   => $service->customer_id,
            'service_id'    => $service->id,
            'kind'          => 'service',
            'currency_code' => $service->currency_code,
            'subtotal'      => $subtotal,
            'tax'           => $tax,
            'total'         => $total,
            'paid'          => 0,
            'status'        => 'unpaid',
            'issued_at'     => $issued,
            'note'          => $service->name,
        ]);

        InvoiceItem::create([
            'invoice_id'  => $invoice->id,
            'title'       => $service->name.' ('.$service->cycleLabel().')',
            'description' => $service->description,
            'quantity'    => 1,
            'unit_price'  => $subtotal,
            'line_total'  => $subtotal,
            'tax_rate_bp' => $service->tax_percent * 100,   // درصد → basis-points
            'tax_amount'  => $tax,
        ]);

        return $invoice;
    }

    /**
     * تغییر وضعیت سرویس — تعلیق، فعال‌سازی دستی، لغو.
     */
    public function update(Request $request, Service $service): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:active,suspended,cancelled'],
        ]);

        /*
        | 🔴 ریفاندِ خودکارِ تحویل‌نشده — لغوِ سرویسی که پولش رفته و هرگز تحویل
        | نشده، بدونِ این یعنی دو کارِ دستیِ جدا برای مدیر و انتظار برای مشتری.
        */
        $refund = 0;
        if ($data['status'] === 'cancelled') {
            $refund = app(\App\Services\Billing\UndeliveredRefund::class)
                ->maybeRefund($service, 'staff');
        }

        $service->status = $data['status'];
        if ($data['status'] === 'cancelled') {
            $service->cancelled_at = now();
        }
        if ($data['status'] === 'active' && $service->activated_at === null) {
            $service->activated_at = now();
        }
        $service->save();

        \App\Models\ActivityLog::forService($service,
            match ($data['status']) { 'suspended' => 'suspend', 'cancelled' => 'terminate', default => 'reactivate' },
            'وضعیت سرویس به «'.$data['status'].'» تغییر کرد — توسط '.($request->user()?->name ?: 'مدیر'),
            'staff', $request);

        return back()->with('ok', $refund > 0
            ? 'وضعیت به‌روزرسانی شد و '.number_format($refund).' به کیفِ پولِ مشتری برگشت (تحویل انجام نشده بود).'
            : 'وضعیت سرویس به‌روزرسانی شد.');
    }

    /**
     * تنظیمِ سررسیدِ سرویسی که ندارد.
     *
     * ═══ چرا این دکمه لازم شد ═══
     *
     * سرویس‌هایی از پیش از ساخته‌شدنِ سیستمِ سررسید مانده‌اند که `next_due_at`
     * ندارند. `services:renew-due` شرطِ `whereNotNull('next_due_at')` دارد، پس
     * آن ردیف‌ها از دیدِ کلِ صورت‌حساب **غایب**اند: نه فاکتور، نه یادآوری، نه
     * تعلیق — و هیچ خطایی هم تولید نمی‌کنند. یعنی سرویسِ رایگانِ ابدی.
     *
     * فرمانِ `services:backfill-due` دسته‌ای حلش می‌کند، ولی روی پروداکشن
     * دسترسیِ خط‌فرمان نداریم؛ این دکمه همان کار را برای یک ردیف می‌کند.
     *
     * 🔴 `after:today` اجباری است و اختیاری نیست. سررسیدِ گذشته یعنی:
     *     ۰۷:۰۰ services:renew-due → فاکتورِ تمدیدِ سررسیدگذشته
     *     ۰۷:۳۰ services:lifecycle → همان فاکتورِ پرداخت‌نشده → تعلیقِ واقعی
     * یعنی مدیر سررسید را «درست» می‌کند و نیم‌ساعت بعد سرویسِ سالمِ مشتری با
     * پیامکِ «سرویس شما غیرفعال شد» قطع می‌شود. همان تلهٔ `/admin/cloud/attach`.
     */
    /**
     * لغوِ سرویس + بازگشتِ وجه به کیف پولِ مشتری — یک اقدام، از پروفایل.
     *
     * ═══ چرا از «تغییر وضعیت به لغو» جداست ═══
     *
     * آن سلکت فقط `status` را می‌نویسد: نه زیرساخت را آزاد می‌کند، نه پولی
     * برمی‌گرداند، نه به مشتری خبر می‌دهد. این‌جا مسیرِ کاملِ خاتمه است —
     * همان `ProvisioningService::terminate` که رباتِ بله استفاده می‌کند
     * (بستنِ صورت‌حساب + آزادسازیِ زیرساخت + اطلاع به مشتری) — به‌علاوهٔ
     * اعتبارِ کیف پول. دو پیاده‌سازیِ خاتمه یعنی روزی یکی‌شان سروری را روی
     * زیرساخت زنده جا می‌گذارد که اجاره‌اش پای ماست.
     *
     * ═══ قواعدِ پول ═══
     *
     * 🔴 سقفِ بازگشت = جمعِ پرداختیِ فاکتورهای همین سرویس. مدیر عدد را
     * می‌تواند کم کند (بازگشتِ جزئی/pro-rata) ولی نه بیشتر — یک صفرِ اضافه
     * در فیلدِ آزاد، پولِ واقعی است. بازگشتِ بیش از پرداختی اگر روزی لازم
     * شد، ابزارِ جدایِ خودش را می‌خواهد نه شل‌کردنِ این سقف.
     *
     * 🔴 گاردِ دوباره‌پرداخت: برای هر سرویس فقط **یک** ردیفِ refund. دکمهٔ
     * دوبار کلیک‌شده یا دو تبِ باز، نباید دو بار پول برگرداند. همان الگویی
     * که مسیرِ لغوِ خودِ مشتری دارد.
     *
     * ⚠️ ترتیب: اول خاتمه، بعد اعتبار. خاتمه حتی وقتی زیرساخت پس بزند
     * صورت‌حساب را می‌بندد و در صفِ تلاشِ دوباره می‌مانَد — پولِ مشتری نباید
     * منتظرِ پاک‌شدنِ ماشین بماند.
     */
    public function cancelRefund(Request $request, Service $service): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $paidTotal = (int) $service->invoices()->where('status', 'paid')->sum('paid');

        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:0', 'max:'.max(0, $paidTotal)],
            'note'   => ['nullable', 'string', 'max:200'],
        ], [
            'amount.max' => 'سقفِ بازگشت، جمعِ پرداختیِ همین سرویس است: '.number_format($paidTotal).' تومان.',
        ], ['amount' => 'مبلغ']);

        $amount = (int) $data['amount'];
        $customer = $service->customer;

        if ($customer === null) {
            return back()->withErrors('این سرویس مشتری ندارد — بازگشتِ وجه مقصدی ندارد.');
        }

        $alreadyRefunded = Schema::hasTable('credit_ledger')
            && \App\Models\CreditEntry::where('source_type', Service::class)
                ->where('source_id', $service->id)
                ->where('reason', 'refund')->exists();

        if ($amount > 0 && $alreadyRefunded) {
            return back()->withErrors('برای این سرویس قبلاً بازگشتِ وجه ثبت شده — دوباره پرداخت نمی‌شود.');
        }

        $wasDead = $service->isDead();

        if (! $wasDead) {
            $r = app(\App\Services\Provisioning\ProvisioningService::class)->terminate($service);
        }

        if ($amount > 0) {
            $balance = $customer->creditBalance('IRT');

            \App\Models\CreditEntry::create([
                'customer_id'   => $customer->id,
                'currency_code' => 'IRT',
                'amount'        => $amount,
                'balance_after' => $balance + $amount,
                'reason'        => 'refund',
                'source_type'   => Service::class,
                'source_id'     => $service->id,
                'note'          => 'بازگشتِ وجه — لغوِ «'.mb_substr((string) $service->name, 0, 60).'» توسط مدیر'
                    .(filled($data['note'] ?? null) ? ' — '.$data['note'] : ''),
            ]);
        }

        \App\Models\ActivityLog::forService($service, 'terminate',
            'لغو از پنل توسط «'.($request->user()->name ?: 'مدیر').'»'
            .($amount > 0 ? ' + بازگشتِ '.number_format($amount).' تومان به کیف پول' : ' (بدونِ بازگشتِ وجه)'),
            'staff', $request);

        $msg = $wasDead
            ? ($amount > 0 ? fa_num(number_format($amount)).' تومان به کیف پولِ مشتری برگشت.' : 'تغییری لازم نبود — سرویس از قبل بسته بود.')
            : 'سرویس لغو شد'.($amount > 0 ? ' و '.fa_num(number_format($amount)).' تومان به کیف پولِ مشتری برگشت.' : ' (بدونِ بازگشتِ وجه).');

        if (! $wasDead && isset($r) && ! $r->ok && ! $r->manual) {
            $msg .= ' ⚠️ زیرساخت حذف را نپذیرفت؛ در صفِ تلاشِ دوباره مانْد.';
        }

        return back()->with('ok', $msg);
    }

    public function setDue(Request $request, Service $service): RedirectResponse
    {
        $data = $request->validate([
            'next_due_at' => ['required', 'date', 'after:today'],
        ], [
            'next_due_at.after' => 'سررسید باید در آینده باشد؛ تاریخِ گذشته همان روز سرویس را تعلیق می‌کند.',
        ], ['next_due_at' => 'سررسید']);

        if ($service->isDead()) {
            return back()->with('err', 'سرویسِ لغوشده سررسید نمی‌گیرد.');
        }

        $service->forceFill(['next_due_at' => \Illuminate\Support\Carbon::parse($data['next_due_at'])])->save();

        \App\Models\ActivityLog::forService($service, 'renew',
            'سررسیدِ سرویس روی '.sdate($service->next_due_at).' تنظیم شد — توسط '
            .($request->user()?->name ?: 'مدیر'), 'staff');

        return back()->with('ok', 'سررسید تنظیم شد. از این پس فاکتورِ تمدید و یادآوری صادر می‌شود.');
    }

    /** صدور دستیِ فاکتور تمدید برای یک سرویس (وقتی کارفرما زودتر می‌خواهد) */
    public function renew(Service $service): RedirectResponse
    {
        if (! $service->isRecurring()) {
            return back()->withErrors('این سرویس دوره‌ای نیست و تمدید ندارد.');
        }

        $this->issueInvoice($service);

        \App\Models\ActivityLog::forService($service, 'renew',
            'فاکتور تمدید توسط مدیر ('.(request()->user()?->name ?: 'مدیر').') صادر شد', 'staff');

        return back()->with('ok', 'فاکتور تمدید صادر شد؛ پس از پرداخت، سررسید سرویس یک دوره جلو می‌رود.');
    }

    /** ساختِ فوری/تلاشِ دوبارهٔ تحویل روی سرور (بدونِ صبر برای کرون) */
    public function provision(Request $request, Service $service): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        // اگر سرویس هنوز سرور/پلن/دامنه ندارد، همین‌جا تعیین می‌شود (رفعِ سفارشی
        // که بدونِ سرور خریداری شده)
        $data = $request->validate([
            'server_id' => ['nullable', 'integer', 'exists:servers,id'],
            'plan'      => ['nullable', 'string', 'max:80'],
            'domain'    => ['nullable', 'string', 'max:190'],
        ]);
        // ⚠️ سرورِ ابری هرگز `server_id` ندارد (پیش از خرید وجود ندارد). بی‌این
        // استثنا، تحویلِ شکست‌خوردهٔ ابری **هیچ راهِ بازیابی** نداشت: کرون فقط
        // `pending` را برمی‌دارد و `failed` را نمی‌بیند، و دکمهٔ «تلاش دوباره»ی
        // ادمین هم با پیامِ «اول یک سرورِ تحویل انتخاب کنید» بیرون می‌زد. یعنی
        // مشتری پول داده، سرور ندارد، و تنها راه ویرایشِ دستیِ دیتابیس بود.
        $isCloud = \App\Services\Cloud\CloudProvisioner::handles($service);

        /*
        | 🔴 روی سرویسِ ابری هیچ‌کدام از این سه ستون **نوشته نمی‌شود**.
        |
        | حالا که فرمِ «تلاش دوباره» برای ردیفِ ابری هم رندر می‌شود، اگر این قید
        | نبود یک POSTِ دست‌ساز (یا فرمی که فردا اشتباه ویرایش شود) می‌توانست
        | `server_id`ِ یک سرورِ WHM و نامِ پکیجِ WHM را روی یک سرویسِ ابری مهر
        | کند. آن‌وقت `ProvisioningService` دیگر آن ردیف را ابری نمی‌بیند و
        | تحویل/تعلیق/حذفش سراغِ WHM می‌رود. قید سمتِ **سرور** است نه فقط ویو،
        | چون ویو هیچ‌وقت محافظ نیست.
        */
        $assign = $isCloud ? [] : array_filter([
            'server_id' => $data['server_id'] ?? null,
            'plan'      => $data['plan'] ?? null,
            'domain'    => $data['domain'] ?? null,
        ], fn ($v) => filled($v));

        if ($assign) {
            $service->update($assign);
            $service->refresh();
        }

        if (! $isCloud && ! $service->server_id) {
            return back()->withErrors('اول یک سرورِ تحویل انتخاب کنید.');
        }

        // شکست‌خورده/آماده را دوباره در صف بگذار، بعد همین حالا اجرا کن.
        // 'running' هم برمی‌گردد: اگر پروسه بینِ قفل و پایانِ ساخت کشته شود
        // (دپلوی، ری‌استارتِ FPM، تایم‌اوت)، سرویس تا ابد در 'running' گیر
        // می‌کرد و هیچ مسیری بیرونش نمی‌آورد.
        if (in_array($service->provision_status, [null, 'failed', 'manual', 'running'], true)) {
            $service->update(['provision_status' => 'pending']);
        }

        $ok = app(\App\Services\Provisioning\ProvisioningService::class)->provision($service->fresh());

        if ($ok) {
            \App\Models\ActivityLog::forService($service, 'provision',
                'تحویلِ دستیِ روی سرور توسط مدیر ('.($request->user()?->name ?: 'مدیر').')', 'staff', $request);
        }

        return $ok
            ? back()->with('ok', 'سرویس روی سرور ساخته و تحویل شد.')
            : back()->withErrors('تحویل انجام نشد: '.($service->fresh()->provision_error ?: 'روی این سرور تحویلِ خودکار نیست یا خطا رخ داد.'));
    }

    /**
     * رهاسازیِ صریحِ یک سفارشِ نگه‌داشته‌شده — «می‌دانم، بساز».
     *
     * ═══ 🔴 چرا روتِ **جدا** و نه یک فیلدِ `force` روی `provision` ═══
     *
     * `/provision` مسیرِ «تلاشِ دوباره»ی هاستِ اشتراکی هم هست و یک فرمِ موجود
     * آن را می‌فرستد. یک فیلدِ اضافه روی همان فرم یعنی کنارگذاشتنِ محافظِ
     * سوءاستفاده می‌تواند از یک جریانِ کاملاً بی‌ربط اتفاق بیفتد — و در لاگ هم
     * از یک «تلاش دوباره»ی معمولی قابلِ تشخیص نباشد. این‌جا هر بار زدنش یک
     * عملِ آگاهانه و جداگانه‌ثبت‌شده است.
     *
     * چهار قفلِ مستقل بینِ مشتری و این متد: گروهِ `auth:web`+`admin` در
     * `routes/web.php`، میان‌افزارِ `EnsureAdmin`، `abort_unless` زیر، و اینکه
     * مشتری اصلاً روی گاردِ `web` نمی‌نشیند (گاردِ `customer` جداست).
     */
    public function provisionOverride(Request $request, Service $service): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        if (! \App\Services\Cloud\CloudProvisioner::handles($service)) {
            return back()->withErrors('رهاسازیِ محافظ فقط برای سرورِ ابری معنا دارد.');
        }

        if ($service->provision_status === 'done') {
            return back()->withErrors('این سرویس قبلاً تحویل شده است.');
        }

        $flagged = (string) ($service->provision_error ?: '—');
        $by = (string) ($request->user()?->name ?: 'مدیر');

        // ردِ حسابرسی **پیش** از هر تلاشی نوشته می‌شود: اگر تحویل وسطِ کار
        // بمیرد، باز هم می‌دانیم چه کسی و کِی اجازه داد.
        \App\Models\ActivityLog::forService($service, 'provision',
            'مدیر ('.$by.') رهاسازیِ دستیِ محافظِ سوءاستفاده را ثبت کرد. نشانهٔ ثبت‌شدهٔ محافظ: '.$flagged,
            'staff', $request);

        \App\Support\ErrorTracker::note('fraud-guard',
            'درخواستِ رهاسازیِ دستی توسط مدیر ('.$by.') برای سرویس #'.$service->id.' — نشانه: '.$flagged,
            ['service' => $service->id, 'by' => $by]);

        \App\Services\Cloud\CloudProvisioner::requestOverride($service, $by);

        // 🔴 رهاسازیِ علت به‌تنهایی کافی **نیست**: `provision:run` هرگز `manual`
        //    را برنمی‌دارد، پس ردیف بی‌هیچ قاعده‌ای پارک می‌مانْد. صریح به صف
        //    برمی‌گردد و همین حالا هم یک بار اجرا می‌شود.
        $service->update(['provision_status' => 'pending']);

        $ok = app(\App\Services\Provisioning\ProvisioningService::class)->provision($service->fresh());

        if ($ok) {
            return back()->with('ok', 'محافظ برای همین سفارش کنار گذاشته شد و سرور تحویل شد.');
        }

        $fresh = $service->fresh();

        // علامتِ مصرف‌نشده را نگه می‌داریم تا کرونِ بعدی هم بتواند ادامه دهد،
        // ولی اگر ردیف دوباره `manual` شده یعنی محافظ **دوباره** جلویش را
        // گرفته و باید صریح گفته شود، نه اینکه مدیر فکر کند دکمه کار نکرد.
        return back()->withErrors('محافظ کنار گذاشته شد ولی تحویل کامل نشد: '
            .($fresh->provision_error ?: 'خطای نامشخص — /admin/errors را ببینید.'));
    }

    public function suspend(Request $request, Service $service): RedirectResponse
    {
        $r = app(\App\Services\Provisioning\ProvisioningService::class)->suspend($service);

        if ($r->ok || $r->manual) {
            \App\Models\ActivityLog::forService($service, 'suspend',
                'سرویس توسط مدیر ('.($request->user()?->name ?: 'مدیر').') معلق شد', 'staff', $request);
        }

        return ($r->ok || $r->manual)
            ? back()->with('ok', 'سرویس معلق شد'.($r->manual ? ' (تعلیقِ سرور را دستی انجام دهید).' : ' و روی سرور غیرفعال شد.'))
            : back()->withErrors('تعلیق ناموفق: '.$r->error);
    }

    public function unsuspend(Request $request, Service $service): RedirectResponse
    {
        $r = app(\App\Services\Provisioning\ProvisioningService::class)->unsuspend($service);

        if ($r->ok || $r->manual) {
            \App\Models\ActivityLog::forService($service, 'reactivate',
                'سرویس توسط مدیر ('.($request->user()?->name ?: 'مدیر').') از تعلیق درآمد', 'staff', $request);
        }

        return ($r->ok || $r->manual)
            ? back()->with('ok', 'سرویس فعال شد.')
            : back()->withErrors('رفعِ تعلیق ناموفق: '.$r->error);
    }

    /**
     * تاریخچهٔ مالکیتِ یک سرویس — خواستهٔ کارفرما: «باید بدانم این سرور در فلان
     * زمان دستِ کی بود». همهٔ رویدادهای service-محور به‌ترتیبِ زمان.
     */
    public function history(Service $service): \Illuminate\View\View
    {
        $service->load('customer');

        $logs = \App\Models\ActivityLog::ofService($service->id)->limit(300)->get();

        return view('admin.service-history', compact('service', 'logs'));
    }

    public function terminate(Request $request, Service $service): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $r = app(\App\Services\Provisioning\ProvisioningService::class)->terminate($service);

        \App\Models\ActivityLog::forService($service, 'terminate',
            'سرویس توسط مدیر ('.($request->user()?->name ?: 'مدیر').') لغو شد'
            .(($r->ok || $r->manual) ? ' و از سرور حذف شد' : ' — حذفِ سرور نزدِ زیرساخت انجام نشد و در صفِ تلاشِ دوباره است'),
            'staff', $request);

        /*
        | 🔴 سرویس **همیشه** بسته می‌شود؛ شکستِ زیرساخت وضعیتِ صورت‌حسابی را عقب
        | نمی‌اندازد (وگرنه سرویسِ ساعتی همان ساعت دوباره از مشتری کسر می‌کرد).
        | ولی مدیر باید بداند ماشین شاید هنوز زنده است — پس هم پیامِ موفقیت
        | می‌آید هم خطا، و ردیف در `provision_status='releasing'` می‌مانَد.
        */
        return ($r->ok || $r->manual)
            ? back()->with('ok', 'سرویس لغو شد'.($r->manual ? ' (حذفِ سرور را دستی انجام دهید).' : ' و حساب از سرور حذف شد.'))
            : back()->with('ok', 'سرویس بسته شد و دیگر صورت‌حساب نمی‌شود.')
                ->withErrors('حذفِ سرور نزدِ زیرساخت انجام نشد: '.$r->error
                    .' — سرویس در صفِ تلاشِ دوبارهٔ خودکار (cloud:release-retry) قرار گرفت؛ اگر ماند، دستی پاکش کنید.');
    }
}
