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

    public function approve(Request $request, CustomerProfile $profile): RedirectResponse
    {
        $profile->status = 'verified';
        $profile->verified_at = now();
        $profile->reject_reason = null;
        $profile->save();

        CustomerDocument::where('customer_profile_id', $profile->id)
            ->update(['status' => 'approved', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);

        $who = $profile->company_name ?: ($profile->customer?->displayName() ?? '');

        // متن به زبانِ خودِ مشتری — مشتریِ خارجی پیامِ فارسی را نمی‌فهمد
        $this->notifyCustomer($profile, ($profile->customer?->locale ?? 'fa') === 'fa'
            ? '✅ هویتِ شما در سرورنت تأیید شد'
                .($profile->company_name ? ' («'.$profile->company_name.'»)' : '').'. حالا می‌توانید از همهٔ خدمات استفاده کنید.'
            : '✅ Your identity has been verified at ServerNet. All services — including Iran-hosted plans — are now available to your account.');

        ActivityLog::record($profile->customer_id, 'verify', 'هویت تأیید شد: '.$who, $request, 'admin');

        return back()->with('ok', 'هویت تأیید شد و به مشتری اطلاع داده شد.');
    }

    public function reject(Request $request, CustomerProfile $profile): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:400']], [], ['reason' => 'دلیلِ رد']);

        $profile->status = 'rejected';
        $profile->reject_reason = $data['reason'];
        $profile->verified_at = null;
        $profile->save();

        CustomerDocument::where('customer_profile_id', $profile->id)
            ->update(['status' => 'rejected', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);

        $this->notifyCustomer($profile, ($profile->customer?->locale ?? 'fa') === 'fa'
            ? '❌ مدارکِ احراز هویتِ شما در سرورنت تأیید نشد. دلیل: '
                .$data['reason'].' — لطفاً از پنل، بخشِ احراز هویت، مدارک را اصلاح و دوباره ارسال کنید.'
            : '❌ Your identity documents could not be verified at ServerNet. Reason: '
                .$data['reason'].' — please correct and re-submit them from your panel (Profile → Identity).');

        ActivityLog::record($profile->customer_id, 'verify', 'هویت رد شد: '.$data['reason'], $request, 'admin');

        return back()->with('ok', 'مدارک رد شد و دلیل به مشتری اطلاع داده شد.');
    }

    /** اعلان به مشتری از هر دو کانالِ پیام‌رسان + ایمیل. */
    private function notifyCustomer(CustomerProfile $profile, string $text): void
    {
        $customer = $profile->customer;
        if (! $customer) {
            return;
        }

        try {
            app(CustomerNotifier::class)->message($customer, $text);
        } catch (\Throwable) {
        }

        $email = $profile->email ?: $customer->email;
        if ($email) {
            try {
                Mail::mailer('smtp')->raw($text, fn ($m) => $m->to($email)->subject('وضعیتِ احراز هویت — سرورنت'));
            } catch (\Throwable) {
            }
        }
    }
}
