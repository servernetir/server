<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Support\Funnel;
use App\Support\OrderHandoff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * تسویهٔ خریدِ یک پکیج — نه یک فروشگاهِ لیستی.
 *
 * قاعدهٔ کارفرما: مشتری در سایتِ اصلی پکیج را می‌بیند و انتخاب می‌کند؛ دکمهٔ
 * «خرید» او را مستقیم به صفحهٔ تسویهٔ همان پکیج در پنل می‌آورد تا فرایندِ خرید
 * را کامل کند (دامنه دارم/می‌خرم → پیش‌فاکتور → پرداخت → تحویلِ خودکار).
 */
class StoreController extends Controller
{
    /**
     * ورودیِ «خرید سرویس» در منوی پنل.
     *
     * ═══ 🔴 حلقهٔ بسته‌ای که این‌جا بود ═══
     *
     * قبلاً به `lroute('home').'#hosting'` می‌رفت. ولی صفحاتِ `account/*` را
     * `ConsoleHost` به `console.servernet.cloud` می‌فرستد، پس `lroute('home')`
     * میزبانِ **کنسول** را می‌گیرد ⇒ `https://console.servernet.cloud#hosting`.
     * و `ConsoleHost` برای مسیرِ `/` روی کنسول، مشتریِ واردشده را به داشبورد
     * برمی‌گرداند.
     *
     * نتیجه: مشتری روی «خرید سرویس» می‌زد و بعد از دو ریدایرکت **دقیقاً همان
     * داشبوردی** را می‌دید که از آن آمده بود — بی‌هیچ پیام و خطایی. هر دو
     * ورودیِ خرید در پنل همین‌طور بودند. (لنگرِ `#hosting` هم اصلاً وجود ندارد.)
     *
     * حالا به **سرورسازِ واقعی** می‌رود که همان‌جا در پنل است و کار می‌کند.
     */
    public function index(): RedirectResponse
    {
        return redirect(lroute('account.cloud.store'));
    }

    /** صفحهٔ تسویهٔ یک پکیج (از دکمهٔ خریدِ سایت اصلی) */
    public function checkout(Product $product): View|RedirectResponse
    {
        if (! $product->is_active) {
            /*
            | ⚠️ به `home` نه — روی کنسول، `/` مشتریِ واردشده را به داشبورد
            | می‌برد و پیامِ خطا در آن پرش گم می‌شود. همان دامی که ورودیِ
            | «خرید سرویس» را هم به حلقهٔ بسته تبدیل کرده بود.
            */
            return redirect(lroute('account.services'))
                ->with('err', 'این پکیج دیگر در دسترس نیست. لطفاً پکیجِ دیگری انتخاب کنید یا با پشتیبانی تماس بگیرید.');
        }

        /*
        | تحویلِ امضاشده از /order/{sku} (ممیزی ۶ — SN-ORDER-001): دوره‌ای که کاربر
        | روی سایت انتخاب کرده همین‌جا از پیش انتخاب می‌شود. امضای نامعتبر/منقضی
        | خرید را نمی‌بندد — فقط نادیده گرفته و شمرده می‌شود. هیچ قیمتی از URL.
        */
        $reason = null;
        $handoff = OrderHandoff::verify(request()->query(), $product->slug, array_keys((array) config('billing.cycles', [])), $reason);

        if ($handoff !== null) {
            session(['order_handoff' => $handoff]);
            Funnel::log('handoff_landed', $handoff + ['lang' => app()->getLocale()]);
        } elseif (request()->query('sig') !== null) {
            // دلیل برای تفکیکِ «لینکِ کهنه» از «دستکاری» (شورا/امنیت) — بدونِ خودِ امضا
            Funnel::log('handoff_invalid', [
                'sku'           => $product->slug,
                'reason'        => (string) $reason,
                'sid'           => OrderHandoff::clean(request()->query('sid'), OrderHandoff::SID_RE),
                'ref'           => OrderHandoff::clean(request()->query('ref'), OrderHandoff::REF_RE),
                'default_cycle' => (string) config('billing.default_cycle', 'monthly'),
                'lang'          => app()->getLocale(),
            ]);
        }

        return view('account.checkout', AccountController::shell('') + [
            'product'   => $product,
            // دورهٔ منتقل‌شده از سایت — null یعنی پیش‌فرضِ config
            'handoffCycle' => $handoff['cycle'] ?? null,
            // لایسنس نه سرور می‌خواهد نه مکان — فهرستِ خالی یعنی ویو بخشِ
            // «محلِ سرور» را اصلاً رندر نمی‌کند (و با پرچمِ isLicense، هشدارِ
            // «سرور نداریم» و غیرفعال‌شدنِ دکمه هم نمی‌آید).
            // مشتریِ احرازنشده «ایران» را اصلاً نمی‌بیند (لایهٔ نمایش؛ گیتِ
            // سختِ سفارش پایین‌تر است — نمایش قابلِ دورزدن است، سفارش نه)
            'countries' => $product->isLicense() ? [] : array_values(array_filter(
                $product->availableCountries(),
                fn ($c) => ! \App\Services\Customer\IranSalesGate::blocks(Auth::guard('customer')->user(), $c),
            )),
            'cycles'    => array_keys((array) config('billing.cycles', [])),
            'isLicense' => $product->isLicense(),
        ]);
    }

    /** ثبتِ سفارش: سرویس + پیش‌فاکتور می‌سازد و به پرداخت می‌برد */
    public function order(Request $request, Product $product): RedirectResponse
    {
        if (! $product->is_active) {
            return back()->withErrors('این پکیج در دسترس نیست.');
        }

        if ($product->isLicense()) {
            return $this->orderLicense($request, $product);
        }

        // مکان‌های واقعاً موجود در همین لحظه — مشتری نباید مکانی را بخرد که
        // سرورِ آماده ندارد، وگرنه پول داده و سرویسش روی هوا می‌ماند.
        $countries = $product->availableCountries();
        $cycles = array_keys((array) config('billing.cycles', []));

        $data = $request->validate([
            'country'     => [$countries === [] ? 'nullable' : 'required', \Illuminate\Validation\Rule::in($countries)],
            'cycle'       => ['required', \Illuminate\Validation\Rule::in($cycles)],
            'domain_mode' => ['required', 'in:have,buy,subdomain'],
            'domain'      => ['nullable', 'string', 'max:190', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'],
            'domain_buy'  => ['nullable', 'string', 'max:190', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'],
            // زیردامنه: فقط حروف/رقم/خط‌تیره، نه در ابتدا/انتها، و نه از فهرستِ
            // ممنوعه. بدونِ این، مشتری می‌توانست console/mail/pay را بگیرد و
            // زیردامنهٔ حساسِ ما به هاستِ او می‌نشست (راهِ فیشینگ).
            'subdomain'   => [
                'nullable', 'string', 'min:3', 'max:40',
                'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/i',
                function ($attr, $value, $fail) {
                    $label = strtolower(trim((string) $value));

                    if (in_array($label, array_map('strtolower', (array) config('servernet.subdomain_reserved', [])), true)) {
                        $fail('این زیردامنه رزرو شده است؛ نام دیگری انتخاب کنید.');

                        return;
                    }

                    // دو مشتری نباید یک زیردامنه بگیرند
                    $fqdn = $label.'.'.config('servernet.subdomain_zone', 'servernet.cloud');
                    if (Service::where('domain', $fqdn)->whereNotIn('status', Service::DEAD_STATUSES)->exists()) {
                        $fail('این زیردامنه قبلاً گرفته شده است؛ نام دیگری انتخاب کنید.');
                    }
                },
            ],
        ], [], [
            'country' => 'محلِ سرور', 'cycle' => 'دورهٔ پرداخت',
            'domain' => 'دامنه', 'domain_buy' => 'دامنه', 'subdomain' => 'زیردامنه',
        ]);

        // دامنهٔ نهایی بر اساس انتخابِ کاربر
        [$domain, $note] = $this->resolveDomain($data);
        if ($domain === null) {
            return back()->withInput()->withErrors(['domain' => 'دامنه را کامل وارد کنید.']);
        }

        $customer = Auth::guard('customer')->user();
        $country = $data['country'] ?? null;

        // 🔴 دروازهٔ فروشِ ایران — محصولِ مستقر در ایران فقط به مشتریِ
        // احرازشده (تصمیمِ کارفرما، ۶ شهریور ۱۴۰۵؛ تنظیمات → عمومی)
        if (\App\Services\Customer\IranSalesGate::blocks($customer, $country)) {
            return back()->withInput()->withErrors(['country' => \App\Services\Customer\IranSalesGate::message()]);
        }
        $cycle = $data['cycle'];

        // مکان → سرورِ مقصد. اگر مکانی انتخاب نشده (پکیجِ دستی)، سرورِ خودِ پکیج.
        $server = $country ? \App\Models\Server::pickForCountry($country) : null;
        if ($country && $server === null) {
            return back()->withInput()->withErrors(['country' => 'ظرفیتِ این مکان همین حالا پر شد؛ مکانِ دیگری را انتخاب کنید.']);
        }

        // مبلغِ دوره در لحظهٔ سفارش قفل می‌شود؛ تغییرِ بعدیِ تخفیف‌ها یا نرخِ ارز
        // این سرویس و تمدیدهایش را عوض نمی‌کند.
        $cyclePrice = $product->priceForCycle($cycle, $country);
        $locNote = $country ? 'محلِ سرور: '.trim((config('billing.locations.'.$country.'.flag') ?? '').' '.(config('billing.locations.'.$country.'.label.fa') ?? $country)) : '';

        $invoice = DB::transaction(function () use ($customer, $product, $domain, $note, $server, $country, $cycle, $cyclePrice, $locNote) {
            $service = Service::create([
                'customer_id'   => $customer->id,
                'name'          => $product->name,
                'description'   => trim(implode("\n", array_filter([$product->description, $locNote, $note]))),
                'currency_code' => $product->currency_code,
                'price'         => $cyclePrice,
                'tax_percent'   => $product->tax_percent,
                'cycle'         => $cycle,
                'status'        => 'pending',
                'server_id'     => $server?->id ?? $product->server_id,
                'plan'          => $product->plan,
                // نیتِ «نمایندگی» همین‌جا قفل می‌شود و در لحظهٔ تحویل دوباره
                // حدس زده نمی‌شود — دلیلش روی `Product::isReseller()`.
                'is_reseller'   => $product->isReseller(),
                'domain'        => $domain,
            ]);

            return $this->issueOrderInvoice($service, $product);
        });

        \App\Models\ActivityLog::record($customer->id, 'purchase',
            'سفارشِ آنلاینِ پکیج «'.$product->name.'» ('.$domain.') — '
            .Service::labelFor($cycle).($country ? ' · '.$country : '').' توسط مشتری ثبت شد',
            $request, 'customer', $invoice->service_id);

        // اعلانِ سفارشِ تازه به مدیر (هنوز پرداخت نشده — پرداختش اعلانِ جدا دارد)
        app(\App\Services\Notify\AdminNotifier::class)->event('سفارشِ جدید (در انتظارِ پرداخت)', [
            'مشتری' => $customer->displayName().' ('.$customer->code.')',
            'پکیج'  => $product->name,
            'دامنه' => $domain,
            'دوره'  => Service::labelFor($cycle),
            'مکان'  => $country,
            'مبلغ'  => fa_num(number_format((int) $invoice->total)).' تومان',
        ], url('/admin/customers/'.$customer->id), '🛒');

        /*
        | رویدادِ پشتِ مرزِ console (ممیزی ۶ — رشد): با همان sid که از /order آمد،
        | تا «نرخِ بقای مرز» و «سهمِ دوره‌ها در سفارشِ نهایی» شمردنی شود.
        */
        $ho = (array) session('order_handoff', []);
        Funnel::log('order_placed', ['sku' => $product->slug, 'cycle' => $cycle, 'sid' => $ho['sid'] ?? '', 'ref' => $ho['ref'] ?? '', 'handoff_cycle' => $ho['cycle'] ?? '']);

        return redirect()->route($this->rp().'account.invoice', $invoice)
            ->with('ok', 'سفارش ثبت شد. برای فعال‌سازی، پیش‌فاکتور را پرداخت کنید.');
    }

    /**
     * سفارشِ لایسنس نرم‌افزار — شناسه‌ی سرویس IP مشتری است، نه دامنه.
     *
     * 🔴 IP در ستونِ `domain` می‌نشیند و این عمدی است: applyPaid «دامنه‌ی
     * پرشده + بی‌سرور» را تحویلِ دستیِ ادمین می‌داند (provision_status=manual)
     * — دقیقاً مسیرِ درستِ لایسنس (فعال‌سازی نزدِ تأمین‌کننده، بعد «تحویل شد»).
     * ستونِ جدا یعنی مسیرِ تحویلِ تازه، و آن یعنی هر سه‌جای فهرست‌شده در
     * PaymentService باید هم‌زمان عوض شود؛ این‌جا هیچ‌کدام لازم نیست.
     */
    private function orderLicense(Request $request, Product $product): RedirectResponse
    {
        $cycles = array_keys((array) config('billing.cycles', []));

        $data = $request->validate([
            'cycle'      => ['required', \Illuminate\Validation\Rule::in($cycles)],
            'license_ip' => [
                /*
                | ⚠️ `ipv4` عمدی است و **نباید** به `ip` باز شود.
                |
                | یک بار همین کار را کردم به این گمان که «سرور اروپایی شاید
                | فقط IPv6 داشته باشد» — ولی خودِ لایسنسِ cPanel و DirectAdmin
                | به IPv4ِ اصلیِ سرور گره می‌خورد. پذیرفتنِ IPv6 یعنی سفارشی
                | ثبت شود که **قابلِ فعال‌سازی نیست**، و مشتری تازه چند روز
                | بعد بفهمد. ضمناً `isPublicIp` رنجِ مستندسازیِ IPv6
                | (`2001:db8::/32`) را رد نمی‌کند، پس گارد هم شل‌تر می‌شد.
                */
                'required', 'ipv4',
                function ($attr, $value, $fail) {
                    // لایسنس روی IP خصوصی/رزرو فعال‌شدنی نیست؛ همان قاعده‌ی SafeUrl
                    if (! \App\Services\SafeUrl::isPublicIp((string) $value)) {
                        $fail('آی‌پی باید عمومی باشد؛ آدرس‌های داخلی و رزروشده پذیرفته نمی‌شوند.');
                    }
                },
            ],
        ], [], ['cycle' => 'دورهٔ پرداخت', 'license_ip' => 'آی‌پی سرور']);

        $customer = Auth::guard('customer')->user();
        $ip = trim($data['license_ip']);
        $cycle = $data['cycle'];

        // مبلغ در لحظه‌ی سفارش قفل می‌شود — بدون ضریبِ مکان (لایسنس مکان ندارد)
        $cyclePrice = $product->priceForCycle($cycle);

        $invoice = DB::transaction(function () use ($customer, $product, $ip, $cycle, $cyclePrice) {
            $service = Service::create([
                'customer_id'   => $customer->id,
                'name'          => $product->name,
                'description'   => trim(implode("\n", array_filter([
                    $product->description,
                    '🔑 فعال‌سازی لایسنس روی IP: '.$ip,
                ]))),
                'currency_code' => $product->currency_code,
                'price'         => $cyclePrice,
                'tax_percent'   => $product->tax_percent,
                'cycle'         => $cycle,
                'status'        => 'pending',
                'server_id'     => null,
                'plan'          => $product->plan,
                'domain'        => $ip,
            ]);

            return $this->issueOrderInvoice($service, $product);
        });

        \App\Models\ActivityLog::record($customer->id, 'purchase',
            'سفارشِ آنلاینِ لایسنس «'.$product->name.'» (IP: '.$ip.') — '
            .Service::labelFor($cycle).' توسط مشتری ثبت شد',
            $request, 'customer', $invoice->service_id);

        app(\App\Services\Notify\AdminNotifier::class)->event('سفارشِ لایسنس (در انتظارِ پرداخت)', [
            'مشتری'  => $customer->displayName().' ('.$customer->code.')',
            'لایسنس' => $product->name,
            'IP'     => $ip,
            'دوره'   => Service::labelFor($cycle),
            'مبلغ'   => fa_num(number_format((int) $invoice->total)).' تومان',
        ], url('/admin/customers/'.$customer->id), '🔑');

        return redirect()->route($this->rp().'account.invoice', $invoice)
            ->with('ok', 'سفارش ثبت شد. برای فعال‌سازی لایسنس، پیش‌فاکتور را پرداخت کنید.');
    }

    /** دامنهٔ نهایی + یادداشت بر اساس حالت (دارم/می‌خرم/زیردامنه) */
    private function resolveDomain(array $data): array
    {
        return match ($data['domain_mode']) {
            'have' => [
                filled($data['domain'] ?? null) ? strtolower(trim($data['domain'])) : null,
                '',
            ],
            'buy' => [
                filled($data['domain_buy'] ?? null) ? strtolower(trim($data['domain_buy'])) : null,
                filled($data['domain_buy'] ?? null) ? '🌐 ثبتِ دامنهٔ '.strtolower(trim($data['domain_buy'])).' درخواست شده (توسط پشتیبانی انجام می‌شود).' : '',
            ],
            'subdomain' => [
                filled($data['subdomain'] ?? null) ? strtolower(trim($data['subdomain'])).'.servernet.cloud' : null,
                'زیردامنهٔ رایگانِ سرورنت',
            ],
            default => [null, ''],
        };
    }

    /**
     * پیش‌فاکتورِ اولین دوره — شاملِ هزینهٔ راه‌اندازی (اگر باشد).
     *
     * ⚠️ خودِ ساختِ فاکتور در `ProductInvoiceIssuer` است، چون فروشِ تلفنی از
     * رباتِ بله هم دقیقاً همان فاکتور را می‌خواهد. اعلانِ زیر مالِ همین مسیر
     * است (مشتری خودش سفارش داده) و عمداً منتقل نشد.
     */
    private function issueOrderInvoice(Service $service, Product $product): Invoice
    {
        $invoice = app(\App\Services\Billing\ProductInvoiceIssuer::class)->issue($service, $product);

        /*
        | دو رویداد که تا امروز هیچ‌کدام وجود نداشتند: «ثبتِ سفارش» و «صدورِ
        | پیش‌فاکتور».
        |
        | ⚠️ الگوی `invoice` سال‌ها در پنلِ الگوها بود و مدیر می‌توانست متنش را
        | ویرایش کند و «ذخیره شد» بگیرد — و **هیچ کدی صدایش نمی‌زد**. این‌جا
        | اولین فراخوانش است.
        */
        try {
            $notifier = app(\App\Services\Notify\Notifier::class);
            $link = console_lroute('account.invoice', $invoice);
            $amount = fa_num(number_format((int) $invoice->total)).' تومان';

            $notifier->fire('service_ordered', $service->customer,
                ['service' => $service->name, 'amount' => $amount],
                '🧾 سفارشِ «'.$service->name.'» ثبت شد.',
                [], url('/admin/customers/'.$service->customer_id), '🧾');

            $notifier->fire('invoice', $service->customer,
                ['number' => (string) $invoice->number, 'amount' => $amount, 'link' => $link],
                'پیش‌فاکتورِ '.fa_num((string) $invoice->number).' به مبلغِ '.$amount
                .' صادر شد. پس از پرداخت، سرویس **خودکار** تحویل می‌شود: '.$link,
                [], url('/admin/customers/'.$service->customer_id), '🧾');
        } catch (\Throwable $e) {
            // اعلان هرگز نباید سفارشِ ثبت‌شده را بشکند
            \App\Support\ErrorTracker::note('notify', $e, ['invoice' => $invoice->id]);
        }

        return $invoice;
    }

    private function rp(): string
    {
        return \App\Providers\AppServiceProvider::LOCALES[app()->getLocale()] ?? '';
    }
}
