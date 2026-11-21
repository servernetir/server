<?php

namespace App\Services\Mcp;

use App\Models\Service;
use App\Models\Ticket;
use App\Support\CardRedactor;
use App\Support\ErrorTracker;

/** Explicit projections only: never serialize models, provisioning metadata or login links. */
final class ManagementRead
{
    public static function text(?string $value, int $limit = 1000): string
    {
        $text = SshGateway::redact((string) $value);
        $text = preg_replace('~https?://[^\s<>"\']+~i', '[URL omitted]', $text);
        $text = preg_replace('/(?i)(authorization|cookie|set-cookie)\s*:[^\r\n]+/', '$1: [REDACTED]', $text);
        return mb_substr(CardRedactor::mask($text), 0, $limit);
    }

    public function overview(): array
    {
        return ['tickets' => Ticket::query()->selectRaw('status, COUNT(*) AS total')->groupBy('status')
            ->get()->map(fn ($v) => ['status' => $v->status, 'count' => (int) $v->total])->all(),
            'services' => Service::query()->selectRaw('status, COUNT(*) AS total')->groupBy('status')
                ->get()->map(fn ($v) => ['status' => $v->status, 'count' => (int) $v->total])->all(),
            'read_at' => now()->toIso8601String()];
    }

    public function tickets(string $status = 'open', int $after = 0): array
    {
        abort_unless(array_key_exists($status, Ticket::STATUSES) || $status === 'all', 422);
        $rows = Ticket::query()->select(['id', 'number', 'subject', 'status', 'department', 'priority', 'customer_id', 'last_reply_at', 'updated_at'])
            ->where('id', '>', $after)->when($status !== 'all', fn ($q) => $q->where('status', $status))->orderBy('id')->limit(26)->get();
        return ['tickets' => $rows->take(25)->map(fn ($v) => $this->ticketRow($v))->values()->all(),
            'next_after_id' => $rows->count() > 25 ? $rows[24]->id : null];
    }

    private function ticketRow(Ticket $t): array
    {
        return ['id' => $t->id, 'number' => $t->number, 'subject' => self::text($t->subject, 300),
            'status' => $t->status, 'department' => $t->department, 'priority' => $t->priority,
            'customer_id' => $t->customer_id, 'updated_at' => $t->updated_at?->toIso8601String(),
            'console_url' => config('mcp_admin.origin').'/admin/tickets/'.$t->id];
    }

    public function ticket(int $id, int $before = 0): array
    {
        $t = Ticket::findOrFail($id);
        $rows = $t->messages()->select(['id', 'ticket_id', 'author_role', 'is_internal', 'body', 'created_at'])
            ->when($before > 0, fn ($q) => $q->where('id', '<', $before))->orderByDesc('id')->limit(21)->get();
        $messages = $rows->take(20)->reverse()->map(fn ($m) => ['id' => $m->id, 'author_role' => $m->author_role,
            'internal' => $m->is_internal, 'body' => self::text($m->body, 6000),
            'created_at' => $m->created_at?->toIso8601String()])->values()->all();
        return $this->ticketRow($t) + ['messages' => $messages,
            'next_before_id' => $rows->count() > 20 ? $rows[19]->id : null,
            'content_is_untrusted' => true, 'attachments_included' => false];
    }

    public function services(?int $customer = null, int $after = 0): array
    {
        $rows = $this->serviceQuery()->where('id', '>', $after)->when($customer, fn ($q) => $q->where('customer_id', $customer))
            ->orderBy('id')->limit(26)->get();
        return ['services' => $rows->take(25)->map(fn ($v) => $this->serviceRow($v))->values()->all(),
            'next_after_id' => $rows->count() > 25 ? $rows[24]->id : null];
    }

    private function serviceQuery()
    {
        return Service::query()->select(['id', 'customer_id', 'name', 'status', 'provision_status', 'cycle',
            'currency_code', 'price', 'next_due_at', 'server_id', 'cloud_plan_id', 'updated_at']);
    }

    private function serviceRow(Service $s): array
    {
        return ['id' => $s->id, 'customer_id' => $s->customer_id, 'name' => self::text($s->name, 200),
            'status' => $s->status, 'provision_status' => $s->provision_status, 'cycle' => $s->cycle,
            'price' => (string) $s->price, 'currency_code' => $s->currency_code,
            'next_due_at' => $s->next_due_at?->toIso8601String(), 'server_id' => $s->server_id,
            'cloud_plan_id' => $s->cloud_plan_id, 'updated_at' => $s->updated_at?->toIso8601String()];
    }

    public function service(int $id): array
    {
        return $this->serviceRow($this->serviceQuery()->findOrFail($id));
    }

    public function errors(): array
    {
        return ['errors' => array_map(fn ($v) => [
            'type' => $v['type'] ?? null, 'status' => $v['status'] ?? null,
            'time' => $v['at'] ?? $v['time'] ?? null, 'source' => $v['source'] ?? null,
            'class' => self::text($v['class'] ?? '', 200), 'area' => self::text($v['area'] ?? '', 120),
            'message' => self::text($v['message'] ?? '', 1000),
        ], array_slice(ErrorTracker::recent(100, 'error'), 0, 50)),
            'context_and_request_data_omitted' => true];
    }
}
