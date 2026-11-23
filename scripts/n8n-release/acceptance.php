<?php
/** CLI-only, one owned Proxmox allocation. No invoices or wallet operations. */
if (PHP_SAPI !== 'cli' || posix_geteuid() === 0) { exit(70); }
chdir('/home/servernetcloud/servernet_app');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\CloudImage;
use App\Models\CloudInstance;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Service;
use App\Services\Billing\ComplimentaryServices;
use App\Services\Cloud\ManagedN8n;
use App\Services\Cloud\ManagedN8nCatalog;
use App\Services\Cloud\ProxmoxClient;
use App\Services\Cloud\PublicPortAllocator;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

umask(0077);
$mode = $argv[1] ?? '';
if (! in_array($mode, ['create', 'status', 'observe', 'cleanup'], true)) { exit(64); }
$suffix = $argv[2] ?? '';
if (! in_array($suffix, ['', 'final', 'final2'], true)) { exit(64); }
$run = 'n8n-acceptance-20261123'.($suffix ? '-'.$suffix : '');
$receipt = __DIR__.'/acceptance-receipt'.($suffix ? '-'.$suffix : '').'.json';
$lock = fopen(__DIR__.'/acceptance.lock', 'c');
if (! flock($lock, LOCK_EX | LOCK_NB)) { exit(75); }
function recordReceipt(string $path, array $data): void {
    $temp = $path.'.tmp';
    file_put_contents($temp, json_encode($data, JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($temp, 0600);
    rename($temp, $path);
}
function requireCondition(bool $ok, string $message): void {
    if (! $ok) { throw new RuntimeException($message); }
}
try {
    $owner = Customer::findOrFail((int) config('billing.complimentary_services.customer_id'));
    requireCondition(app(ComplimentaryServices::class)->customerExempt($owner), 'Owner binding changed');
    $driver = app(ProxmoxClient::class);
    if ($mode === 'create') {
        requireCondition(! file_exists($receipt), 'Acceptance was already started; inspect its receipt');
        requireCondition(! Service::where('provision_meta->acceptance_run', $run)->exists(), 'Existing acceptance service');
        $plan = CloudPlan::where('provider', 'proxmox')->where('location_code', 'ir-tehran')
            ->where('vcpu', 2)->where('ram_mb', 2048)->where('disk_gb', 40)->firstOrFail();
        $key = app(ManagedN8nCatalog::class)->image($plan);
        $image = $key ? CloudImage::compatibleRefFor($plan, $key) : null;
        requireCondition(filled($image), 'Compatible Linux template unavailable');
        $service = Service::create(['customer_id' => $owner->id, 'name' => 'Internal n8n delivery acceptance',
            'price' => 0, 'tax_percent' => 0, 'currency_code' => 'IRT', 'cycle' => 'once',
            'billing_mode' => Service::BILLING_EXTERNAL, 'status' => 'active', 'provision_status' => 'none',
            'cloud_plan_id' => $plan->id, 'cloud_image_key' => $key,
            'provision_meta' => ['application' => 'n8n', 'acceptance_run' => $run,
                'internal_infrastructure' => true, 'internal_infrastructure_customer' => $owner->id]]);
        requireCondition($service->isInternalInfrastructure(), 'Internal exemption missing');
        $name = 'sn-n8n-acceptance-20261123-'.$service->id;
        $instance = CloudInstance::create(['service_id' => $service->id, 'provider' => 'proxmox',
            'location_code' => $plan->location_code, 'image_key' => $key, 'hostname' => $name,
            'status' => 'building', 'specs' => ['vcpu' => 2, 'ram_mb' => 2048, 'disk_gb' => 40]]);
        $data = ['run' => $run, 'service_id' => $service->id, 'instance_id' => $instance->id,
            'name' => $name, 'requested_at' => now()->toIso8601String()];
        // A durable receipt precedes the clone. Unknown results are never purchased again.
        recordReceipt($receipt, $data);
        $result = $driver->createServer(['name' => $name, 'image_ref' => $image,
            'vcpu' => 2, 'ram_mb' => 2048, 'disk_gb' => 40, 'managed_application' => 'n8n',
            'labels' => ['service' => (string) $service->id, 'purpose' => $run]]);
        if (filled($result['ref'] ?? null)) {
            $instance->fill(['provider_ref' => $result['ref'], 'ipv4' => $result['ipv4'] ?? null,
                'status' => $result['status'] ?? 'error', 'synced_at' => now()]);
            $instance->setPassword($result['root_password'] ?? null);
            $instance->save();
            $data['ref'] = (string) $result['ref'];
            recordReceipt($receipt, $data);
        }
        requireCondition(($result['ok'] ?? false) && filled($instance->provider_ref), 'Allocation not confirmed; inspect receipt');
        requireCondition(app(PublicPortAllocator::class)->allocate($instance) !== null, 'Public SSH port unavailable');
        $service->update(['provision_status' => 'done', 'provisioned_at' => now(), 'activated_at' => now()]);
        echo json_encode(['status' => 'allocated', 'service_id' => $service->id, 'ref' => $instance->provider_ref]).PHP_EOL;
        exit(0);
    }
    $data = json_decode(file_get_contents($receipt), true, 512, JSON_THROW_ON_ERROR);
    requireCondition(($data['run'] ?? '') === $run, 'Receipt run mismatch');
    $service = Service::findOrFail($data['service_id']);
    $instance = CloudInstance::findOrFail($data['instance_id']);
    requireCondition($service->customer_id === $owner->id && $service->isInternalInfrastructure()
        && data_get($service->provision_meta, 'acceptance_run') === $run
        && $instance->service_id === $service->id && $instance->provider === 'proxmox'
        && (string) $instance->provider_ref === (string) ($data['ref'] ?? '')
        && $instance->hostname === $data['name'], 'Allocation ownership mismatch');
    requireCondition(! Invoice::where('service_id', $service->id)->exists(), 'Unexpected financial record');
    if ($mode === 'cleanup') {
        $service->update(['status' => 'cancelled', 'cancelled_at' => now(), 'provision_status' => 'none']);
        requireCondition(app(ManagedN8n::class)->removeGateway($instance), 'Gateway cleanup is pending');
        $inventory = $driver->listServers();
        requireCondition($inventory['ok'] ?? false, 'Cannot verify allocation identity');
        $found = collect($inventory['servers'])->firstWhere('ref', (string) $instance->provider_ref);
        if ($found) {
            requireCondition($found['name'] === $data['name'], 'Provider allocation name mismatch');
            requireCondition($driver->deleteServer((string) $instance->provider_ref)['ok'] ?? false, 'Deletion not confirmed');
        }
        $instance->update(['status' => 'deleted']);
        $data['cleaned_at'] = now()->toIso8601String();
        recordReceipt($receipt, $data);
        echo json_encode(['status' => 'cleaned', 'service_id' => $service->id]).PHP_EOL;
        exit(0);
    }
    if ($mode !== 'observe') {
        $live = $driver->serverStatus((string) $instance->provider_ref);
        requireCondition($live['ok'] ?? false, 'Provider status unavailable');
        $instance->update(['status' => $live['status'], 'synced_at' => now()]);
        app(ManagedN8n::class)->advance($service);
    }
    $instance->refresh();
    $state = (array) data_get($instance->meta, 'n8n', []);
    if (($state['state'] ?? '') === 'ready' && empty($data['verified_at'])) {
        $login = Http::timeout(10)->withoutRedirecting()->post('https://'.$state['hostname'].'/rest/login',
            ['emailOrLdapLoginId' => $state['owner_email'], 'password' => Crypt::decryptString($state['password_enc'])]);
        requireCondition($login->status() === 200 && $login->json('data.email') === $state['owner_email'], 'Initial owner login failed');
        $data['verified_at'] = now()->toIso8601String();
        $data['hostname'] = $state['hostname'];
        recordReceipt($receipt, $data);
    }
    echo json_encode(['service_id' => $service->id, 'ref' => $instance->provider_ref,
        'vm' => $instance->status, 'application' => $state['state'] ?? 'pending',
        'issue' => $state['last_issue'] ?? null, 'owner_login_verified' => ! empty($data['verified_at']),
        'hostname' => $state['hostname'] ?? null]).PHP_EOL;
} catch (Throwable $e) {
    // Do not emit exception messages from transports or secret-bearing payloads.
    fwrite(STDERR, 'Acceptance phase failed; inspect the bound receipt and state.'.PHP_EOL);
    exit(1);
}
