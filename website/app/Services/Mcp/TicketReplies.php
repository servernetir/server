<?php

namespace App\Services\Mcp;

use App\Models\McpAdminAction;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Ticket\TicketReplyService;
use App\Services\Ticket\TicketChangedException;
use Illuminate\Support\Facades\DB;

final class TicketReplies
{
    /** Durable claim before notifications. An interrupted/unknown reply is never automatically sent again. */
    public function post(User $user, string $requestId, int $ticketId, string $body, int $expectedLastMessageId, bool $internal = false): array
    {
        abort_unless(AdminAccess::allowed($user) && $user->tokenCan(AdminAccess::TICKET_REPLY) && filled($user->currentAccessToken()?->id), 403);
        abort_unless(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $requestId)
            && strlen(trim($body)) >= 1 && strlen($body) <= 12000 && ! str_contains($body, "\0"), 422);
        $payload = json_encode([$ticketId, $body, $expectedLastMessageId, $internal], JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $payload);
        $claimed = DB::transaction(function () use ($user, $requestId, $ticketId, $expectedLastMessageId, $payload, $hash) {
            // Serializes submissions for this ticket and validates against new customer/staff messages.
            $ticket = Ticket::lockForUpdate()->findOrFail($ticketId);
            $existing = McpAdminAction::lockForUpdate()->find($requestId);
            if ($existing) {
                abort_unless((int) $existing->user_id === (int) $user->id
                    && $existing->token_id === $user->currentAccessToken()->id
                    && $existing->target === 'ticket:'.$ticketId && hash_equals($existing->target_hash, $hash), 409);
                return ['action' => $existing, 'new' => false];
            }
            abort_unless((int) ($ticket->messages()->max('id') ?? 0) === $expectedLastMessageId, 409);
            // A second key is not a retry. Do not race an in-flight or unreconciled reply.
            abort_if(McpAdminAction::where('target', 'ticket:'.$ticketId)
                ->whereIn('status', ['executing', 'unknown'])->exists(), 409);
            $action = McpAdminAction::create(['id' => $requestId, 'user_id' => $user->id,
                'token_id' => $user->currentAccessToken()->id, 'target' => 'ticket:'.$ticketId,
                'target_hash' => $hash, 'command' => $payload, 'status' => 'executing',
                'expires_at' => now()->addMinutes(10), 'executed_at' => now()]);
            return ['action' => $action, 'new' => true];
        });
        $action = $claimed['action'];
        if (! $claimed['new']) {
            return ($action->result ?? ['ok' => false, 'outcome' => 'unknown', 'reconcile_before_retry' => true]) + ['replayed' => true];
        }
        try {
            $message = app(TicketReplyService::class)->post(Ticket::findOrFail($ticketId), $user->id, $user->name, $body, internal: $internal, expectedLastMessageId: $expectedLastMessageId);
            $result = ['ok' => true, 'ticket_id' => $ticketId, 'message_id' => $message->id, 'internal' => $internal];
            $action->update(['status' => 'completed', 'result' => $result]);
        } catch (TicketChangedException) {
            $result = ['ok' => false, 'code' => 'ticket_changed', 'reconcile_before_retry' => true];
            $action->update(['status' => 'completed', 'result' => $result]);
        } catch (\Throwable) {
            $result = ['ok' => false, 'outcome' => 'unknown', 'reconcile_before_retry' => true];
            $action->update(['status' => 'unknown', 'result' => $result]);
        }
        return $result;
    }
}
