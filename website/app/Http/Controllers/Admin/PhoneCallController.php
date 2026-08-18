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
        ]);
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

        return $result['status'] === OutgoingCallService::OK
            ? back()->with('ok', $result['message'])
            : back()->with('err', $result['message']);
    }
}
