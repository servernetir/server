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

        /*
        | متن به زبانِ خودِ مشتری (fa/en/tr) از ui.ntf_kyc_ok — یک منبع برای
        | پیام‌رسان و ایمیل. ⚠️ نسخهٔ قبلی در متنِ انگلیسی «Iran-hosted plans»
        | داشت — قاعدهٔ کارفرما: ایمیلِ مشتریِ خارجی هیچ اشاره‌ای به ایران ندارد.
        */
        $this->notifyCustomer($profile, 'kyc_ok', []);

        try {
            ActivityLog::record($profile->customer_id, 'verify',
                __('ui.act_kyc_ok', ['who' => $who], $profile->customer?->locale ?: 'fa'), null, 'admin');
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

        $this->notifyCustomer($profile, 'kyc_no', ['reason' => $reason]);

        try {
            ActivityLog::record($profile->customer_id, 'verify',
                __('ui.act_kyc_no', ['reason' => $reason], $profile->customer?->locale ?: 'fa'), null, 'admin');
        } catch (\Throwable) {
        }

        return ['ok' => true, 'message' => 'مدارک رد شد و دلیل به مشتری اطلاع داده شد.'];
    }

    /**
     * اعلان به مشتری — پیام‌رسان + ایمیلِ برنددار، هر دو به زبانِ خودِ مشتری.
     *
     * متن از `ui.ntf_{key}_s/_b` می‌آید (fa/en/tr)؛ ایمیل با `TemplateMail`
     * می‌رود تا مثلِ بقیهٔ ایمیل‌ها لوگو/قالب داشته باشد — نه `Mail::raw`ِ لخت.
     */
    private function notifyCustomer(CustomerProfile $profile, string $key, array $vars): void
    {
        $customer = $profile->customer;

        if (! $customer) {
            return;
        }

        $locale = in_array($customer->locale, ['en', 'tr'], true) ? $customer->locale : 'fa';
        $repl = $vars + ['url' => 'https://console.servernet.cloud'];
        $subject = trans('ui.ntf_'.$key.'_s', $repl, $locale);
        $text = trans('ui.ntf_'.$key.'_b', $repl, $locale);

        /*
        | 🔴 هر دو مسیرِ زیر ممکن است بشکنند و هیچ‌کدام نباید بازبینی را
        | بشکند (تصمیمِ مدیر از قبل ثبت شده). ولی «نشکستن» یعنی ادامه دادن،
        | نه فراموش‌کردن: مشتری‌ای که نتیجهٔ احراز هویتش را نگیرد، تا ابد منتظر
        | می‌مانَد و بعد تیکت می‌زند — و آن‌وقت کسی نمی‌داند چرا خبر نرفته.
        */
        try {
            app(CustomerNotifier::class)->message($customer, $text);
        } catch (\Throwable $e) {
            \App\Support\ErrorTracker::noteOnce('kyc',
                'نتیجهٔ احراز هویت به مشتریِ #'.$customer->id.' اعلام نشد (پیام‌رسان): '.$e->getMessage(), 900);
        }

        $email = $profile->email ?: $customer->email;

        if ($email) {
            try {
                Mail::mailer('smtp')->to($email)
                    ->send(new \App\Mail\TemplateMail($subject, nl2br(e($text)), $locale));
            } catch (\Throwable $e) {
                \App\Support\ErrorTracker::noteOnce('kyc',
                    'ایمیلِ نتیجهٔ احراز هویت به مشتریِ #'.$customer->id.' نرفت: '.$e->getMessage(), 900);
            }
        }
    }
}
