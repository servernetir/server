<?php

namespace App\Services\Bale\Management;

use App\Models\Setting;
use App\Models\User;
use App\Services\Bale\Admin\AdminBaleGate;
use App\Services\Bale\Admin\AdminBaleRouter;
use App\Services\Calendar\CalendarService;
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
        $lines = ['📋 گزارش روزانه مدیریت — '.$at->format('Y-m-d H:i').' تهران'];
        foreach (['stats', 'payments', 'domains', 'hermes', 'mail', 'calendar', 'identity', 'content', 'traffic', 'fx', 'balances', 'costs'] as $topic) {
            $rows = $this->lines($topic, $s[$topic] ?? ['available' => false], false);
            $lines = array_merge($lines, $rows);
        }
        $lines[] = '۲۴ساعت، منتهی به زمان گزارش است. جزئیات با دکمه‌ها؛ مقدار ناموجود صفر فرض نشده است.';
        return mb_substr(implode("\n", $lines), 0, 3500);
    }

    public function detail(string $topic): string
    {
        $out = '';
        foreach ($this->lines($topic, $this->section($topic), true) as $line) {
            if (mb_strlen($out) + mb_strlen($line) + 1 > 3300) {
                return $out."\nچند ردیف دیگر در این پیام جا نشد؛ ادامه در پنل مدیریت.";
            }
            $out .= ($out === '' ? '' : "\n").$line;
        }
        return $out;
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
        $service = app(CalendarService::class);
        $layers = array_keys(array_filter((array) config('calendar.layers'), fn ($v, $k) => $k !== 'google' && ($v['default'] ?? true), ARRAY_FILTER_USE_BOTH));
        $items = $service->upcoming($layers, 2)->reject(fn ($i) => $i->status === 'done');
        return ['available' => true, 'partial' => $service->failures() !== [] || $service->truncatedLayers() !== [], 'count' => $items->count(),
            'items' => $items->take(10)->map(fn ($i) => ['title' => $i->title, 'at' => $i->at->copy()->setTimezone('Asia/Tehran')->format('Y-m-d H:i'), 'money' => $i->money()])->values()->all(),
            'basis' => 'default organization layers, next two days; signed-in personal Google calendar excluded'];
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
        $titles = ['stats' => '📊 مشتری و تحویل', 'payments' => '💰 دریافت موفق', 'domains' => '🌐 دامنه', 'hermes' => '⚠️ پیگیری هرمس', 'mail' => '📬 ایمیل',
            'calendar' => '📅 تقویم', 'identity' => '🪪 استعلام', 'content' => '📝 محتوا', 'traffic' => '🔎 ورودی سایت', 'fx' => '💱 نرخ ارز', 'balances' => '🏦 موجودی زیرساخت', 'costs' => '📈 افزایش هزینه تأمین‌کننده'];
        $title = $titles[$topic];
        if (empty($s['available'])) {
            $out = [$title.': نامشخص'.($topic === 'traffic' ? '؛ داده تازه و قابل تأیید بازدیدکننده GA4 و کلیک/نمایش GSC در دسترس نیست.' : ($topic === 'costs' ? '؛ مقایسه مستند هزینه قبلی و فعلی موجود نیست.' : '؛ منبع اکنون قابل خواندن نیست.'))];
            if ($detail && $topic === 'traffic') { $out[] = 'اتصال و وضعیت منابع: '.url('/admin/bale/reporting'); }
            return $out;
        }
        $v = fn ($n) => $n === null ? 'نامشخص' : number_format((int) $n);
        switch ($topic) {
            case 'stats':
                $out = [$title.': ثبت‌نام ۲۴ساعت '.$v($s['signups_24h']).' | ماه قبل '.$v($s['signups_previous_month']).' | کل '.$v($s['customers_total']),
                    'تحویل ثبت‌شده: ۲۴ساعت '.$v($s['delivered_24h']).' | ماه قبل '.$v($s['delivered_previous_month']).' ('.$s['month_label'].')'];
                if ($detail) { $out[] = '۳۰ روز اخیر: ثبت‌نام '.$v($s['signups_30d'] ?? null).' | تحویل '.$v($s['delivered_30d'] ?? null)."\nتحویل بر پایه زمان ثبت اعلان آمادگی ابر و موفقیت ساخت سایر سرویس‌هاست؛ رسید خواندن مشتری نیست."; }
                return $out;
            case 'payments':
                $amounts = array_map(fn ($r) => AccountingMoney::display($r['amount_minor'], $r['currency']), $s['currencies']);
                return [$title.' در ۲۴ساعت: '.$v($s['count']).' پرداخت؛ '.($amounts ? implode('، ', $amounts) : '۰ مبلغ ثبت‌شده').($detail ? "\nشامل شارژ کیف پول؛ این عدد درآمد فروش یا سود نیست. هر ردیف پرداخت موفق فقط یک بار جمع شده است.\nبازه [".$s['start_at'].'، '.$s['end_at'].')' : '')];
            case 'domains': return [$title.' در ۲۴ساعت: ثبت '.$v($s['registered_24h']).' | آخرین تمدید ثبت‌شده '.$v($s['renewed_24h_recorded']).($detail ? "\nتمدید بر اساس آخرین renewed_at هر دامنه است؛ دفتر کامل رویدادهای تاریخی نیست." : '')];
            case 'mail':
                $out = [$title.': نامه تازه در ۲۴ساعت '.$v($s['received_24h']).' | کل صف پاسخ '.$v($s['pending'])];
                if ($detail) { foreach ($s['items'] as $i) { $out[] = '• #'.$i['id'].' '.$this->clean($i['subject'] ?? '', 100).' — '.$this->clean($i['summary'] ?? '', 180); } }
                return $out;
            case 'calendar':
                $out = [$title.': '.$v($s['count']).' مورد در دو روز آینده'.(! empty($s['partial']) ? '؛ پوشش ناقص' : '')];
                if ($detail) { foreach ($s['items'] as $i) { $out[] = '• '.$i['at'].' '.$this->clean($i['title'], 130).($i['money'] ? ' — '.$i['money'] : ''); } }
                return $out;
            case 'identity': return $s['discrepancies'] || $s['pending_reconciliation'] || ! empty($s['funding_evidence_stale'])
                ? [$title.': '.$v($s['discrepancies']).' مغایرت | '.$v($s['pending_reconciliation']).' تطبیق معوق'.(! empty($s['funding_evidence_stale']) ? ' | شاهد موجودی قدیمی' : '').'؛ رسید همان درخواست و تازگی موجودی بررسی شود.'] : ($detail ? [$title.': مغایرت یا تطبیق معوق ثبت‌شده‌ای نیست.'] : []);
            case 'content':
                $out = [$title.': انتشار سایت در ۲۴ساعت '.$v($s['published_24h']).' (Growth '.$v($s['growth_published_24h'] ?? null).') | منتظر بازبینی '.$v($s['pending_review'])];
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
                $out = ['🔎 بازدیدکننده یکتای GA4: '.($ga['yesterday']['start_date'] ?? 'دیروز منبع').' '.$metric($ga['yesterday'] ?? [], 'total_users').' | ماه قبل '.$metric($ga['previous_month'] ?? [], 'total_users'),
                    'جستجوی GSC (کلیک/نمایش): '.($gsc['yesterday']['start_date'] ?? 'دیروز منبع').' '.$metric($gsc['yesterday'] ?? [], 'clicks').'/'.$metric($gsc['yesterday'] ?? [], 'impressions')
                    .' | ماه قبل '.$metric($gsc['previous_month'] ?? [], 'clicks').'/'.$metric($gsc['previous_month'] ?? [], 'impressions')];
                $preliminary = collect(array_merge(array_values($ga), array_values($gsc)))
                    ->contains(fn ($r) => ($r['status'] ?? '') === 'known' && ! empty($r['preliminary']));
                if ($preliminary) { $out[] = 'بخشی از داده گوگل اولیه است و ممکن است تکمیل شود.'; }
                if ($detail) {
                    foreach (['GA4' => $ga, 'GSC' => $gsc] as $source => $periods) {
                        foreach (['yesterday' => 'دیروز', 'previous_month' => 'ماه تقویمی قبل'] as $key => $label) {
                            $r = $periods[$key] ?? [];
                            $out[] = $source.' '.$label.': '.($r['start_date'] ?? 'نامشخص').' تا '.($r['end_date'] ?? 'نامشخص').'؛ منطقه زمانی '.($r['timezone'] ?? 'نامشخص');
                        }
                    }
                    $out[] = 'مشاهده منبع: '.($s['as_of'] ?? 'نامشخص').'؛ کاربران GA4 در کل بازه یکتا هستند؛ جمع آمار روزانه نیستند. کلیک و نمایش GSC بازدیدکننده نیستند.';
                    $out[] = 'اتصال و وضعیت منابع: '.url('/admin/bale/reporting');
                }
                return $out;
            case 'fx':
                $parts = [];
                foreach ($s['rows'] as $currency => $r) { $parts[] = $currency.' '.($r ? $v($r['rate_toman']).' تومان'.($r['stale'] ? ' (قدیمی)' : '').($detail ? '؛ مشاهده '.$r['at'] : '') : 'نامشخص'); }
                return [$title.': '.implode(' | ', $parts)];
            case 'balances':
                $parts = []; $unknown = 0;
                foreach ($s['rows'] as $row) {
                    if (! is_array($row)) { continue; }
                    if (($row['status'] ?? '') !== 'known' || ($row['value'] ?? null) === null) {
                        $unknown++;
                        if ($detail) {
                            $why = ['not_configured' => 'اتصال حساب کامل نیست', 'balance_api_not_integrated' => 'رابط معتبر موجودی متصل نشده',
                                'not_applicable' => 'حساب پیش‌پرداخت ندارد', 'no_observation' => 'مشاهده‌ای ثبت نشده',
                                'observation_expired' => 'مشاهده قبلی قدیمی است'][$row['reason'] ?? ''] ?? 'منبع قابل تأیید نیست';
                            $parts[] = ($row['label'] ?? $row['provider'] ?? 'زیرساخت').': نامشخص؛ '.$why;
                        } else {
                            $parts[] = ($row['label'] ?? $row['provider'] ?? 'زیرساخت').' نامشخص';
                        }
                        continue;
                    }
                    $observed = empty($row['observed_at']) ? 'نامشخص' : Carbon::parse($row['observed_at'])->setTimezone('Asia/Tehran')->format($detail ? 'Y-m-d H:i' : 'H:i');
                    $parts[] = ($row['label'] ?? $row['provider']).' '.$row['value'].' '.$row['currency'].(! empty($row['stale']) ? ' (قدیمی)' : '').'؛ مشاهده '.$observed;
                }
                return [$title.': '.implode(' | ', $parts)];
            case 'hermes':
                $out = [$title.': منتظر تصمیم '.($s['task_counts']['waiting_owner'] ?? 0).' | منتظر مشتری '.($s['task_counts']['waiting_customer'] ?? 0).' | هشدار '.count($s['alerts']).(! empty($s['source_stale']) ? '؛ مرور مالی قدیمی/نامشخص' : '')];
                if ($detail) { $out[] = 'در حال بررسی '.($s['task_counts']['investigating'] ?? 0).' | حل‌شده '.($s['task_counts']['resolved'] ?? 0).'؛ آخرین مرور مالی '.(empty($s['last_run_at']) ? 'نامشخص' : Carbon::createFromTimestamp((int) $s['last_run_at'])->setTimezone('Asia/Tehran')->format('Y-m-d H:i')).' تهران'; }
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
                    return [$title.': نامشخص؛ خط مبنای تاریخ‌دار و قابل مقایسه هنوز کامل نیست.'];
                }
                $out = [$title.': '.$v($s['increase_count']).' افزایش در برآورد کاتالوگ؛ '.$v($s['unknown_count']).' پلن نامشخص.'];
                foreach (array_slice($s['provider_increases'] ?? [], 0, $detail ? 12 : 3) as $group) {
                    $out[] = '• '.$this->clean($group['label'], 60).': '.$v($group['increase_count']).' نرخ بیشتر؛ علت تأمین‌کننده/ارز تأیید نشده؛ تعرفه تاریخ‌دار و نرخ ارز را تطبیق دهید، سپس حاشیه فروش بررسی شود.';
                    if ($detail) {
                        foreach ($group['items'] ?? [] as $item) {
                            $exp = $item['period'] === 'hour' ? 6 : 2;
                            $out[] = '  #'.$item['plan_id'].' از '.bcdiv((string) $item['before'], (string) $item['scale'], $exp).' به '.bcdiv((string) $item['after'], (string) $item['scale'], $exp).' EUR/'.($item['period'] === 'hour' ? 'ساعت' : 'ماه').'؛ زمان منبع قبلی '.$item['before_source_at'].'؛ فعلی '.$item['source_at'];
                        }
                    }
                }
                if ($detail) { $out[] = 'ثبت خط مبنا: '.$s['baseline_at'].'؛ برآورد نرمال‌شده کاتالوگ EUR است، هزینه قطعی فاکتور تأمین‌کننده نیست.'; }
                return $out;
        }
        return [$title.': نامشخص'];
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
