<?php

namespace App\Services\Hermes;

use Closure;
use Throwable;

/** Pure workflow core; adapters supply the existing ticket and delivery services. */
final class HermesSupportProcessor
{
    private const TERMINAL = ['sent', 'sent_notification_error', 'review_required', 'awaiting_approval', 'rejected', 'expired', 'invalidated', 'excluded', 'stale', 'delivery_uncertain', 'dispatching', 'message_written'];

    public function __construct(
        private HermesSupportState $state,
        private HermesSupportPolicy $policy,
        private Closure $encrypt,
    ) {}

    /**
     * $fresh returns the latest visible message + canonical customer/ticket IDs.
     * $dispatch MUST use the shared reply service and call $written(replyId).
     * Ambiguous delivery is deliberately not retried automatically.
     */
    public function process(
        array $ticket,
        array $message,
        callable $fresh,
        callable $draft,
        callable $saveDraft,
        callable $dispatch,
        bool $allowSend,
        string $requestId,
    ): string {
        $key = 'ticket:'.$ticket['id'].':message:'.$message['id'];
        return $this->state->withItemLock($key, fn (): string => $this->processLocked($ticket, $message, $fresh, $draft, $saveDraft, $dispatch, $allowSend, $requestId)) ?? 'busy';
    }

    private function processLocked(
        array $ticket,
        array $message,
        callable $fresh,
        callable $draft,
        callable $saveDraft,
        callable $dispatch,
        bool $allowSend,
        string $requestId,
    ): string {
        $key = 'ticket:'.$ticket['id'].':message:'.$message['id'];
        $prior = $this->state->item($key);
        if ($prior && in_array($prior['status'] ?? '', self::TERMINAL, true)) {
            return $prior['status'];
        }
        if (($prior['next_retry_at'] ?? 0) > time()) {
            return 'retry_wait';
        }
        $attempt = (int) ($prior['attempts'] ?? 0) + 1;
        $base = [
            'ticket_id' => (int) $ticket['id'], 'customer_id' => (int) $ticket['customer_id'],
            'customer_message_id' => (int) $message['id'], 'attempts' => $attempt,
            'request_id' => $requestId, 'started_at' => time(),
        ];
        $this->state->put($key, $base + ['status' => 'queued']);
        if (! $this->matches($fresh(), $ticket, $message)) {
            $this->state->put($key, ['status' => 'stale', 'finished_at' => time()]);
            return 'stale';
        }
        // The immutable context is EXACTLY the bounded payload sent to the writer.
        $classification = $this->policy->classify((string) $ticket['subject'], (string) ($message['context'] ?? $message['body']), (string) $ticket['department']);
        if ($classification === 'credentials' || ($ticket['excluded'] ?? false)) {
            $this->state->put($key, ['status' => 'excluded', 'reason' => $classification === 'credentials' ? 'credentials' : 'owner_exclusion', 'finished_at' => time()]);
            return 'excluded';
        }
        try {
            $automatic = $allowSend ? $this->policy->automaticReply($classification, (string) $message['body']) : null;
            $body = $automatic ?? $draft();
            if (! is_string($body) || trim($body) === '') {
                return $this->draftFailure($key, $base, 'draft_unavailable');
            }
            $body = trim($body);
            // Secret-shaped output is not copied into a note or customer reply.
            if ($this->policy->classify('', $body, 'technical') === 'credentials') {
                return $this->draftFailure($key, $base, 'draft_contains_credentials', false);
            }
            $this->state->put($key, ['draft_ciphertext' => ($this->encrypt)($body), 'classification' => $classification, 'draft_created_at' => time(), 'automatic_template' => $automatic === null ? null : 'clarification_v1']);
            if (! $this->matches($fresh(), $ticket, $message)) {
                $this->state->put($key, ['status' => 'stale', 'finished_at' => time()]);
                return 'stale';
            }
            if (! $allowSend || ! $this->policy->maySend($classification, $body, (string) $message['body'])) {
                $noteId = $saveDraft($body, $classification);
                $this->state->put($key, ['status' => 'review_required', 'reason' => $classification, 'internal_note_id' => $noteId, 'finished_at' => time()]);
                return 'review_required';
            }
        } catch (Throwable) {
            return $this->draftFailure($key, $base, 'draft_or_note_failed');
        }

        // Persist BEFORE delivery. A crash at any later point cannot resend it.
        $this->state->put($key, ['status' => 'dispatching', 'dispatch_started_at' => time()]);
        if (! $this->matches($fresh(), $ticket, $message)) {
            $this->state->put($key, ['status' => 'stale', 'finished_at' => time()]);
            return 'stale';
        }
        try {
            $replyId = $dispatch($body, function (int $id) use ($key): void {
                $this->state->put($key, ['status' => 'message_written', 'reply_id' => $id]);
            });
            if (! is_int($replyId) || $replyId < 1) {
                $this->state->put($key, ['status' => 'stale', 'finished_at' => time()]);
                return 'stale';
            }
            $this->state->put($key, ['status' => 'sent', 'reply_id' => $replyId, 'finished_at' => time()]);
            return 'sent';
        } catch (Throwable) {
            $record = $this->state->item($key) ?? [];
            $status = isset($record['reply_id']) ? 'sent_notification_error' : 'delivery_uncertain';
            $this->state->put($key, ['status' => $status, 'finished_at' => time()]);
            $this->state->error($status, $requestId, $base + ['reply_id' => $record['reply_id'] ?? null], false);
            return $status;
        }
    }

    private function draftFailure(string $key, array $base, string $code, bool $retryable = true): string
    {
        $retry = $retryable && $base['attempts'] < 3;
        $this->state->put($key, [
            'status' => $retry ? 'retry' : 'review_required', 'reason' => $code,
            'next_retry_at' => $retry ? time() + [1 => 300, 2 => 900][$base['attempts']] : null,
            'finished_at' => time(),
        ]);
        $this->state->error($code, $base['request_id'], $base + ['attempt' => $base['attempts']], $retry);
        return $retry ? 'retry' : 'review_required';
    }

    private function matches(?array $fresh, array $ticket, array $message): bool
    {
        return $fresh !== null
            && ($fresh['status'] ?? '') === 'open'
            && (int) ($fresh['ticket_id'] ?? 0) === (int) $ticket['id']
            && (int) ($fresh['customer_id'] ?? 0) === (int) $ticket['customer_id']
            && (int) ($fresh['message_id'] ?? 0) === (int) $message['id']
            && ($fresh['author_role'] ?? '') === 'customer';
    }
}
