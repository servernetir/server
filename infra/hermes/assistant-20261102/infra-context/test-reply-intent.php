<?php

/** Focused reply-routing regression table. No framework bootstrap, model or network. */
declare(strict_types=1);

namespace App\Services\Hermes {
    final class HermesBusinessReport {
        public static function text(array $data): string { return 'synthetic-business-report'; }
    }
}

namespace {
    use App\Services\Hermes\HermesConversationMemory as Memory;

    require_once __DIR__.'/HermesConversationMemory.php';
    $state = ['messages' => [], 'memories' => [['text' => 'گزارش کوتاه باشد.']]];
    $runtime = ['available' => true, 'support_enabled' => true, 'support_last_run_at' => time(),
        'knowledge' => ['reference_document_count' => 174,
            'internal_observation' => ['observed_at' => time()], 'internal_stale' => false,
            'repository_source' => ['status' => 'ok', 'stale' => false, 'last_success_at' => time()],
            'sources' => ['php' => ['status' => 'ok', 'stale' => false, 'last_success_at' => time()]],
            'recent_articles' => [['title' => 'Synthetic PHP release', 'published_at' => time(), 'url' => 'https://www.php.net/releases/']]]];
    $initialState = $state; $initialRuntime = $runtime;
    $checks = 0;
    $check = static function (bool $condition, string $label) use (&$checks): void {
        $checks++; if (! $condition) { throw new \RuntimeException('FAILED: '.$label); }
    };
    $modelCases = [
        'چرا زیرساخت آروان باز فروش پنل هاست بسته هست چیشده؟',
        'چه زیرساخت هایی فروششون بسته است؟',
        'آراز چخبر ؟', 'آراز چخبر؟', 'چخبر', 'چه خبر', 'چه خبر؟', 'آراز چه خبر؟', 'آراز، چه خبر؟',
        'سلام آراز چه خبر؟', 'آراز سلام', 'سلام', 'چه خبر جدیدی داری؟', 'چخبر از کارهامون؟',
        'خبر داری؟', 'خبر خوب بگو', 'آخرین خبر چیه؟', 'آراز امروز چه خبر؟',
        'دانشت بروزه؟ حالا رله رو بررسی کن', 'دانشت بروزه و رله را بررسی کن',
        'آخرین خبرهای هاستینگ را بگو و رله را بررسی کن',
        'آخرین اخبار هاستینگ؛ بعد صفحه سایت را اصلاح کن',
        'از دانش به‌روزت برای اصلاح صفحه استفاده کن', 'وضعیت دانش برنامه‌نویسی این مشتری را بررسی کن',
        'خبرهای سرورم رو بررسی کن', 'آخرین خبرهای Kianpet را بگو',
        "what's new?", 'Araz what is new?', 'Any news?', 'latest news',
        'Is your knowledge up to date? Check the relay too.',
        'Show me the latest hosting news and repair the service',
        'Use your latest knowledge to fix this bug', 'Explain this PHP release news',
        'I am learning how to update the application',
    ];
    foreach ($modelCases as $query) {
        $check(Memory::reply($query, $state, $runtime, []) === null, 'reaches model: '.$query);
    }
    $reportCases = [
        'دانشت بروزه؟', 'دانشت به‌روزه؟', 'دانشت آپدیته؟', 'دانش تو به روز است؟',
        'دانشت چطور به روز میشه؟', 'دانش چگونه خودکار به‌روز می‌شود؟',
        'وضعیت دانشت', 'گزارش دانش را بده', 'آراز وضعیت دانشت را بگو',
        'آخرین خبرهای هاستینگ را بگو', 'آخرین خبرهای هاستینگ...',
        'آخرین اخبار میزبانی', 'اخبار رسمی php', 'خبرهای هاستینگ و سرورها رو بگو', 'اخبار سرور',
        'hosting news', 'latest hosting news', 'Show me the latest hosting news',
        'Is your knowledge up to date?', 'your knowledge status', 'knowledge updates',
    ];
    foreach ($reportCases as $query) {
        $reply = Memory::reply($query, $state, $runtime, []);
        $check(is_string($reply) && str_contains($reply, 'Synthetic PHP release'), 'explicit source report: '.$query);
    }
    $check(str_contains((string) Memory::reply('حافظه چیست؟', $state, $runtime, []), '۱۷۴')
        || str_contains((string) Memory::reply('حافظه چیست؟', $state, $runtime, []), '174'), 'existing memory response retained');
    $check(str_contains((string) Memory::reply('یاد بگیر در حافظه', $state, $runtime, ['یادداشت مصنوعی']), 'یادداشت مصنوعی'), 'existing confirmed save response retained');
    $check(Memory::reply('گزارش مدیریتی', $state, $runtime, []) === 'synthetic-business-report', 'existing business routing retained');
    $check(str_contains((string) Memory::reply('وضعیت تیکت', $state, $runtime, []), 'بررسی خودکار تیکت'), 'existing ticket status routing retained');
    $check($state === $initialState && $runtime === $initialRuntime, 'routing does not mutate stored state or receipts');
    echo json_encode(['ok' => true, 'checks' => $checks, 'model_routed_cases' => count($modelCases),
        'explicit_report_cases' => count($reportCases), 'network_calls' => 0], JSON_THROW_ON_ERROR).PHP_EOL;
}
