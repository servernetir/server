<?php

namespace App\Services\Hermes;

use App\Models\Ticket;
use App\Services\Ticket\TicketReplyService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Atomic ownership/latest-message guard around the canonical reply pipeline. */
final class HermesSupportDelivery
{
    public function __construct(private TicketReplyService $replies, private HermesSupportPolicy $policy) {}

    public function post(int $ticketId, int $customerId, int $messageId, string $body, callable $written): ?int
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() !== 0) {
            throw new RuntimeException('Hermes delivery requires its own transaction');
        }
        // The production ticket_messages.ticket_id foreign key participates in
        // this parent-row lock, including ordinary writers using addMessage().
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            throw new RuntimeException('Hermes atomic delivery driver unavailable');
        }
        $connection->beginTransaction();
        $committed = false;
        try {
            $ticket = Ticket::query()->lockForUpdate()->find($ticketId);
            $latest = $ticket?->visibleMessages()->orderByDesc('id')->lockForUpdate()->first();
            if (! $ticket || ! $latest || $ticket->status !== 'open'
                || (int) $ticket->customer_id !== $customerId || ! $ticket->customer
                || (int) $latest->id !== $messageId || $latest->author_role !== 'customer') {
                $connection->rollBack();
                return null;
            }
            $context = $ticket->visibleMessages()->orderByDesc('id')->limit(6)->get()->map(
                static fn ($m): string => mb_substr((string) $m->body, 0, 1500)
            )->implode("\n")."\n".(string) $latest->body;
            $classification = $this->policy->classify((string) $ticket->subject, $context, (string) $ticket->department);
            if (! $this->policy->maySend($classification, $body, (string) $latest->body)) {
                $connection->rollBack();
                return null;
            }
            $reply = $this->replies->post($ticket, null, 'دستیار پشتیبانی سرورنت', $body,
                internal: false, close: false,
                afterMessage: function ($message) use ($connection, &$committed, $written): void {
                    // The shared service invokes this before any customer notification.
                    $connection->commit();
                    $committed = true;
                    $written((int) $message->id);
                });
            return (int) $reply->id;
        } catch (Throwable $e) {
            if (! $committed && $connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            // A post-commit failure must never trigger a second public message.
            throw $e;
        }
    }
}
