<?php

namespace App\Services\Hermes;

use Closure;
use RuntimeException;
use Throwable;

/**
 * One proposal, one explicit owner decision, one exact public reply.
 *
 * The adapter must revalidate the CURRENT owner binding before invoking decide().
 * $fresh receives the persisted item and returns canonical ticket/customer/latest
 * public message metadata. $dispatch must retain the atomic database guard and
 * call $written(replyId) AFTER commit and BEFORE customer notifications.
 */
final class HermesSupportApproval
{
    private const DECIDED = ['sent', 'sent_notification_error', 'rejected', 'stale', 'expired', 'invalidated', 'delivery_uncertain', 'dispatching', 'message_written'];
    private const LIFETIME = 86400;

    public function __construct(private HermesSupportState $state, private Closure $decrypt) {}

    /**
     * Prepare before notifying the owner. A retry returns the same token and exact
     * body; it never replaces an existing proposal. Analysis is encrypted by the
     * adapter and is deliberately not accepted or persisted as plaintext here.
     *
     * @return array{token:string,body:string,item:array}|null
     */
    public function prepare(string $key, string $ownerId, string $chatId): ?array
    {
        if (! $this->validIdentity($ownerId, $chatId)) {
            return null;
        }
        return $this->state->withItemLock($key, function () use ($key, $ownerId, $chatId): ?array {
            $item = $this->state->item($key);
            if (! $item || ! empty($item['investigation_required']) || ! $this->validSource($key, $item) || ! $this->validContentHash($item)) {
                return null;
            }
            $body = $this->body($item);
            if ($body === null) {
                return null;
            }
            if (($item['status'] ?? '') === 'awaiting_approval') {
                if ((int) ($item['approval_expires_at'] ?? 0) <= time()) {
                    $this->state->put($key, ['status' => 'expired', 'finished_at' => time()]);
                    return null;
                }
                if (! $this->bound($item, $ownerId, $chatId)
                    || ! $this->validToken((string) ($item['approval_token'] ?? ''))
                    || (int) ($item['approval_expires_at'] ?? 0) <= time()
                    || ! $this->sameDraft($item, $body)) {
                    return null;
                }
                return ['token' => $item['approval_token'], 'body' => $body, 'item' => $item];
            }
            if (($item['status'] ?? '') !== 'review_required') {
                return null;
            }
            $token = bin2hex(random_bytes(16));
            $this->state->put($key, [
                'status' => 'awaiting_approval', 'approval_token' => $token,
                'approval_owner_id' => $ownerId, 'approval_chat_id' => $chatId,
                'approval_draft_sha256' => hash('sha256', $body),
                'approval_source_sha256' => $this->sourceHash($item),
                'approval_source_content_sha256' => ($item['source_content_sha256'] ?? '') ?: null,
                'approval_created_at' => time(), 'approval_expires_at' => time() + self::LIFETIME,
                'owner_notified_at' => null, 'owner_message_id' => null,
            ]);
            return ['token' => $token, 'body' => $body, 'item' => $this->state->item($key)];
        });
    }

    /** Called only after the owner notification transport confirms its message. */
    public function markNotified(string $token, int $messageId): bool
    {
        if ($messageId < 1 || ($key = $this->keyForToken($token)) === null) {
            return false;
        }
        return $this->state->withItemLock($key, function () use ($key, $token, $messageId): bool {
            $item = $this->state->item($key);
            if (! $item || ! hash_equals((string) ($item['approval_token'] ?? ''), $token)
                || ($item['status'] ?? '') !== 'awaiting_approval'
                || (int) ($item['approval_expires_at'] ?? 0) <= time()) {
                return false;
            }
            // The first confirmed owner message is the proposal receipt. A later
            // notification retry cannot change what an accepted decision binds.
            if ((int) ($item['owner_notified_at'] ?? 0) > 0) {
                return true;
            }
            $this->state->put($key, ['owner_notified_at' => time(), 'owner_message_id' => $messageId]);
            return true;
        }) ?? false;
    }

    public function decide(
        string $token,
        string $ownerId,
        string $chatId,
        bool $approve,
        callable $fresh,
        callable $dispatch,
    ): string {
        if (! $this->validIdentity($ownerId, $chatId) || ($key = $this->keyForToken($token)) === null) {
            return 'invalid';
        }
        return $this->state->withItemLock($key, function () use ($key, $token, $ownerId, $chatId, $approve, $fresh, $dispatch): string {
            $item = $this->state->item($key);
            if (! $item || ! hash_equals((string) ($item['approval_token'] ?? ''), $token)
                || ! $this->bound($item, $ownerId, $chatId) || ! $this->validSource($key, $item)) {
                return 'invalid';
            }
            if (in_array($item['status'] ?? '', self::DECIDED, true)) {
                return $item['status'];
            }
            if (($item['status'] ?? '') !== 'awaiting_approval'
                || (int) ($item['owner_notified_at'] ?? 0) < 1 || (int) ($item['owner_message_id'] ?? 0) < 1) {
                return 'not_ready';
            }
            if (! empty($item['investigation_required'])) { return 'not_ready'; }
            if ((int) ($item['approval_expires_at'] ?? 0) <= time()) {
                $this->state->put($key, ['status' => 'expired', 'finished_at' => time()]);
                return 'expired';
            }
            $body = $this->body($item);
            if ($body === null || ! $this->sameDraft($item, $body)) {
                $this->state->put($key, ['status' => 'invalidated', 'reason' => 'proposal_changed', 'finished_at' => time()]);
                return 'invalidated';
            }
            if (! $approve) {
                $this->state->put($key, ['status' => 'rejected', 'rejected_at' => time(), 'decision_owner_id' => $ownerId, 'finished_at' => time()]);
                return 'rejected';
            }
            try {
                if (! $this->matches($fresh($item), $item)) {
                    $this->state->put($key, ['status' => 'stale', 'finished_at' => time()]);
                    return 'stale';
                }
            } catch (Throwable) {
                // A read failure grants no send permission; the owner may retry.
                return 'unavailable';
            }
            $this->state->put($key, [
                'status' => 'dispatching', 'approved_at' => time(),
                'approved_by' => $ownerId, 'approved_chat_id' => $chatId,
                'approved_draft_sha256' => hash('sha256', $body),
                'dispatch_started_at' => time(),
            ]);
            try {
                $replyId = $dispatch($this->state->item($key), $body, function (int $id) use ($key): void {
                    $current = $this->state->item($key);
                    if ($id < 1 || (isset($current['reply_id']) && (int) $current['reply_id'] !== $id)) {
                        throw new RuntimeException('Invalid approved delivery receipt');
                    }
                    $this->state->put($key, ['status' => 'message_written', 'reply_id' => $id]);
                });
                $current = $this->state->item($key) ?? [];
                if ($replyId === null && ! isset($current['reply_id'])) {
                    // The atomic adapter declined because canonical state changed.
                    $this->state->put($key, ['status' => 'stale', 'finished_at' => time()]);
                    return 'stale';
                }
                if (! is_int($replyId) || $replyId < 1 || (int) ($current['reply_id'] ?? 0) !== $replyId) {
                    throw new RuntimeException('Unconfirmed approved delivery');
                }
                $this->state->put($key, ['status' => 'sent', 'reply_id' => $replyId, 'finished_at' => time()]);
                return 'sent';
            } catch (Throwable) {
                $current = $this->state->item($key) ?? [];
                $status = isset($current['reply_id']) ? 'sent_notification_error' : 'delivery_uncertain';
                $this->state->put($key, ['status' => $status, 'finished_at' => time()]);
                $this->state->error($status, (string) ($item['request_id'] ?? 'owner-approval'), $item + ['reply_id' => $current['reply_id'] ?? null], false);
                return $status;
            }
        }) ?? 'busy';
    }

    private function keyForToken(string $token): ?string
    {
        if (! $this->validToken($token)) {
            return null;
        }
        foreach ($this->state->read()['items'] as $key => $item) {
            if (is_string($item['approval_token'] ?? null) && hash_equals($item['approval_token'], $token)) {
                return $key;
            }
        }
        return null;
    }

    private function body(array $item): ?string
    {
        try {
            $ciphertext = $item['draft_ciphertext'] ?? null;
            $body = is_string($ciphertext) ? ($this->decrypt)($ciphertext) : null;
            return is_string($body) && trim($body) !== '' && strlen($body) <= 16000 ? $body : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function sameDraft(array $item, string $body): bool
    {
        return is_string($item['approval_draft_sha256'] ?? null)
            && hash_equals($item['approval_draft_sha256'], hash('sha256', $body))
            && is_string($item['approval_source_sha256'] ?? null)
            && hash_equals($item['approval_source_sha256'], $this->sourceHash($item))
            && $this->validContentHash($item)
            && ($item['approval_source_content_sha256'] ?? null) === (($item['source_content_sha256'] ?? '') ?: null);
    }

    private function validContentHash(array $item): bool
    {
        $hash = $item['source_content_sha256'] ?? null;
        return $hash === null || $hash === '' || (is_string($hash) && preg_match('/\\A[a-f0-9]{64}\\z/D', $hash) === 1);
    }

    private function sourceHash(array $item): string
    {
        return hash('sha256', (int) ($item['ticket_id'] ?? 0).':'.(int) ($item['customer_id'] ?? 0).':'.(int) ($item['customer_message_id'] ?? 0));
    }

    private function validSource(string $key, array $item): bool
    {
        return (int) ($item['ticket_id'] ?? 0) > 0 && (int) ($item['customer_id'] ?? 0) > 0
            && (int) ($item['customer_message_id'] ?? 0) > 0
            && $key === 'ticket:'.(int) $item['ticket_id'].':message:'.(int) $item['customer_message_id'];
    }

    private function bound(array $item, string $ownerId, string $chatId): bool
    {
        return (string) ($item['approval_owner_id'] ?? '') === $ownerId && (string) ($item['approval_chat_id'] ?? '') === $chatId;
    }

    private function validIdentity(string $ownerId, string $chatId): bool
    {
        return $ownerId !== '' && $chatId !== '' && strlen($ownerId) <= 80 && strlen($chatId) <= 80;
    }

    private function validToken(string $token): bool
    {
        return preg_match('/\\A[a-f0-9]{32}\\z/D', $token) === 1;
    }

    private function matches(?array $fresh, array $item): bool
    {
        return $fresh !== null && ($fresh['status'] ?? '') === 'open' && ($fresh['author_role'] ?? '') === 'customer'
            && (int) ($fresh['ticket_id'] ?? 0) === (int) $item['ticket_id']
            && (int) ($fresh['customer_id'] ?? 0) === (int) $item['customer_id']
            && (int) ($fresh['message_id'] ?? 0) === (int) $item['customer_message_id']
            && (empty($item['approval_source_content_sha256'])
                || (is_string($fresh['source_content_sha256'] ?? null)
                    && hash_equals($item['approval_source_content_sha256'], $fresh['source_content_sha256'])));
    }
}

