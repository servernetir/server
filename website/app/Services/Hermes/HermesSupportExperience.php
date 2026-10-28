<?php

namespace App\Services\Hermes;

use App\Models\Service;
use App\Models\Ticket;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Source-linked case memory; published answers remain references, never live health or permissions. */
final class HermesSupportExperience
{
    public function __construct(private HermesOperationsStore $store) {}

    public function refresh(array $owner): array
    {
        $version = DB::table('ticket_messages')->where('is_internal', false)->max('id') ?? 0;
        $key = 'hermes:support-experience:v2:'.hash('sha256', $owner['user_id'].':'.$owner['chat_id']).':'.$version;
        $index = Cache::remember($key, 600, function (): array {
            $tickets = DB::table('tickets')->where('department', 'technical')->orderByDesc('last_reply_at')->limit(501)
                ->get(['id', 'customer_id', 'subject', 'department', 'status'])->map(fn ($r) => (array) $r)->all();
            $ids = array_column(array_slice($tickets, 0, 500), 'id');
            $messages = $ids ? DB::table('ticket_messages')->whereIn('ticket_id', $ids)->where('is_internal', false)
                ->orderByDesc('id')->limit(5001)->get(['id', 'ticket_id', 'author_role', 'author_name', 'body', 'created_at'])
                ->map(fn ($r) => (array) $r)->all() : [];
            return self::compile($tickets, $messages, time());
        });
        $this->store->change($owner, function (array &$state) use ($index): void { $state['support_experience'] = $index; });
        return $index;
    }

    public function context(Ticket $ticket, array $owner, string $intent): array
    {
        $index = $this->refresh($owner);
        $context = self::retrieve($index, (int) $ticket->id, (int) $ticket->customer_id, $ticket->subject.' '.$intent);
        // Include this customer's sales/billing history too; it is never cross-customer learning text.
        $ownTickets = DB::table('tickets')->where('customer_id', $ticket->customer_id)->where('id', '!=', $ticket->id)
            ->orderByDesc('last_reply_at')->limit(8)->get(['id', 'department', 'status'])->keyBy('id');
        $ownReplies = $ownTickets->isNotEmpty() ? DB::table('ticket_messages')->whereIn('ticket_id', $ownTickets->keys()->all())
            ->where('author_role', 'staff')->where('is_internal', false)->where(function ($q) {
                $q->whereNull('author_name')->orWhere('author_name', '!=', 'Hermes');
            })->orderByDesc('id')->limit(50)->get(['id', 'ticket_id', 'created_at']) : collect();
        $history = [];
        foreach ($context['same_customer_history'] as $row) { $history[$row['ticket_id']] = $row; }
        foreach ($ownReplies as $row) {
            if (isset($history[$row->ticket_id])) { continue; }
            $history[$row->ticket_id] = ['ticket_id' => (int) $row->ticket_id, 'customer_id' => (int) $ticket->customer_id,
                'reply_message_id' => (int) $row->id, 'reply_at' => (string) $row->created_at,
                'department' => $ownTickets[$row->ticket_id]->department, 'outcome' => 'historical_reference_unassessed', 'reference_only' => true];
        }
        $history = array_values($history);
        usort($history, fn ($a, $b) => $b['reply_message_id'] <=> $a['reply_message_id']);
        $context['same_customer_history'] = array_slice($history, 0, 3);
        $context['customer_history_scope'] = 'same_customer_all_ticket_departments';
        // Same-customer history is checked again against the database before an excerpt is exposed.
        foreach ($context['same_customer_history'] as &$history) {
            $row = DB::table('ticket_messages')->join('tickets', 'tickets.id', '=', 'ticket_messages.ticket_id')
                ->where('tickets.customer_id', $ticket->customer_id)->where('tickets.id', $history['ticket_id'])
                ->where('ticket_messages.id', $history['reply_message_id'])->where('ticket_messages.author_role', 'staff')
                ->where('ticket_messages.is_internal', false)->value('ticket_messages.body');
            $history['published_reply_excerpt'] = is_string($row) ? self::excerpt($row) : null;
        }
        unset($history);
        $owned = Service::query()->where('customer_id', $ticket->customer_id)->latest('id')->limit(21)
            ->get(['id', 'status', 'billing_mode', 'provision_status', 'cloud_plan_id']);
        $context['owned_service_inventory'] = ['source' => 'servernet_database', 'checked_at' => time(),
            'truncated' => $owned->count() > 20, 'services' => $owned->take(20)->map(fn ($s) => [
                'id' => (int) $s->id, 'status' => $s->status, 'billing_mode' => $s->billing_mode,
                'provision_status' => $s->provision_status, 'cloud_order' => $s->cloud_plan_id !== null])->all()];
        if (strlen(json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > 7000) {
            foreach ($context['same_customer_history'] as &$row) { unset($row['published_reply_excerpt']); }
            unset($row);
        }
        return $context;
    }

    public static function compile(array $tickets, array $messages, int $now): array
    {
        $complete = count($tickets) <= 500 && count($messages) <= 5000;
        $groups = [];
        foreach (array_slice($messages, 0, 5000) as $message) {
            if (! empty($message['is_internal'])) { continue; }
            $groups[(int) $message['ticket_id']][] = $message;
        }
        $cases = []; $counts = [];
        foreach (array_slice($tickets, 0, 500) as $ticket) {
            if (($ticket['department'] ?? '') !== 'technical') { continue; }
            $rows = $groups[(int) $ticket['id']] ?? [];
            usort($rows, fn ($a, $b) => (int) $a['id'] <=> (int) $b['id']);
            $reply = null; $customer = null;
            foreach ($rows as $row) {
                if (($row['author_role'] ?? '') === 'staff' && ($row['author_name'] ?? '') !== 'Hermes') { $reply = $row; }
                if (($row['author_role'] ?? '') === 'customer') { $customer = $row; }
            }
            // Keep the actual answer a customer confirmed, rather than a later closing acknowledgement.
            if ($customer && self::confirmed((string) $customer['body'])) {
                foreach (array_reverse($rows) as $row) {
                    if (($row['author_role'] ?? '') === 'staff' && ($row['author_name'] ?? '') !== 'Hermes'
                        && (int) $row['id'] < (int) $customer['id']) { $reply = $row; break; }
                }
            }
            if (! $reply || self::excerpt((string) $reply['body']) === null) { continue; }
            $outcome = 'awaiting_customer_confirmation';
            if ($customer && (int) $customer['id'] > (int) $reply['id']) {
                $thread = [['role' => 'support', 'body' => ''], ['role' => 'customer', 'body' => (string) $customer['body']]];
                $outcome = HermesTechnicalFollowup::continues('technical', $thread) ? 'customer_reports_continuing'
                    : (self::confirmed((string) $customer['body']) ? 'customer_confirmed_report' : 'no_explicit_outcome');
            } elseif (($ticket['status'] ?? '') === 'closed') { $outcome = 'staff_closed_unconfirmed'; }
            $topics = self::topics($ticket['subject'].' '.($customer['body'] ?? '').' '.$reply['body']);
            foreach ($topics as $topic) { $counts[$topic] = ($counts[$topic] ?? 0) + 1; }
            $cases[] = ['ticket_id' => (int) $ticket['id'], 'customer_id' => (int) $ticket['customer_id'],
                'reply_message_id' => (int) $reply['id'], 'reply_at' => (string) $reply['created_at'],
                'source_digest' => hash('sha256', (string) $reply['body']), 'topics' => $topics,
                'staff_reported_tags' => self::reportedTags((string) $reply['body']),
                'outcome' => $outcome, 'reference_only' => true];
        }
        return ['version' => 2, 'observed_at' => $now, 'source' => 'published_staff_replies_and_customer_followups',
            'source_ticket_count' => min(count($tickets), 500), 'source_message_count' => min(count($messages), 5000),
            'bounded_inventory_complete' => $complete, 'cases' => $cases, 'topic_counts' => $counts,
            'confirmed_case_count' => count(array_filter($cases, fn ($c) => $c['outcome'] === 'customer_confirmed_report')),
            'grants_permissions' => false];
    }

    public static function retrieve(array $index, int $ticketId, int $customerId, string $query): array
    {
        $topics = self::topics($query); $related = []; $own = [];
        foreach ($index['cases'] ?? [] as $case) {
            if ($case['ticket_id'] === $ticketId) { continue; }
            if ($case['customer_id'] === $customerId) { $own[] = $case; }
            $overlap = array_intersect($topics, $case['topics']);
            if ($overlap) { $related[] = ['score' => count($overlap), 'case' => $case, 'topics' => array_values($overlap)]; }
        }
        usort($own, fn ($a, $b) => $b['reply_message_id'] <=> $a['reply_message_id']);
        $reliability = static fn ($row) => match ($row['case']['outcome']) {
            'customer_confirmed_report' => 3, 'customer_reports_continuing' => 2, 'staff_closed_unconfirmed' => 0, default => 1,
        };
        usort($related, fn ($a, $b) => ($b['score'] <=> $a['score']) ?: ($reliability($b) <=> $reliability($a))
            ?: ($b['case']['reply_message_id'] <=> $a['case']['reply_message_id']));
        $patterns = []; $seen = [];
        foreach ($related as $row) {
            foreach ($row['topics'] as $topic) {
                if (isset($seen[$topic])) { continue; } $seen[$topic] = true;
                // Cross-customer memory contains no body, identifier, name, address or account balance.
                $patterns[] = ['topic' => $topic, 'prior_outcome' => $row['case']['outcome'],
                    'source_reply_at' => $row['case']['reply_at'], 'staff_reported_tags' => $row['case']['staff_reported_tags'] ?? [],
                    'lesson' => self::lesson($topic), 'reference_only' => true];
                if (count($patterns) >= 4) { break 2; }
            }
        }
        return ['available' => true, 'observed_at' => $index['observed_at'] ?? null,
            'learned_case_count' => count($index['cases'] ?? []), 'confirmed_case_count' => $index['confirmed_case_count'] ?? 0,
            'same_customer_history' => array_slice($own, 0, 3), 'related_case_patterns' => $patterns,
            'historical_reference_only' => true, 'current_state_verified_by_history' => false,
            'instruction' => 'History can explain a prior decision, not prove current health. A published answer is not a confirmed fix. Current exact-service evidence wins; never copy another customer or grant new permissions.'];
    }

    public static function excerpt(string $text): ?string
    {
        if ((new HermesSupportPolicy())->classify('', $text, 'technical') === 'credentials') { return null; }
        $text = preg_replace('~(?:https?://|vless://|vmess://|trojan://)\S+|[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}|\b[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}\b|\b(?:\d{1,3}\.){3}\d{1,3}\b|\b[0-9۰-۹٠-٩]{7,}\b~u', '[redacted]', $text) ?? '';
        return mb_substr($text, 0, 350);
    }

    private static function confirmed(string $text): bool
    {
        $text = str_replace('‌', ' ', $text);
        return ! preg_match('/نشد|نشده|نمی|not |still |çalışmıyor|çözülmedi/iu', $text)
            && preg_match('/حل شد|رفع شد|درست شد|برطرف شد|وصل شد|working now|resolved|fixed|çözüldü|düzeldi/iu', $text) === 1;
    }

    private static function topics(string $text): array
    {
        $patterns = ['wallet_and_suspension' => '/اعتبار|شارژ|ذخیره|تعلیق|ساعتی|خاموش|ترافیک|wallet|reserve|hourly|suspend/iu',
            'network' => '/udp|tcp|فایروال|پورت|firewall|شبکه/iu', 'dns' => '/dns|nameserver|نیم.?سرور/iu',
            'php_runtime' => '/php|session\.upload_progress/iu', 'mail' => '/smtp|imap|mail|ایمیل|ای.?میل/iu',
            'application' => '/\.net|iis|jupyter|gpu|cuda|سایت|برنامه|هاست|خطا|error/iu'];
        return array_keys(array_filter($patterns, fn ($pattern) => preg_match($pattern, $text) === 1));
    }

    private static function reportedTags(string $text): array
    {
        $text = str_replace('‌', ' ', $text); $tags = [];
        if (preg_match('/ذخیره|رزرو/u', $text) && preg_match('/اعتبار|موجودی/u', $text)) { $tags[] = 'total_reserved_available_credit_distinguished'; }
        if (preg_match('/شارژ مجدد/u', $text) && preg_match('/فعال نمی|رفع.*نمی/u', $text)) { $tags[] = 'topup_does_not_clear_recorded_hold'; }
        if (preg_match('/فایروال|پورت/u', $text) && preg_match('/مجاز هستند|مجاز است|مجاز شده|باز شد|برداشته شد/u', $text)
            && ! preg_match('/باز نشده|مجاز نیست/u', $text)) { $tags[] = 'staff_reported_infrastructure_permission'; }
        if (preg_match('/listener|داخل سرور|سیستم عامل/iu', $text) && preg_match('/بررسی|آزمایش|تست/u', $text)) { $tags[] = 'guest_verification_remains_separate'; }
        if (preg_match('/dns|رکورد/iu', $text) && preg_match('/اصلاح شد|تغییر کرد|تنظیم شد/u', $text)) { $tags[] = 'staff_reported_dns_change'; }
        if (preg_match('/php/iu', $text) && preg_match('/وصله|ارتقا/u', $text) && preg_match('/نصب شد|انجام شد/u', $text)) { $tags[] = 'staff_reported_php_change_requires_current_runtime_check'; }
        return $tags;
    }

    private static function lesson(string $topic): string
    {
        return match ($topic) {
            'wallet_and_suspension' => 'ماندهٔ کل، ذخیره و اعتبار قابل‌مصرف را جدا بخوان؛ علت تعلیق را از flag و رویداد همان سرویس بگیر. پرداخت به‌تنهایی رفع تعلیق نیست و علت تاریخی را از ماندهٔ امروز حدس نزن.',
            'network' => 'گروه متصل و قواعد جاری را بخوان؛ مجوز زیرساخت، listener مهمان یا تحویل UDP را ثابت نمی‌کند.',
            'dns' => 'رکورد، delegation و زمان مشاهده را تفکیک کن؛ resolve ساده، صحت authoritative DNS نیست.',
            'php_runtime' => 'نسخهٔ شاخه، build وصله و تنظیم مؤثر همان SAPI را جدا بررسی کن؛ پیام قبلی پشتیبانی اثبات وصلهٔ جاری نیست.',
            'mail' => 'دریافت API یا تنظیم SMTP، تحویل پیام نیست؛ رخداد همان پیام لازم است و محدودیت شبکه‌ها تعمیم داده نمی‌شود.',
            default => 'وضعیت حساب/ماشین را از سلامت برنامه جدا کن؛ پاسخ قبلی و بسته‌شدن تیکت به‌تنهایی اثبات رفع مشکل نیست.',
        };
    }
}
