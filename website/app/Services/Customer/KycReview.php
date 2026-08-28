<?php

namespace App\Services\Customer;

use App\Models\ActivityLog;
use App\Models\CustomerDocument;
use App\Models\CustomerProfile;
use App\Services\Notify\CustomerNotifier;
use Illuminate\Support\Facades\Mail;

/**
 * تأیید/ردِ احراز هویت — منطقِ مشترکِ پنلِ مدیریت و رباتِ بله.
 *
 * ═══ چرا سرویسِ جدا (۶ شهریور ۱۴۰۵) ═══
 *
 * کارفرما: «از داخلِ بله باید مدارک را ببینم و همان‌جا تأیید/رد کنم.» تا امروز
 * منطق داخلِ `Admin\VerificationController` بود؛ کپی‌کردنش در ربات یعنی دو
 * تعریف از «تأیید» که روزی واگرا می‌شوند — و یک طرفِ واگرایی، دروازهٔ فروشِ
 * ایران است که همین پرچمِ verified را می‌خوانَد. همان الگوی BankReceiptReviewer.
 *
 * ═══ قواعد ═══
 * - تأیید = پروفایل verified + همهٔ مدارک approved + اعلان به مشتری به زبانِ
 *   خودش. IranSalesGate خودکار برای همین مشتری باز می‌شود (هیچ کارِ اضافه).
 * - رد **حتماً دلیل می‌خواهد** — مشتری همان متن را می‌بیند.
 * - اعلان‌ها best-effort اند و نتیجهٔ بررسی را نمی‌شکنند.
 * - هرگز throw نمی‌کند؛ نتیجه در آرایه است (فراخوانِ بله داخلِ کرون است).
 */
class KycReview
{
    /** @return array{ok:bool,message:string} */
    public function approve(CustomerProfile $profile, ?int $byUserId): array
    {
        if ($profile->status === 'verified') {
            return ['ok' => false, 'message' => 'این پروفایل از قبل تأیید شده.'];
        }

        try {
            $profile->status = 'verified';
            $profile->verified_at = now();
            $profile->reject_reason = null;
            $profile->save();

            CustomerDocument::where('customer_profile_id', $profile->id)
                ->update(['status' => 'approved', 'reviewed_by' => $byUserId, 'reviewed_at' => now()]);
        } catch (\Throwable $e) {
            \App\Support\ErrorTracker::note('kyc', $e, ['profile' => $profile->id]);

            return ['ok' => false, 'message' => 'ثبتِ تأیید شکست خورد؛ در /admin/errors ثبت شد.'];
        }

        $who = $profile->company_name ?: ($profile->customer?->displayName() ?? '');

        // متن به زبانِ خودِ مشتری — مشتریِ خارجی پیامِ فارسی را نمی‌فهمد
        $this->notifyCustomer($profile, ($profile->customer?->locale ?? 'fa') === 'fa'
            ? '✅ هویتِ شما در سرورنت تأیید شد'
                .($profile->company_name ? ' («'.$profile->company_name.'»)' : '').'. حالا می‌توانید از همهٔ خدمات استفاده کنید.'
            : '✅ Your identity has been verified at ServerNet. All services — including Iran-hosted plans — are now available to your account.');

        try {
            ActivityLog::record($profile->customer_id, 'verify', 'هویت تأیید شد: '.$who, null, 'admin');
        } catch (\Throwable) {
        }

        return ['ok' => true, 'message' => 'هویتِ «'.($who ?: '#'.$profile->id).'» تأیید و به مشتری اطلاع داده شد.'];
    }

    /** @return array{ok:bool,message:string} */
    public function reject(CustomerProfile $profile, string $reason, ?int $byUserId): array
    {
        $reason = trim(mb_substr($reason, 0, 400));

        if ($reason === '') {
            return ['ok' => false, 'message' => 'ردِ بی‌دلیل ممکن نیست — مشتری باید بداند چه چیزی را اصلاح کند.'];
        }

        try {
            $profile->status = 'rejected';
            $profile->reject_reason = $reason;
            $profile->verified_at = null;
            $profile->save();

            CustomerDocument::where('customer_profile_id', $profile->id)
                ->update(['status' => 'rejected', 'reviewed_by' => $byUserId, 'reviewed_at' => now()]);
        } catch (\Throwable $e) {
            \App\Support\ErrorTracker::note('kyc', $e, ['profile' => $profile->id]);

            return ['ok' => false, 'message' => 'ثبتِ رد شکست خورد؛ در /admin/errors ثبت شد.'];
        }

        $this->notifyCustomer($profile, ($profile->customer?->locale ?? 'fa') === 'fa'
            ? '❌ مدارکِ احراز هویتِ شما در سرورنت تأیید نشد. دلیل: '
                .$reason.' — لطفاً از پنل، بخشِ احراز هویت، مدارک را اصلاح و دوباره ارسال کنید.'
            : '❌ Your identity documents could not be verified at ServerNet. Reason: '
                .$reason.' — please correct and re-submit them from your panel (Profile → Identity).');

        try {
            ActivityLog::record($profile->customer_id, 'verify', 'هویت رد شد: '.$reason, null, 'admin');
        } catch (\Throwable) {
        }

        return ['ok' => true, 'message' => 'مدارک رد شد و دلیل به مشتری اطلاع داده شد.'];
    }

    /** اعلان به مشتری — پیام‌رسان + ایمیل، با موضوعِ هم‌زبان. */
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
                $subject = ($customer->locale ?? 'fa') === 'fa'
                    ? 'وضعیتِ احراز هویت — سرورنت'
                    : 'Identity verification — ServerNet';
                Mail::mailer('smtp')->raw($text, fn ($m) => $m->to($email)->subject($subject));
            } catch (\Throwable) {
            }
        }
    }
}
