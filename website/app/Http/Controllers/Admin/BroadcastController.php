<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\BroadcastMail;
use App\Models\Broadcast;
use App\Models\Customer;
use App\Services\Notify\CustomerNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * اعلان به مشتریان — یک نفر یا همه.
 *
 * از همان هابِ CustomerNotifier می‌رود، پس هر اعلان هم پیامک می‌شود هم بله
 * (قاعدهٔ کارفرما: هرچه پیامک شد، بله هم بشود). این‌جا فقط مخاطب را انتخاب و
 * متن را می‌نویسیم؛ تحویل و fallbackها کارِ همان هاب است.
 *
 * ⚠️ ارسال گروهی «پول واقعی» است (هر پیامک هزینه دارد) و بازگشت‌ناپذیر. پس
 * ارسال فقط با POSTِ تأییدشده و شمارشِ گیرنده پیش از ارسال انجام می‌شود.
 */
class BroadcastController extends Controller
{
    /** سقفِ ایمنی برای یک ارسال گروهی — جلوی خطای گران را می‌گیرد */
    private const MAX_RECIPIENTS = 5000;

    public function index(Request $request): View
    {
        $ready = Schema::hasTable('broadcasts') && Schema::hasTable('customers');

        return view('admin.broadcasts', [
            'history'  => $ready
                ? Broadcast::with(['customer', 'sender'])->orderByDesc('id')->limit(30)->get()
                : collect(),
            'counts'   => [
                'all'      => Schema::hasTable('customers') ? Customer::count() : 0,
                'active'   => Schema::hasTable('customers') ? Customer::where('status', 'active')->count() : 0,
                'verified' => Schema::hasTable('customers')
                    ? Customer::whereHas('profiles', fn ($p) => $p->where('status', 'verified'))->count() : 0,
            ],
            // پیش‌انتخاب یک مشتری خاص وقتی از پروندهٔ او آمده‌ایم
            'preselect' => $request->integer('customer') ?: null,
            'notReady'  => ! $ready,
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'audience'    => ['required', 'in:all,active,verified,one'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'title'       => ['nullable', 'string', 'max:120'],
            'body'        => ['required', 'string', 'max:1000'],
        ]);

        if ($data['audience'] === 'one' && empty($data['customer_id'])) {
            return back()->withErrors('برای ارسال به یک مشتری، مشتری را انتخاب کنید.')->withInput();
        }

        // عنوان اختیاری است: با nullable، اگر فرستاده نشود کلیدش اصلاً در $data
        // نیست. پس همین اول یکبار نرمال می‌کنیم تا هیچ‌جا به کلید نبود دست نزنیم.
        $title = trim((string) ($data['title'] ?? ''));

        // متن نهایی — عنوان (اگر باشد) روی خط اول
        $text = trim(($title !== '' ? $title."\n" : '').$data['body']);

        $targets = $this->targets($data['audience'], $data['customer_id'] ?? null);
        $count   = $targets->count();

        if ($count === 0) {
            return back()->withErrors('هیچ گیرنده‌ای برای این مخاطب پیدا نشد.')->withInput();
        }

        if ($count > self::MAX_RECIPIENTS) {
            return back()->withErrors('تعداد گیرنده ('.number_format($count).') از سقف ایمنی بیشتر است.')->withInput();
        }

        $notifier = app(CustomerNotifier::class);
        $sent = 0;

        foreach ($targets as $customer) {
            try {
                $notifier->event($customer, 'announce', ['title' => $title, 'body' => $text], $text);
                $sent++;
            } catch (\Throwable) {
                // یک گیرندهٔ خراب نباید کل ارسال را متوقف کند
            }

            // ایمیل هم بفرست (کانالِ مستقل) — قاعدهٔ کارفرما: همهٔ اعلان‌ها ایمیل
            // هم بشوند. با قالبِ برنددارِ سه‌زبانه، به زبانِ خودِ مشتری. try/catch
            // جدا تا شکستِ ایمیل نه مسیر پیامک/بله را بشکند نه شمارش را.
            if (filled($customer->email)) {
                try {
                    Mail::mailer('smtp')->to($customer->email)->send(
                        new BroadcastMail($title !== '' ? $title : null, $data['body'], $customer->locale ?: 'fa')
                    );
                } catch (\Throwable) {
                    // ایمیلِ خراب هم نباید ارسال را متوقف کند
                }
            }
        }

        Broadcast::create([
            'audience'    => $data['audience'],
            'customer_id' => $data['audience'] === 'one' ? $data['customer_id'] : null,
            'title'       => $title !== '' ? $title : null,
            'body'        => $data['body'],
            'recipients'  => $sent,
            // در عمل همیشه مدیرِ واردشده است؛ ?-> فقط تضمین می‌کند حتی حالت
            // مرزی هم ثبت را (که ستونش nullable است) ۵۰۰ نکند
            'sent_by'     => $request->user()?->id,
        ]);

        /*
        | 🔴 فقط کانال‌هایی نام برده می‌شوند که **واقعاً** رفتند.
        |
        | قبلاً بی‌قید و شرط می‌نوشت «(پیامک، بله و ایمیل)». ولی `announce`
        | الگوی پیامک ندارد و درایورِ فعال متنِ آزاد نمی‌فرستد — پس تعدادِ
        | پیامکِ ارسال‌شده **صفر** بود. مدیر یک اطلاعیه به ۳۰۰ مشتری می‌فرستاد،
        | تأییدِ سبز می‌گرفت، و باور می‌کرد ۳۰۰ پیامک رفته.
        |
        | ⚠️ فهرست از همان جایی می‌آید که خودِ ارسال از رویش تصمیم می‌گیرد، پس
        | اگر روزی الگوی `announce` ساخته شد، این جمله خودبه‌خود درست می‌شود.
        */
        $channels = \App\Models\NotificationTemplate::channelsFor('announce');

        $names = array_values(array_filter([
            in_array('sms', $channels, true) ? 'پیامک' : null,
            in_array('bale', $channels, true) ? 'بله' : null,
            in_array('email', $channels, true) ? 'ایمیل' : null,
        ]));

        return back()->with('ok', 'اعلان به '.number_format($sent).' مشتری ارسال شد'
            .($names ? ' ('.implode(' و ', $names).').' : '.'));
    }

    /**
     * مجموعهٔ مشتریانِ هدف بر اساس مخاطب.
     *
     * @return \Illuminate\Support\Collection<int,Customer>
     */
    private function targets(string $audience, ?int $customerId)
    {
        // گیرنده = هرکس که دستِ‌کم یک راهِ تماس دارد: موبایل (برای پیامک/بله) یا
        // ایمیل (برای ایمیل). قبلاً فقط موبایل‌دارها هدف بودند؛ حالا که ایمیل هم
        // اضافه شده، مشتریِ فقط‌ایمیل‌دار هم باید اعلان بگیرد.
        $q = Customer::query()->where(function ($w) {
            $w->where(function ($p) {
                $p->whereNotNull('phone')->where('phone', '!=', '');
            })->orWhere(function ($e) {
                $e->whereNotNull('email')->where('email', '!=', '');
            });
        });

        return match ($audience) {
            'one'      => Customer::where('id', $customerId)->get(),
            'active'   => $q->where('status', 'active')->get(),
            'verified' => $q->whereHas('profiles', fn ($p) => $p->where('status', 'verified'))->get(),
            default    => $q->get(),
        };
    }
}
