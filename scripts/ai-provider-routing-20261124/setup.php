<?php
/** Run only after the pinned release is installed. No secrets, customer balances or inference calls. */
if (PHP_SAPI !== 'cli' || realpath(getcwd()) !== '/home/servernetcloud/servernet_app') {
    throw new RuntimeException('Run from the production application directory.');
}
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\AiProvider;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
$backup = $argv[1] ?? '';
if (!preg_match('~\A/home/servernetcloud/deploy-backups/ai-provider-routing-20261124-[a-f0-9]{12}/configuration-before\.json\z~', $backup)
    || file_exists($backup)) throw new RuntimeException('A new private configuration backup is required.');
$before = [
    'providers' => AiProvider::whereIn('slug', ['openrouter', 'gapgpt'])->get()->toArray(),
    'settings' => collect(['ai_price_routing_enabled','ai_uniform_margin'])
        ->mapWithKeys(fn ($key) => [$key => Setting::get($key)])->all(),
];
file_put_contents($backup, json_encode($before, JSON_THROW_ON_ERROR), LOCK_EX); chmod($backup, 0600);
DB::transaction(function () {
    $p = AiProvider::firstOrNew(['slug' => 'openrouter']);
    if (!$p->exists) {
        $reference = AiProvider::where('slug', 'deepinfra')->firstOrFail();
        $p->fill(['name' => 'OpenRouter', 'driver' => 'OpenAI-Compatible', 'enabled' => true,
            'commercial_enabled' => true, 'resale_allowed' => true, 'live_calls_enabled' => true,
            'agreement_status' => AiProvider::STATUS_OWNER_ASSUMED, 'priority' => 100,
            'billing_currency_code' => 'USD', 'fx_fee_bp' => 3390,
            'daily_cost_cap_micro' => $reference->daily_cost_cap_micro,
            'notes' => 'Owner requested resale and economic provider selection on 2026-11-24. Funding overhead configured from actual credit purchase total. No signed agreement supplied.']);
        $p->save();
    }
    AiProvider::where('slug', 'gapgpt')->update(['enabled' => false, 'commercial_enabled' => false, 'live_calls_enabled' => false]);
    Setting::put('ai_price_routing_enabled', '1');
    Setting::put('ai_uniform_margin', '1');
});
echo json_encode(['provider' => 'openrouter', 'configured' => filled(AiProvider::bySlug('openrouter')->apiKey()),
    'overhead_bp' => AiProvider::bySlug('openrouter')->fx_fee_bp, 'margin_pct' => Setting::get('ai_margin_pct'),
    'gapgpt_disabled' => !AiProvider::bySlug('gapgpt')->enabled], JSON_THROW_ON_ERROR), PHP_EOL;
