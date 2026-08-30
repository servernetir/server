<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CustomerDocument;
use App\Models\CustomerProfile;
use App\Services\Notify\CustomerNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * صفِ احراز هویتِ مشتریان در پنلِ مدیریت.
 *
 * پروفایل‌های «در انتظار بررسی» را نشان می‌دهد؛ مدیر مدارک را دانلود و بررسی
 * می‌کند و تأیید/رد می‌کند. تأیید/رد به مشتری اعلان می‌رود (پیامک/بله + ایمیل).
 * دانلودِ مدرک از دیسکِ خصوصیِ بیرونِ webroot و فقط برای مدیرِ واردشده.
 */
class VerificationController extends Controller
{
    public function index(Request $request): View
    {
        if (! Schema::hasTable('customer_profiles')) {
            return view('admin.verifications', ['notReady' => true, 'pending' => collect(), 'recent' => collect(), 'q' => '']);
        }

        $q = trim((string) $request->query('q', ''));

        // پایه با جستجوی اختیاری: نامِ حقیقی/شرکت روی پروفایل، یا مشتری (کد/ایمیل/موبایل).
        $base = CustomerProfile::with('customer')->withCount('documents')
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->where('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhere('company_name', 'like', "%{$q}%")
                        ->orWhereHas('customer', function ($c) use ($q) {
                            $c->where('code', 'like', "%{$q}%")
                                ->orWhere('email', 'like', "%{$q}%")
                                ->orWhere('phone', 'like', "%{$q}%");
                        });
                });
            });

        return view('admin.verifications', [
            'notReady' => false,
            'q'        => $q,
            'pending'  => (clone $base)->where('status', 'pending')->orderBy('updated_at')->get(),
            // هنگام جستجو سقفِ «اخیر» بالاتر می‌رود تا نتیجهٔ قدیمی‌تر هم پیدا شود.
            'recent'   => (clone $base)->whereIn('status', ['verified', 'rejected'])->latest('verified_at')->latest('updated_at')->limit($q !== '' ? 100 : 30)->get(),
        ]);
    }

    /** دانلودِ امنِ مدرک — فقط مدیرِ واردشده، از دیسکِ خصوصی. */
    public function document(CustomerProfile $profile, CustomerDocument $document): StreamedResponse
    {
        abort_unless($document->customer_profile_id === $profile->id, 404);
        abort_unless(Storage::disk('local')->exists($document->disk_path), 404);

        return Storage::disk('local')->download(
            $document->disk_path,
            $document->original_name ?: basename($document->disk_path),
        );
    }

    /*
    | 🔴 منطقِ تأیید/رد در `KycReview` است، نه این‌جا — رباتِ بله هم همان را صدا
    | می‌زند و دو تعریف از «تأیید» یعنی روزی دروازهٔ ایران با پنل نخوانَد.
    */

    public function approve(Request $request, CustomerProfile $profile): RedirectResponse
    {
        $res = app(\App\Services\Customer\KycReview::class)->approve($profile, $request->user()->id);

        return back()->with($res['ok'] ? 'ok' : 'err', $res['message']);
    }

    public function reject(Request $request, CustomerProfile $profile): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:400']], [], ['reason' => 'دلیلِ رد']);

        $res = app(\App\Services\Customer\KycReview::class)->reject($profile, $data['reason'], $request->user()->id);

        return back()->with($res['ok'] ? 'ok' : 'err', $res['message']);
    }
}
