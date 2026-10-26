<?php

namespace App\Services\Cloud;

use App\Models\Setting;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Reviewed account evidence, never a guessed market FX or a currency-label fallback. */
final class VpsTcMoneyContract
{
    public const KEY = 'vpstc_money_contract';

    public function __construct(private VpsTcApi $api) {}

    /** IDs and account evidence are supplied privately through an encrypted setting. */
    public function verified(): ?array
    {
        try {
            $raw = Setting::getSecret(self::KEY);
            $stored = Setting::query()->whereKey(self::KEY)->value('value');
            if (! is_string($stored) || ! hash_equals($stored, (string) Setting::get(self::KEY, ''))) { return null; }
            if (! is_string($raw) || strlen($raw) > 20000) { return null; }
            $proof = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($proof) || ($proof['version'] ?? null) !== 1
                || ($proof['account_fingerprint'] ?? null) !== $this->api->accountFingerprint()
                || ($proof['base_currency'] ?? null) !== 'USD' || ($proof['account_currency'] ?? null) !== 'TRY'
                || ($proof['source']['kind'] ?? null) !== 'reviewed_portal_currency_table'
                || ! in_array($proof['source']['url'] ?? null, ['https://www.vps.tc/en/vps', 'https://vps.tc/en/vps'], true)
                || ! $this->hash($proof['source']['sha256'] ?? null)
                || ! $this->id($proof['payment_method'] ?? null)
                || ! $this->id($proof['anchor']['invoice_id'] ?? null)
                || ! $this->id($proof['anchor']['service_id'] ?? null)
                || ! $this->id($proof['anchor']['product_id'] ?? null)
                || (string) ($proof['source']['product_id'] ?? '') !== (string) $proof['anchor']['product_id']
                || ($proof['anchor']['cycle'] ?? null) !== 'm'
                || ! $this->hash($proof['anchor']['financial_sha256'] ?? null)
                || ! $this->hash($proof['anchor']['service_sha256'] ?? null)
                || ! is_string($proof['source']['reviewed_at'] ?? null)
                || ! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T/', $proof['source']['reviewed_at'])
                || \Illuminate\Support\Carbon::parse($proof['source']['reviewed_at'])->gt(now()->addMinutes(5))) { return null; }
            $rate = VpsTcInvoiceAmounts::decimal($proof['multiplier'] ?? null);
            $base = VpsTcInvoiceAmounts::decimal($proof['anchor']['base_price'] ?? null);
            $pct = VpsTcInvoiceAmounts::decimal($proof['anchor']['tax_percent'] ?? null);
            if (! $rate?->isPositive() || ! $base?->isPositive() || $pct === null || $pct->isGreaterThan(100)) { return null; }
            if (! $this->equal($proof['source']['base_price'] ?? null, $proof['anchor']['base_price'])
                || ! $this->equal($proof['source']['native_price'] ?? null, (string) $base->multipliedBy($rate))) { return null; }
            $read = $this->api->invoice((string) $proof['anchor']['invoice_id']);
            $invoice = $read['data']['invoice'] ?? null;
            $amounts = is_array($invoice) ? VpsTcInvoiceAmounts::inspect($invoice) : null;
            if (! ($read['ok'] ?? false) || $amounts === null
                || (string) ($invoice['id'] ?? '') !== (string) $proof['anchor']['invoice_id']
                || ($invoice['status'] ?? null) !== 'Paid' || ($invoice['currency'] ?? null) !== 'TRY'
                || ! $this->equal($invoice['rate'] ?? null, $proof['multiplier'])
                || ! $this->equal($amounts['subtotal'], (string) $base->multipliedBy($rate))
                || ! $this->equal($amounts['tax'], (string) BigDecimal::of($amounts['subtotal'])->multipliedBy($pct)->dividedBy(100, 18, RoundingMode::Unnecessary))
                || ! $this->zero($amounts['tax2'])
                || ! hash_equals($proof['anchor']['financial_sha256'], self::financialFingerprint($invoice))) { return null; }
            $items = $invoice['items'] ?? null;
            if (! is_array($items) || count($items) !== 1) { return null; }
            $item = array_values($items)[0];
            if (! is_array($item) || strtolower((string) ($item['type'] ?? '')) !== 'hosting'
                || (string) ($item['item_id'] ?? '') !== (string) $proof['anchor']['service_id']
                || ! $this->equal($item['qty'] ?? null, '1')) { return null; }
            $serviceRead = $this->api->service((string) $proof['anchor']['service_id']);
            $service = $serviceRead['data']['service'] ?? [];
            if (! ($serviceRead['ok'] ?? false) || (string) ($service['id'] ?? '') !== (string) $proof['anchor']['service_id']
                || strtolower((string) ($service['billingcycle'] ?? '')) !== 'monthly'
                || ! is_string($service['name'] ?? null) || trim($service['name']) === ''
                || ! hash_equals($proof['anchor']['service_sha256'], self::serviceFingerprint($service))) { return null; }
            if (! hash_equals($stored, (string) Setting::query()->whereKey(self::KEY)->value('value'))) { return null; }
            return $proof + ['financial_amounts' => $amounts, 'digest' => hash('sha256', $raw)];
        } catch (\Throwable) { return null; }
    }

    /** A literal blank currency is resolvable only by fresh account-specific financial proof. */
    public function resolveCurrency(array $balance): ?string
    {
        if (array_key_exists('ok', $balance)) {
            if ($balance['ok'] !== true) { return null; }
            if (! is_array($balance['data'] ?? null) || ! is_array($balance['data']['details'] ?? null)) { return null; }
            $balance = $balance['data']['details'] ?? [];
        }
        $currency = $balance['currency'] ?? null;
        if (in_array($currency, ['USD', 'EUR', 'USDT', 'TRY'], true)) { return $currency; }
        if ($currency !== '') { return null; }
        return $this->verified()['account_currency'] ?? null;
    }

    /** Full computed configuration must reconcile in both supplier units, including consumed credit. */
    public function normalizeQuote(string $productId, string $paymentMethod, array $baseMonthly, array $nativeMonthly, array $calculated, ?array $custom = null, ?string $hostname = null): ?array
    {
        try {
            $proof = $this->verified();
            if (! $proof || (string) $proof['payment_method'] !== $paymentMethod
                || ! $this->zero($baseMonthly['setup'] ?? null)
                || ! $this->zero($nativeMonthly['setup'] ?? null)) { return null; }
            $rate = BigDecimal::of((string) $proof['multiplier']);
            $base = $this->canonical($baseMonthly['price'] ?? null);
            $native = $this->canonical($nativeMonthly['price'] ?? null);
            $summary = $calculated['summary'] ?? null;
            $items = $calculated['items'] ?? null;
            if (! $base?->isPositive() || ! $native?->isPositive()
                || ! $this->close($base->multipliedBy($rate), $native)
                || ! is_array($summary) || ! is_array($items) || count($items) !== 1) { return null; }
            if (array_diff(array_keys($summary), ['subtotal', 'total', 'credit', 'setup_fee', 'cost', 'discount',
                'recurring', 'recurring_discount', 'tax', 'grandtotal', 'tax2', 'fee', 'fixed_fee', 'percent_fee',
                'discount_amount', 'discount_total', 'fees', 'discounts']) !== []) { return null; }
            foreach ($calculated as $key => $value) {
                if (! in_array($key, ['summary', 'items', 'error', 'errors', 'valid', 'success', 'status'], true)
                    && (is_numeric($value) || is_array($value))) { return null; }
            }
            $item = array_values($items)[0];
            if (! is_array($item) || ($item['valid'] ?? null) !== true
                || (string) ($item['product']['id'] ?? '') !== $productId
                || ! $this->echoMatches($item['product'] ?? [], $productId, $custom, $hostname)
                || ! $this->equal($item['product']['price'] ?? null, (string) $base)
                || ! $this->equal($summary['subtotal'] ?? null, (string) $base)
                || ! $this->equal($summary['cost'] ?? null, (string) $base)) { return null; }
            foreach ([$calculated, $summary, $item, $item['product']] as $row) {
                if (($row['valid'] ?? true) !== true || ($row['success'] ?? true) === false || ($row['status'] ?? true) === false) { return null; }
                foreach (['error', 'errors'] as $key) {
                    if (array_key_exists($key, $row) && ! in_array($row[$key], [null, false, '', [], 0], true)) { return null; }
                }
            }
            foreach (['price_today', 'recurring_price'] as $key) {
                if (array_key_exists($key, $item['product']) && ! $this->equal($item['product'][$key], (string) $base)) { return null; }
            }
            foreach ($item['product'] as $key => $value) {
                if (! in_array($key, ['price', 'price_today', 'recurring_price', 'setup'], true)
                    && preg_match('/(?:fee|surcharge|discount|total|cost|price)/i', (string) $key) && ! $this->zero($value)) { return null; }
            }
            foreach ($item as $key => $value) {
                if (! in_array($key, ['product', 'valid', 'error', 'errors', 'info', 'coupon', 'success', 'status', 'index', 'domains'], true)
                    && (is_numeric($value) || is_array($value))) { return null; }
            }
            if ((array_key_exists('index', $item) && $item['index'] !== 0)
                || (array_key_exists('domains', $item) && ! $this->hostnameOnly($item['domains'], $hostname ?? 'preflight-'.$productId.'.example.invalid'))) { return null; }
            foreach ([$summary, $item] as $row) {
                foreach (['fee', 'fixed_fee', 'percent_fee', 'tax2', 'discount', 'discount_amount', 'discount_total', 'setup_fee'] as $key) {
                    if (array_key_exists($key, $row) && ! $this->zero($row[$key])) { return null; }
                }
                foreach (['fees', 'discounts', 'recurring_discount', 'coupon'] as $key) {
                    if (array_key_exists($key, $row) && $row[$key] !== []) { return null; }
                }
            }
            $taxes = $summary['tax'] ?? null;
            if (! is_array($taxes) || count($taxes) !== 1) { return null; }
            $tax = array_values($taxes)[0];
            $pct = VpsTcInvoiceAmounts::decimal($proof['anchor']['tax_percent']);
            if (! is_array($tax) || $pct === null || $pct->isGreaterThan(100)
                || ! $this->equal($tax['value'] ?? null, (string) $pct)) { return null; }
            $expectedTax = $base->multipliedBy($pct)->dividedBy(100, 18, RoundingMode::Unnecessary);
            if (! $this->equal($tax['tax'] ?? null, (string) $expectedTax)) { return null; }
            $due = $this->canonical($summary['total'] ?? null); $credit = $this->canonical($summary['credit'] ?? null);
            $baseGross = $base->plus($expectedTax);
            if ($due === null || $credit === null || ! $this->close($due->plus($credit), $baseGross)) { return null; }
            if (array_key_exists('grandtotal', $summary) && ! $this->equal($summary['grandtotal'], (string) $baseGross)) { return null; }
            $gross = $native->multipliedBy($pct->plus(100))->dividedBy(100, 18, RoundingMode::Unnecessary);
            if (! $this->close($baseGross->multipliedBy($rate), $gross)) { return null; }
            $periods = $summary['recurring'] ?? null;
            if (! is_array($periods) || count($periods) !== 1) { return null; }
            $period = array_values($periods)[0];
            if (! is_array($period) || ($period['value'] ?? null) !== 'm'
                || ! $this->equal($period['price'] ?? null, (string) $baseGross)) { return null; }
            return ['currency' => 'TRY', 'monthly_amount' => $this->text($native), 'setup_amount' => '0',
                'total_amount' => (string) $gross->toScale(2, RoundingMode::Ceiling),
                'renewal_total_amount' => (string) $gross->toScale(2, RoundingMode::Ceiling),
                'money_contract' => $proof['digest'], 'multiplier' => (string) $rate,
                'tax_percent' => (string) $pct,
                'configuration_verified' => $custom !== null,
                'calculation_sha256' => hash('sha256', json_encode($calculated, JSON_THROW_ON_ERROR))];
        } catch (\Throwable) { return null; }
    }

    public static function financialFingerprint(array $invoice): string
    {
        $projection = [];
        foreach (['id', 'status', 'currency', 'rate', 'subtotal', 'tax', 'tax2', 'grandtotal',
            'total', 'credit', 'discount', 'discount_amount', 'discount_total', 'discounts', 'items'] as $key) {
            $projection[$key] = $invoice[$key] ?? null;
        }
        return hash('sha256', json_encode($projection, JSON_THROW_ON_ERROR));
    }

    public static function serviceFingerprint(array $service): string
    {
        return hash('sha256', json_encode(['id' => (string) ($service['id'] ?? ''),
            'name' => $service['name'] ?? null, 'billingcycle' => $service['billingcycle'] ?? null,
            'date_created' => $service['date_created'] ?? null], JSON_THROW_ON_ERROR));
    }

    private function equal(mixed $left, mixed $right): bool
    {
        $a = $this->canonical($left); $b = $this->canonical($right);
        return $a !== null && $b !== null && $this->close($a, $b);
    }
    private function text(BigDecimal $value): string
    {
        $text = (string) $value;
        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }
    private function close(BigDecimal $a, BigDecimal $b): bool
    {
        return $a->compareTo($b) === 0;
    }
    private function canonical(mixed $value, bool $round = true): ?BigDecimal
    {
        $float = is_float($value);
        if ((! is_string($value) && ! is_int($value) && ! $float) || ($float && ! is_finite($value))) { return null; }
        $text = $float ? json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) : (string) $value;
        if (! preg_match('/\A(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,18})?\z/D', $text)) { return null; }
        $number = BigDecimal::of($text);
        // Only JSON numeric floats may contain binary tails. Exact decimal
        // strings remain exact; >=1e-8 differences survive the 8-place boundary.
        return $round && $float ? $number->toScale(8, RoundingMode::HalfUp) : $number;
    }
    private function zero(mixed $value): bool { return $this->canonical($value, false)?->isZero() ?? false; }
    private function echoMatches(array $product, string $id, ?array $custom, ?string $hostname): bool
    {
        $hostname ??= 'preflight-'.$id.'.example.invalid';
        if (($product['domain'] ?? null) !== $hostname || ($product['recurring'] ?? null) !== 'm'
            || ! $this->zero($product['setup'] ?? null) || ! is_array($product['config']['forms'] ?? null)) { return false; }
        $forms = $product['config']['forms'];
        if ($custom !== null && count($forms) !== count($custom)) { return false; }
        $seen = [];
        foreach ($forms as $form) {
            $fid = is_array($form) ? (string) ($form['id'] ?? '') : '';
            if (! $this->id($fid) || isset($seen[$fid]) || ! is_array($form['value'] ?? null)
                || ($custom !== null && (! isset($custom[$fid]) || ! is_array($custom[$fid])
                    || count($form['value']) !== count($custom[$fid])))) { return false; }
            $seen[$fid] = true;
            foreach ($form['value'] as $quantity) {
                if (! is_string($quantity) && ! is_int($quantity)) { return false; }
            }
            foreach (['price', 'recurring_price', 'setup'] as $key) {
                $amount = VpsTcInvoiceAmounts::decimal($form[$key] ?? null);
                if ($amount === null || ! $amount->isZero()) { return false; }
            }
            foreach ($form as $key => $value) {
                if (! in_array($key, ['price', 'recurring_price', 'setup'], true)
                    && preg_match('/(?:fee|surcharge|discount|total|cost|price)/i', (string) $key) && ! $this->zero($value)) { return false; }
            }
            foreach ($custom[$fid] ?? [] as $item => $qty) {
                $actual = $form['value'][$item] ?? null;
                if ((! is_string($qty) && ! is_int($qty)) || (! is_string($actual) && ! is_int($actual))
                    || ! array_key_exists($item, $form['value']) || (string) $actual !== (string) $qty) { return false; }
            }
        }
        $cycles = array_values(array_filter($product['config']['product'] ?? [], fn ($field) => is_array($field) && ($field['id'] ?? null) === 'cycle'));
        if (count($cycles) === 1) {
            foreach ($cycles[0] as $key => $value) {
                if (! in_array($key, ['price', 'setup'], true)
                    && preg_match('/(?:fee|surcharge|discount|total|cost|price)/i', (string) $key) && ! $this->zero($value)) { return false; }
            }
        }
        return count($cycles) === 1 && ($cycles[0]['value'] ?? null) === 'm'
            && $this->equal($cycles[0]['price'] ?? null, $product['price'] ?? null)
            && $this->zero($cycles[0]['setup'] ?? null);
    }
    private function id(mixed $id): bool { return (is_string($id) || is_int($id)) && preg_match('/\A[1-9][0-9]{0,17}\z/D', (string) $id); }
    private function hash(mixed $hash): bool { return is_string($hash) && preg_match('/\A[0-9a-f]{64}\z/D', $hash); }

    private function hostnameOnly(mixed $domains, string $hostname): bool
    {
        if (! is_array($domains) || array_keys($domains) !== [$hostname] || ! is_array($domains[$hostname])) { return false; }
        $domain = $domains[$hostname];
        if (array_diff(array_keys($domain), ['category_id', 'category_name', 'config', 'id', 'name', 'nameservers',
            'period', 'price', 'recurring_price', 'setup', 'tld', 'type']) !== []
            || ($domain['type'] ?? null) !== 'hostname' || ($domain['name'] ?? null) !== $hostname
            || ($domain['tld'] ?? null) !== '' || ($domain['config'] ?? null) !== [] || ($domain['nameservers'] ?? null) !== []
            || ! $this->zero($domain['period'] ?? null) || ! $this->zero($domain['price'] ?? null) || ! $this->zero($domain['setup'] ?? null)) { return false; }
        foreach (['id', 'category_id', 'category_name'] as $key) {
            if (! array_key_exists($key, $domain) || $domain[$key] !== null) { return false; }
        }
        return array_key_exists('recurring_price', $domain) && ($domain['recurring_price'] === null || $this->zero($domain['recurring_price']));
    }
}
