<?php

namespace App\Services\Bale\Admin;

use App\Models\BankTransferReceipt;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Service;
use Illuminate\Support\Facades\Schema;

/**
 * صفحه‌های **خواندنیِ** کنسولِ مدیر — مشتری، سرویس، فاکتور، رسید، دامنه.
 *
 * ═══ چرا جدا از `AdminBaleCommands` ═══
 *
 * آن کلاس دربارهٔ تیکت و سلامت است و از قبل بلند بود. این‌جا «پروندهٔ کسب‌وکار»
 * است. هر دو یک قرارداد دارند: **فقط متن می‌سازند**؛ نه می‌فرستند، نه می‌نویسند.
 * پس هیچ‌کدام نمی‌توانند به‌طور اتفاقی پول جابه‌جا کنند یا پیامی به مشتری
 * بفرستند.
 *
 * ═══ چه چیزی هرگز چاپ نمی‌شود ═══
 *
 * 🔴 یک ترنسکریپتِ چت قابلِ فوروارد است و در گوشیِ گم‌شده باز می‌مانَد. پس:
 *   • ایمیل و موبایلِ مشتری — نام و کدِ عمومیِ `SN-…` کافی است
 *   • رمزِ سرویس و رمزِ روت (`services.password`)
 *   • نامِ زیرساخت و بهایِ تمام‌شده (`CloudPlan::$hidden` — سفیدبرچسبی)
 *   • متنِ خامِ خطای زیرساخت، کدِ ملی، شمارهٔ کارت
 *   • مدارکِ احراز هویت
 *
 * ⚠️ تاریخ‌ها **نسبی**‌اند («۲ روز پیش»)، چون ساعتِ اپ UTC است و روزِ کاریِ
 * کارفرما تهران؛ «۰۸:۳۰» در دو تقویم دو معنی دارد.
 */
class AdminBaleScreens
{
    /** بیشترین ردیف در یک صفحه — بیشتر از این، دکمه‌ها از صفحهٔ گوشی بیرون می‌زند */
    public const PAGE = 6;

    // ───────────────────────────── مشتری ─────────────────────────────

    /**
     * فهرستِ مشتریانِ تازه — **کِی‌ست**، نه شمارهٔ صفحه.
     *
     * ⚠️ شمارهٔ صفحه با یک ثبت‌نامِ تازه بینِ دو کلیک جابه‌جا می‌شود و ردیف را
     * دو بار یا هرگز نشان می‌دهد. آخرین شناسه پایدار است و در بودجهٔ ۶۴ بایتیِ
     * `callback_data` هم جا می‌شود.
     *
     * @return array{text:string,rows:array<int,array{id:int,label:string}>,next:?int}
     */
    public function customers(?int $cursor = null): array
    {
        if (! Schema::hasTable('customers')) {
            return ['text' => 'جدولِ مشتریان روی این نصب نیست.', 'rows' => [], 'next' => null];
        }

        $q = Customer::query()->orderByDesc('id')->limit(self::PAGE + 1);

        if ($cursor !== null && $cursor > 0) {
            $q->where('id', '<', $cursor);
        }

        $rows = $q->get();
        $more = $rows->count() > self::PAGE;
        $rows = $rows->take(self::PAGE);

        if ($rows->isEmpty()) {
            return ['text' => 'مشتریِ دیگری نیست.', 'rows' => [], 'next' => null];
        }

        return [
            'text' => "👥 مشتریانِ تازه\n\nبرای دیدنِ پرونده، روی نام بزنید.",
            'rows' => $rows->map(fn ($c) => [
                'id'    => (int) $c->id,
                'label' => '👤 '.$this->clip($c->displayName(), 26).' · '.$c->code,
            ])->all(),
            'next' => $more ? (int) $rows->last()->id : null,
        ];
    }

    /**
     * جستجو — نام، کدِ SN، ایمیل یا موبایل.
     *
     * ⚠️ روی گوشی، جستجو از ورق‌زدن بهتر است: ۲۰۰ مشتری یعنی ۳۳ صفحه «بعدی»،
     * ولی یک واژه یک آپدیت است و یک پاسخ.
     *
     * ⚠️ ایمیل و موبایل **قابلِ جستجو** هستند ولی **چاپ نمی‌شوند** — کارفرما
     * اغلب شماره را از تماس دارد و می‌خواهد پرونده را پیدا کند.
     *
     * @return array{text:string,rows:array<int,array{id:int,label:string}>,next:?int}
     */
    public function search(string $term): array
    {
        $term = trim($term);

        if (mb_strlen($term) < 2 || ! Schema::hasTable('customers')) {
            return ['text' => 'دستِ‌کم دو حرف بفرستید.', 'rows' => [], 'next' => null];
        }

        $digits = preg_replace('/\D+/', '', $term) ?? '';

        $rows = Customer::query()
            ->where(function ($q) use ($term, $digits) {
                $q->where('code', 'like', '%'.$term.'%')
                    ->orWhere('email', 'like', '%'.$term.'%');

                if ($digits !== '' && mb_strlen($digits) >= 4) {
                    $q->orWhere('phone', 'like', '%'.$digits.'%');
                }

                if (Schema::hasTable('customer_profiles')) {
                    $q->orWhereHas('profiles', fn ($p) => $p
                        ->where('first_name', 'like', '%'.$term.'%')
                        ->orWhere('last_name', 'like', '%'.$term.'%')
                        ->orWhere('company_name', 'like', '%'.$term.'%'));
                }
            })
            ->orderByDesc('id')->limit(self::PAGE)->get();

        if ($rows->isEmpty()) {
            return ['text' => '🔎 «'.$this->clip($term, 40).'» — چیزی پیدا نشد.', 'rows' => [], 'next' => null];
        }

        return [
            'text' => '🔎 نتیجهٔ «'.$this->clip($term, 40).'»',
            'rows' => $rows->map(fn ($c) => [
                'id'    => (int) $c->id,
                'label' => '👤 '.$this->clip($c->displayName(), 26).' · '.$c->code,
            ])->all(),
            'next' => null,
        ];
    }

    /** پروندهٔ مشتری — بی‌ایمیل، بی‌موبایل */
    public function customer(Customer $c): string
    {
        $services = $this->safeCount(fn () => $c->services()->whereNotIn('status', Service::DEAD_STATUSES)->count());
        $unpaid   = $this->safeCount(fn () => $c->invoices()->where('status', 'unpaid')->count());
        $tickets  = $this->safeCount(fn () => $c->tickets()->where('status', 'open')->count());
        $credit   = $this->safeCount(fn () => $c->creditBalance('IRT'));

        return implode("\n", array_filter([
            '👤 '.$c->displayName(),
            $c->code.' · '.$this->customerStatus($c),
            '',
            '🖥 سرویسِ فعال: '.fa_num((string) $services),
            '🧾 فاکتورِ پرداخت‌نشده: '.fa_num((string) $unpaid),
            '🎫 تیکتِ باز: '.fa_num((string) $tickets),
            $credit > 0 ? ('💰 اعتبار: '.fa_num(number_format($credit)).' تومان') : null,
            '',
            'عضویت: '.$this->ago($c->created_at),
        ]));
    }

    // ───────────────────────────── سرویس ─────────────────────────────

    /** @return array<int,array{id:int,label:string}> */
    public function serviceRows(Customer $c): array
    {
        return $c->services()->orderByDesc('id')->limit(self::PAGE)->get()
            ->map(fn ($s) => [
                'id'    => (int) $s->id,
                'label' => $this->serviceIcon($s).' '.$this->clip((string) $s->name, 28),
            ])->all();
    }

    /**
     * کارتِ سرویس.
     *
     * ⚠️ فقط نامِ **خودمان** چاپ می‌شود. نامِ زیرساخت و بهایِ تمام‌شده در
     * `CloudPlan::$hidden` است و سفیدبرچسبی به آن بند است — یک ترنسکریپتِ
     * فورواردشده نباید بگوید سرور را از کجا می‌خریم.
     */
    public function service(Service $s): string
    {
        $c = $s->customer;

        return implode("\n", array_filter([
            $this->serviceIcon($s).' '.$this->clip((string) $s->name, 70),
            $c ? ('👤 '.$c->displayName().' · '.$c->code) : null,
            'وضعیت: '.$this->serviceStatus($s),
            $s->plan ? ('پلن: '.$s->plan) : null,
            $s->domain ? ('دامنه: '.$s->domain) : null,
            $s->next_due_at ? ('سررسید: '.$this->ago($s->next_due_at)) : null,
            $s->price ? ('مبلغِ دوره: '.fa_num(number_format((int) $s->price)).' تومان') : null,
            '',
            $s->provision_error ? ('⚠️ '.$this->clip((string) $s->provision_error, 200)) : null,
            url('/admin/services/'.$s->id.'/history'),
        ]));
    }

    /** سرویس‌هایی که تحویلشان گیر کرده — همان تعریفی که ناظرها می‌پرسند */
    public function stuck(): array
    {
        $rows = Service::query()
            ->whereIn('provision_status', ['failed', 'manual'])
            ->whereNotIn('status', Service::DEAD_STATUSES)
            ->with('customer')->orderByDesc('id')->limit(self::PAGE)->get();

        if ($rows->isEmpty()) {
            return ['text' => '✅ تحویلِ گیرکرده‌ای نیست.', 'rows' => []];
        }

        return [
            'text' => "⚠️ تحویلِ گیرکرده\n\nاین سرویس‌ها پول گرفته‌اند ولی تحویل نشده‌اند.",
            'rows' => $rows->map(fn ($s) => [
                'id'    => (int) $s->id,
                'label' => '⚠️ '.$this->clip((string) $s->name, 22).' · '.($s->customer?->code ?? '—'),
            ])->all(),
        ];
    }

    // ───────────────────────────── فاکتور ─────────────────────────────

    /** @return array<int,array{id:int,label:string}> */
    public function invoiceRows(Customer $c): array
    {
        return $c->invoices()->orderByDesc('id')->limit(self::PAGE)->get()
            ->map(fn ($i) => [
                'id'    => (int) $i->id,
                'label' => $this->invoiceIcon($i).' '.$i->number.' · '.fa_num(number_format((int) $i->total)),
            ])->all();
    }

    public function invoice(Invoice $i): string
    {
        $c = $i->customer;

        return implode("\n", array_filter([
            $this->invoiceIcon($i).' فاکتورِ '.$i->number,
            $c ? ('👤 '.$c->displayName().' · '.$c->code) : null,
            'وضعیت: '.$this->invoiceStatus($i),
            'مبلغ: '.fa_num(number_format((int) $i->total)).' '.($i->currency_code === 'IRT' ? 'تومان' : (string) $i->currency_code),
            (int) $i->paid > 0 ? ('پرداخت‌شده: '.fa_num(number_format((int) $i->paid))) : null,
            'صدور: '.$this->ago($i->issued_at),
            '',
            url('/admin/customers/'.($i->customer_id ?? 0)),
        ]));
    }

    // ─────────────────────── رسیدِ واریزِ بانکی ───────────────────────

    public function receipts(): array
    {
        if (! Schema::hasTable('bank_transfer_receipts')) {
            return ['text' => 'جدولِ رسیدها روی این نصب نیست.', 'rows' => []];
        }

        $rows = BankTransferReceipt::where('status', 'pending')
            ->with('customer')->orderBy('id')->limit(self::PAGE)->get();

        if ($rows->isEmpty()) {
            return ['text' => '✅ رسیدِ بررسی‌نشده‌ای نیست.', 'rows' => []];
        }

        return [
            'text' => "🏦 رسیدهای واریزِ بررسی‌نشده\n\nتا تأیید نشوند، فاکتور تسویه نمی‌شود.",
            'rows' => $rows->map(fn ($r) => [
                'id'    => (int) $r->id,
                'label' => '🏦 '.fa_num(number_format((int) $r->amount)).' · '.($r->customer?->code ?? '—'),
            ])->all(),
        ];
    }

    /**
     * کارتِ رسید.
     *
     * 🔴 **دو عدد کنارِ هم چاپ می‌شوند** و این تزئین نیست: مبلغی که مشتری ادعا
     * کرده، و بدهیِ واقعیِ فاکتور. تأییدِ رسید مبلغِ ادعاشده را **نادیده
     * می‌گیرد** و بدهیِ فاکتور را تسویه می‌کند. اگر این دو نخوانند، کارفرما
     * باید پیش از تأیید ببیندش — روی گوشی جای دیگری برای دیدنش نیست.
     */
    public function receipt(BankTransferReceipt $r): string
    {
        $inv = $r->invoice;
        $due = $inv ? (int) $inv->due() : 0;
        $amt = (int) $r->amount;

        return implode("\n", array_filter([
            '🏦 رسیدِ واریز',
            $r->customer ? ('👤 '.$r->customer->displayName().' · '.$r->customer->code) : null,
            '',
            'ادعای مشتری: '.fa_num(number_format($amt)).' تومان',
            $inv ? ('بدهیِ فاکتورِ '.$inv->number.': '.fa_num(number_format($due)).' تومان') : '⚠️ فاکتورِ این رسید پیدا نشد.',
            $inv && $amt !== $due ? '⚠️ این دو عدد یکی نیستند — تأیید، بدهیِ فاکتور را تسویه می‌کند نه مبلغِ ادعاشده.' : null,
            $inv && ! $inv->isPayable() ? '⚠️ این فاکتور دیگر قابلِ پرداخت نیست (لغو یا تسویه شده).' : null,
            '',
            'شمارهٔ پیگیری: '.$this->clip((string) $r->reference, 40),
            'ثبت: '.$this->ago($r->created_at),
        ]));
    }

    // ───────────────────────────── دامنه ─────────────────────────────

    public function domains(): array
    {
        if (! Schema::hasTable('domains')) {
            return ['text' => 'جدولِ دامنه‌ها روی این نصب نیست.', 'rows' => []];
        }

        $rows = Domain::whereIn('provision_status', ['manual', 'failed'])
            ->whereNotIn('status', Domain::DEAD_STATUSES)
            ->with('customer')->orderByDesc('id')->limit(self::PAGE)->get();

        if ($rows->isEmpty()) {
            return ['text' => '✅ دامنه‌ای منتظرِ اقدام نیست.', 'rows' => []];
        }

        return [
            'text' => "🌐 دامنه‌های منتظرِ اقدام\n\nاینها پرداخت شده‌اند ولی ثبت نشده‌اند.",
            'rows' => $rows->map(fn ($d) => [
                'id'    => (int) $d->id,
                'label' => '🌐 '.$this->clip((string) $d->domain, 28),
            ])->all(),
        ];
    }

    public function domain(Domain $d): string
    {
        return implode("\n", array_filter([
            '🌐 '.$d->domain,
            $d->customer ? ('👤 '.$d->customer->displayName().' · '.$d->customer->code) : null,
            'وضعیت: '.(string) $d->status.' · '.(string) $d->provision_status,
            $d->expires_at ? ('انقضا: '.$this->ago($d->expires_at)) : null,
            '',
            $d->provision_error ? ('⚠️ '.$this->clip((string) $d->provision_error, 250)) : null,
            url('/admin/domains'),
        ]));
    }

    // ───────────────────────────── کمکی ─────────────────────────────

    private function customerStatus(Customer $c): string
    {
        return ['active' => '🟢 فعال', 'pending' => '🟡 در انتظار',
                'suspended' => '🔴 معلق', 'closed' => '⚪️ بسته'][$c->status] ?? (string) $c->status;
    }

    private function serviceStatus(Service $s): string
    {
        return ['active' => '🟢 فعال', 'pending' => '🟡 منتظرِ پرداخت',
                'awaiting_provision' => '🔵 در حالِ آماده‌سازی', 'suspended' => '🔴 معلق',
                'provision_failed' => '⚠️ تحویل ناموفق', 'cancelled' => '⚪️ لغو',
                'terminated' => '⚪️ خاتمه'][$s->status] ?? (string) $s->status;
    }

    private function serviceIcon(Service $s): string
    {
        return match ($s->status) {
            'active' => '🟢', 'suspended' => '🔴',
            'provision_failed' => '⚠️', 'awaiting_provision' => '🔵',
            default => '⚪️',
        };
    }

    private function invoiceStatus(Invoice $i): string
    {
        return ['paid' => '✅ پرداخت‌شده', 'unpaid' => '🟠 پرداخت‌نشده',
                'canceled' => '⚪️ لغو', 'draft' => '📝 پیش‌نویس'][$i->status] ?? (string) $i->status;
    }

    private function invoiceIcon(Invoice $i): string
    {
        return match ($i->status) {
            'paid' => '✅', 'unpaid' => '🟠',
            default => '⚪️',
        };
    }

    private function safeCount(callable $fn): int
    {
        try {
            return (int) $fn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** نسبی، نه مطلق — ساعتِ اپ UTC است و روزِ کاری تهران */
    private function ago($at): string
    {
        if ($at === null) {
            return '—';
        }

        try {
            $mins = (int) round(abs(\Illuminate\Support\Carbon::parse($at)->diffInMinutes(now())));
            $past = \Illuminate\Support\Carbon::parse($at)->lt(now());

            $span = match (true) {
                $mins < 60   => $mins.' دقیقه',
                $mins < 1440 => intdiv($mins, 60).' ساعت',
                default      => intdiv($mins, 1440).' روز',
            };

            return fa_num($span).($past ? ' پیش' : ' دیگر');
        } catch (\Throwable) {
            return '—';
        }
    }

    private function clip(string $s, int $max): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);

        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1).'…' : $s;
    }
}
