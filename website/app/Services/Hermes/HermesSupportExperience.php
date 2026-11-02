<?php

namespace App\Services\Hermes;

use App\Models\Service;
use App\Models\Ticket;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Source-linked case memory; published answers remain references, never live health or permissions. */
final class HermesSupportExperience
{
    public function __construct(private HermesOperationsStore $store, private ?HermesSupportExperienceIndex $indexStore = null) {}

    private function indexes(): HermesSupportExperienceIndex
    {
        return $this->indexStore ??= new HermesSupportExperienceIndex();
    }

    public function refresh(array $owner, bool $force = false): array
    {
        return $this->indexes()->synchronized($owner, function () use ($owner, $force): array {
            $version = (int) (DB::table('ticket_messages')->where('is_internal', false)->max('id') ?? 0);
            $lastTicket = (int) (DB::table('tickets')->max('id') ?? 0);
            $previous = $this->store->read($owner)['support_experience'] ?? [];
            $key = 'hermes:support-experience:v4:'.hash('sha256', $owner['user_id'].':'.$owner['chat_id']).':'.$lastTicket.':'.$version;
            if ($force) { Cache::forget($key); }
            $index = Cache::remember($key, 600, function () use ($owner, $version, $lastTicket, $previous): array {
                return $this->indexes()->publish($owner, function (callable $append) use ($version, $lastTicket): array {
                    $summary = self::compile([], [], time(), true);
                    unset($summary['cases']);
                    $summary['compiler_version'] = 'support-experience-20261102-paged-v1';
                    $summary['department_counts'] = [];
                    DB::table('tickets')->where('id', '<=', $lastTicket)->orderBy('id')
                        ->select(['id', 'customer_id', 'subject', 'department', 'status'])
                        ->chunkById(100, function ($rows) use (&$summary, $version, $append): void {
                            foreach ($rows as $row) {
                                $ticket = (array) $row;
                                ++$summary['source_ticket_count'];
                                $department = (string) ($ticket['department'] ?? 'unknown');
                                $summary['department_counts'][$department] = ($summary['department_counts'][$department] ?? 0) + 1;
                                $seen = function () use (&$summary): void { ++$summary['source_message_count']; };
                                foreach ($this->ticketCases($ticket, $version, $summary['observed_at'], $seen) as $case) {
                                    $append([$case]);
                                    foreach ($case['topics'] as $topic) {
                                        $summary['topic_counts'][$topic] = ($summary['topic_counts'][$topic] ?? 0) + 1;
                                    }
                                    if ($case['outcome'] === 'customer_confirmed_report') { ++$summary['confirmed_case_count']; }
                                }
                            }
                        }, 'id');
                    $summary['snapshot_max_ticket_id'] = $lastTicket;
                    $summary['snapshot_max_message_id'] = $version;
                    return $summary;
                }, $previous);
            });
            try { $this->indexes()->summary($owner, $index['index_ref']); }
            catch (\Throwable $error) {
                Cache::forget($key); // Only this corrupt/missing generation's cache key.
                throw $error;
            }
            // Publish all pages before replacing the small encrypted state pointer.
            $this->store->change($owner, function (array &$state) use ($index): void { $state['support_experience'] = $index; });
            try {
                $this->indexes()->collect($owner, [$index['index_ref'], $previous['index_ref'] ?? null]);
            } catch (\Throwable) { /* Retention failure cannot invalidate a durable index. */ }
            return $index;
        });
    }

    /** Legacy inline v2 snapshots remain readable; new snapshots stream private pages. */
    public function cases(array $owner, array $index): iterable
    {
        if (isset($index['index_ref'])) { return $this->indexes()->cases($owner, $index['index_ref']); }
        return $index['cases'] ?? [];
    }

    /** Compile a long thread with only its pending answer/customer pair in memory. */
    private function ticketCases(array $ticket, int $version, int $observedAt, callable $seen): \Generator
    {
        $before = null; $pending = null; $pendingBefore = null; $after = null;
        $emit = static function (?array $reply, ?array $customer, ?array $following) use ($ticket, $observedAt): array {
            if ($reply === null) { return []; }
            $messages = array_values(array_filter([$customer, $reply, $following], static fn ($row): bool => $row !== null));
            return self::compile([$ticket], $messages, $observedAt, true)['cases'];
        };
        $rows = DB::table('ticket_messages')->where('ticket_id', $ticket['id'])->where('is_internal', false)
            ->where('id', '<=', $version)->orderBy('id')
            ->select(['id', 'ticket_id', 'author_role', 'author_name', 'body', 'created_at'])->lazyById(500, 'id');
        foreach ($rows as $row) {
            $seen(); $message = (array) $row;
            if ($message['author_role'] === 'customer') {
                $before = $message;
                if ($pending !== null) { $after = $message; }
            } elseif ($message['author_role'] === 'staff' && ($message['author_name'] ?? '') !== 'Hermes') {
                yield from $emit($pending, $pendingBefore, $after);
                $pending = self::excerpt((string) $message['body']) === null ? null : $message;
                $pendingBefore = $before; $after = null;
            }
        }
        yield from $emit($pending, $pendingBefore, $after);
    }

    public function context(Ticket $ticket, array $owner, string $intent): array
    {
        $index = $this->refresh($owner);
        try {
            $context = self::retrieve($index, (int) $ticket->id, (int) $ticket->customer_id, $ticket->department.' '.$ticket->subject.' '.$intent, $this->cases($owner, $index));
        } catch (\Throwable $error) {
            $key = 'hermes:support-experience:v4:'.hash('sha256', $owner['user_id'].':'.$owner['chat_id']).':'
                .($index['snapshot_max_ticket_id'] ?? 0).':'.($index['snapshot_max_message_id'] ?? 0);
            Cache::forget($key);
            throw $error; // No partial context may escape a failed page read.
        }
        // Include this customer's sales/billing history too; it is never cross-customer learning text.
        $ownTickets = DB::table('tickets')->where('customer_id', $ticket->customer_id)->where('id', '!=', $ticket->id)
            ->orderByDesc('last_reply_at')->limit(8)->get(['id', 'department', 'status'])->keyBy('id');
        $ownReplies = $ownTickets->isNotEmpty() ? DB::table('ticket_messages')->whereIn('ticket_id', $ownTickets->keys()->all())
            ->where('author_role', 'staff')->where('is_internal', false)->where(function ($q) {
                $q->whereNull('author_name')->orWhere('author_name', '!=', 'Hermes');
            })->orderByDesc('id')->limit(50)->get(['id', 'ticket_id', 'body', 'created_at']) : collect();
        $history = [];
        foreach ($context['same_customer_history'] as $row) { $history[$row['ticket_id']] = $row; }
        foreach ($ownReplies as $row) {
            if (isset($history[$row->ticket_id]) || self::closingAcknowledgement((string) $row->body)) { continue; }
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

    public static function compile(array $tickets, array $messages, int $now, bool $fullHistory = false): array
    {
        $complete = $fullHistory || (count($tickets) <= 500 && count($messages) <= 5000);
        $groups = [];
        foreach (($fullHistory ? $messages : array_slice($messages, 0, 5000)) as $message) {
            if (! empty($message['is_internal'])) { continue; }
            $groups[(int) $message['ticket_id']][] = $message;
        }
        $cases = []; $counts = [];
        foreach (($fullHistory ? $tickets : array_slice($tickets, 0, 500)) as $ticket) {
            $rows = $groups[(int) $ticket['id']] ?? [];
            usort($rows, fn ($a, $b) => (int) $a['id'] <=> (int) $b['id']);
            foreach ($rows as $offset => $reply) {
                if (($reply['author_role'] ?? '') !== 'staff' || ($reply['author_name'] ?? '') === 'Hermes'
                    || self::excerpt((string) $reply['body']) === null) { continue; }
                $customer = null; $after = null;
                foreach (array_slice($rows, 0, $offset) as $row) {
                    if (($row['author_role'] ?? '') === 'customer') { $customer = $row; }
                }
                // Attribute an outcome only to the answer preceding it. A later
                // staff claim or a closing acknowledgement cannot confirm a fix.
                foreach (array_slice($rows, $offset + 1) as $row) {
                    if (($row['author_role'] ?? '') === 'staff' && ($row['author_name'] ?? '') !== 'Hermes') { break; }
                    if (($row['author_role'] ?? '') === 'customer') { $after = $row; }
                }
                $outcome = 'awaiting_customer_confirmation';
                if ($after) {
                    $thread = [['role' => 'support', 'body' => ''], ['role' => 'customer', 'body' => (string) $after['body']]];
                    $outcome = HermesTechnicalFollowup::continues('technical', $thread) ? 'customer_reports_continuing'
                        : (self::confirmed((string) $after['body']) ? 'customer_confirmed_report' : 'no_explicit_outcome');
                } elseif (($ticket['status'] ?? '') === 'closed') { $outcome = 'staff_closed_unconfirmed'; }
                $topics = self::topics(($ticket['department'] ?? '').' '.$ticket['subject'].' '.($customer['body'] ?? '').' '.$reply['body']);
                foreach ($topics as $topic) { $counts[$topic] = ($counts[$topic] ?? 0) + 1; }
                $cases[] = ['ticket_id' => (int) $ticket['id'], 'customer_id' => (int) $ticket['customer_id'],
                    'department' => (string) ($ticket['department'] ?? 'unknown'),
                    'reply_message_id' => (int) $reply['id'], 'reply_at' => (string) $reply['created_at'],
                    'source_digest' => hash('sha256', (string) $reply['body']), 'topics' => $topics,
                    'closing_acknowledgement' => self::closingAcknowledgement((string) $reply['body']),
                    'staff_reported_tags' => self::reportedTags((string) $reply['body']),
                    'outcome' => $outcome, 'reference_only' => true];
            }
        }
        return ['version' => 3, 'observed_at' => $now, 'source' => 'published_staff_replies_and_customer_followups',
            'source_ticket_count' => $fullHistory ? count($tickets) : min(count($tickets), 500),
            'source_message_count' => $fullHistory ? count($messages) : min(count($messages), 5000),
            'source_scope' => $fullHistory ? 'all_departments_public_history' : 'bounded_public_history',
            'bounded_inventory_complete' => $complete, 'cases' => $cases, 'topic_counts' => $counts,
            'confirmed_case_count' => count(array_filter($cases, fn ($c) => $c['outcome'] === 'customer_confirmed_report')),
            'grants_permissions' => false];
    }

    public static function retrieve(array $index, int $ticketId, int $customerId, string $query, ?iterable $cases = null): array
    {
        $topics = self::topics($query); $relatedByTopic = []; $ownByTicket = [];
        $reliability = static fn (array $case): int => match ($case['outcome']) {
            'customer_confirmed_report' => 3, 'customer_reports_continuing' => 2, 'staff_closed_unconfirmed' => 0, default => 1,
        };
        $compare = static fn (array $a, array $b): int => ($b['score'] <=> $a['score'])
            ?: ($reliability($b['case']) <=> $reliability($a['case']))
            ?: ($b['case']['reply_message_id'] <=> $a['case']['reply_message_id']);
        $learned = 0;
        // Consume every page before returning: a late integrity error must never
        // leave an apparently complete partial context for the writer.
        foreach ($cases ?? ($index['cases'] ?? []) as $case) {
            ++$learned;
            if ($case['ticket_id'] === $ticketId || ! empty($case['closing_acknowledgement'])) { continue; }
            if ($case['customer_id'] === $customerId && empty($case['closing_acknowledgement'])) {
                $prior = $ownByTicket[$case['ticket_id']] ?? null;
                if ($prior === null || self::preferHistoryCase($case, $prior)) { $ownByTicket[$case['ticket_id']] = $case; }
                if (count($ownByTicket) > 3) {
                    uasort($ownByTicket, fn ($a, $b) => $b['reply_message_id'] <=> $a['reply_message_id']);
                    $ownByTicket = array_slice($ownByTicket, 0, 3, true);
                }
            }
            $overlap = array_intersect($topics, $case['topics']);
            if (! $overlap) { continue; }
            $row = ['score' => count($overlap), 'case' => $case, 'topics' => array_values($overlap)];
            foreach ($overlap as $topic) {
                if (! isset($relatedByTopic[$topic]) || $compare($row, $relatedByTopic[$topic]) < 0) {
                    $relatedByTopic[$topic] = $row;
                }
            }
        }
        if (isset($index['case_count']) && $learned !== (int) $index['case_count']) {
            throw new \RuntimeException('Experience retrieval coverage mismatch');
        }
        $own = array_values($ownByTicket);
        usort($own, fn ($a, $b) => $b['reply_message_id'] <=> $a['reply_message_id']);
        $related = array_values($relatedByTopic); usort($related, $compare);
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
            'learned_case_count' => $learned,
            'source_scope' => $index['source_scope'] ?? 'legacy_bounded_history',
            'source_ticket_count' => $index['source_ticket_count'] ?? 0,
            'source_message_count' => $index['source_message_count'] ?? 0,
            'bounded_inventory_complete' => $index['bounded_inventory_complete'] ?? false,
            'confirmed_case_count' => $index['confirmed_case_count'] ?? 0,
            'same_customer_history' => array_slice($own, 0, 3), 'related_case_patterns' => $patterns,
            'historical_reference_only' => true, 'current_state_verified_by_history' => false,
            'instruction' => 'History can explain a prior decision, not prove current health. A published answer is not a confirmed fix. Current exact-service evidence wins; never copy another customer or grant new permissions.'];
    }

    public static function preferHistoryCase(array $candidate, array $current): bool
    {
        $candidateSubstantive = empty($candidate['closing_acknowledgement']);
        $currentSubstantive = empty($current['closing_acknowledgement']);
        if ($candidateSubstantive !== $currentSubstantive) { return $candidateSubstantive; }
        // A reopened incident's newer substantive answer wins over an older fix.
        return (int) ($candidate['reply_message_id'] ?? 0) > (int) ($current['reply_message_id'] ?? 0);
    }

    /** Only unmistakable whole-message courtesies are acknowledgements. */
    private static function closingAcknowledgement(string $text): bool
    {
        if (mb_strlen($text) > 250) { return false; }
        $text = mb_strtolower(str_replace(['ي', 'ك', '‌', '’'], ['ی', 'ک', ' ', "'"], $text));
        $text = preg_replace('/[،,!.؛:؟?]+/u', ' ', $text) ?? '';
        $text = trim(preg_replace('/\\s+/u', ' ', $text) ?? '');
        if ($text === '') { return false; }
        $phrases = ['خواهش می کنم', 'خواهش میکنم', 'موفق باشید', 'در خدمتیم', 'خوشحالیم',
            'سپاسگزاریم', 'ممنون از اطلاع رسانی', 'با تشکر', "you're welcome", 'you are welcome',
            'happy to help', 'glad to help', 'glad it works', 'glad it is working',
            'thanks for the update', 'thank you for the update', 'have a nice day',
            'rica ederim', 'iyi günler'];
        $pattern = implode('|', array_map(fn ($phrase) => preg_quote($phrase, '~'), $phrases));
        return preg_match('~^(?:(?:'.$pattern.')(?:\\s+|$))+$~uD', $text) === 1;
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
        return ! preg_match('/نشد|نشده|نمی|not |still |çalışmıyor|çözülmedi|اگر|اگه|آیا|کاش|فکر کنم|امیدوار|ولی|اما|\\?|؟|\\b(?:if|when|hope|wish|unresolved|hasn.t|wasn.t)\\b|çözüldü mü/iu', $text)
            && preg_match('/حل شد|رفع شد|درست شد|برطرف شد|وصل شد|working now|resolved|fixed|çözüldü|düzeldi/iu', $text) === 1;
    }

    private static function topics(string $text): array
    {
        $text = str_replace(['ي', 'ك', '‌'], ['ی', 'ک', ' '], $text);
        $patterns = ['wallet_and_suspension' => '/اعتبار|شارژ|ذخیره|تعلیق|ساعتی|خاموش|ترافیک|wallet|reserve|hourly|suspend/iu',
            'network' => '/udp|tcp|فایروال|پورت|firewall|شبکه/iu', 'dns' => '/dns|nameserver|نیم.?سرور/iu',
            'php_runtime' => '/php|session\.upload_progress/iu', 'mail' => '/smtp|imap|mail|ایمیل|ای.?میل/iu',
            'application' => '/\.net|iis|jupyter|gpu|cuda|سایت|برنامه|هاست|خطا|error/iu',
            'billing_and_documents' => '/فاکتور|مودیان|مالیات|پرداخت|عودت|استرداد|invoice|refund|tax|payment|billing|امور\\s*مالی|muhasebe|fatura|ödeme|iade|vergi/iu',
            'sales_and_selection' => '/خرید|پیشنهاد|قیمت|ماهانه|تبدیل|سفارش|quote|price|monthly|order|sales|فروش|satış|satın|fiyat|aylık|sipariş|teklif/iu',
            'account_and_identity' => '/احراز|هویت|پروفایل|ثبت نام|identity|verification|profile|account|حساب|kimlik|hesap|doğrulama|profil|kayıt/iu'];
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
            'network' => 'قواعد جاری، listener مهمان و مسیر واقعی مشتری را جدا آزمایش کن؛ SSH موفق از یک مسیر، سلامت همه مسیرها یا فیلترشدن شبکه مشتری را ثابت نمی‌کند. بدون آزمون مربوط، علت را به اینترنت مشتری یا بیرون از کنترل ما نسبت نده.',
            'dns' => 'رکورد، delegation و زمان مشاهده را تفکیک کن؛ resolve ساده، صحت authoritative DNS نیست.',
            'php_runtime' => 'نسخهٔ شاخه، build وصله و تنظیم مؤثر همان SAPI را جدا بررسی کن؛ پیام قبلی پشتیبانی اثبات وصلهٔ جاری نیست.',
            'billing_and_documents' => 'پرداخت ثبت‌شده، صدور فاکتور و ثبت مالیاتی را جدا بررسی کن؛ مبلغ و واحد را از سند همان مشتری بگیر. قول قبلی یا عذرخواهی، رسید انجام کار نیست؛ پیگیری داخلی را بر عهده بگیر و موعد تأییدنشده نده.',
            'sales_and_selection' => 'هدف و بودجه مشتری را با قابلیت و قیمت جاری تطبیق بده؛ هزینه خدمات اضافی را از سرویس پایه جدا کن. قیمت تاریخی، موجودی یا امکان سفارش امروز را ثابت نمی‌کند.',
            'account_and_identity' => 'اطلاعات موجود را دوباره نخواه؛ نتیجه احراز را از وضعیت ثبت‌شده بخوان و داده هویتی یا دسترسی را در پاسخ یا حافظه عمومی کپی نکن.',
            'mail' => 'دریافت API یا تنظیم SMTP، تحویل پیام نیست؛ رخداد همان پیام لازم است و محدودیت شبکه‌ها تعمیم داده نمی‌شود.',
            default => 'وضعیت حساب/ماشین را از سلامت برنامه جدا کن؛ پاسخ قبلی و بسته‌شدن تیکت به‌تنهایی اثبات رفع مشکل نیست.',
        };
    }
}
