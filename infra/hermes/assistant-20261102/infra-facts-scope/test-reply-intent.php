<?php

/** Focused reply-routing regression table. No framework bootstrap, model or network. */
declare(strict_types=1);

namespace App\Services\Hermes {
    final class HermesBusinessReport {
        public static function text(array $data): string { return 'synthetic-business-report'; }
    }
}

namespace {
    use App\Services\Cloud\CloudManager;
    use App\Services\Hermes\HermesInfrastructureContext;
    use App\Services\Hermes\HermesConversationMemory as Memory;

    $repository = dirname(__DIR__, 4);
    require_once $repository.'/website/app/Services/Cloud/CloudManager.php';
    require_once $repository.'/website/app/Services/Hermes/HermesInfrastructureContext.php';
    require_once __DIR__.'/HermesProviderReply.php';
    require_once __DIR__.'/HermesConversationMemory.php';
    $state = ['messages' => [], 'memories' => [['text' => 'گزارش کوتاه باشد.']]];
    $runtime = ['available' => true, 'support_enabled' => true, 'support_last_run_at' => time(),
        'knowledge' => ['reference_document_count' => 174,
            'internal_observation' => ['observed_at' => time()], 'internal_stale' => false,
            'repository_source' => ['status' => 'ok', 'stale' => false, 'last_success_at' => time()],
            'sources' => ['php' => ['status' => 'ok', 'stale' => false, 'last_success_at' => time()]],
            'recent_articles' => [['title' => 'Synthetic PHP release', 'published_at' => time(), 'url' => 'https://www.php.net/releases/']]]];
    $records = [];
    foreach (CloudManager::REAL_NAMES as $id => $label) {
        $records[$id] = ['sales' => ['ok' => true, 'reason' => 'available', 'offers' => 1], 'disabled' => false];
    }
    $records['arvan'] = ['sales' => ['ok' => true, 'reason' => 'available', 'offers' => 268], 'disabled' => false,
        'configured' => true, 'hourly_enabled' => true,
        'catalog' => ['plans' => 804, 'active' => 332, 'in_stock' => 600, 'admin_disabled' => 473,
            'synced_at' => time() - 60, 'regions' => ['ir-test']],
        'quarantine_records' => array_fill(0, 32, ['admin_disabled' => true, 'note' => 'توقف دستی ساختگی', 'updated_at' => time() - 3600]),
        'inventory' => ['tracked' => 30, 'by_status' => ['active' => 20, 'stopped' => 10], 'synced_at' => time() - 60],
        'scan' => ['at' => time() - 120, 'complete' => true], 'capabilities' => ['metrics' => false]];
    $records['vpstc'] = ['sales' => ['ok' => false, 'reason' => 'admin_disabled', 'offers' => 0], 'disabled' => true];
    $runtime['provider_context'] = HermesInfrastructureContext::project($records, 'آروان', time(), CloudManager::REAL_NAMES);
    $runtime['unrelated_infrastructure'] = ['label' => 'PRIVATE_VM_128_VPN_AI_MARKER'];
    $initialState = $state; $initialRuntime = $runtime;
    $checks = 0;
    $check = static function (bool $condition, string $label) use (&$checks): void {
        $checks++; if (! $condition) { throw new \RuntimeException('FAILED: '.$label); }
    };
    $modelCases = [
        'آروان را بررسی کن و فروشش را باز کن', 'چرا فروش آروان بسته است و رله رو هم بررسی کن',
        'خبرهای آروان را بگو', 'در مورد معماری آروان توضیح بده', 'یک پلن از آروان بخر',
        'فروش آروان بسته است، لطفا درستش کن',
        'What is Arvan sales status? Restart nginx.',
        'وضعیت فروش آروان چیه و وضعیت تیکت‌ها چطوره؟',
        'وضعیت فروش آروان چیه؟ سرورها را ریستارت بده',
        'چه زیرساخت هایی میفروشیم و رله چطوره؟',
        'چرا فروش ساعتی آروان بسته است؟', 'چرا فروش ماهانه آروان بسته است؟',
        'وضعیت فروش آروان برای یک ماه چیه؟', 'What is Arvan hourly sales status?',
        'Is Arvan monthly sales available?', 'تعداد پلن های ساعتی آروان چنده؟',
        'فروش آروان بسته است لطفا علت را در تیکت بنویس',
        'وضعیت فروش آروان چیه و nginx را راه اندازی مجدد بده',
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
    $factQueries = ['چرا زیرساخت آروان باز فروش پنل هاست بسته هست چیشده؟',
        'نه در مورد سرورنت اطلاعاتی نداری چه زیرساخت هایی میفروشیم؟', 'چه زیرساخت هایی فروششون بسته است؟'];
    foreach ($factQueries as $query) {
        $reply = Memory::reply($query, $state, $runtime, []);
        $check(is_string($reply) && trim($reply) !== '', 'explicit provider facts use recorded application reply: '.$query);
        $check(! str_contains((string) $reply, 'PRIVATE_VM_128_VPN_AI_MARKER'), 'private infrastructure is not invented as a commercial product');
    }
    $arvanReply = (string) Memory::reply($factQueries[0], $state, $runtime, []);
    $normalizeNumbers = static fn (string $text): string => strtr($text,
        ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
    $normalized = $normalizeNumbers($arvanReply);
    $check(str_contains($normalized, '268') && str_contains($normalized, '804') && str_contains($normalized, '473'),
        'Arvan reply distinguishes sellable offers, catalogue and blocked rows');
    $check(! preg_match('/332\s*(?:عرضه|پلن\s*قابل\s*فروش|sellable|offers)/iu', $normalized), 'active rows are not substituted for sellable offers');
    $allReply = (string) Memory::reply($factQueries[1], $state, $runtime, []);
    foreach (array_keys(CloudManager::REAL_NAMES) as $id) {
        $check(str_contains(mb_strtolower($allReply), $id === 'hetzner-robot' ? 'robot' : ($id === 'vpstc' ? 'vps.tc' : ($id === 'arvan' ? 'آروان' : $id))),
            'commercial list retains registered provider: '.$id);
    }
    $missing = $runtime;
    unset($missing['provider_context']);
    $unknown = Memory::reply($factQueries[0], $state, $missing, []);
    $check(is_string($unknown) && trim($unknown) !== '' && ! str_contains($normalizeNumbers($unknown), '268'),
        'matched question without provider evidence returns unknown rather than invented counts');
    $invalid = $runtime; $invalid['provider_context'] = 'invalid synthetic context';
    $check(Memory::reply($factQueries[0], $state, $invalid, []) === $unknown, 'nonarray provider context uses safe missing-evidence route');
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
    echo json_encode(['ok' => true, 'checks' => $checks, 'model_routed_cases' => count($modelCases), 'fact_routed_cases' => count($factQueries),
        'explicit_report_cases' => count($reportCases), 'network_calls' => 0], JSON_THROW_ON_ERROR).PHP_EOL;
}
