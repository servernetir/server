<?php

namespace App\Services\Bale\Management;

use App\Models\Setting;
use App\Models\User;
use App\Services\Bale\Admin\AdminBaleGate;
use App\Services\Bale\Admin\AdminBaleRouter;
use App\Services\ExchangeRate;
use App\Services\Finance\AccountingMoney;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

/** Read-only manager views. Building a report never sends, stamps mail, or runs a worker. */
class BaleManagerDailyDigest
{
    public const TOPICS = ['stats', 'payments', 'domains', 'hermes', 'mail', 'calendar', 'identity', 'content', 'traffic', 'balances', 'fx', 'costs'];
    private const TITLES = ['stats' => '📊 مشتری و تحویل', 'payments' => '💰 دریافت موفق', 'domains' => '🌐 دامنه', 'hermes' => '⚠️ پیگیری هرمس', 'mail' => '📬 ایمیل',
        'calendar' => '📅 تقویم', 'identity' => '🪪 استعلام', 'content' => '📝 محتوا', 'traffic' => '🔎 ورودی سایت', 'fx' => '💱 نرخ ارز', 'balances' => '🏦 موجودی زیرساخت', 'costs' => '📈 افزایش هزینه تأمین‌کننده'];
    private ?Carbon $reportAt = null;

    public function snapshot(): array
    {
        $previous = $this->reportAt;
        $this->reportAt = now();
        try {
            $sections = [];
            foreach (self::TOPICS as $topic) { $sections[$topic] = $this->section($topic); }
            return ['observed_at' => $this->reportAt->toIso8601String(), 'timezone' => 'Asia/Tehran', 'sections' => $sections];
        } finally { $this->reportAt = $previous; }
    }

    public function section(string $topic): array
    {
        if (! in_array($topic, self::TOPICS, true)) { throw new \InvalidArgumentException('Unknown digest topic'); }
        try { return $this->{$topic}(); }
        catch (\Throwable) { return ['available' => false, 'reason' => 'source_unavailable']; }
    }

    /** Explicit Gregorian calendar month; all query bounds converted from Tehran to UTC. */
    public function periods(): array
    {
        $local = ($this->reportAt ?? now())->copy()->setTimezone('Asia/Tehran');
        return ['now' => $local->copy()->utc(), 'day_start' => $local->copy()->subDay()->utc(),
            'month_start' => $local->copy()->startOfMonth()->subMonth()->utc(),
            'month_end' => $local->copy()->startOfMonth()->utc(),
            'rolling_start' => $local->copy()->subDays(30)->utc(),
            'yesterday_start' => $local->copy()->startOfDay()->subDay()->utc(),
            'yesterday_end' => $local->copy()->startOfDay()->utc(),
            'month_label' => $local->copy()->startOfMonth()->subMonth()->format('Y-m').' میلادی'];
    }

    public function text(?array $snapshot = null): string
    {
        $snapshot ??= $this->snapshot();
        $s = $snapshot['sections'];
        $at = Carbon::parse($snapshot['observed_at'] ?? now())->setTimezone('Asia/Tehran');
        // Bale automatically parses Markdown. Only fixed headings contain markup;
        // the surrounding spaces are required by Bale's documented bold syntax.
        $heading = "📋 *گزارش روزانه مدیریت* \n".$this->jalali($at).' تهران';
        $blocks = [];
        foreach (['stats', 'payments', 'domains', 'hermes', 'mail', 'calendar', 'identity', 'content', 'traffic', 'fx', 'balances', 'costs'] as $topic) {
            $rows = $this->lines($topic, $s[$topic] ?? ['available' => false], false);
            if ($rows === []) { continue; }
            $title = self::TITLES[$topic];
            if (str_starts_with($rows[0], $title)) {
                $rows[0] = ltrim(mb_substr($rows[0], mb_strlen($title)), ': ');
                if ($rows[0] === '') { array_shift($rows); }
            } elseif ($topic === 'traffic') {
                $rows[0] = preg_replace('/^🔎\s*/u', '', $rows[0]) ?? $rows[0];
            }
            [$icon, $label] = explode(' ', $title, 2);
            $blocks[] = ['heading' => $icon.' *'.$label.'* ',
                'body' => implode("\n", $rows)];
        }
        $footer = 'بازهٔ ۲۴ساعت تا زمان گزارش است. ماه قبل، ماه تقویمی میلادی است. جزئیات با دکمه‌ها؛ مقدار ناموجود صفر فرض نشده است.';
        return $this->boundedReport($heading, $blocks, $footer);
    }

    /** Keep every section heading and balanced markup, even with unusually long source labels. */
    private function boundedReport(string $heading, array $blocks, string $footer): string
    {
        $render = static fn (array $parts) => $heading."\n\n".implode("\n\n", array_map(
            static fn (array $block) => $block['heading']."\n".$block['body'], $parts))."\n\n".$footer;
        $text = $render($blocks);
        while (mb_strlen($text) > 3500) {
            $longest = null;
            foreach ($blocks as $key => $block) {
                if (mb_strlen($block['body']) > 180 && ($longest === null || mb_strlen($block['body']) > mb_strlen($blocks[$longest]['body']))) { $longest = $key; }
            }
            if ($longest === null) { break; }
            $note = '… جزئیات با دکمهٔ همین بخش.';
            $limit = max(180, mb_strlen($blocks[$longest]['body']) - (mb_strlen($text) - 3500));
            $kept = [];
            foreach (explode("\n", $blocks[$longest]['body']) as $line) {
                if (mb_strlen(implode("\n", [...$kept, $line])) + mb_strlen($note) + 1 > $limit) { break; }
                $kept[] = $line;
            }
            $blocks[$longest]['body'] = ($kept === [] ? '' : implode("\n", $kept)."\n").$note;
            $text = $render($blocks);
        }
        return $text;
    }

    public function detail(string $topic): string
    {
        $rows = $this->lines($topic, $this->section($topic), true);
        [$icon, $label] = explode(' ', self::TITLES[$topic], 2);
        $out = $icon.$this->strong($label);
        if (($rows[0] ?? '') === self::TITLES[$topic]) { array_shift($rows); }
        foreach ($rows as $paragraph) {
            if (mb_strlen($out) + mb_strlen($paragraph) + 2 > 3300) {
                return $out."\n\nچند مورد دیگر در این پیام جا نشد؛ ادامه در پنل مدیریت.";
            }
            $out .= "\n\n".$paragraph;
        }
        return $out;
    }

    /** Display only: query bounds and source calendar periods remain unchanged. */
    private function jalali(mixed $value, bool $withTime = true, string $inputTimezone = 'UTC'): string
    {
        if ($value === null || $value === '') { return 'نامشخص'; }
        try {
            $at = $value instanceof \DateTimeInterface ? Carbon::instance($value)->copy() : Carbon::parse((string) $value, $inputTimezone);
            $at->setTimezone('Asia/Tehran');
            [$year, $month, $day] = \App\Support\Jalali::ofMoment($at, 'Asia/Tehran');
            return sprintf('%04d/%02d/%02d', $year, $month, $day).($withTime ? ' '.$at->format('H:i') : '');
        } catch (\Throwable) { return 'نامشخص'; }
    }

    /** A Google date is a source-calendar day, not a UTC instant. */
    private function sourceDay(mixed $date): string
    {
        return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
            ? $this->jalali($date, false, 'Asia/Tehran') : 'نامشخص';
    }

    private function monthRange(string $label): string
    {
        if (! preg_match('/^(\d{4}-\d{2})\b/u', $label, $match)) { return 'نامشخص'; }
        try {
            $start = Carbon::parse($match[1].'-01', 'Asia/Tehran')->startOfDay();
            return $this->jalali($start, false).' تا '.$this->jalali($start->copy()->endOfMonth(), false);
        } catch (\Throwable) { return 'نامشخص'; }
    }

    private function plain(string $text, int $limit = 500): string
    {
        return strtr($this->clean($text, $limit), ['*' => '＊', '_' => '＿', '[' => '［', ']' => '］']);
    }

    private function strong(string $text): string
    {
        return ' *'.$this->plain($text).'* ';
    }

    /** Group an already calculated decimal string; never cast money through float. */
    private function decimal(string $value): string
    {
        if (! preg_match('/^(-?)(\d+)(\.\d+)?$/D', $value, $match)) { return $this->plain($value); }
        return $match[1].preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $match[2]).($match[3] ?? '');
    }

    private function paymentAmount(int $minor, string $currency): string
    {
        $display = AccountingMoney::display($minor, $currency);
        $amount = substr($display, 0, -(strlen($currency) + 1));
        $unit = ['IRT' => 'تومان (IRT)', 'IRR' => 'ریال (IRR)'][$currency] ?? $this->plain($currency);
        return $this->decimal($amount).' '.$unit;
    }

    public function buttons(): array
    {
        $rows = [];
        foreach ([['stats' => '📊 آمار', 'hermes' => '⚠️ علت و اقدام'], ['mail' => '📬 ایمیل', 'calendar' => '📅 تقویم'],
            ['payments' => '💰 دریافت‌ها', 'balances' => '🏦 موجودی زیرساخت'], ['domains' => '🌐 دامنه', 'content' => '📝 محتوا'],
            ['traffic' => '🔎 ورودی سایت', 'identity' => '🪪 استعلام'], ['fx' => '💱 ارز', 'costs' => '📈 افزایش هزینه']] as $row) {
            $buttons = [];
            foreach ($row as $topic => $label) { $buttons[] = ['text' => $label, 'data' => AdminBaleRouter::CB_PREFIX.'md:'.$topic]; }
            $rows[] = $buttons;
        }
        return $rows;
    }

    protected function stats(): array
    {
        $p = $this->periods();
        $this->requireColumns('customers', ['created_at']);
        return ['available' => true, 'month_label' => $p['month_label'],
            'signups_24h' => $this->countBetween('customers', 'created_at', $p['day_start'], $p['now']),
            'signups_previous_month' => $this->countBetween('customers', 'created_at', $p['month_start'], $p['month_end']),
            'signups_30d' => $this->countBetween('customers', 'created_at', $p['rolling_start'], $p['now']),
            'customers_total' => DB::table('customers')->count(),
            'delivered_24h' => $this->delivered($p['day_start'], $p['now']),
            'delivered_previous_month' => $this->delivered($p['month_start'], $p['month_end']),
            'delivered_30d' => $this->delivered($p['rolling_start'], $p['now']),
            'delivery_basis' => 'cloud readiness notice claim; other services successful provisioning timestamp'];
    }

    private function delivered(Carbon $from, Carbon $to): ?int
    {
        try {
            $this->requireColumns('services', ['id', 'cloud_plan_id', 'provisioned_at', 'provision_status']);
            $this->requireColumns('cloud_instances', ['service_id', 'ready_notified_at']);
            $internal = (array) config('billing.internal_service_ids', []);
            $cloud = DB::table('cloud_instances')->whereNotIn('service_id', $internal)
                ->where('ready_notified_at', '>=', $from)->where('ready_notified_at', '<', $to)->distinct()->count('service_id');
            $other = DB::table('services')->whereNull('cloud_plan_id')->whereNotIn('id', $internal)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('cloud_instances')->whereColumn('cloud_instances.service_id', 'services.id'))
                ->where('provision_status', 'done')->where('provisioned_at', '>=', $from)->where('provisioned_at', '<', $to)->count();
            return $cloud + $other;
        } catch (\Throwable) { return null; }
    }

    protected function payments(): array
    {
        $this->requireColumns('payments', ['status', 'paid_at', 'currency_code', 'amount']);
        $p = $this->periods();
        $rows = DB::table('payments')->where('status', 'paid')->where('paid_at', '>=', $p['day_start'])->where('paid_at', '<', $p['now'])
            ->selectRaw('currency_code, COUNT(*) AS payment_count, SUM(amount) AS amount_minor')->groupBy('currency_code')->get();
        return ['available' => true, 'period' => '24h', 'start_at' => $p['day_start']->toIso8601String(), 'end_at' => $p['now']->toIso8601String(), 'count' => (int) $rows->sum('payment_count'),
            'currencies' => $rows->map(fn ($r) => ['currency' => $r->currency_code, 'count' => (int) $r->payment_count, 'amount_minor' => (int) $r->amount_minor])->all(),
            'basis' => 'successful payment rows; wallet charges are included, not product revenue'];
    }

    protected function domains(): array
    {
        $this->requireColumns('domains', ['registered_at', 'status', 'meta']);
        $p = $this->periods();
        $renewed = 0;
        foreach (DB::table('domains')->where('meta', 'like', '%renewed_at%')->get(['meta']) as $row) {
            $at = (json_decode((string) $row->meta, true) ?: [])['renewed_at'] ?? null;
            if ($at && Carbon::parse($at, 'UTC')->gte($p['day_start']) && Carbon::parse($at, 'UTC')->lt($p['now'])) { $renewed++; }
        }
        return ['available' => true, 'registered_24h' => $this->countBetween('domains', 'registered_at', $p['day_start'], $p['now']),
            'renewed_24h_recorded' => $renewed, 'renewal_basis' => 'latest recorded renewed_at per domain; historical repeated renewals are not an event ledger'];
    }

    protected function mail(): array
    {
        $this->requireColumns('mailbox_messages', ['handled_at', 'is_system', 'needs_reply', 'importance', 'subject', 'summary', 'received_at', 'category']);
        $p = $this->periods();
        $base = DB::table('mailbox_messages')->where('is_system', false)
            ->where(fn ($q) => $q->whereNull('category')->orWhereNotIn('category', (array) config('mailboxes.quiet_categories', ['bulk', 'spam'])));
        $q = (clone $base)->where('received_at', '>=', $p['day_start'])->where('received_at', '<', $p['now']);
        return ['available' => true, 'received_24h' => (clone $q)->count(),
            'pending' => (clone $base)->whereNull('handled_at')->where('needs_reply', true)->count(), 'items' => $q->orderByDesc('importance')->orderByDesc('received_at')->limit(8)
            ->get(['id', 'subject', 'summary', 'received_at'])->map(fn ($r) => (array) $r)->all()];
    }

    protected function calendar(): array
    {
        return app(ManagerBusinessCalendarSnapshot::class)->snapshot();
    }

    protected function identity(): array
    {
        $this->requireColumns('identity_requests', ['discrepancy', 'financial_status', 'created_at']);
        $fundingStale = null;
        try {
            $controls = app(\App\Services\IdentityApi\Controls::class);
            $fundingStale = $controls->dispatchEnabled() && ! $controls->fundingReady();
        } catch (\Throwable) {}
        return ['available' => true, 'discrepancies' => DB::table('identity_requests')->where('discrepancy', true)->count(),
            'pending_reconciliation' => DB::table('identity_requests')->where('financial_status', 'pending_reconciliation')->count(),
            'funding_evidence_stale' => $fundingStale];
    }

    protected function content(): array
    {
        $p = $this->periods();
        $this->requireColumns('posts', ['published_at', 'status']);
        $published = DB::table('posts')->where('status', 'published')->where('published_at', '>=', $p['day_start'])->where('published_at', '<', $p['now'])->count();
        $review = $growthPublished = null;
        if (Schema::hasTable('growth_contents')) {
            $review = DB::table('growth_contents')->whereIn('status', ['draft', 'review'])->count();
            if (Schema::hasColumns('growth_contents', ['post_id', 'published_at'])) {
                $growthPublished = DB::table('growth_contents')->where('status', 'published')->whereNotNull('post_id')
                    ->where('published_at', '>=', $p['day_start'])->where('published_at', '<', $p['now'])->count();
            }
        }
        $jobs = ['available' => false, 'failed_24h' => null, 'uncertain_cost' => null, 'reasons' => []];
        if (Schema::hasTable('growth_jobs') && Schema::hasColumns('growth_jobs', ['status', 'finished_at', 'actual_cost_irt', 'max_cost_irt', 'error_code'])) {
            $failed = DB::table('growth_jobs')->where('status', 'failed')->where('finished_at', '>=', $p['day_start'])->where('finished_at', '<', $p['now']);
            $jobs = ['available' => true, 'failed_24h' => (clone $failed)->count(),
                'uncertain_cost' => DB::table('growth_jobs')->whereIn('status', ['processing', 'failed', 'completed'])->where('max_cost_irt', '>', 0)->whereNull('actual_cost_irt')->count(),
                'reasons' => $failed->selectRaw('error_code, COUNT(*) AS count')->groupBy('error_code')->orderByDesc('count')->limit(3)->get()->map(fn ($r) => (array) $r)->all()];
        }
        return ['available' => true, 'published_24h' => $published, 'growth_published_24h' => $growthPublished, 'pending_review' => $review, 'jobs' => $jobs,
            'basis' => 'published site posts; external social publications are not inferred from drafts'];
    }

    protected function traffic(): array
    {
        // Consent/refresh belongs to the explicit connector and scheduled path.
        // A preview must never start an OAuth flow or query Google.
        return app(ManagerTrafficSnapshot::class)->cachedSnapshot();
    }

    protected function fx(): array
    {
        $rows = [];
        foreach (['USD', 'EUR', 'TRY'] as $currency) {
            $row = app(ExchangeRate::class)->current($currency);
            $rows[$currency] = $row ? ['rate_toman' => $row['rate_toman'], 'at' => $row['at'] ?? null, 'source_at' => $row['source_at'] ?? null,
                'stale' => ! empty($row['stale']) || empty($row['at']) || Carbon::parse($row['at'])->lt(now()->subHours(8))] : null;
        }
        return ['available' => true, 'rows' => $rows];
    }

    protected function balances(): array
    {
        return ['available' => true, 'rows' => app(ProviderBalanceSnapshot::class)->cachedSnapshot()];
    }

    protected function hermes(): array
    {
        // Native Store::read() calls change() and can create state/lock files.
        // Read the existing atomically replaced encrypted snapshot directly.
        // Reading malformed binding evidence must not auto-disable the console
        // during a preview. Gate::binding() owns that mutable recovery behavior.
        $owner = json_decode((string) Setting::getSecret(AdminBaleGate::KEY_BIND), true);
        if (Setting::get(AdminBaleGate::KEY_ENABLED) !== '1' || ! is_array($owner)
            || ! ctype_digit((string) ($owner['user_id'] ?? ''))
            || ! User::find((int) $owner['user_id'])?->isAdmin()) { return ['available' => false, 'reason' => 'owner_unavailable']; }
        $state = $this->readHermesState($owner);
        $alerts = array_values(array_filter($state['alerts'] ?? [], fn ($a) => ! empty($a['active']) && empty($a['acknowledged_at'])));
        $priority = ['business_review_unavailable' => 0, 'metering_gap' => 1, 'estimated_underwater' => 2, 'traffic_budget_risk' => 3,
            'wallet_runway_low' => 4, 'invoice_receipt_mismatch' => 5, 'active_service_overdue' => 6];
        usort($alerts, fn ($a, $b) => [($a['data']['severity'] ?? '') === 'critical' ? 0 : 1, $priority[$a['code']] ?? 20]
            <=> [($b['data']['severity'] ?? '') === 'critical' ? 0 : 1, $priority[$b['code']] ?? 20]);
        return ['available' => true, 'task_counts' => array_count_values(array_column($state['tasks'] ?? [], 'status')),
            'alerts' => array_map(fn ($a) => ['code' => $a['code'] ?? 'unknown', 'service_id' => $a['data']['service_id'] ?? null], $alerts),
            'last_run_at' => $state['business']['at'] ?? null,
            'source_stale' => empty($state['business']['at']) || (int) $state['business']['at'] < now()->subHour()->timestamp];
    }

    protected function costs(): array
    {
        return app(ManagementCostChanges::class)->snapshot(false);
    }

    private function lines(string $topic, array $s, bool $detail): array
    {
        $title = self::TITLES[$topic];
        if (empty($s['available'])) {
            $out = [$title, 'نامشخص'.($topic === 'traffic' ? '؛ داده تازه و قابل تأیید بازدیدکننده GA4 و کلیک/نمایش GSC در دسترس نیست.' : ($topic === 'costs' ? '؛ مقایسه مستند هزینه قبلی و فعلی موجود نیست.' : '؛ منبع اکنون قابل خواندن نیست.'))];
            return $out;
        }
        $v = fn ($n) => $n === null ? 'نامشخص' : number_format((int) $n);
        switch ($topic) {
            case 'stats':
                $out = [$title];
                if ($detail) {
                    $out[] = $this->strong('ثبت‌نام کاربران')."\n۲۴ ساعت گذشته: ".$v($s['signups_24h']).' کاربر'."\nماه قبل: ".$v($s['signups_previous_month']).' کاربر'."\nکل کاربران: ".$v($s['customers_total']).' کاربر';
                    $out[] = $this->strong('تحویل ثبت‌شده')."\n۲۴ ساعت گذشته: ".$v($s['delivered_24h']).' سرویس'."\nماه قبل: ".$v($s['delivered_previous_month']).' سرویس';
                    $out[] = '۳۰ روز اخیر: ثبت‌نام '.$v($s['signups_30d'] ?? null).' کاربر · تحویل '.$v($s['delivered_30d'] ?? null).' سرویس';
                    $out[] = 'تحویل بر پایه زمان ثبت اعلان آمادگی ابر و موفقیت ساخت سایر سرویس‌هاست؛ رسید خواندن مشتری نیست.';
                } else {
                    $out[] = 'ثبت‌نام: ۲۴ساعت '.$v($s['signups_24h']).' کاربر · ماه قبل '.$v($s['signups_previous_month']).' کاربر · کل '.$v($s['customers_total']).' کاربر';
                    $out[] = 'تحویل ثبت‌شده: ۲۴ساعت '.$v($s['delivered_24h']).' سرویس · ماه قبل '.$v($s['delivered_previous_month']).' سرویس';
                }
                $out[] = 'بازهٔ ماه قبل (تقویم میلادی): '.$this->monthRange($s['month_label'] ?? '');
                return $out;
            case 'payments':
                $out = [$title, '۲۴ ساعت گذشته: '.$v($s['count']).' پرداخت موفق'];
                foreach ($s['currencies'] as $row) {
                    $out[] = '•'.$this->strong($this->paymentAmount((int) $row['amount_minor'], (string) $row['currency'])).($detail ? ' · '.$v($row['count'] ?? null).' پرداخت' : '');
                }
                if ($s['currencies'] === []) { $out[] = 'مبلغی برای پرداخت موفق ثبت نشده است.'; }
                if ($detail) {
                    $out[] = 'شامل شارژ کیف پول؛ این عدد درآمد فروش یا سود نیست. هر ردیف پرداخت موفق فقط یک بار جمع شده است.';
                    $out[] = 'از '.$this->jalali($s['start_at'] ?? null).' تا پیش از '.$this->jalali($s['end_at'] ?? null).' تهران';
                }
                return $out;
            case 'domains':
                return [$title, 'ثبت در ۲۴ ساعت گذشته: '.$v($s['registered_24h']).' دامنه',
                    'آخرین تمدید ثبت‌شده در ۲۴ ساعت: '.$v($s['renewed_24h_recorded']).' دامنه',
                    ...($detail ? ['تمدید بر اساس آخرین زمان تمدید هر دامنه است؛ دفتر کامل رویدادهای تاریخی نیست.'] : [])];
            case 'mail':
                $out = [$title, 'نامه تازه در ۲۴ ساعت: '.$v($s['received_24h']).' نامه'."\nکل صف پاسخ: ".$v($s['pending']).' نامه'];
                if ($detail) {
                    foreach ($s['items'] as $item) {
                        $out[] = '✉️ '.$this->strong('نامه #'.(string) $item['id'])."\nعنوان: ".$this->plain($item['subject'] ?? '', 120)
                            ."\nخلاصه: ".$this->plain($item['summary'] ?? '', 250)
                            .(! empty($item['received_at']) ? "\nدریافت: ".$this->jalali($item['received_at']).' تهران' : '');
                    }
                }
                return $out;
            case 'calendar':
                $partial = ! empty($s['partial']);
                $out = [$title, 'امروز و فردا: '.$v($s['count'] ?? null).' رویداد'.($partial ? '؛ حداقل '.$v($s['observed_count'] ?? null).' رویداد مشاهده‌شده' : ''),
                    '۷ روز آینده: '.$v($s['count_7d'] ?? null).' رویداد'.($partial ? '؛ حداقل '.$v($s['observed_count_7d'] ?? null).' رویداد مشاهده‌شده' : '')];
                if ($partial) { $out[] = '⚠️'.$this->strong('پوشش ناقص').'؛ نبود داده به معنی نبود رویداد نیست.'; }
                if (! empty($s['content_count'])) { $out[] = 'برنامهٔ محتوا در ۷ روز: '.($partial ? 'حداقل ' : '').$v($s['content_count']).' مورد'.($partial ? ' مشاهده‌شده' : '').'؛ در فهرست اقدامات تکرار نشده است.'; }
                foreach (array_slice($s['items'] ?? [], 0, $detail ? 10 : 3) as $item) {
                    $out[] = '•'.$this->strong($this->clean($item['title'], 160))."\nزمان: ".$this->jalali($item['at'] ?? null, true, 'Asia/Tehran').' تهران'
                        .($detail && ! empty($item['type_label']) ? ' · '.$this->plain((string) $item['type_label'], 60) : '')
                        .(! empty($item['money']) ? "\nمبلغ: ".$this->plain((string) $item['money']) : '');
                }
                if ($detail) {
                    $out[] = 'بازهٔ ۷ روز: '.$this->jalali($s['lookahead_from_at'] ?? null).' تا '.$this->jalali($s['lookahead_to_at'] ?? null).' تهران';
                    $types = [];
                    foreach ($s['type_counts'] ?? [] as $type => $count) {
                        $types[] = $this->plain((string) ($s['type_labels'][$type] ?? $type), 80).': '.($partial ? 'حداقل ' : '').$v($count).' رویداد'.($partial ? ' مشاهده‌شده' : '');
                    }
                    if ($types !== []) { $out[] = 'تفکیک رویدادها'.($partial ? ' (مشاهده‌شده)' : '').":\n".implode("\n", $types); }
                    $out[] = 'پوشش سازمانی: '.implode('، ', array_map(fn ($label) => $this->plain((string) $label, 80), $s['coverage_labels'] ?? []));
                    foreach (['failed_layers' => 'منابع ناموفق', 'truncated_layers' => 'منابع دارای سقف نتیجه', 'missing_layers' => 'منابع تنظیم‌نشده'] as $key => $label) {
                        if (! empty($s[$key])) { $out[] = $label.': '.implode('، ', array_map(fn ($item) => $this->plain((string) $item, 80), $s[$key])); }
                    }
                    if (! empty($s['omitted_count'])) { $out[] = $v($s['omitted_count']).' اقدام دیگر در تقویم مدیریت است.'; }
                }
                return $out;
            case 'identity':
                return $s['discrepancies'] || $s['pending_reconciliation'] || ! empty($s['funding_evidence_stale'])
                    ? [$title, 'مغایرت: '.$v($s['discrepancies']).' درخواست · تطبیق معوق: '.$v($s['pending_reconciliation']).' درخواست'
                        .(! empty($s['funding_evidence_stale']) ? ' · شاهد موجودی قدیمی' : ''), 'اقدام: رسید همان درخواست و تازگی موجودی بررسی شود.']
                    : ($detail ? [$title, 'مغایرت یا تطبیق معوق ثبت‌شده‌ای نیست.'] : []);
            case 'content':
                $out = [$title, 'انتشار سایت در ۲۴ساعت: '.$v($s['published_24h']).' محتوا (Growth '.$v($s['growth_published_24h'] ?? null).')'."\n".'منتظر بازبینی: '.$v($s['pending_review']).' محتوا'];
                $jobs = $s['jobs'] ?? ['available' => false];
                if (empty($jobs['available'])) { $out[] = 'پردازش Growth: نامشخص؛ رسید پردازش قابل خواندن نیست.'; }
                elseif ($jobs['failed_24h'] || $jobs['uncertain_cost']) {
                    $out[] = 'Growth: خطای ۲۴ساعت '.$v($jobs['failed_24h']).' | هزینه تطبیق‌نشده '.$v($jobs['uncertain_cost']).'؛ رسید مصرف بررسی و پیش از اجرای دوباره تطبیق شود.';
                    if ($detail) {
                        foreach ($jobs['reasons'] as $reason) {
                            $why = ['quote_day_expired' => 'اعتبار زمانی برآورد گذشته؛ برآورد تازه آماده شود', 'quote_increased' => 'سقف برآورد بیشتر شده؛ سقف هزینه برای تأیید بررسی شود',
                                'content_changed' => 'نسخه محتوا عوض شده؛ نسخه انتخاب‌شده تطبیق داده شود', 'gateway_key_changed' => 'کلید اتصال عوض شده؛ اتصال و برآورد تطبیق داده شود',
                                'invalid_ai_output' => 'خروجی معتبر نبود؛ رسید مصرف و پاسخ پالایش‌شده بررسی شود'][$reason['error_code'] ?? ''] ?? 'علت قطعی نیازمند رسید همان پردازش است؛ گزارش پالایش‌شده و رسید مصرف بررسی شود';
                            $out[] = '• '.$v($reason['count']).' مورد: '.$why;
                        }
                        $out[] = 'هزینه تطبیق‌نشده می‌تواند مربوط به پردازش قدیمی‌تر باشد؛ actual_cost ناموجود به معنی هزینه صفر نیست.';
                    }
                }
                return $out;
            case 'traffic':
                $ga = $s['ga4']['periods'] ?? [];
                $gsc = $s['gsc']['periods'] ?? [];
                $metric = fn (array $period, string $key) => ($period['status'] ?? '') === 'known' ? $v($period[$key] ?? null) : 'نامشخص';
                $out = [$title];
                foreach (['GA4' => $ga, 'GSC' => $gsc] as $source => $periods) {
                    $sourceRows = [$detail ? $this->strong($source === 'GA4' ? 'بازدیدکننده یکتای GA4' : 'جستجوی GSC (کلیک/نمایش)') : $source];
                    foreach (['yesterday' => 'دیروز منبع', 'previous_month' => 'ماه تقویمی قبل'] as $key => $label) {
                        $period = $periods[$key] ?? [];
                        $counts = $source === 'GA4' ? $metric($period, 'total_users').' کاربر یکتا'
                            : $metric($period, 'clicks').' کلیک · '.$metric($period, 'impressions').' نمایش';
                        $dates = $this->sourceDay($period['start_date'] ?? null);
                        if ($detail || $key === 'previous_month') { $dates .= ' تا '.$this->sourceDay($period['end_date'] ?? null); }
                        $sourceRows[] = $label.': '.$counts.' ('.$dates.')';
                        if ($detail) {
                            $timezone = (string) ($period['timezone'] ?? 'نامشخص');
                            $sourceRows[] = 'منطقه زمانی دورهٔ منبع: '.(preg_match('/^[A-Za-z0-9_+\/-]+$/D', $timezone) ? $timezone : $this->plain($timezone));
                        }
                    }
                    $out[] = implode("\n", $sourceRows);
                }
                $preliminary = collect(array_merge(array_values($ga), array_values($gsc)))
                    ->contains(fn ($period) => ($period['status'] ?? '') === 'known' && ! empty($period['preliminary']));
                if ($preliminary) { $out[] = 'بخشی از داده گوگل اولیه است و ممکن است تکمیل شود.'; }
                if ($detail) {
                    $out[] = 'مشاهده منبع: '.$this->jalali($s['as_of'] ?? null).' تهران';
                    $out[] = 'تاریخ روزهای آماری، شمسیِ همان روز تقویمی منبع است؛ دوره به تهران جابه‌جا نشده است. کاربران GA4 در کل بازه یکتا هستند؛ جمع آمار روزانه نیستند. کلیک و نمایش GSC بازدیدکننده نیستند.';
                }
                return $out;
            case 'fx':
                $out = [$title];
                foreach ($s['rows'] as $currency => $r) {
                    $out[] = '• '.$this->plain((string) $currency).': '.($r ? $v($r['rate_toman']).' تومان'.($r['stale'] ? ' (قدیمی)' : '').($detail ? '؛ مشاهده '.$this->jalali($r['at'] ?? null).' تهران' : '') : 'نامشخص');
                }
                return $out;
            case 'balances':
                $out = [$title]; $unknown = [];
                foreach ($s['rows'] as $row) {
                    if (! is_array($row)) { continue; }
                    $label = $this->plain((string) ($row['label'] ?? $row['provider'] ?? 'زیرساخت'), 200);
                    if (($row['status'] ?? '') !== 'known' || ($row['value'] ?? null) === null) {
                        if ($detail) {
                            $why = ['not_configured' => 'اتصال حساب کامل نیست', 'balance_api_not_integrated' => 'رابط معتبر موجودی متصل نشده',
                                'not_applicable' => 'حساب پیش‌پرداخت ندارد', 'no_observation' => 'مشاهده‌ای ثبت نشده',
                                'observation_expired' => 'مشاهده قبلی قدیمی است'][$row['reason'] ?? ''] ?? 'منبع قابل تأیید نیست';
                            $out[] = '• '.$label.': نامشخص؛ '.$why;
                        } else { $unknown[] = $label; }
                        continue;
                    }
                    $observed = $this->jalali($row['observed_at'] ?? null);
                    $out[] = '• '.$label.': '.$this->decimal((string) $row['value']).' '.$this->plain((string) $row['currency']).(! empty($row['stale']) ? ' (قدیمی)' : '').'؛ مشاهده '.$observed.' تهران';
                }
                if ($unknown !== []) {
                    $line = 'نامشخص: ';
                    foreach ($unknown as $label) {
                        $separator = $line === 'نامشخص: ' || $line === '' ? '' : '، ';
                        if (mb_strlen($line.$separator.$label) > 99 && $separator !== '') {
                            $out[] = $line.'،';
                            $line = $label;
                        } else { $line .= $separator.$label; }
                    }
                    $out[] = $line;
                }
                if (count($out) === 1) { $out[] = 'نامشخص؛ مشاهدهٔ موجودی در دسترس نیست.'; }
                return $out;
            case 'hermes':
                $ownerCount = $s['task_counts']['waiting_owner'] ?? 0;
                $ownerLine = 'منتظر تصمیم: '.$v($ownerCount).' مورد';
                $alertLine = 'هشدار فعال: '.$v(count($s['alerts'])).' مورد';
                $out = [$title, ($ownerCount > 0 ? $this->strong($ownerLine) : $ownerLine)
                    ."\nمنتظر مشتری: ".$v($s['task_counts']['waiting_customer'] ?? 0).' مورد'
                    ."\n".($s['alerts'] !== [] ? $this->strong($alertLine) : $alertLine)
                    .(! empty($s['source_stale']) ? "\n⚠️".$this->strong('مرور مالی قدیمی/نامشخص') : '')];
                if ($detail) {
                    $out[] = 'در حال بررسی: '.$v($s['task_counts']['investigating'] ?? 0).' مورد'."\nحل‌شده: ".$v($s['task_counts']['resolved'] ?? 0).' مورد';
                    $out[] = 'آخرین مرور مالی: '.$this->jalali(empty($s['last_run_at']) ? null : Carbon::createFromTimestamp((int) $s['last_run_at'])).' تهران';
                    $out[] = $this->strong('علت و اقدام پیشنهادی');
                }
                $group = array_count_values(array_column($s['alerts'], 'code'));
                foreach (array_slice($group, 0, $detail ? 12 : 2, true) as $code => $count) {
                    $advice = [
                        'unassigned_resource' => 'منبع تطبیق‌نخورده؛ کاربرد داخلی/مشتری را مشخص کنید، سپس اتصال پرونده را اصلاح کنید.',
                        'service_resource_missing' => 'منبع سرویس پیدا نشده؛ موجودی کامل همان زیرساخت و شناسه سرویس را تطبیق دهید.',
                        'closed_service_still_running' => 'منبع سرویس بسته باقی است؛ رسید حذف و هزینه را بررسی کنید.',
                        'inventory_unavailable' => 'موجودی نامعلوم؛ اتصال و تازگی داده همان زیرساخت را بررسی کنید.',
                        'operations_run_failed' => 'چرخه پایش ناموفق؛ رسید خطای اجرای همان چرخه را بررسی کنید.',
                        'traffic_threshold' => 'مصرف نزدیک سقف؛ گزارش تازه ترافیک و پلن همان سرویس را تطبیق دهید.',
                        'collection_unverified' => 'رسید دریافت کامل نیست؛ دریافت‌های ثبت‌شده و رسید همان فاکتور را تطبیق دهید.',
                        'invoice_receipt_mismatch' => 'مبلغ رسید و فاکتور نیازمند تطبیق است؛ جمع پرداخت موفق و شناسه رسید بررسی شود.',
                        'active_service_overdue' => 'فاکتور سرویس فعال سررسید گذشته؛ وضعیت دریافت و مهلت همان سرویس بررسی شود.',
                        'metering_gap' => 'ثبت مصرف ساعتی عقب است؛ زمان آخرین برداشت و رسید چرخه مالی بررسی شود.',
                        'supplier_cost_unverified' => 'هزینه ساعتی تازه تأیید نشده؛ تعرفه معتبر و تاریخ مشاهده بررسی شود.',
                        'estimated_underwater' => 'نرخ فروش زیر برآورد کاتالوگ است؛ هزینه و نرخ ارز تطبیق و تغییر پلن برای تأیید آماده شود.',
                        'wallet_runway_low' => 'اعتبار طبق نرخ فعلی زیر ۲۴ساعت است؛ رسید و نرخ همان سرویس بررسی و شارژ به مشتری یادآوری شود.',
                        'traffic_usage_unverified' => 'مصرف یا سهمیه نامشخص است؛ گزارش تازه و قرارداد پلن تطبیق داده شود.',
                        'traffic_budget_risk' => 'مصرف در معرض عبور از سقف است؛ ترافیک تازه و هزینه اضافه بررسی شود.',
                        'business_review_unavailable' => 'مرور مالی تکمیل نشده؛ رسید اجرای همان چرخه و اتصال منبع بررسی شود.',
                    ][$code] ?? 'یافته نیازمند بررسی؛ علت و رسید همان پرونده در مینی‌اپ ← عملیات بررسی شود.';
                    $out[] = '• '.$count.' مورد: '.$advice;
                }
                return $out;
            case 'costs':
                if (($s['status'] ?? '') !== 'compared') {
                    return [$title, 'نامشخص؛ خط مبنای تاریخ‌دار و قابل مقایسه هنوز کامل نیست.'];
                }
                $out = [$title, $v($s['increase_count']).' افزایش در برآورد کاتالوگ؛ '.$v($s['unknown_count']).' پلن نامشخص.'];
                foreach (array_slice($s['provider_increases'] ?? [], 0, $detail ? 12 : 3) as $group) {
                    $out[] = '• '.$this->plain($group['label'], 60).': '.$v($group['increase_count']).' نرخ بیشتر؛ علت تأمین‌کننده/ارز تأیید نشده؛ تعرفه تاریخ‌دار و نرخ ارز را تطبیق دهید، سپس حاشیه فروش بررسی شود.';
                    if ($detail) {
                        foreach ($group['items'] ?? [] as $item) {
                            $exp = $item['period'] === 'hour' ? 6 : 2;
                            $out[] = '  #'.$item['plan_id'].' از '.bcdiv((string) $item['before'], (string) $item['scale'], $exp).' به '.bcdiv((string) $item['after'], (string) $item['scale'], $exp).' EUR/'.($item['period'] === 'hour' ? 'ساعت' : 'ماه').'؛ زمان منبع قبلی '.$this->jalali($item['before_source_at'] ?? null).' تهران؛ فعلی '.$this->jalali($item['source_at'] ?? null).' تهران';
                        }
                    }
                }
                if ($detail) { $out[] = 'ثبت خط مبنا: '.$this->jalali($s['baseline_at'] ?? null).' تهران؛ برآورد نرمال‌شده کاتالوگ EUR است، هزینه قطعی فاکتور تأمین‌کننده نیست.'; }
                return $out;
        }
        return [$title, 'نامشخص'];
    }

    private function countBetween(string $table, string $field, Carbon $from, Carbon $to): int
    {
        return DB::table($table)->where($field, '>=', $from)->where($field, '<', $to)->count();
    }

    private function requireColumns(string $table, array $columns): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) { throw new \RuntimeException('source_schema_unavailable'); }
    }

    private function clean(string $text, int $limit): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? ''), 0, $limit);
    }

    protected function readHermesState(array $owner): array
    {
        if (! ctype_digit((string) ($owner['user_id'] ?? '')) || ! ctype_digit((string) ($owner['chat_id'] ?? ''))) { throw new \RuntimeException('invalid_owner'); }
        $path = storage_path('app/private/hermes-operations/').hash('sha256', $owner['user_id'].':'.$owner['chat_id']);
        if (! is_file($path) || is_link($path) || filesize($path) > 18 * 1024 * 1024) { throw new \RuntimeException('native_snapshot_unavailable'); }
        $state = json_decode(Crypt::decryptString(file_get_contents($path)), true, 64, JSON_THROW_ON_ERROR);
        if (($state['version'] ?? null) !== 1 || ! is_array($state['alerts'] ?? null) || ! is_array($state['tasks'] ?? null)) { throw new \RuntimeException('native_snapshot_invalid'); }
        return $state;
    }
}
