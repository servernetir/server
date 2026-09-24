<?php

namespace App\Services\Ai;

use App\Models\CustomerApiToken;
use App\Models\AiProject;

/**
 * سیمِ admissionِ دروازهٔ AI — تنها دروازهٔ «آیا این کلید اجازه دارد؟».
 *
 * مالدار-بی‌طرف است: تنظیم‌شده روی `CustomerApiToken` (نه درایورِ برند) و
 * هیچ چیز در این کلاس خریدار، منابع، یا درایورِ ارائه‌دهنده دیده نمی‌شود.
 * M3 تسویه و M4 ترابرد `/v1` روی همین سیم می‌نشینند — کلیدِ AI از /v1
 * درمی‌آید (`CustomerApiToken::findByPlain`)، به این‌جا می‌آید و
 * پاسخ می‌گیرد:
 *
 *   token usable (انقضا/ابطال — واگذارِ منطقِ موجودِ خودِ توکن)
 *   customer active (واگذارِ `Customer::isActive`)
 *   ability صریحِ AI (تیکِ صدور؛ wildcards ناکارآمد = `can()` مرزِ AI)
 *   پروژهٔ bound باید **فعال** باشد و بازهٔ بودجه‌اش تازه
 *
 * 🔴 چه کاری در این کلاس **هیچ‌وقت** نوشته نمی‌شود: جابه‌جاییِ پول (کیفِ
 *    پول/رزرو/ledger)، فراخوانِ ارائه‌دهنده (live calls)، یا مسیرِ عمومی
 *    `/v1`. هر یکی‌اش که لازم شد، در متُدِ خودِ M3/M4.
 *
 * 🔴 CIDR به منطقِ موجودِ توکن واگذار است: `CustomerApiToken::allowsIp()`
 *    همین‌جا خوانده می‌شود و نتیجه «ip_not_allowed» می‌آید — منطقی تکرار
 *    نمی‌شود، فقط ادغام.
 */
final class AiAdmission
{
    /** یک مجوز — یا حکمِ رد با کدِ قابلِ انتقال به API (M4 مستقیم همین را چاپ می‌کند) */
    public static function authorize(
        CustomerApiToken $token,
        string $ability,
        ?string $ip = null,
        ?\DateTimeInterface $at = null,
    ): AiAuthContext {
        $at = $at ?? now();

        if (! str_starts_with($ability, CustomerApiToken::AI_PREFIX)) {
            return AiAuthContext::denied('not_ai_scope', 'سطحِ درخواست‌شده دامنهٔ AI نیست.');
        }

        /*
        | unusableReason / allowsIp / can — همان منطقِ امنیتیِ سالمی که
        | نمایندگیِ دامنه هنوز رویش سوار است؛ این‌جا فقط خوانده می‌شود،
        | نه بازنویسی. یک گذرِ حیث با مهاجرتِ M2 دوبل می‌شد.
        */
        $reason = $token->unusableReason();

        if ($reason !== null) {
            return AiAuthContext::denied($reason, 'کلید قابلِ استفاده نیست.');
        }

        if (! $token->allowsIp($ip)) {
            return AiAuthContext::denied('ip_not_allowed', 'این کلید فقط از IPهای مجازِ خودش کار می‌کند.');
        }

        /*
        | 🔴 کلیدِ AI که پروژه‌اش حذف شده: علت را درست بگو.
        |
        | `ai_project_id` با `nullOnDelete` نال می‌شود (مهاجرتِ 001100)، و
        | `can()` بی‌پروژه false می‌دهد — پس پاسخ «دامنهٔ ai:chat را ندارد»
        | می‌شد، در حالی که کلید دقیقاً همان دامنه را دارد و مشکل جای دیگری
        | است. دسترسی در هر دو حالت رد می‌شود؛ چیزی که فرق می‌کند این است که
        | مشتری بداند باید پروژه بسازد، نه اینکه دنبالِ دامنهٔ کلید بگردد.
        */
        if ($token->isAiKey() && $token->ai_project_id === null) {
            return AiAuthContext::denied('project_missing', 'این کلید به هیچ پروژهٔ AI وصل نیست.');
        }

        if (! $token->can($ability)) {
            return AiAuthContext::denied($token->isAiKey() ? 'insufficient_scope' : 'not_ai_key',
                'کلید دامنهٔ «'.$ability.'» ندارد');
        }

        $customer = $token->customer;

        if ($customer === null || ! $customer->isActive()) {
            return AiAuthContext::denied('account_inactive', 'حسابِ مالکِ کلید فعال نیست.');
        }

        $project = $token->ai_project_id === null
            ? null
            : $token->aiProject()->first();

        if ($project === null) {
            return AiAuthContext::denied('project_missing', 'کلید AI به پروژه‌ای سالم گره نخورده است.');
        }

        if ($project->status !== AiProject::STATUS_ACTIVE) {
            return AiAuthContext::denied('project_inactive', 'پروژهٔ مالکِ این کلید غیرفعال است.');
        }

        if (! $project->budgetWindowCovers($at)) {
            return AiAuthContext::denied('budget_window_stale',
                'بازهٔ بودجهٔ پروژهٔ مسیرِ درخواست تازه‌سازی نشده است.');
        }

        /*
        | شمارشِ مصرف — همان الگوی اتمیکِ میدل‌ورِ `CustomerApiToken`: یک
        | `increment` تکی روی کوئریِ تازه، نه read-modify-write. فقط همین‌جا،
        | فقط در مسیرِ سبز: هر ردی که بالا‌تر برگردد، پیش از رسیدن به این‌جا
        | return شده و هیچ شماره‌ای نمی‌خورد.
        */
        $token->newQuery()->whereKey($token->getKey())->increment('use_count');

        return new AiAuthContext(
            ok: true,
            code: 'authorized',
            customer: $customer,
            project: $project,
            token: $token,
        );
    }
}

/**
 * حکمِ admission — به بولینِ کم نمی‌افزوده: `code` علتِ رد را برای M4 حمل
 * می‌کند و `project` ظرفِ بودجهٔ سیمِ تسویه است. هیچ پولی در این‌جا نیست.
 */
final class AiAuthContext
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $code,
        public readonly ?\App\Models\Customer $customer = null,
        public readonly ?AiProject $project = null,
        public readonly ?CustomerApiToken $token = null,
        public readonly string $message = '',
    ) {}

    public static function denied(string $code, string $message): self
    {
        return new self(ok: false, code: $code, message: $message);
    }
}
