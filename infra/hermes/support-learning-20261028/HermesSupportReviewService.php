<?php

namespace App\Services\Hermes;

use App\Models\Setting;
use App\Models\Ticket;
use App\Services\Bale\Admin\AdminBaleGate;
use App\Services\Ticket\TicketReplyService;
use App\Support\ErrorTracker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/** One bounded reviewer. Customer delivery requires an explicit owner approval. */
final class HermesSupportReviewService
{
    private const TERMINAL = ['sent', 'sent_notification_error', 'review_required', 'awaiting_approval', 'rejected', 'expired', 'invalidated', 'excluded', 'stale', 'delivery_uncertain', 'dispatching', 'message_written'];

    public function __construct(
        private HermesSupportState $state,
        private HermesSupportPolicy $policy,
        private HermesSupportDraftWriter $writer,
        private TicketReplyService $replies,
        private AdminBaleGate $gate,
        private HermesSupportDelivery $delivery,
        private HermesSupportOwnerReview $ownerReview,
    ) {}

    public function run(int $limit = 5, bool $dryRun = false, bool $noSend = false): array
    {
        $limit = max(1, min(10, $limit));
        $runId = bin2hex(random_bytes(12));
        $enabled = Setting::get('hermes_support_enabled') === '1';
        $enabledAt = ctype_digit((string) Setting::get('hermes_support_enabled_at'))
            ? (int) Setting::get('hermes_support_enabled_at') : 0;
        $report = ['request_id' => $runId, 'enabled' => $enabled, 'dry_run' => $dryRun, 'at' => time(), 'scanned' => 0, 'outcomes' => [], 'failures' => 0];
        if (! $dryRun && (! $enabled || $enabledAt < 1 || $enabledAt > time() + 30)) {
            return $report + ['skipped' => $enabled ? 'activation_time_missing' : 'disabled'];
        }
        if (! $dryRun && (! $this->gate->enabled() || ! $this->gate->binding() || ! $this->gate->boundUser())) {
            $this->note('owner_binding_unavailable', $runId);
            return $report + ['skipped' => 'owner_binding_unavailable'];
        }
        $cutoff = $enabledAt > 0 ? $enabledAt : time() - 86400;
        $lock = null;
        try {
            $lock = Cache::lock('hermes:support-review', 2700);
            if (! $lock->get()) {
                return $report + ['skipped' => 'already_running'];
            }
            $excluded = $this->exclusions();
            if (! $dryRun) {
                $report['recovered_incidents'] = $this->state->reconcileInterrupted();
            }
            $state = $this->state->read();
            $cursor = $dryRun ? 0 : (int) ($state['discovery_message_cursor'] ?? 0);
            $sequence = (int) ($state['run_sequence'] ?? 0);
            $new = DB::table('ticket_messages')->select(['id', 'ticket_id'])
                ->where('id', '>', $cursor)->where('is_internal', false)->where('author_role', 'customer')
                ->where('created_at', '>=', gmdate('Y-m-d H:i:s', $cutoff))->orderBy('id')->limit(500)->get();
            $pending = [];
            if (! $dryRun) {
                foreach ($state['items'] as $item) {
                    if (in_array($item['status'] ?? '', ['retry', 'queued'], true) && (int) ($item['next_retry_at'] ?? 0) <= time()) {
                        $pending[] = ['ticket_id' => (int) ($item['ticket_id'] ?? 0), 'id' => (int) ($item['customer_message_id'] ?? 0), 'pending' => true, 'due' => (int) ($item['next_retry_at'] ?? $item['started_at'] ?? 0)];
                    }
                }
                usort($pending, static fn (array $a, array $b): int => $a['due'] <=> $b['due']);
            }
            // Reserve discovery capacity even during persistent retry failures.
            $retryBudget = count($new) === 0 ? $limit : ($limit === 1 ? $sequence % 2 : intdiv($limit, 2));
            $candidates = array_merge(array_slice($pending, 0, $retryBudget), array_map(static fn ($row): array => ['ticket_id' => (int) $row->ticket_id, 'id' => (int) $row->id, 'pending' => false], $new->all()));
            $processed = 0;
            $processor = new HermesSupportProcessor($this->state, $this->policy, static fn (string $s): string => Crypt::encryptString($s));
            foreach ($candidates as $candidate) {
                // Enforce the scan bound before every branch, including stale/terminal skips.
                if ($report['scanned'] >= 500 || $processed >= $limit) {
                    break;
                }
                ++$report['scanned'];
                if (! $candidate['pending']) {
                    $cursor = max($cursor, $candidate['id']);
                }
                $key = 'ticket:'.$candidate['ticket_id'].':message:'.$candidate['id'];
                $existing = $this->state->item($key);
                if ($existing && (in_array($existing['status'] ?? '', self::TERMINAL, true) || (int) ($existing['next_retry_at'] ?? 0) > time())) {
                    continue;
                }
                $ticket = Ticket::query()->with('customer')->find($candidate['ticket_id']);
                $message = $ticket?->visibleMessages()->orderByDesc('id')->first();
                if (! $ticket || ! $message || $ticket->status !== 'open' || $message->author_role !== 'customer'
                    || (int) $message->id !== $candidate['id'] || $message->created_at->timestamp < $cutoff || ! $ticket->customer) {
                    if (! $dryRun && $existing) {
                        $this->state->put($key, ['status' => 'stale', 'finished_at' => time()]);
                    }
                    continue;
                }
                // This immutable bounded snapshot is both screened and sent to the writer.
                $evidence = HermesSupportSnapshot::capture($ticket);
                $thread = $evidence['thread'];
                $snapshot = ['id' => (int) $ticket->id, 'customer_id' => (int) $ticket->customer_id, 'subject' => (string) $ticket->subject, 'department' => (string) $ticket->department,
                    'excluded' => in_array((string) $ticket->id, $excluded, true) || in_array((string) $ticket->number, $excluded, true)];
                $msg = ['id' => (int) $message->id, 'body' => (string) $message->body, 'context' => implode("\n", array_column($thread, 'body'))."\n".(string) $message->body];
                $classification = $this->policy->classify($snapshot['subject'], $msg['context'], $snapshot['department']);
                if ($dryRun) {
                    $report['outcomes'][] = ['ticket_id' => $snapshot['id'], 'customer_message_id' => $msg['id'], 'classification' => $snapshot['excluded'] ? 'owner_exclusion' : $classification, 'status' => 'preview'];
                    ++$processed;
                    continue;
                }
                $ticketLock = Cache::lock('hermes:support-ticket:'.$ticket->id, 180);
                if (! $ticketLock->get()) {
                    // Discovery can advance while this exact item remains durably retryable.
                    $this->state->queueIfUnclaimed($key, ['ticket_id' => $snapshot['id'], 'customer_id' => $snapshot['customer_id'], 'customer_message_id' => $msg['id'], 'next_retry_at' => time() + 300, 'reason' => 'ticket_busy']);
                    ++$processed;
                    continue;
                }
                try {
                    $outcome = $processor->process(
                        $snapshot, $msg, fn (): ?array => $this->fresh($snapshot['id']),
                        function () use ($snapshot, $thread, $key, $evidence, $ticket, $msg): ?string {
                            $operations = app(HermesOperations::class);
                            $diagnostics = $operations->evidence($ticket, $msg['context']);
                            $followup = null;
                            if (Setting::get('hermes_technical_followup_enabled') === '1' && $snapshot['department'] === 'technical') {
                                // The bounded model snapshot can omit an older staff reply after many customer updates.
                                $priorSupport = in_array('support', array_column($thread, 'role'), true)
                                    || $ticket->visibleMessages()->where('author_role', 'staff')->where('id', '<', $msg['id'])->exists();
                                $followup = HermesTechnicalFollowup::assess($snapshot['department'], $snapshot['subject'], $thread, $diagnostics, null, $priorSupport);
                            }
                            $investigation = $followup ?? HermesSupportInvestigation::assess($snapshot['subject'], $thread, $diagnostics);
                            $this->state->put($key, ['technical_followup' => $followup !== null]);
                            $this->state->put($key, ['investigation_required' => $investigation !== null]);
                            if ($investigation !== null) {
                                if (Setting::get('hermes_support_learning_enabled') === '1') {
                                    $reasoning = $this->writer->reviewSnapshot($snapshot['subject'], $snapshot['department'], $thread,
                                        $diagnostics + ['investigation' => $investigation]);
                                    if ($reasoning !== null) {
                                        $investigation['baseline_findings'] = $investigation['analysis'];
                                        $investigation['analysis'] = $reasoning['analysis'];
                                        $investigation['reasoning_source'] = 'model_with_current_service_and_case_evidence';
                                    }
                                }
                                $diagnostics['investigation'] = $investigation;
                                $this->state->put($key, ['analysis_ciphertext' => Crypt::encryptString($investigation['analysis']),
                                    'source_content_sha256' => $evidence['source_content_sha256']]);
                                $operations->recordReview($ticket, $msg['id'], $diagnostics);
                                // Private draft storage only; OwnerReview and Approval both block sending this as a reply.
                                return 'بررسی داخلی تکمیل نشده است. این یادداشت پاسخ مشتری نیست و قابل تأیید برای ارسال نیست.';
                            }
                            $review = $this->writer->reviewSnapshot($snapshot['subject'], $snapshot['department'], $thread, $diagnostics);
                            if ($review === null) {
                                return null;
                            }
                            $this->state->put($key, ['analysis_ciphertext' => Crypt::encryptString($review['analysis']), 'source_content_sha256' => $evidence['source_content_sha256']]);
                            $operations->recordReview($ticket, $msg['id'], $diagnostics);
                            return $review['reply'];
                        },
                        fn (string $body, string $reason): int => $this->saveDraft($ticket, $msg['id'], $body, $reason),
                        fn (string $body, callable $written): ?int => $this->delivery->post($snapshot['id'], $snapshot['customer_id'], $msg['id'], $body, $written),
                        false, // Old settings cannot re-enable automatic customer delivery.
                        $runId,
                    );
                    if ($outcome === 'busy') {
                        $this->state->queueIfUnclaimed($key, ['ticket_id' => $snapshot['id'], 'customer_id' => $snapshot['customer_id'], 'customer_message_id' => $msg['id'], 'next_retry_at' => time() + 300, 'reason' => 'claim_busy']);
                    }
                    $report['outcomes'][] = ['ticket_id' => $snapshot['id'], 'customer_message_id' => $msg['id'], 'classification' => $classification, 'status' => $outcome];
                    if (in_array($outcome, ['retry', 'sent_notification_error', 'delivery_uncertain'], true)) {
                        ++$report['failures'];
                        $this->note($outcome, $runId, ['ticket_id' => $snapshot['id'], 'customer_message_id' => $msg['id']]);
                    }
                } catch (Throwable) {
                    ++$report['failures'];
                    // This item must not be lost when the message cursor advances.
                    $prior = $this->state->item($key);
                    if (! $prior || ! in_array($prior['status'] ?? '', self::TERMINAL, true)) {
                        $attempt = (int) ($prior['attempts'] ?? 0) + 1;
                        $this->state->put($key, ['status' => $attempt < 3 ? 'retry' : 'review_required', 'attempts' => $attempt, 'reason' => 'ticket_review_failed', 'ticket_id' => $snapshot['id'], 'customer_id' => $snapshot['customer_id'], 'customer_message_id' => $msg['id'], 'next_retry_at' => time() + 900]);
                    }
                    $this->note('ticket_review_failed', $runId, ['ticket_id' => $snapshot['id']]);
                } finally {
                    $ticketLock->release();
                }
                ++$processed;
            }
            if (! $dryRun) {
                if (! $noSend) {
                    $report['owner_notifications'] = $this->ownerReview->notifyPending($limit);
                    $report['failures'] += $report['owner_notifications']['failed'];
                }
                $report['tracker_new'] = $this->auditErrors();
                $this->state->change(function (array $s) use ($report, $cursor, $sequence): array {
                    $s['last_run_at'] = time();
                    $s['discovery_message_cursor'] = $cursor;
                    $s['run_sequence'] = $sequence + 1;
                    if ($report['failures'] === 0) {
                        $s['last_success_at'] = time();
                    }
                    $s['runs'][] = $report + ['completed_at' => time()];
                    return $s;
                });
            }
            return $report + ['completed' => true];
        } catch (Throwable) {
            $this->note('review_run_failed', $runId);
            return $report + ['completed' => false, 'error' => 'review_run_failed'];
        } finally {
            $lock?->release();
        }
    }

    private function fresh(int $id): ?array
    {
        $ticket = Ticket::query()->find($id);
        $message = $ticket?->visibleMessages()->orderByDesc('id')->first();
        return ! $ticket || ! $message ? null : ['ticket_id' => (int) $ticket->id, 'customer_id' => (int) $ticket->customer_id,
            'status' => (string) $ticket->status, 'message_id' => (int) $message->id, 'author_role' => (string) $message->author_role];
    }

    private function saveDraft(Ticket $ticket, int $messageId, string $body, string $reason): int
    {
        $marker = '[Hermes draft for customer message '.$messageId.']';
        $existing = $ticket->messages()->where('is_internal', true)->where('author_name', 'Hermes')->where('body', 'like', $marker.'%')->first();
        if ($existing) {
            return (int) $existing->id;
        }
        $note = $marker."\nپیش‌نویس برای بازبینی کارشناس؛ ارسال نشده است.\nدلیل بازبینی: ".$reason."\n\n".$body;
        return (int) $this->replies->post($ticket, null, 'Hermes', $note, internal: true, close: false)->id;
    }

    private function exclusions(): array
    {
        $raw = Setting::get('hermes_support_excluded_tickets', '[]');
        $list = json_decode($raw ?? '[]', true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($list) || ! array_is_list($list) || count(array_filter($list, static fn ($v): bool => (is_int($v) || is_string($v)) && trim((string) $v) !== '')) !== count($list)) {
            throw new \RuntimeException('Invalid Hermes support exclusions');
        }
        return array_values(array_map(static fn ($v): string => trim((string) $v), $list));
    }

    private function auditErrors(): int
    {
        $state = $this->state->read();
        $seen = $state['seen_tracker'] ?? [];
        $new = [];
        foreach (ErrorTracker::recent(400, 'error') as $row) {
            if (! str_starts_with((string) ($row['area'] ?? ''), 'hermes') && ! str_contains((string) ($row['class'] ?? ''), 'Hermes')) {
                continue;
            }
            $id = hash('sha256', json_encode([$row['at'] ?? null, $row['class'] ?? null, $row['message'] ?? null], JSON_THROW_ON_ERROR));
            if (isset($seen[$id])) {
                continue;
            }
            $new[$id] = time();
            // Never copy raw error text, URLs, query strings, headers or HTML.
            $this->state->error('tracked_hermes_incident', bin2hex(random_bytes(12)), ['status' => (int) ($row['status'] ?? 0), 'component' => 'tracker'], false);
        }
        $this->state->change(function (array $s) use ($seen, $new): array {
            $s['seen_tracker'] = array_slice($seen + $new, -500, null, true);
            $s['last_error_review_at'] = time();
            return $s;
        });
        return count($new);
    }

    private function note(string $code, string $runId, array $context = []): void
    {
        try {
            $this->state->error($code, $runId, $context);
        } catch (Throwable) {
            // The command still returns a failing status when durable state is unavailable.
        }
        try {
            ErrorTracker::noteOnce('hermes', 'support.'.$code, 3600, ['request_id' => $runId] + $context);
        } catch (Throwable) {
            // Diagnostic failures must not hide the explicit failing command result.
        }
    }
}
