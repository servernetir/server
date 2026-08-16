<?php

namespace App\Support;

/**
 * دادهٔ رابطِ گزارشِ بررسیِ سایت (`window.SEO_META`).
 *
 * 🔴 چرا از Blade بیرون آمد: حالا **دو** صفحه همان گزارش را رندر می‌کنند —
 * ابزارِ `/tools/seo` و صفحهٔ عمومیِ `/report/{token}` که برای صاحبِ سایت
 * فرستاده می‌شود. اگر هرکدام نسخهٔ خودش را بسازد، روزی برچسبِ یک چک در یکی
 * عوض می‌شود و در دیگری نه، و گزارشی که برای مشتری فرستاده‌ایم چیزی متفاوت با
 * چیزی می‌گوید که خودمان روی سایت می‌بینیم. همان تلهٔ «دورهٔ شش‌ماهه در ۷ جا».
 */
class AuditViewData
{
    public static function meta(): array
    {
        $cats = config('tools.categories');
        $audience = config('tools.audience', []);

        return [
            'cats'   => collect($cats)->map(fn ($c, $k) => [
                'icon' => $c['icon'],
                't'    => lc($c),
                'who'  => isset($audience[$k]) ? lc($audience[$k]) : null,
            ])->all(),
            'checks' => collect(config('tools.checks'))->map(fn ($m) => lc($m))->all(),
            'fixes'  => self::fixes(),
            'fa'     => app()->getLocale() === 'fa',
            // مرحله‌های تخمینیِ نوارِ پیشرفت — بررسیِ هفت‌بُعدی چند ثانیه طول می‌کشد
            'stages' => [
                __('ui.au_s1'), __('ui.au_s2'), __('ui.au_s3'), __('ui.au_s4'), __('ui.au_s5'),
            ],
            'i18n'   => [
                'pass' => __('ui.tl_pass'), 'warn' => __('ui.tl_warn'), 'fail' => __('ui.tl_fail'),
                'weight' => __('ui.tl_weight'), 'errUnreachable' => __('ui.tl_err_unreach'),
                'errInvalid' => __('ui.tl_err_invalid'), 'errGeneric' => __('ui.chat_error'),
                'passes' => __('ui.tl_passes'), 'warns' => __('ui.tl_warns'), 'fails' => __('ui.tl_fails'),
                'ip' => __('ui.tl_f_ip'), 'size' => __('ui.tl_f_size'), 'load' => __('ui.tl_f_load'),
                'server' => __('ui.tl_f_server'), 'code' => __('ui.tl_f_code'), 'vitals' => __('ui.tl_vitals'),
                'planTitle' => __('ui.au_plan_t'), 'planLead' => __('ui.au_plan_d'),
                'planNone' => __('ui.au_plan_none'), 'howFix' => __('ui.au_howfix'),
                'copy' => __('ui.au_copy'), 'copied' => __('ui.au_copied'),
                'fAll' => __('ui.au_f_all'), 'fFail' => __('ui.au_f_fail'), 'fWarn' => __('ui.au_f_warn'),
                'impact' => __('ui.au_impact'), 'print' => __('ui.au_print'),
                'who' => __('ui.au_who'), 'jump' => __('ui.au_jump'),
                'shareCopied' => __('ui.au_share_copied'),
            ],
        ];
    }

    /**
     * راهکارها **با صفحه** می‌آیند، نه در پاسخِ API.
     *
     * متنِ ثابتی است که به نتیجهٔ بررسی ربط ندارد؛ فرستادنش در هر پاسخِ audit
     * یعنی چند ده کیلوبایت تکراری روی هر درخواست.
     */
    private static function fixes(): array
    {
        $file = resource_path('content/audit-fixes.php');
        $all = is_file($file) ? (array) require $file : [];
        $loc = app()->getLocale();

        $out = [];
        foreach ($all as $key => $byLocale) {
            $entry = $byLocale[$loc] ?? $byLocale['fa'] ?? null;
            if ($entry && ! empty($entry['fix'])) {
                $out[$key] = array_filter([
                    'fix'  => $entry['fix'],
                    'code' => $entry['code'] ?? null,
                ], fn ($v) => $v !== null && $v !== '');
            }
        }

        return $out;
    }
}
