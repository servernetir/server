<?php
/** CLI-only synthetic rendering acceptance. Never creates customers, keys, or paid inquiries. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = rtrim($argv[1] ?? '', '/');
if (! is_file($root.'/vendor/autoload.php')) { throw new RuntimeException('Application root required'); }
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$customer = new App\Models\Customer(['name' => 'Workspace preview', 'email' => 'preview@example.com']);
$customer->id = 2147483001;
Illuminate\Support\Facades\Auth::guard('customer')->setUser($customer);
$writes = 0;
Illuminate\Support\Facades\DB::listen(static function ($query) use (&$writes) {
    if (! preg_match('/^\s*(select|pragma|show|describe|explain)\b/i', $query->sql)) { $writes++; }
});
$checks = [];
$translatedKeys = [];
foreach (['fa', 'en', 'tr'] as $locale) {
    app()->setLocale($locale);
    foreach (['identity', 'ai'] as $product) {
        $dictionary = require $root.'/lang/'.$locale.'/'.$product.'.php';
        $keys = array_values(array_filter(array_keys($dictionary), fn ($key) => str_starts_with($key, 'workspace_')));
        sort($keys);
        if (isset($translatedKeys[$product]) && $translatedKeys[$product] !== $keys) {
            throw new RuntimeException('Workspace translation key mismatch');
        }
        $translatedKeys[$product] = $keys;
    }
    $shell = ['pnlUser' => ['name' => 'Workspace preview', 'code' => 'PREVIEW'], 'pnlNav' => [], 'pnlActive' => 'identity'];
    $application = new App\Models\IdentityApplication(['name' => 'Preview application', 'status' => 'active']);
    $application->id = 2147483002;
    $card = ['slug' => 'shahkar', 'category' => 'authentication', 'name' => __('identity.service_shahkar'),
        'status' => 'available', 'active' => true, 'price' => 1000];
    $selected = ['slug' => 'shahkar', 'operations' => [['input' => [
        'properties' => ['mobile' => ['type' => 'string', 'maxLength' => 11], 'national_code' => ['type' => 'string', 'maxLength' => 10]],
        'required' => ['mobile', 'national_code'],
    ]]]];
    $identity = view('account.identity', $shell + ['ready' => true, 'eligible' => true, 'services' => [$card],
        'selected' => $selected, 'checkout' => ['price_version' => 1, 'subtotal_irt' => 1000, 'tax_irt' => 100, 'total_irt' => 1100],
        'applications' => collect([$application]), 'history' => new Illuminate\Pagination\LengthAwarePaginator([], 0, 20),
        'balance' => 100000, 'reserved' => 10000, 'keys' => collect(),
    ])->render();
    if (! str_contains($identity, 'api-workspace--identity') || ! str_contains($identity, 'identity-checkout')
        || ! str_contains($identity, 'name="max_charge_irt" value="1100"') || ! str_contains($identity, 'name="real_charge"')
        || preg_match('/\bidentity\.workspace_/', strip_tags($identity))) {
        throw new RuntimeException('Identity render acceptance failed: '.$locale);
    }
    $project = new App\Models\AiProject(['name' => 'Preview project', 'status' => 'active', 'budget_period' => 'none']);
    $project->id = 2147483003;
    $project->active_tokens_count = 0;
    $ai = view('account.ai', array_replace($shell, ['pnlActive' => 'ai']) + ['spent' => 0,
        'history' => new Illuminate\Pagination\LengthAwarePaginator([], 0, 20), 'projects' => collect([$project]),
    ])->render();
    if (! str_contains($ai, 'api-workspace--ai') || ! str_contains($ai, 'data-ai-billing-receipts')
        || preg_match('/\bai\.workspace_/', strip_tags($ai))) {
        throw new RuntimeException('AI render acceptance failed: '.$locale);
    }
    $checks[] = ['locale' => $locale, 'identity' => true, 'ai' => true];
}
if ($writes !== 0) { throw new RuntimeException('Unexpected SQL write during synthetic rendering'); }
echo json_encode(['synthetic_live_renders' => $checks, 'translation_keys' => array_map('count', $translatedKeys),
    'db_write_queries' => $writes, 'customer_records_created' => 0, 'financial_or_provider_commands_run' => false], JSON_UNESCAPED_SLASHES).PHP_EOL;
