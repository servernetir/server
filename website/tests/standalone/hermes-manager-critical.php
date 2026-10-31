<?php
/** Composer-only isolated acceptance; all state, owner lookups and sends are synthetic. */
namespace {
    if (PHP_SAPI !== 'cli') { exit(1); }
    $autoload = $argv[1] ?? '';
    if (! is_file($autoload)) { throw new \RuntimeException('Explicit Composer autoload required'); }
    $loader = require $autoload;
    $loader->setClassMapAuthoritative(false);
    $root = $argv[2] ?? dirname(__DIR__, 3);
}
namespace App\Models {
    class Setting {
        public static bool $manager = true;
        public static bool $operations = true;
        public static function get($key, $default = null) {
            return match ($key) {
                'bale_manager_policy_v2_enabled' => self::$manager ? '1' : '0',
                'hermes_operations_enabled' => self::$operations ? '1' : '0',
                default => $default,
            };
        }
    }
    class Service {
        public static array $rows = [];
        public int $id;
        public int $customer_id;
        public string $name;
        public object $customer;
        public object $cloudInstance;
        public object $cloudPlan;
        public object $server;
        public function __construct(int $id, string $provider = 'hetzner') {
            $this->id = $id; $this->customer_id = 1000 + $id; $this->name = 'Synthetic service '.$id;
            $this->customer = new class($id) {
                public string $code;
                public function __construct(private int $id) { $this->code = 'SN-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT); }
                public function displayName(): string { return 'Synthetic customer '.$this->id; }
            };
            $this->cloudInstance = (object) ['provider' => $provider];
            $this->cloudPlan = (object) ['provider' => $provider];
            $this->server = (object) ['name' => 'Synthetic server'];
        }
        public static function query() {
            return new class {
                public function find($id, $columns = ['*']) { return Service::$rows[(int) $id] ?? null; }
                public function __call($method, $args) { return $method === 'get' ? [] : $this; }
            };
        }
    }
    class CloudInstance {}
    class Ticket {}
}
namespace App\Services\Bale\Admin {
    class AdminBaleGate {
        public array $owner = ['chat_id' => '200', 'user_id' => '100'];
        public bool $on = true;
        public function binding() { return $this->owner; }
        public function boundUser() { return (object) ['id' => $this->owner['user_id']]; }
        public function enabled() { return $this->on; }
    }
}
namespace App\Services\Bale {
    class BaleSender {
        public array $sent = [];
        public bool $fail = false;
        public bool $throw = false;
        public function enabled() { return true; }
        public function send($chat, $text) {
            $this->sent[] = [$chat, $text];
            if ($this->throw) { throw new \RuntimeException('synthetic ambiguous send'); }
            return ! $this->fail;
        }
    }
}
namespace App\Services\Cloud {
    class CloudInventory {}
    class CloudManager {
        public const REAL_NAMES = ['hetzner' => 'Hetzner Cloud', 'aeza' => 'Aeza'];
        public const DRIVERS = ['hetzner' => 'unused', 'aeza' => 'unused'];
    }
}
namespace Illuminate\Console {
    class Command {
        public const SUCCESS = 0;
        public const FAILURE = 1;
        public array $lines = [];
        public array $errors = [];
        public function line($message) { $this->lines[] = $message; }
        public function error($message) { $this->errors[] = $message; }
    }
}
namespace Illuminate\Support\Facades {
    class Cache {
        public static bool $allowLock = true;
        public static int $released = 0;
        public static function lock($key, $seconds) {
            return new class {
                public function get() { return Cache::$allowLock; }
                public function release() { Cache::$released++; }
            };
        }
    }
}
namespace App\Services\Hermes {
    class HermesOperationsStore {
        public array $state = ['tasks' => [], 'rules' => [], 'alerts' => [], 'scans' => [], 'digests' => [],
            'business' => [], 'manager_critical_notices' => []];
        public int $reads = 0;
        public $afterChange = null;
        public function read($owner) { $this->reads++; return $this->state; }
        public function change($owner, $operation) {
            $result = $operation($this->state);
            if ($this->afterChange) { $hook = $this->afterChange; $this->afterChange = null; $hook($this); }
            return $result;
        }
    }
    class HermesServiceDossier {}
    class HermesReadOnlyDiagnostics {}
    class HermesSupportState {}
    class HermesSupportPolicy { public function classify(...$args) { return 'technical'; } }
    class HermesOperationsPolicy {
        public static function alert(array &$state, string $key, string $code, array $data): void {
            $state['alerts'][$key] = ['code' => $code, 'data' => $data, 'active' => true,
                'last_at' => time(), 'count' => 1, 'acknowledged_at' => null];
        }
    }
    class HermesBusinessAudit {
        public int $calls = 0;
        public bool $fail = false;
        public $onRun = null;
        public function run(): array {
            $this->calls++;
            if ($this->fail) { throw new \RuntimeException('synthetic audit failure'); }
            if ($this->onRun) { ($this->onRun)(); }
            return ['status' => 'reviewed', 'services_checked' => 1];
        }
    }
    class HermesReleaseKnowledge {
        public int $calls = 0;
        public $onCapture = null;
        public function capture(): array {
            $this->calls++;
            if ($this->onCapture) { ($this->onCapture)(); }
            return ['at' => time(), 'fingerprint' => 'synthetic-release-knowledge'];
        }
    }
    class HermesBusinessReport {
        public const LABELS = [];
        public static function text($state) { return 'legacy-business-marker'; }
        public static function fingerprint($alerts) { return hash('sha256', json_encode($alerts)); }
    }
}
namespace {
    use App\Models\Service;
    use App\Models\Setting;
    use App\Services\Bale\Admin\AdminBaleGate;
    use App\Services\Bale\BaleSender;
    use App\Services\Hermes\HermesOperations;
    use App\Services\Hermes\HermesOperationsStore;

    require_once $root.'/website/app/Services/Notify/BaleManagerPolicy.php';
    require_once $root.'/website/app/Services/Hermes/HermesAlertSummary.php';
    require_once $root.'/website/app/Services/Hermes/HermesOwnerNotification.php';
    require_once $root.'/infra/hermes/bale-manager-20261031/HermesOperations.php';
    require_once $root.'/infra/hermes/bale-manager-20261031/HermesBusinessReview.php';
    $checks = 0;
    $assert = static function (bool $condition, string $label) use (&$checks): void {
        if (! $condition) { throw new \RuntimeException('FAILED: '.$label); }
        $checks++;
    };
    $fixture = static function (): array {
        Setting::$manager = Setting::$operations = true;
        Service::$rows = [];
        for ($id = 77; $id <= 90; $id++) { Service::$rows[$id] = new Service($id); }
        $store = new HermesOperationsStore;
        $store->state['scans'] = ['hetzner' => ['complete' => true, 'at' => time()], 'aeza' => ['complete' => true, 'at' => time()]];
        $store->state['business'] = ['at' => time(), 'services' => []];
        foreach (Service::$rows as $id => $service) {
            $store->state['business']['services'][$id] = ['service_id' => $id, 'checked_at' => time()];
        }
        $gate = new AdminBaleGate;
        $sender = new BaleSender;
        $ops = new HermesOperations($store, $gate, new \App\Services\Hermes\HermesServiceDossier,
            new \App\Services\Hermes\HermesReadOnlyDiagnostics, new \App\Services\Hermes\HermesSupportState,
            new \App\Services\Cloud\CloudInventory, new \App\Services\Cloud\CloudManager, $sender);
        return [$ops, $store, $gate, $sender];
    };
    $alert = static function (string $code = 'closed_service_still_running', array $data = []): array {
        return ['code' => $code, 'active' => true, 'acknowledged_at' => null,
            'first_at' => time() - 60, 'last_at' => time() - 2, 'count' => 1,
            'data' => $data + ['provider' => 'hetzner', 'service_id' => 77, 'ref' => 'synthetic-resource',
                'reason' => 'synthetic verified cause', 'severity' => 'critical', 'band' => 'critical']];
    };
    $statuses = static fn ($store) => array_column($store->state['manager_critical_notices'] ?? [], 'status');

    [$ops, $store, , $sender] = $fixture();
    $store->state['alerts']['resource:77'] = $alert();
    $assert($ops->digest(true) === 'handled_by_manager_digest' && $sender->sent === [] && $store->reads === 0,
        'natural daily report is owned by the consolidated manager digest without native source reads');
    $ops->digest(false);
    $assert(count($sender->sent) === 1 && in_array('sent', $statuses($store), true), 'new verified critical incident is sent and durably claimed');
    $message = $sender->sent[0][1];
    $assert(str_contains($message, '77') && (str_contains($message, 'Hetzner') || str_contains($message, 'hetzner')),
        'critical notice identifies exact service and provider');
    $assert(str_contains($message, 'Synthetic customer 77') || str_contains($message, 'SN-000077'), 'critical notice identifies the exact customer');
    $ops->digest(false);
    $store->state['alerts']['resource:77']['count'] = 999;
    $store->state['alerts']['resource:77']['last_at'] = time() - 1;
    $ops->digest(false);
    $assert(count($sender->sent) === 1, 'unchanged count and observation timestamp do not create new incidents');
    $store->state['alerts']['resource:77']['data']['reason'] = 'a different verified cause';
    $ops->digest(false);
    $assert(count($sender->sent) === 2, 'changed stable cause remains immediate');
    $store->state['alerts']['resource:77']['data']['provider'] = 'aeza';
    Service::$rows[77] = new Service(77, 'aeza');
    $ops->digest(false);
    $assert(count($sender->sent) === 3, 'independent provider identity remains immediate');
    $store->state['alerts']['resource:77']['active'] = false;
    $ops->digest(false);
    $store->state['alerts']['resource:77']['active'] = true;
    $ops->digest(false);
    $assert(count($sender->sent) === 4, 'explicit fresh recovery permits a later recurrence');

    [$ops, $store, , $sender] = $fixture();
    $store->state['alerts']['resource:77'] = $alert();
    $ops->digest(false);
    $store->state['alerts']['resource:77']['active'] = false;
    $store->state['scans']['hetzner']['at'] = time() - 7201;
    $ops->digest(false);
    $store->state['alerts']['resource:77']['active'] = true;
    $store->state['scans']['hetzner']['at'] = time();
    $ops->digest(false);
    $assert(count($sender->sent) === 1, 'a stale inactive snapshot cannot manufacture recovery and a duplicate recurrence');

    [$ops, $store, , $sender] = $fixture();
    $store->state['alerts'] = ['unknown' => $alert('unexpected_critical_code'),
        'unassigned' => $alert('unassigned_resource'),
        'estimate-warning' => $alert('estimated_underwater', ['severity' => 'warning']),
        'wallet-warning' => $alert('wallet_runway_low', ['severity' => 'warning']),
        'traffic-warning' => $alert('traffic_threshold', ['severity' => 'warning'])];
    $ops->digest(false);
    $assert($sender->sent === [], 'unknown, unassigned and warning-only estimates or runway never interrupt the manager');
    $store->state['alerts'] = ['stale-inventory' => $alert('service_resource_missing')];
    $store->state['scans']['hetzner']['complete'] = false;
    $ops->digest(false);
    $store->state['scans']['hetzner'] = ['complete' => true, 'at' => time() - 7201];
    $ops->digest(false);
    $store->state['scans']['hetzner']['at'] = time() + 600;
    $ops->digest(false);
    $assert($sender->sent === [], 'incomplete, expired or future inventory is not verified evidence');
    $store->state['scans']['hetzner']['at'] = time();
    $ops->digest(false);
    $assert(count($sender->sent) === 1, 'the same missing-resource incident becomes eligible on fresh complete evidence');

    [$ops, $store, , $sender] = $fixture();
    $store->state['alerts']['traffic'] = $alert('traffic_threshold');
    $store->state['alerts']['traffic']['last_at'] = time() - 7201;
    $ops->digest(false);
    $assert($sender->sent === [], 'stale critical traffic observation cannot interrupt');
    $store->state['alerts']['traffic']['last_at'] = time();
    $ops->digest(false);
    $assert(count($sender->sent) === 1, 'fresh critical traffic observation is actionable');
    $store->state['alerts']['traffic']['data']['band'] = 'critical-higher';
    $ops->digest(false);
    $assert(count($sender->sent) === 2, 'changed risk band is a changed incident');

    [$ops, $store, , $sender] = $fixture();
    $store->state['alerts']['business:row'] = $alert('metering_gap');
    unset($store->state['business']['services'][77]);
    $ops->digest(false);
    $assert($sender->sent === [], 'a globally fresh audit does not verify a missing exact service row');
    $store->state['business']['services'][77] = ['service_id' => 77, 'checked_at' => time() - 3601];
    $ops->digest(false);
    $assert($sender->sent === [], 'a globally fresh audit does not verify a stale exact service row');
    $store->state['business']['services'][77] = ['service_id' => 78, 'checked_at' => time()];
    $ops->digest(false);
    $assert($sender->sent === [], 'a fresh row for another service cannot authorize this service incident');
    $store->state['business']['services'][77] = ['service_id' => 77, 'checked_at' => time()];
    $store->state['alerts']['business:row']['last_at'] = time() - 3601;
    $ops->digest(false);
    $assert($sender->sent === [], 'an old business alert is not fresh merely because the audit and row are fresh');
    $store->state['alerts']['business:row']['last_at'] = time();
    $ops->digest(false);
    $assert(count($sender->sent) === 1, 'matching fresh service row and fresh global audit verify the business incident');

    [$ops, $store, , $sender] = $fixture();
    $store->state['alerts'] = ['missing-service' => $alert('closed_service_still_running', ['service_id' => null]),
        'invalid-service' => $alert('traffic_threshold', ['service_id' => -1])];
    $ops->digest(false);
    $assert($sender->sent === [], 'all critical codes require a positive exact service identity');

    [$ops, $store, , $sender] = $fixture();
    foreach (['metering_gap', 'invoice_receipt_mismatch', 'traffic_budget_risk'] as $i => $code) {
        $store->state['alerts']['business:'.$code] = $alert($code, ['service_id' => 77 + $i]);
    }
    $store->state['business']['at'] = time() - 3601;
    $ops->digest(false);
    $assert($sender->sent === [], 'stale business evidence cannot interrupt');
    $store->state['business']['at'] = time();
    $store->state['alerts']['inventory:80'] = $alert('closed_service_still_running', ['service_id' => 80]);
    $ops->digest(false);
    $assert(count($sender->sent) === 1 && count($statuses($store)) === 3, 'up to three new incidents share one compact message');
    $ops->digest(false);
    $assert(count($sender->sent) === 2 && count($statuses($store)) === 4, 'a later call covers the remaining new incident without repeating the first three');

    [$ops, $store, , $sender] = $fixture();
    $store->state['alerts']['critical-price'] = $alert('estimated_underwater');
    $ops->digest(false);
    $assert(count($sender->sent) === 1, 'native critical margin risk remains immediate');
    $assert(str_contains($sender->sent[0][1], 'برآورد') || str_contains($sender->sent[0][1], 'کاتالوگ'),
        'critical margin risk is explicitly an estimate rather than a supplier invoice');
    $store->state['alerts']['critical-wallet'] = $alert('wallet_runway_low', ['service_id' => 78]);
    $ops->digest(false);
    $assert(count($sender->sent) === 2, 'native critical runway risk remains immediate while warning-only runway stays in the daily report');

    [$ops, $store, , $sender] = $fixture();
    $store->state['alerts']['interrupted'] = $alert();
    $ops->digest(false);
    $store->state['manager_critical_notices']['interrupted']['status'] = 'dispatching';
    $ops->digest(false);
    $assert(count($sender->sent) === 1, 'an interrupted dispatching claim is never automatically repeated');

    foreach (['false', 'exception'] as $mode) {
        [$ops, $store, , $sender] = $fixture();
        $store->state['alerts']['ambiguous'] = $alert();
        $sender->fail = $mode === 'false'; $sender->throw = $mode === 'exception';
        $ops->digest(false);
        $assert(count($sender->sent) === 1 && in_array('uncertain', $statuses($store), true), 'ambiguous '.$mode.' transport result is recorded as uncertain');
        $sender->fail = $sender->throw = false;
        $ops->digest(false);
        $assert(count($sender->sent) === 1, 'ambiguous '.$mode.' attempt is never automatically repeated');
    }

    [$ops, $store, $gate, $sender] = $fixture();
    $store->state['alerts']['owner-change'] = $alert();
    $store->afterChange = static function () use ($gate): void { $gate->owner = ['chat_id' => '999', 'user_id' => '999']; };
    $ops->digest(false);
    $assert($sender->sent === [], 'owner is rechecked after the claim and before transport');
    $gate->owner = ['chat_id' => '200', 'user_id' => '100'];
    $ops->digest(false);
    $assert($sender->sent === [], 'an interrupted owner-change claim cannot later send automatically');

    [$ops, $store, , $sender] = $fixture();
    Setting::$manager = false;
    $assert($ops->digest(false) === 'no_alerts' && $sender->sent === [], 'policy-off legacy no-alert return is preserved');
    $assert(($store->state['manager_critical_notices'] ?? []) === [], 'policy-off path does not create manager claims');
    Setting::$manager = true; Setting::$operations = false;
    $store->state['alerts']['disabled'] = $alert();
    $ops->digest(false);
    $assert($sender->sent === [], 'operations disabled still prevents a critical push');

    [$ops, $store, , $sender] = $fixture();
    $audit = new \App\Services\Hermes\HermesBusinessAudit;
    $audit->onRun = static function () use ($store, $alert): void {
        $store->state['business']['at'] = time();
        $store->state['business']['services'][77] = ['service_id' => 77, 'checked_at' => time()];
        $store->state['alerts']['command:77'] = $alert('metering_gap');
    };
    $knowledge = new \App\Services\Hermes\HermesReleaseKnowledge;
    $knowledge->onCapture = static function () use ($sender, $assert): void {
        $assert($sender->sent === [], 'the command never dispatches before successful knowledge capture');
    };
    $command = new \App\Console\Commands\HermesBusinessReview;
    $released = \Illuminate\Support\Facades\Cache::$released;
    $result = $command->handle($audit, $ops, $store, $knowledge);
    $summary = json_decode($command->lines[0] ?? '{}', true, 16, JSON_THROW_ON_ERROR);
    $assert($result === 0 && $audit->calls === 1 && $knowledge->calls === 1, 'the actual 15-minute review command completes the existing audit and knowledge path');
    $assert(($store->state['release_knowledge']['fingerprint'] ?? null) === 'synthetic-release-knowledge', 'the command preserves the existing release-knowledge capture');
    $assert(($summary['owner_alert'] ?? null) === 'sent' && count($sender->sent) === 1,
        'a successful gate-on review invokes real native critical dispatch');
    $assert(\Illuminate\Support\Facades\Cache::$released === $released + 1, 'successful command releases its existing audit lock');
    $knowledge->onCapture = null;
    $command = new \App\Console\Commands\HermesBusinessReview;
    $command->handle($audit, $ops, $store, $knowledge);
    $summary = json_decode($command->lines[0] ?? '{}', true, 16, JSON_THROW_ON_ERROR);
    $assert(count($sender->sent) === 1 && ($summary['owner_alert'] ?? '') === 'no_new_critical_alerts',
        'repeated successful audit cycles obey the existing stable critical deduplication');

    [$ops, $store, , $sender] = $fixture();
    Setting::$manager = false;
    $store->state['alerts']['legacy-command:77'] = $alert();
    $audit = new \App\Services\Hermes\HermesBusinessAudit;
    $knowledge = new \App\Services\Hermes\HermesReleaseKnowledge;
    $command = new \App\Console\Commands\HermesBusinessReview;
    $result = $command->handle($audit, $ops, $store, $knowledge);
    $summary = json_decode($command->lines[0] ?? '{}', true, 16, JSON_THROW_ON_ERROR);
    $assert($result === 0 && $audit->calls === 1 && $knowledge->calls === 1, 'gate-off command retains its existing audit behavior');
    $assert(! array_key_exists('owner_alert', $summary) && $sender->sent === []
        && ($store->state['manager_critical_notices'] ?? []) === [], 'gate-off successful audit adds no owner dispatch or critical claims');

    [$ops, $store, , $sender] = $fixture();
    $store->state['alerts']['failed-command:77'] = $alert();
    $audit = new \App\Services\Hermes\HermesBusinessAudit;
    $audit->fail = true;
    $knowledge = new \App\Services\Hermes\HermesReleaseKnowledge;
    $command = new \App\Console\Commands\HermesBusinessReview;
    $released = \Illuminate\Support\Facades\Cache::$released;
    $result = $command->handle($audit, $ops, $store, $knowledge);
    $assert($result === 1 && $audit->calls === 1 && $knowledge->calls === 0 && count($command->errors) === 1,
        'failed audit retains the failure return and never proceeds to knowledge capture');
    $assert($sender->sent === [] && ($store->state['manager_critical_notices'] ?? []) === [],
        'failed audit does not dispatch even a pre-existing critical alert');
    $assert(($store->state['alerts']['business:review']['code'] ?? '') === 'business_review_unavailable'
        && \Illuminate\Support\Facades\Cache::$released === $released + 1,
        'failed audit preserves the existing unavailable finding and releases its lock');

    [$ops, $store, , $sender] = $fixture();
    $audit = new \App\Services\Hermes\HermesBusinessAudit;
    $knowledge = new \App\Services\Hermes\HermesReleaseKnowledge;
    $command = new \App\Console\Commands\HermesBusinessReview;
    \Illuminate\Support\Facades\Cache::$allowLock = false;
    $result = $command->handle($audit, $ops, $store, $knowledge);
    \Illuminate\Support\Facades\Cache::$allowLock = true;
    $assert($result === 0 && $command->lines === ['busy'] && $audit->calls === 0 && $knowledge->calls === 0
        && $sender->sent === [], 'busy audit lock does not run work or dispatch alerts');

    echo json_encode(['status' => 'passed', 'checks' => $checks,
        'live_provider_calls' => 0, 'external_messages_sent' => 0], JSON_THROW_ON_ERROR).PHP_EOL;
}
