<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MarketingRedemption;
use App\Models\User;
use App\Services\Marketing\CampaignManager;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MarketingCampaignConcurrencyTest extends TestCase
{
    public function test_real_parallel_workers_cannot_reuse_a_private_code_or_oversubscribe_capacity_or_budget(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'snet-campaign-race-');
        $barrier = $file.'.start';
        $baseConfig = config('database.connections.sqlite');
        config(['database.default' => 'marketing_race', 'database.connections.marketing_race' => array_replace($baseConfig, ['database' => $file, 'busy_timeout' => 5000]),
            'cache.default' => 'array']);
        try {
            $this->artisan('migrate', ['--database' => 'marketing_race', '--force' => true])->assertSuccessful();
            $admin = User::factory()->create(['role' => 'admin']);
            foreach (['private_code', 'last_capacity', 'last_budget'] as $scenario) {
                @unlink($barrier);
                $max = $scenario === 'last_capacity' ? 1 : 2;
                $budget = $scenario === 'last_budget' ? 200_000 : 600_000;
                $manager = app(CampaignManager::class);
                $campaign = $manager->save([
                    'name' => $scenario, 'currency_code' => 'IRT', 'trigger' => 'manual', 'timezone' => 'Asia/Tehran',
                    'budget' => $budget, 'max_uses' => $max, 'max_per_customer' => 1,
                    'rule' => ['type' => 'percent', 'value' => 2000, 'max_discount' => 200_000,
                        'min_purchase' => 0, 'per_customer_cap' => 200_000, 'locales' => ['fa'], 'operations' => ['purchase'],
                        'stackable' => false, 'min_margin_bp' => 0, 'subsidy_approved' => true, 'subsidy_reason' => 'Race test'],
                    'audience' => [], 'automation' => ['valid_hours' => 72, 'at' => '00:00'],
                    'message' => ['enabled' => false, 'template' => '', 'service_name' => 'VPS',
                        'landing_path' => '/account/cloud-store', 'limit' => 0, 'budget' => 0],
                ], $admin);
                $manager->transition($campaign, $admin, 'activate', 'Race approval');
                $customers = [];
                foreach ([1, 2] as $n) {
                    $customers[] = Customer::create(['email' => bin2hex(random_bytes(6)).'@example.test',
                        'phone' => '0912'.random_int(1000000, 9999999), 'status' => 'active', 'locale' => 'fa', 'password' => 'test']);
                }
                if ($scenario === 'private_code') {
                    $customers[1] = $customers[0];
                }
                $grant = $manager->issue($campaign->fresh(), $scenario === 'private_code' ? $customers[0] : null,
                    $scenario, $scenario !== 'private_code');
                $processes = [];
                foreach ($customers as $customer) {
                    $invoice = Invoice::create(['customer_id' => $customer->id, 'kind' => 'service',
                        'currency_code' => 'IRT', 'subtotal' => 1_000_000, 'tax' => 0, 'total' => 1_000_000,
                        'paid' => 0, 'status' => 'unpaid']);
                    InvoiceItem::create(['invoice_id' => $invoice->id, 'title' => 'VPS', 'quantity' => 1,
                        'unit_price' => 1_000_000, 'line_total' => 1_000_000, 'tax_rate_bp' => 0, 'tax_amount' => 0,
                        'marketing_context' => ['eligible' => true, 'operation' => 'purchase']]);
                    $command = [PHP_BINARY, '-n', '-d', 'memory_limit=512M', '-d', 'extension_dir='.ini_get('extension_dir')];
                    foreach (['mbstring', 'pdo_sqlite', 'sqlite3', 'openssl', 'fileinfo', 'intl', 'curl', 'gd', 'sodium', 'zip'] as $ext) {
                        if (extension_loaded($ext)) {
                            array_push($command, '-d', 'extension='.$ext);
                        }
                    }
                    array_push($command, base_path('tests/Support/marketing-race-worker.php'), $file,
                        (string) $invoice->id, (string) $customer->id, (string) $grant->id, $barrier, now()->toIso8601String());
                    $process = new Process($command, base_path(), ['APP_ENV' => 'testing']);
                    $process->setTimeout(45)->start();
                    $processes[] = $process;
                }
                file_put_contents($barrier, 'go');
                $results = [];
                foreach ($processes as $process) {
                    $process->wait();
                    $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
                    $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['result'];
                }
                sort($results);
                $this->assertSame(['rejected', 'reserved'], $results, $scenario);
                $this->assertSame(1, MarketingRedemption::where('campaign_id', $campaign->id)->count(), $scenario);
                $fresh = $campaign->fresh();
                $this->assertLessThanOrEqual($fresh->budget, $fresh->held + $fresh->spent, $scenario);
            }
        } finally {
            DB::disconnect('marketing_race');
            foreach ([$barrier, $file.'-wal', $file.'-shm', $file] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
