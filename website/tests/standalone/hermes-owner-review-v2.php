<?php
/** Isolated Composer-only test. No live app bootstrap, model, database or external sends. */
namespace {
    if (PHP_SAPI !== 'cli') { exit(1); }
    $root = $argv[2] ?? dirname(__DIR__, 3);
    $loader = require $argv[1];
    $loader->setClassMapAuthoritative(false);
}
namespace App\Models {
    class Setting { public static bool $enabled = true; public static function get($key) { return self::$enabled ? '1' : '0'; } }
    class Ticket {
        public static string $hash = '';
        public int $id = 11; public int $customer_id = 10; public string $number = 'TK-fixture-11'; public string $status = 'open';
        public $customer;
        public function __construct() { $this->customer = new class { public string $code = 'SN-123456'; public function displayName() { return 'مشتری آزمایشی'; } }; }
        public static function query() { return new class { public function __call($name, $args) { return $name === 'first' || $name === 'find' ? new Ticket : $this; } }; }
        public function visibleMessages() { return new class { public function __call($name, $args) { return $name === 'first' ? (object) ['id' => 20, 'author_role' => 'customer'] : ($name === 'value' ? 'سرور وصل نمی‌شود' : $this); } }; }
    }
}
namespace App\Services\Bale\Admin {
    class AdminBaleRouter { public const CB_PREFIX = 'v1:'; }
    class AdminBaleGate {
        public bool $on = true;
        public function enabled() { return $this->on; }
        public function binding() { return ['chat_id' => '200']; }
        public function boundUser() { return (object) ['id' => 100]; }
        public function isBoundChat($chat) { return $chat === '200'; }
    }
}
namespace App\Services\Bale {
    class BaleSender {
        public array $sent = []; public bool $fail = false;
        public function send($chat, $text) { $this->sent[] = ['chat' => $chat, 'text' => $text, 'rows' => []]; return ! $this->fail; }
        public function sendButtons($chat, $text, $rows) { $this->sent[] = compact('chat', 'text', 'rows'); return $this->fail ? null : count($this->sent); }
    }
}
namespace App\Services\Hermes {
    class HermesSupportPolicy { public function classify($subject, $text, $department) { return str_contains($text, 'sensitive-marker') ? 'credentials' : 'technical'; } }
    class HermesSupportDelivery { public array $bodies = []; public function postApproved($item, $body, $written) { $this->bodies[] = $body; $written(1001); return 1001; } }
    class HermesSupportSnapshot { public static function capture($ticket) { return ['source_content_sha256' => \App\Models\Ticket::$hash]; } }
    class HermesOperations { public function owner() { return ['user_id' => '100', 'chat_id' => '200']; } }
    class HermesOperationsStore {
        public static int $serviceCustomer = 10;
        public function read($owner) { return ['tasks' => ['ticket:11' => ['message_id' => 20, 'evidence' => ['dossier' => ['service' => [
            'id' => 88, 'customer_id' => self::$serviceCustomer, 'cloud' => ['provider' => 'hetzner', 'recorded_ipv4' => '192.0.2.10']]]]]]]; }
    }
}
namespace {
    use App\Services\Hermes\HermesSupportState;
    use App\Services\Hermes\HermesSupportOwnerReview;
    use Illuminate\Support\Facades\Crypt;
    $container = new \Illuminate\Container\Container;
    \Illuminate\Container\Container::setInstance($container);
    \Illuminate\Support\Facades\Facade::setFacadeApplication($container);
    Crypt::swap(new \Illuminate\Encryption\Encrypter(str_repeat('x', 32), 'AES-256-CBC'));
    \Illuminate\Support\Facades\Cache::swap(new class { public function lock($key, $seconds) { return new class { public function get() { return true; } public function release() {} }; } });
    $container->instance(\App\Services\Hermes\HermesOperations::class, new \App\Services\Hermes\HermesOperations);
    $container->instance(\App\Services\Hermes\HermesOperationsStore::class, new \App\Services\Hermes\HermesOperationsStore);
    require $root.'/website/tests/Fixtures/hermes-bale-manager/HermesSupportState.php';
    require $root.'/website/tests/Fixtures/hermes-bale-manager/HermesSupportApproval.php';
    require $root.'/website/app/Services/Cloud/CloudManager.php';
    require $root.'/website/app/Services/Hermes/HermesOwnerNotification.php';
    require $root.'/infra/hermes/bale-manager-20261031/HermesSupportOwnerReview.php';
    $dir = sys_get_temp_dir().'/hermes-owner-v2-'.bin2hex(random_bytes(8));
    register_shutdown_function(static function () use ($dir) { foreach (glob($dir.'/*') ?: [] as $p) { if (is_file($p)) { unlink($p); } } if (is_file($dir.'/.lock')) { unlink($dir.'/.lock'); } if (is_dir($dir)) { rmdir($dir); } });
    $state = new HermesSupportState($dir); $gate = new \App\Services\Bale\Admin\AdminBaleGate;
    $sender = new \App\Services\Bale\BaleSender; $delivery = new \App\Services\Hermes\HermesSupportDelivery;
    $review = new HermesSupportOwnerReview($state, $gate, $sender, $delivery, new \App\Services\Hermes\HermesSupportPolicy);
    $key = 'ticket:11:message:20'; $body = str_repeat('پاسخ اصلی به مشتری. ', 50);
    $checks = 0;
    $assert = static function (bool $ok, string $label) use (&$checks) { if (! $ok) { throw new \RuntimeException('FAILED: '.$label); } $checks++; };
    $put = static function (array $changes = []) use ($state, $key, $body) {
        $state->change(static function ($s) { $s['items'] = []; return $s; });
        \App\Models\Ticket::$hash = str_repeat('a', 64);
        $state->put($key, $changes + ['status' => 'review_required', 'ticket_id' => 11, 'customer_id' => 10, 'customer_message_id' => 20,
            'draft_ciphertext' => Crypt::encryptString($body), 'analysis_ciphertext' => Crypt::encryptString('شواهد موجود بررسی شد؛ نیازمند تأیید مدیر است.'),
            'source_content_sha256' => \App\Models\Ticket::$hash]);
    };
    $put();
    $result = $review->notifyPending(); $item = $state->item($key); $token = $item['approval_token'];
    $assert($result['notified'] === 1 && isset($item['owner_preview_at']) && ! isset($item['owner_notified_at']), 'compact preview is not a full-text approval receipt');
    $text = $sender->sent[0]['text']; $buttons = json_encode($sender->sent[0]['rows']);
    $assert(mb_strlen($text) < 1350 && str_contains($text, 'مشتری آزمایشی') && str_contains($text, 'Hetzner Cloud') && str_contains($text, '#88'), 'compact card has exact scoped customer/service/provider context');
    $assert(str_contains($buttons, 'hsv:'.$token) && ! str_contains($buttons, 'hsa:'.$token) && str_contains($buttons, 'tw:11'), 'preview only offers view-before-approve and manual reply');
    $assert($review->notifyPending()['notified'] === 0 && count($sender->sent) === 1, 'same compact proposal is not pushed twice');
    $assert($review->decide($token, '200', '200', true) === 'not_ready' && $delivery->bodies === [], 'unseen full draft cannot be approved');
    $assert($review->showFull($token, '300', '200') === 'invalid' && count($sender->sent) === 1, 'other chat cannot read full proposal');
    $assert($review->showFull($token, '200', '200') === 'shown', 'explicit owner action shows full text');
    $assert(str_contains($sender->sent[1]['text'], $body) && isset($state->item($key)['owner_notified_at']), 'exact full draft is displayed before original approval is armed');
    $assert($review->decide($token, '200', '200', true) === 'sent' && $delivery->bodies === [$body], 'real approval sends exact full stored draft');
    $assert($review->decide($token, '200', '200', true) === 'sent' && count($delivery->bodies) === 1, 'same approval cannot dispatch twice');
    $put(); $review->notifyPending(); $token = $state->item($key)['approval_token'];
    $assert($review->decide($token, '200', '200', false) === 'rejected' && count($delivery->bodies) === 1, 'compact preview can be rejected without customer send');
    $put(); $review->notifyPending(); $token = $state->item($key)['approval_token']; \App\Models\Ticket::$hash = str_repeat('b', 64);
    $assert($review->showFull($token, '200', '200') === 'stale' && ! isset($state->item($key)['owner_notified_at']), 'changed ticket cannot arm old full draft');
    $put(); $review->notifyPending(); $state->put($key, ['status' => 'review_required', 'approval_token' => null, 'owner_notified_at' => null, 'draft_ciphertext' => Crypt::encryptString('متن ویرایش‌شده')]);
    $assert($review->notifyPending()['notified'] === 1, 'edited draft obtains a fresh compact preview despite previous preview metadata');
    $put(['investigation_required' => true]); $result = $review->notifyPending(); $last = $sender->sent[array_key_last($sender->sent)];
    $assert($result['notified'] === 1 && mb_strlen($last['text']) <= 1100 && str_contains($last['text'], 'نیازمند بررسی فنی'), 'investigation is concise and actionable');
    $assert(! str_contains(json_encode($last['rows']), 'hsa:') && ! str_contains(json_encode($last['rows']), 'hsv:'), 'incomplete investigation has no approval button');
    \App\Models\Setting::$enabled = false; $put(); $review->notifyPending(); $item = $state->item($key);
    $assert(isset($item['owner_notified_at']) && ! isset($item['owner_preview_at']), 'gate-off legacy full-card behavior remains intact');
    \App\Models\Setting::$enabled = true;
    $longBody = str_repeat('segment-'.str_repeat('x', 300), 35);
    $put(['draft_ciphertext' => Crypt::encryptString($longBody)]); $review->notifyPending(); $token = $state->item($key)['approval_token'];
    $beforeSends = count($sender->sent);
    $assert($review->showFull($token, '200', '200') === 'shown' && count($sender->sent) > $beforeSends + 1, 'long full draft is displayed in confirmed bounded parts');
    $assert(max(array_map(fn ($row) => mb_strlen($row['text']), array_slice($sender->sent, $beforeSends))) <= 4000, 'every full-view transport message respects Bale limit');
    $assert($review->decide($token, '200', '200', true) === 'sent' && $delivery->bodies[array_key_last($delivery->bodies)] === $longBody, 'long draft approval still sends the exact untruncated stored body');
    echo json_encode(['status' => 'passed', 'checks' => $checks, 'real_customer_sends' => 0, 'real_model_calls' => 0]).PHP_EOL;
}
