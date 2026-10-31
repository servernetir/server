<?php

namespace App\Services\Hermes;

use App\Models\Ticket;
use App\Models\Setting;
use App\Services\Bale\Admin\AdminBaleGate;
use App\Services\Bale\Admin\AdminBaleRouter;
use App\Services\Bale\BaleSender;
use App\Services\Cloud\CloudManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/** Owner-only proposal delivery. This adapter never chooses a customer recipient. */
final class HermesSupportOwnerReview
{
    public function __construct(
        private HermesSupportState $state,
        private AdminBaleGate $gate,
        private BaleSender $sender,
        private HermesSupportDelivery $delivery,
        private HermesSupportPolicy $policy,
    ) {}

    private function approvals(): HermesSupportApproval
    {
        return new HermesSupportApproval($this->state, static fn (string $s): string => Crypt::decryptString($s));
    }

    /** Retry owner notices independently of model generation; never regenerate a shown reply. */
    public function notifyPending(int $limit = 5): array
    {
        $report = ['notified' => 0, 'failed' => 0];
        $binding = $this->gate->binding();
        $owner = $this->gate->boundUser();
        if (! $this->gate->enabled() || ! $binding || ! $owner) {
            return $report;
        }
        $processed = 0;
        foreach ($this->state->read()['items'] as $key => $item) {
            if ($processed >= max(1, min(10, $limit))) {
                break;
            }
            if (! in_array($item['status'] ?? '', ['review_required', 'awaiting_approval', 'excluded'], true)
                || isset($item['owner_notified_at']) || isset($item['owner_notice_at'])
                || ($this->compactEnabled() && $this->previewCurrent($item, (string) $owner->id, (string) $binding['chat_id']))
                || (int) ($item['owner_notify_retry_at'] ?? 0) > time()) {
                continue;
            }
            ++$processed;
            $lock = Cache::lock('hermes:support-owner:'.hash('sha256', $key), 120);
            if (! $lock->get()) {
                continue;
            }
            try {
                $item = $this->state->item($key) ?? [];
                if (isset($item['owner_notified_at']) || isset($item['owner_notice_at'])
                    || ($this->compactEnabled() && $this->previewCurrent($item, (string) $owner->id, (string) $binding['chat_id']))) {
                    continue;
                }
                $fresh = $this->fresh($item);
                if (! $this->current($item, $fresh)) {
                    $this->state->put($key, ['status' => 'stale', 'finished_at' => time()]);
                    continue;
                }
                if (empty($item['draft_ciphertext']) || ($item['status'] ?? '') === 'excluded' || ! empty($item['investigation_required'])) {
                    // Do not copy credential-bearing ticket subjects, bodies or attachments to Bale/model.
                    $text = '⚠️ هرمس — تیکت #'.(int) $item['ticket_id']."\n";
                    if (! empty($item['investigation_required'])) {
                        $analysis = Crypt::decryptString($item['analysis_ciphertext'] ?? '');
                        if ($this->policy->classify('', $analysis, 'technical') === 'credentials' || mb_strlen($analysis) > 3000) {
                            throw new \RuntimeException('Invalid investigation notice');
                        }
                        $text .= "نیازمند تکمیل بررسی داخلی؛ تأیید ارسال پاسخ ندارد.\n".$analysis;
                    } else { $text .= ($item['reason'] ?? '') === 'credentials'
                        ? 'متن دارای اطلاعات حساس است؛ برای بررسی امن، تیکت را در پنل باز کن. هیچ پاسخی ارسال نشده است.'
                        : 'تهیهٔ پاسخ پیشنهادی ممکن نشد؛ بررسی دستی در پنل لازم است. هیچ پاسخی ارسال نشده است.'; }
                    $compact = $this->compactEnabled();
                    if ($compact) {
                        $context = $this->context($item, ($item['reason'] ?? '') !== 'credentials');
                        $text = ! empty($item['investigation_required'])
                            ? HermesOwnerNotification::investigation($context, $analysis)
                            : HermesOwnerNotification::failure($context, (string) ($item['reason'] ?? 'writer_unavailable'));
                    }
                    if (! $this->sameOwner($owner->id, (string) $binding['chat_id'])) {
                        throw new \RuntimeException('Owner changed');
                    }
                    $sent = $compact
                        ? $this->sender->sendButtons((string) $binding['chat_id'], $text, $this->ticketRows((int) $item['ticket_id'])) !== null
                        : $this->sender->send((string) $binding['chat_id'], $text);
                    if (! $sent) {
                        throw new \RuntimeException('Owner notice unavailable');
                    }
                    $this->state->put($key, ['owner_notice_at' => time(), 'notice_owner_id' => (string) $owner->id,
                        'notice_chat_id' => (string) $binding['chat_id']]);
                } else {
                    $proposal = $this->approvals()->prepare($key, (string) $owner->id, (string) $binding['chat_id']);
                    if ($proposal === null) {
                        // Revoked/expired proposals must not starve later owner notifications.
                        if (($item['status'] ?? '') === 'awaiting_approval'
                            && ((string) ($item['approval_owner_id'] ?? '') !== (string) $owner->id
                                || (string) ($item['approval_chat_id'] ?? '') !== (string) $binding['chat_id'])) {
                            $this->state->put($key, ['status' => 'invalidated', 'reason' => 'owner_binding_changed', 'finished_at' => time()]);
                        }
                        continue;
                    }
                    $analysis = isset($item['analysis_ciphertext']) ? Crypt::decryptString($item['analysis_ciphertext'])
                        : 'تحلیل این پیش‌نویس قبلی در دسترس نیست؛ متن و تیکت را پیش از تأیید بررسی کن.';
                    if ($this->policy->classify('', $analysis."\n".$proposal['body'], 'technical') === 'credentials') {
                        throw new \RuntimeException('Sensitive proposal blocked');
                    }
                    $compact = $this->compactEnabled();
                    $text = $compact ? HermesOwnerNotification::proposal($this->context($item), $proposal['body'], $analysis)
                        : self::card((int) $item['ticket_id'], $analysis, $proposal['body']);
                    $prefix = AdminBaleRouter::CB_PREFIX;
                    $rows = [[
                        ['text' => $compact ? '✅ متن کامل و تأیید' : '✅ تأیید و ارسال همین پاسخ', 'data' => $prefix.($compact ? 'hsv:' : 'hsa:').$proposal['token']],
                        ['text' => '❌ رد پیشنهاد', 'data' => $prefix.'hsr:'.$proposal['token']],
                    ], [['text' => '📋 مشاهدهٔ تیکت', 'data' => $prefix.'t:'.(int) $item['ticket_id']]]];
                    if ($compact) { $rows[] = [['text' => '✍️ خودم می‌نویسم', 'data' => $prefix.'tw:'.(int) $item['ticket_id']]]; }
                    if (! $this->sameOwner($owner->id, (string) $binding['chat_id'])) {
                        throw new \RuntimeException('Owner changed');
                    }
                    $id = $this->sender->sendButtons((string) $binding['chat_id'], $text, $rows);
                    if ($id === null) {
                        throw new \RuntimeException('Owner proposal unavailable');
                    }
                    if ($compact) {
                        // Preview is not the approval receipt: only showFull() can
                        // attest that the exact unabridged draft was displayed.
                        $this->state->put($key, ['owner_preview_at' => time(), 'preview_owner_id' => (string) $owner->id,
                            'preview_chat_id' => (string) $binding['chat_id'], 'preview_message_id' => $id,
                            'owner_preview_token' => $proposal['token'], 'owner_preview_draft_sha256' => hash('sha256', $proposal['body'])]);
                    } elseif (! $this->approvals()->markNotified($proposal['token'], $id)) {
                        throw new \RuntimeException('Owner proposal unavailable');
                    }
                }
                ++$report['notified'];
            } catch (Throwable) {
                ++$report['failed'];
                // Only an owner card can be retried; no customer dispatch is performed here.
                $this->state->put($key, ['owner_notify_retry_at' => time() + 300]);
                $this->state->error('owner_proposal_unavailable', bin2hex(random_bytes(12)), ['ticket_id' => $item['ticket_id'] ?? 0], true);
            } finally {
                $lock->release();
            }
        }
        return $report;
    }

    public static function card(int $ticketId, string $analysis, string $reply): string
    {
        $text = '📨 هرمس — تیکت #'.$ticketId."\n\nبررسی برای تو:\n".$analysis
            ."\n\nپاسخ پیشنهادی به مشتری:\n".$reply
            ."\n\nهنوز ارسال نشده است. تأیید فقط همین متن را می‌فرستد؛ پیشنهاد ۲۴ ساعت اعتبار دارد.";
        // Never show a truncated reply and then send a longer, unseen version.
        if (mb_strlen($text) > 4000) {
            throw new \RuntimeException('Proposal exceeds owner card limit');
        }
        return $text;
    }

    /** Explicit owner request only: show the original full draft before approval. */
    public function showFull(string $token, string $from, string $chat): string
    {
        if (! preg_match('/^[a-f0-9]{32}$/D', $token)) { return 'invalid'; }
        $lock = Cache::lock('hermes:support-full-view:'.hash('sha256', $token), 60);
        if (! $lock->get()) { return 'unavailable'; }
        try {
            return $this->showFullLocked($token, $from, $chat);
        } catch (Throwable) {
            return 'unavailable';
        } finally { $lock->release(); }
    }

    private function showFullLocked(string $token, string $from, string $chat): string
    {
        $owner = $this->gate->boundUser();
        if (! preg_match('/^[a-f0-9]{32}$/D', $token) || ! $owner || ! $this->gate->isBoundChat($from)
            || ! hash_equals($from, $chat) || ! $this->sameOwner($owner->id, $chat)) { return 'invalid'; }
        foreach ($this->state->read()['items'] as $key => $item) {
            if (! hash_equals((string) ($item['approval_token'] ?? ''), $token)) { continue; }
            if (! empty($item['investigation_required']) || ! $this->current($item, $this->fresh($item))
                || (string) ($item['approval_owner_id'] ?? '') !== (string) $owner->id
                || (string) ($item['approval_chat_id'] ?? '') !== $chat) { return 'stale'; }
            $proposal = $this->approvals()->prepare($key, (string) $owner->id, $chat);
            if ($proposal === null || ! hash_equals($token, $proposal['token'])) { return 'stale'; }
            $analysis = isset($item['analysis_ciphertext']) ? Crypt::decryptString($item['analysis_ciphertext']) : 'تحلیل قبلی در دسترس نیست؛ تیکت را بررسی کن.';
            if ($this->policy->classify('', $analysis."\n".$proposal['body'], 'technical') === 'credentials') { return 'invalid'; }
            $prefix = AdminBaleRouter::CB_PREFIX;
            $rows = [[['text' => '✅ تأیید و ارسال همین متن کامل', 'data' => $prefix.'hsa:'.$token],
                ['text' => '❌ رد پیشنهاد', 'data' => $prefix.'hsr:'.$token]],
                [['text' => '✍️ خودم می‌نویسم', 'data' => $prefix.'tw:'.(int) $item['ticket_id']]]];
            if (! $this->sameOwner($owner->id, $chat)) { return 'invalid'; }
            $parts = self::fullCards((int) $item['ticket_id'], $analysis, $proposal['body']);
            $id = null;
            foreach ($parts as $index => $part) {
                if (! $this->sameOwner($owner->id, $chat)) { return 'invalid'; }
                if ($index < count($parts) - 1) {
                    if (! $this->sender->send($chat, $part)) { return 'unavailable'; }
                } else { $id = $this->sender->sendButtons($chat, $part, $rows); }
            }
            return $id !== null && $this->approvals()->markNotified($token, $id) ? 'shown' : 'unavailable';
        }
        return 'stale';
    }

    /** Preserve every character of a long draft; approval follows confirmed delivery of every part. */
    public static function fullCards(int $ticketId, string $analysis, string $reply): array
    {
        $analysis = HermesOwnerNotification::excerpt($analysis, 500);
        $single = '📨 هرمس — تیکت #'.$ticketId."\n\nخلاصه بررسی:\n".$analysis
            ."\n\nمتن کامل پاسخ به مشتری:\n".$reply
            ."\n\nهنوز ارسال نشده است. تأیید فقط همین متن کامل را می‌فرستد؛ پیشنهاد ۲۴ ساعت اعتبار دارد.";
        if (mb_strlen($single) <= 4000) { return [$single]; }
        $parts = [];
        $count = (int) ceil(mb_strlen($reply) / 3000);
        for ($index = 0; $index < $count; $index++) {
            $parts[] = '📨 هرمس — تیکت #'.$ticketId.'؛ متن کامل پاسخ، بخش '.($index + 1).' از '.$count
                .($index === 0 ? "\nخلاصه بررسی: ".$analysis : '')
                ."\n\n".mb_substr($reply, $index * 3000, 3000)
                .($index === $count - 1 ? "\n\nهنوز ارسال نشده؛ تأیید، متن کامل همه بخش‌های بالا را می‌فرستد." : '');
        }
        return $parts;
    }

    private function compactEnabled(): bool
    {
        return Setting::get('bale_manager_policy_v2_enabled') === '1';
    }

    private function previewCurrent(array $item, string $ownerId, string $chat): bool
    {
        if (($item['status'] ?? '') !== 'awaiting_approval' || ! isset($item['owner_preview_at'])
            || (int) ($item['approval_expires_at'] ?? 0) <= time()
            || (string) ($item['preview_owner_id'] ?? '') !== $ownerId
            || (string) ($item['preview_chat_id'] ?? '') !== $chat
            || ! preg_match('/^[a-f0-9]{32}$/D', (string) ($item['approval_token'] ?? ''))
            || ! hash_equals((string) ($item['owner_preview_token'] ?? ''), (string) $item['approval_token'])
            || ! hash_equals((string) ($item['owner_preview_draft_sha256'] ?? ''), (string) ($item['approval_draft_sha256'] ?? ''))) { return false; }
        try {
            return hash_equals((string) $item['owner_preview_draft_sha256'], hash('sha256', Crypt::decryptString($item['draft_ciphertext'] ?? '')));
        } catch (Throwable) { return false; }
    }

    private function ticketRows(int $ticketId): array
    {
        $prefix = AdminBaleRouter::CB_PREFIX;
        return [[['text' => '📋 جزئیات تیکت', 'data' => $prefix.'t:'.$ticketId],
            ['text' => '✍️ پاسخ دستی', 'data' => $prefix.'tw:'.$ticketId]]];
    }

    /** Read the exact recorded customer/message evidence, never infer an owner from prose. */
    private function context(array $item, bool $includeQuestion = true): array
    {
        $result = ['ticket_id' => (int) ($item['ticket_id'] ?? 0)];
        try {
            $ticket = Ticket::query()->with('customer')->whereKey($result['ticket_id'])
                ->where('customer_id', (int) ($item['customer_id'] ?? 0))->first();
            if (! $ticket) { return $result; }
            $result += ['ticket_number' => (string) $ticket->number,
                'customer_name' => (string) ($ticket->customer?->displayName() ?? ''),
                'customer_code' => (string) ($ticket->customer?->code ?? '')];
            if ($includeQuestion) {
                $result['question'] = (string) $ticket->visibleMessages()->whereKey((int) ($item['customer_message_id'] ?? 0))
                    ->where('author_role', 'customer')->value('body');
            }
            $operations = app(HermesOperations::class);
            $owner = $operations->owner();
            if (! $owner) { return $result; }
            $state = app(HermesOperationsStore::class)->read($owner);
            $task = $state['tasks']['ticket:'.$ticket->id] ?? [];
            $service = $task['evidence']['dossier']['service'] ?? [];
            if ((int) ($task['message_id'] ?? 0) !== (int) ($item['customer_message_id'] ?? 0)
                || (int) ($service['customer_id'] ?? 0) !== (int) $ticket->customer_id) { return $result; }
            $provider = (string) ($service['cloud']['provider'] ?? '');
            $result += ['service_id' => (int) ($service['id'] ?? 0),
                'provider_label' => CloudManager::REAL_NAMES[$provider] ?? (! empty($service['server_id']) ? 'میزبانی مدیریت‌شده' : 'نامشخص'),
                'ipv4' => $service['cloud']['recorded_ipv4'] ?? null];
        } catch (Throwable) { /* Explicit unknown fields remain visible; never print raw failures. */ }
        return $result;
    }

    public function decide(string $token, string $from, string $chat, bool $approve): string
    {
        $binding = $this->gate->binding();
        $owner = $this->gate->boundUser();
        if (! $this->gate->enabled() || ! $binding || ! $owner || ! $this->gate->isBoundChat($from)
            || ! hash_equals((string) $binding['chat_id'], $chat) || ! hash_equals($chat, $from)) {
            return 'invalid';
        }
        if (! $approve && $this->compactEnabled()) {
            $rejected = $this->rejectPreview($token, (string) $owner->id, $chat);
            if ($rejected !== null) { return $rejected; }
        }
        return $this->approvals()->decide($token, (string) $owner->id, $chat, $approve,
            fn (array $item): ?array => $this->fresh($item),
            function (array $item, string $body, callable $written) use ($owner, $chat): ?int {
                return $this->sameOwner($owner->id, $chat) ? $this->delivery->postApproved($item, $body, $written) : null;
            });
    }

    /** A preview may be rejected without authorizing unseen text for delivery. */
    private function rejectPreview(string $token, string $ownerId, string $chat): ?string
    {
        if (! preg_match('/^[a-f0-9]{32}$/D', $token)) { return null; }
        foreach ($this->state->read()['items'] as $key => $item) {
            if (! hash_equals((string) ($item['approval_token'] ?? ''), $token)
                || ! isset($item['owner_preview_at']) || isset($item['owner_notified_at'])) { continue; }
            return $this->state->withItemLock($key, function () use ($key, $token, $ownerId, $chat): string {
                $item = $this->state->item($key) ?? [];
                if (($item['status'] ?? '') !== 'awaiting_approval'
                    || ! hash_equals((string) ($item['approval_token'] ?? ''), $token)
                    || (string) ($item['preview_owner_id'] ?? '') !== $ownerId
                    || (string) ($item['preview_chat_id'] ?? '') !== $chat
                    || (string) ($item['approval_owner_id'] ?? '') !== $ownerId
                    || (string) ($item['approval_chat_id'] ?? '') !== $chat) { return 'invalid'; }
                if ((int) ($item['approval_expires_at'] ?? 0) <= time()) { return 'expired'; }
                $this->state->put($key, ['status' => 'rejected', 'rejected_at' => time(),
                    'decision_owner_id' => $ownerId, 'finished_at' => time()]);
                return 'rejected';
            }) ?? 'busy';
        }
        return null;
    }

    private function sameOwner(int|string $ownerId, string $chat): bool
    {
        $binding = $this->gate->binding();
        $owner = $this->gate->boundUser();
        return $this->gate->enabled() && $binding && $owner
            && hash_equals((string) $ownerId, (string) $owner->id) && hash_equals($chat, (string) $binding['chat_id']);
    }

    private function fresh(array $item): ?array
    {
        $ticket = Ticket::query()->find((int) ($item['ticket_id'] ?? 0));
        $message = $ticket?->visibleMessages()->orderByDesc('id')->first();
        return ! $ticket || ! $message ? null : ['ticket_id' => (int) $ticket->id, 'customer_id' => (int) $ticket->customer_id,
            'status' => (string) $ticket->status, 'message_id' => (int) $message->id, 'author_role' => (string) $message->author_role,
            'source_content_sha256' => HermesSupportSnapshot::capture($ticket)['source_content_sha256']];
    }

    private function current(array $item, ?array $fresh): bool
    {
        return $fresh && $fresh['status'] === 'open' && $fresh['author_role'] === 'customer'
            && $fresh['ticket_id'] === (int) ($item['ticket_id'] ?? 0)
            && $fresh['customer_id'] === (int) ($item['customer_id'] ?? 0)
            && $fresh['message_id'] === (int) ($item['customer_message_id'] ?? 0)
            && (! isset($item['source_content_sha256']) || hash_equals($item['source_content_sha256'], $fresh['source_content_sha256']));
    }
}

