<?php
/**
 * Private isolated CLI acceptance. Usage: php TEST VENDOR_AUTOLOAD [SOURCE_ROOT]
 * Loads Composer only (no application bootstrap/.env), an in-memory SQLite DB,
 * synthetic native boundaries, and a captured provider call. Never reads live data.
 */
namespace {
    $autoload = $argv[1] ?? '';
    if (! is_file($autoload)) { throw new \RuntimeException('Explicit Composer autoload required'); }
    $loader = require $autoload;
    $root = $argv[2] ?? dirname(__DIR__, 3);
    if (! is_dir($root.'/infra/hermes/business-20261030')) { throw new \RuntimeException('Source root missing'); }
    $loader->setClassMapAuthoritative(false);
}
namespace App\Models {
    class Setting { public static function get($key) { return in_array($key, ['hermes_operations_enabled', 'hermes_support_enabled'], true) ? '1' : ''; } }
    class Ticket { public string $subject = 'SMTP support'; public int $customer_id = 10; public $customer; public function __construct() { $this->customer = (object) ['locale' => 'en']; } }
    class Service { public static function query() { return new class { public function __call($name, $args) { return $name === 'get' ? [] : $this; } }; } }
    class CloudInstance {}
}
namespace App\Services\Bale\Admin {
    class AdminBaleGate { public function binding() { return ['chat_id' => 'fixture']; } public function boundUser() { return (object) ['id' => 1]; } public function enabled() { return true; } }
}
namespace App\Services\Bale { class BaleSender {} }
namespace App\Services\Cloud { class CloudInventory {} class CloudManager {} }
namespace App\Services\Ticket {
    class TicketDraftWriter {
        public static array $captured = [];
        protected function call($system, $user, $tokens, $timeout = 140, $stream = false): ?string {
            self::$captured = json_decode($user, true, flags: JSON_THROW_ON_ERROR);
            return json_encode(['analysis' => 'بررسی اولیه با مرجع داخلی انجام شد.', 'reply' => 'Please share your preferred location so we can check a suitable option.']);
        }
    }
}
namespace App\Services\Hermes {
    class HermesOperationsStore {
        public static string $padding = '';
        public function read($owner) { return ['tasks' => [], 'rules' => [], 'alerts' => [], 'scans' => ['padding' => self::$padding], 'digests' => [], 'knowledge' => []]; }
    }
    class HermesServiceDossier {
        public function resolve($ticket) { return ['service' => null, 'status' => 'unselected']; }
        public function ticket($ticket, $selection) { return ['service' => null]; }
    }
    class HermesReadOnlyDiagnostics {}
    class HermesSupportState { public function read() { return ['items' => [], 'last_run_at' => null, 'last_success_at' => null]; } }
    class HermesOperationsPolicy { public static function approvedRules($state) { return ['preserve-existing-rule']; } }
    class HermesSupportPlaybooks { public static string $padding = ''; public static function build($intent, $dossier, $checks) { return ['padding' => self::$padding]; } }
    class HermesBusinessReport { public static function context($state) { return ['available' => false]; } }
    class HermesKnowledge { public static function context($knowledge, $now, $query) { return ['reference_only' => true]; } }
}
namespace {
    use Illuminate\Container\Container;
    use Illuminate\Database\Capsule\Manager as Capsule;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Date;
    use Illuminate\Support\Facades\Facade;
    use Illuminate\Support\Facades\Schema;
    use App\Services\Hermes\HermesApprovedBusinessKnowledge as Knowledge;
    $container = new Container;
    Container::setInstance($container);
    Facade::setFacadeApplication($container);
    $capsule = new Capsule($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    DB::swap($capsule->getDatabaseManager());
    Schema::swap($capsule->schema());
    Date::swap(new \Illuminate\Support\DateFactory);
    require_once $root.'/website/app/Services/Growth/Redactor.php';
    require_once $root.'/website/app/Services/Hermes/HermesApprovedBusinessKnowledge.php';
    require_once $root.'/website/app/Services/Hermes/HermesSupportEvidenceGuard.php';
    require_once $root.'/infra/hermes/support-firewall-20261028/HermesSupportPolicy.php';
    require_once $root.'/infra/hermes/business-20261030/HermesSupportDraftWriter.php';
    require_once $root.'/infra/hermes/business-20261030/HermesOperations.php';
    require_once $root.'/infra/hermes/business-20261030/HermesConversationMemory.php';
    $checks = 0;
    $assert = static function (bool $condition, string $case) use (&$checks): void {
        if (! $condition) { throw new \RuntimeException('FAILED: '.$case); }
        $checks++;
    };
    $knowledge = new Knowledge;
    $assert($knowledge->read()['reason'] === 'schema_unavailable', 'missing schema is unavailable');
    Schema::create('growth_documents', function (Blueprint $table) {
        $table->integer('id')->primary(); $table->string('title'); $table->text('body'); $table->string('status');
        $table->string('scope'); $table->string('classification'); $table->string('kind'); $table->integer('version');
        $table->dateTime('verified_at')->nullable(); $table->dateTime('review_at')->nullable();
    });
    $base = ['title' => 'SMTP guidance', 'body' => 'SMTP procedural public-marker. Check the selected infrastructure before confirming capabilities.',
        'status' => 'verified', 'scope' => 'general', 'classification' => 'public', 'kind' => 'runbook', 'version' => 1,
        'verified_at' => gmdate('Y-m-d H:i:s', time() - 60), 'review_at' => gmdate('Y-m-d H:i:s', time() + 86400)];
    $put = static function (int $id, array $changes = []) use ($base): void { DB::table('growth_documents')->insert(['id' => $id] + $changes + $base); };
    $put(1); $put(2, ['classification' => 'internal', 'body' => 'SMTP internal-private-marker']);
    $put(3, ['status' => 'candidate', 'body' => 'SMTP candidate-marker']);
    $put(4, ['review_at' => gmdate('Y-m-d H:i:s', time() - 1), 'body' => 'SMTP expired-marker']);
    $put(5, ['classification' => 'restricted', 'body' => 'SMTP restricted-marker']);
    $put(6, ['scope' => 'service:999', 'body' => 'SMTP other-customer-marker']);
    $put(7, ['kind' => 'ticket', 'body' => 'SMTP ticket-private-marker']);
    $put(8, ['verified_at' => null, 'body' => 'SMTP unverifiable-marker']);
    $put(9, ['body' => 'SMTP password=synthetic-secret']);
    $put(12, ['classification' => 'internal', 'body' => 'SMTP توکن واژه‌ای عمومی در این راهنماست. owner-generic-word-marker']);
    $put(13, ['classification' => 'internal', 'body' => 'SMTP password=private-synthetic-secret']);
    $support = $knowledge->read('SMTP');
    $json = json_encode($support, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $assert(count($support['references']) === 1, 'support only public general verified current runbooks');
    $assert(str_contains($json, 'public-marker'), 'support reference retrieved');
    foreach (['private-marker', 'candidate-marker', 'expired-marker', 'restricted-marker', 'other-customer-marker', 'unverifiable-marker', 'synthetic-secret'] as $marker) {
        $assert(! str_contains($json, $marker), 'excluded '.$marker);
    }
    $ownerJson = json_encode($knowledge->read('SMTP', true));
    $assert(str_contains($ownerJson, 'internal-private-marker'), 'owner receives internal general runbook');
    $assert(str_contains($ownerJson, 'owner-generic-word-marker'), 'benign credential terminology remains available to owner');
    $assert(! str_contains($ownerJson, 'private-synthetic-secret'), 'actual credentials excluded from owner references');
    $assert(! $support['grants_execution_rights'] && ! $support['current_state_verified'], 'no new authority or live-health claim');
    DB::table('growth_documents')->where('id', 1)->update(['status' => 'rejected']);
    $assert($knowledge->read('SMTP')['references'] === [], 'revocation applies immediately without stale cache');
    DB::table('growth_documents')->where('id', 1)->update(['status' => 'verified']);
    $put(10, ['title' => 'راهنمای فنی', 'body' => "آغاز عمومی\nپشتیبانی: بررسی وضعیت جاری و اقدام بعدی."]);
    $assert(str_contains(json_encode($knowledge->read('پشتيباني'), JSON_UNESCAPED_UNICODE), 'پشتیبانی'), 'Persian Arabic-character matching reaches relevant paragraph');
    $put(11, ['body' => 'SMTP '.str_repeat('راهنمای مناسب ', 300)]);
    $assert(strlen(json_encode($knowledge->read('SMTP'), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) <= Knowledge::MAX_BYTES, 'UTF8 payload is bounded');
    DB::table('growth_documents')->whereIn('id', [10, 11])->delete();
    $operations = new \App\Services\Hermes\HermesOperations(new \App\Services\Hermes\HermesOperationsStore,
        new \App\Services\Bale\Admin\AdminBaleGate, new \App\Services\Hermes\HermesServiceDossier,
        new \App\Services\Hermes\HermesReadOnlyDiagnostics, new \App\Services\Hermes\HermesSupportState,
        new \App\Services\Cloud\CloudInventory, new \App\Services\Cloud\CloudManager, new \App\Services\Bale\BaleSender);
    $evidence = $operations->evidence(new \App\Models\Ticket, 'SMTP');
    $assert(isset($evidence['approved_business_knowledge']['references'][0]), 'native evidence integration retrieves runbook');
    $assert($evidence['approved_reference_rules'] === ['preserve-existing-rule'], 'existing rule evidence preserved');
    $writer = new \App\Services\Hermes\HermesSupportDraftWriter;
    $review = $writer->reviewSnapshot('SMTP support', 'technical', [['role' => 'customer', 'body' => 'Can you support SMTP?']], $evidence);
    $assert($review !== null, 'native writer accepts screened bridge evidence');
    $captured = \App\Services\Ticket\TicketDraftWriter::$captured['application_read_only_evidence'];
    $assert(str_contains(json_encode($captured), 'public-marker'), 'actual native model call receives reviewed reference');
    $assert(! str_contains(json_encode($captured), 'internal-private-marker'), 'private owner knowledge absent from actual support prompt');
    $laterInvestigation = $evidence;
    $laterInvestigation['investigation'] = ['fixture' => str_repeat('x', 22500)];
    // Make this boundary case independent of the exact short fixture guide.
    $laterInvestigation['approved_business_knowledge']['references'][0]['excerpt'] = str_repeat('راهنما ', 250);
    $review = $writer->reviewSnapshot('SMTP support', 'technical', [['role' => 'customer', 'body' => 'Can you support SMTP?']], $laterInvestigation);
    $assert($review !== null, 'post-collector investigation overflow keeps review available');
    $capturedLate = \App\Services\Ticket\TicketDraftWriter::$captured['application_read_only_evidence'];
    $assert(! isset($capturedLate['approved_business_knowledge']), 'final writer boundary removes optional guidance only');
    $assert(strlen($capturedLate['investigation']['fixture']) === 22500, 'late investigation preserved intact in model prompt');
    $laterInvestigation['investigation']['fixture'] = str_repeat('x', 25000);
    \App\Services\Ticket\TicketDraftWriter::$captured = [];
    $assert($writer->reviewSnapshot('SMTP support', 'technical', [['role' => 'customer', 'body' => 'Can you support SMTP?']], $laterInvestigation) === null,
        'oversized nonoptional evidence remains rejected');
    $assert(\App\Services\Ticket\TicketDraftWriter::$captured === [], 'oversized nonoptional evidence makes no model call');
    $owner = $operations->chatContext(['user_id' => '1', 'chat_id' => 'fixture'], 'SMTP');
    $assert(str_contains(json_encode($owner['approved_business_knowledge']), 'internal-private-marker'), 'actual owner context receives internal reference');
    $assert($owner['customer_send'] === 'exact_owner_approval' && $owner['infrastructure_changes_authorized'] === false, 'owner authority preserved');
    \App\Services\Hermes\HermesOperationsStore::$padding = str_repeat('x', 7000);
    $ownerLarge = $operations->chatContext(['user_id' => '1', 'chat_id' => 'fixture'], 'SMTP');
    $assert(isset($ownerLarge['approved_business_knowledge']), 'owner runtime carries optional knowledge to final caller');
    $assert(strlen($ownerLarge['inventory_checks']['padding']) === 7000, 'existing owner observations preserved');
    $decodeContext = static function (string $prompt): array {
        $json = substr($prompt, strpos($prompt, '{'));
        return json_decode(substr($json, 0, strrpos($json, '}') + 1), true, flags: JSON_THROW_ON_ERROR);
    };
    $ownerState = ['messages' => [], 'memories' => [['text' => 'durable-owner-marker']]];
    $ownerPrompt = \App\Services\Hermes\HermesConversationMemory::context($ownerState, $ownerLarge, 'current');
    $ownerPayload = $decodeContext($ownerPrompt);
    $assert(isset($ownerPayload['application_state']['approved_business_knowledge']['references'][0]['citation']), 'actual owner caller retains cited optional knowledge');
    $assert(in_array('durable-owner-marker', $ownerPayload['durable_owner_memory'], true), 'caller retains existing owner memory');
    $assert(strlen($ownerPayload['application_state']['inventory_checks']['padding']) === 7000, 'caller retains existing diagnostics');
    $assert(mb_strlen(json_encode($ownerPayload, JSON_UNESCAPED_UNICODE)) <= 9000, 'actual owner JSON budget holds');
    $assert(mb_strlen($ownerPrompt) + 4000 < 16000, 'actual owner relay budget holds');
    $baseRuntime = $ownerLarge; unset($baseRuntime['approved_business_knowledge']);
    $basePayload = $decodeContext(\App\Services\Hermes\HermesConversationMemory::context($ownerState, $baseRuntime, 'current'));
    $comparePayload = $ownerPayload; unset($comparePayload['application_state']['approved_business_knowledge']);
    unset($basePayload['checked_at'], $comparePayload['checked_at']);
    $assert($basePayload === $comparePayload, 'optional guidance never changes original retained observations or memories');
    for ($size = 7500; $size <= 9300; $size += 100) {
        $runtime = $baseRuntime; $runtime['inventory_checks']['padding'] = str_repeat('x', $size);
        $without = $decodeContext(\App\Services\Hermes\HermesConversationMemory::context($ownerState, $runtime, 'current'));
        $runtime['approved_business_knowledge'] = $ownerLarge['approved_business_knowledge'];
        $withPrompt = \App\Services\Hermes\HermesConversationMemory::context($ownerState, $runtime, 'current');
        $with = $decodeContext($withPrompt);
        unset($with['application_state']['approved_business_knowledge'], $with['checked_at'], $without['checked_at']);
        $assert($with === $without, 'optional guidance preserves original compression at size '.$size);
        $assert(mb_strlen($withPrompt) + 4000 < 16000, 'relay remains bounded at size '.$size);
    }
    $tight = $baseRuntime;
    $tight['inventory_checks']['padding'] = str_repeat('x', 7900);
    $tight['approved_business_knowledge'] = $ownerLarge['approved_business_knowledge'];
    $tightPrompt = \App\Services\Hermes\HermesConversationMemory::context($ownerState, $tight, 'current');
    $assert(str_contains($tightPrompt, 'Approved business runbook context:'), 'caller explicitly reports inclusion or omission state');
    \App\Services\Hermes\HermesSupportPlaybooks::$padding = str_repeat('x', 23000);
    $large = $operations->evidence(new \App\Models\Ticket, 'SMTP');
    $assert(! isset($large['approved_business_knowledge']), 'optional guidance omitted before overflowing writer');
    $assert(strlen($large['support_playbooks']['padding']) === 23000, 'existing diagnostic/playbook payload never discarded');
    Schema::drop('growth_documents');
    $assert($operations->evidence(new \App\Models\Ticket)['approved_business_knowledge']['available'] === false, 'missing growth schema preserves native evidence path');
    echo json_encode(['status' => 'passed', 'assertions' => $checks, 'database' => 'sqlite_memory', 'model' => 'captured_fake', 'external_calls' => 0], JSON_THROW_ON_ERROR).PHP_EOL;
}
