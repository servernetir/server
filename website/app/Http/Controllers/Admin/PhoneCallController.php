<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\PhoneCall;
use App\Services\CloudPhone\OutgoingCallService;
use App\Support\IranianPhone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * گزارش تماس‌ها و تماسِ یک‌کلیکی در پنل مدیریت.
 */
class PhoneCallController extends Controller
{
    /** فهرست تماس‌ها — `/admin/calls` */
    public function index(Request $request): View
    {
        // نگهبانِ مهاجرت: روی سروری که هنوز مهاجرت نکرده، صفحه نباید ۵۰۰ شود
        if (! Schema::hasTable('phone_calls')) {
            return view('admin.calls', [
                'notReady' => true,
                'calls' => collect(),
                'counts' => ['all' => 0, 'missed' => 0, 'incoming' => 0, 'outgoing' => 0],
                'filter' => 'all',
                'q' => '',
                'dialer' => $this->dialerState($request),
            ]);
        }

        $filter = (string) $request->query('f', 'all');
        $q = trim((string) $request->query('q', ''));

        $base = fn () => PhoneCall::query();

        $calls = $base()->with('customer');

        $calls = match ($filter) {
            // 🔴 `missed()` صریحاً `answered = false` است، نه «پاسخ‌داده‌نشده».
            //    تماسِ در جریان (`answered = null`) از‌دست‌رفته نیست.
            'missed' => $calls->missed(),
            'incoming' => $calls->where('direction', 'incoming'),
            'outgoing' => $calls->where('direction', 'outgoing'),
            'unmatched' => $calls->whereNull('customer_id'),
            default => $calls,
        };

        if ($q !== '') {
            /*
            | جستجو روی شکلِ **نرمال‌شده** هم انجام می‌شود، نه فقط خام.
            | مدیر «۰۹۱۴…» تایپ می‌کند ولی در دیتابیس «۹۱۴…» نشسته.
            */
            $norm = IranianPhone::normalize($q);

            $calls->where(function ($w) use ($q, $norm) {
                $w->where('caller_number', 'like', '%'.$q.'%')
                    ->orWhere('transferred_to_number', 'like', '%'.$q.'%');

                if ($norm !== null) {
                    $w->orWhere('caller_number_norm', 'like', '%'.$norm.'%');
                }
            });
        }

        return view('admin.calls', [
            'notReady' => false,
            'calls' => $calls->orderByDesc('started_at')->orderByDesc('id')->paginate(50)->withQueryString(),
            'counts' => [
                'all' => $base()->count(),
                'missed' => $base()->missed()->count(),
                'incoming' => $base()->where('direction', 'incoming')->count(),
                'outgoing' => $base()->where('direction', 'outgoing')->count(),
                'unmatched' => $base()->whereNull('customer_id')->count(),
            ],
            'filter' => $filter,
            'q' => $q,
            'dialer' => $this->dialerState($request),
        ]);
    }

    /**
     * آیا شماره‌گیرِ دستی آماده است — و اگر نه، **چرا**.
     *
     * ⚠️ «چرا» جزوِ خروجی است، نه یک بولین. دکمه‌ای که بی‌توضیح غیبش بزند،
     * مدیر را می‌فرستد سراغِ تیم فنی؛ «رله وصل نیست» و «شمارهٔ خودت ثبت نشده»
     * دو کارِ کاملاً متفاوت لازم دارند.
     *
     * @return array{ready:bool, agent:?string, why:string}
     */
    private function dialerState(Request $request): array
    {
        $service = app(OutgoingCallService::class);
        $agent = $service->agentNumberFor($request->user()?->phoneExtension());

        if (! $service->enabled()) {
            return ['ready' => false, 'agent' => null, 'why' => 'رلهٔ تلفن ابری پیکربندی نشده'];
        }

        if ($agent === null) {
            return ['ready' => false, 'agent' => null, 'why' => 'شمارهٔ تماس‌گیرنده تنظیم نشده'];
        }

        if ($service->extension() === null) {
            return ['ready' => false, 'agent' => $agent, 'why' => 'خطِ ابری تنظیم نشده'];
        }

        return ['ready' => true, 'agent' => $agent, 'why' => ''];
    }

    /**
     * تماس با یک مشتری — `POST /admin/customers/{customer}/call`
     *
     * ⚠️ شمارهٔ مقصد از **دیتابیس** خوانده می‌شود، نه از فرم.
     *
     * اگر شماره را از فرم می‌گرفتیم، هر کسی با دسترسیِ پنل می‌توانست از خطِ
     * شرکت به هر شماره‌ای زنگ بزند — یعنی پنلِ مدیریت تبدیل می‌شد به یک
     * تلفنِ رایگانِ بین‌المللی. این‌طوری فقط می‌شود به شمارهٔ ثبت‌شدهٔ خودِ
     * مشتری زنگ زد.
     */
    public function call(Request $request, Customer $customer, OutgoingCallService $service): RedirectResponse
    {
        $user = $request->user();

        $number = $customer->phone
            ?: optional($customer->profiles->firstWhere('is_default', true))->mobile
            ?: optional($customer->profiles->first())->mobile;

        if (! $number) {
            return back()->with('err', 'برای این مشتری شماره‌ای ثبت نشده.');
        }

        $result = $service->place((string) $number, $user?->phoneExtension());

        /*
        | ⚠️ سه حالت، نه دو تا. «نمی‌دانیم» نباید به «نشد» تبدیل شود — یک بار
        | همین باعث شد پنل بگوید تماس برقرار نشد در حالی که تلفن زنگ خورده بود.
        */
        return match ($result['status']) {
            OutgoingCallService::OK => back()->with('ok', $result['message']),
            OutgoingCallService::UNKNOWN => back()->with('warn', $result['message']),
            default => back()->with('err', $result['message']),
        };
    }

    /**
     * تماس با یک شمارهٔ دلخواه — `POST /admin/calls/dial`
     *
     * ═══ چرا این با `call()` فرق دارد ═══
     *
     * `call()` شماره را از **دیتابیس** می‌خوانَد و کامنتش می‌گوید چرا: «اگر
     * شماره را از فرم می‌گرفتیم، پنل تبدیل می‌شد به یک تلفنِ رایگانِ
     * بین‌المللی». آن نگرانی سرِ جایش است — ولی خواستهٔ واقعیِ کارفرما هم
     * هست: «مشتریم نبود هم بتوانم تماس بگیرم.»
     *
     * پس شماره از فرم می‌آید، و همان نگرانی با سه چیزِ **دیگر** بسته می‌شود:
     *
     *   ۱) `place()` فقط موبایل یا ثابتِ **ایرانیِ** با پیش‌شماره را می‌پذیرد
     *      (`IranianPhone::kind`). شمارهٔ بین‌المللی اصلاً از آن نگهبان رد
     *      نمی‌شود — یعنی «تلفنِ رایگانِ بین‌المللی» ممکن نیست.
     *   ۲) محدودیتِ نرخ روی خودِ روت (در `routes/web.php`).
     *   ۳) هر شماره‌گیری در `ActivityLog` می‌نشیند، با نامِ کاربر.
     *
     * ⚠️ بندِ ۳ مهم‌ترینشان است. تماس با مشتری خودش رد می‌گذارد (به پروندهٔ
     * او می‌چسبد)، ولی تماس با غریبه به هیچ پرونده‌ای وصل نیست — پس بی‌این
     * لاگ، تنها ردش صورت‌حسابِ تأمین‌کننده بود.
     */
    public function dial(Request $request, OutgoingCallService $service): RedirectResponse
    {
        $user = $request->user();

        /*
        | ⚠️ قاعدهٔ ثبت‌شدهٔ پروژه: وقتی الگو `|` دارد، قواعد باید **آرایه**
        | باشند وگرنه لاراول رشته را از روی `|` تکه می‌کند و الگو می‌شکند.
        */
        $data = $request->validate([
            'number' => ['required', 'string', 'max:20'],
        ]);

        $number = IranianPhone::digits((string) $data['number']);

        if ($number === '') {
            return back()->with('err', 'شماره را وارد کنید.');
        }

        $result = $service->place($number, $user?->phoneExtension());

        /*
        | ⚠️ لاگ **پیش از** بازگشت و برای هر سه حالت — حتی «نمی‌دانم». تماسی
        | که وضعیتش نامعلوم است ممکن است برقرار شده باشد و پول خرج کرده باشد؛
        | نبودنش در لاگ یعنی همان تماس هیچ ردی ندارد.
        */
        try {
            \App\Models\ActivityLog::record(
                null,
                'call',
                'شماره‌گیریِ دستی: '.$number.' — نتیجه: '.$result['status'],
                null,
                'staff',
            );
        } catch (\Throwable $e) {
            \App\Support\ErrorTracker::note('cloud-phone', $e, ['area' => 'dial-log']);
        }

        return match ($result['status']) {
            OutgoingCallService::OK => back()->with('ok', $result['message']),
            OutgoingCallService::UNKNOWN => back()->with('warn', $result['message']),
            default => back()->with('err', $result['message']),
        };
    }
}
