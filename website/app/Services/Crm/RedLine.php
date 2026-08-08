<?php

namespace App\Services\Crm;

use Illuminate\Support\Facades\Log;

/**
 * آخرین سد پیش از ارسال.
 *
 * مدل هرچقدر هم خوب راهنمایی شود، گاهی عدد از خودش درمی‌آورد یا «تضمین» و
 * «فرصت محدود» می‌نویسد. این کلاس **بعد از** تولید اجرا می‌شود و متنِ آلوده را
 * رد می‌کند. مدل نمی‌تواند دورش بزند چون خودش تصمیم‌گیرنده نیست.
 *
 * 🔴 چرا اینقدر سخت‌گیر: احسان KPI اندازه‌گیری‌شده ندارد. هر درصدی که در ایمیل
 * برود، ساختگی است. خریدارِ صنعتی می‌پرسد «چطور اندازه گرفتید؟» و جوابِ نداشتن
 * فقط آن عدد را نمی‌کُشد — همهٔ حرف‌های راستِ دیگر را هم مشکوک می‌کند.
 */
class RedLine
{
    /** الگوهای ممنوع از config/crm.php */
    protected function patterns(): array
    {
        return (array) config('crm.redlines', []);
    }

    /**
     * آیا متن پاک است؟ اگر نه، فهرستِ تخلف‌ها برمی‌گردد.
     *
     * @return array{clean: bool, hits: array<int, string>}
     */
    public function inspect(string $text): array
    {
        $hits = [];

        foreach ($this->patterns() as $re) {
            if (@preg_match($re, $text, $m)) {
                $hits[] = trim((string) ($m[0] ?? $re));
            }
        }

        return ['clean' => $hits === [], 'hits' => $hits];
    }

    public function clean(string $text): bool
    {
        return $this->inspect($text)['clean'];
    }

    /**
     * دروازهٔ ارسال. اگر متن آلوده باشد `false` برمی‌گرداند و دلیل را لاگ می‌کند
     * تا در پنل دیده شود — بی‌صدا رد کردن یعنی هیچ‌وقت نمی‌فهمی چرا هیچ ایمیلی
     * از صف بیرون نمی‌آید.
     */
    public function allow(string $text, array $context = []): bool
    {
        $r = $this->inspect($text);

        if (! $r['clean']) {
            Log::warning('crm.redline.blocked', $context + ['hits' => $r['hits']]);
        }

        return $r['clean'];
    }
}
