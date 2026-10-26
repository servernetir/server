<?php

namespace App\Services\Cloud;

use App\Models\Setting;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Import only reviewed, account-bound monthly VPS contracts. Descriptions and
 * product names are not a machine-readable resource or traffic guarantee.
 * Acceptance also requires observed supplier billing behavior: manual renewal
 * must reuse an existing unpaid renewal invoice, and parent-service cancellation
 * must stop future charges. These manifest assertions record account-specific
 * evidence gathered before mapping; they are not customer or admin opt-in flags.
 */
class VpsTcCatalog
{
    public function __construct(private VpsTcApi $api, private VpsTcPricing $pricing) {}

    public function fetch(): array
    {
        $empty = ['ok' => false, 'message' => 'کاتالوگ ماهانهٔ تأییدشده در دسترس نیست.',
            'locations' => [], 'plans' => [], 'images' => []];
        $manifest = $this->manifest();
        if ($manifest === null || ! $this->pricing->configured()) {
            return $empty;
        }
        $locations = $plans = $images = [];
        foreach ($manifest['products'] as $mapping) {
            $quote = $this->quote((string) ($mapping['product_id'] ?? ''));
            if ($quote === null) {
                // A partial read must not silently retire unrelated products.
                return $empty;
            }
            $code = CloudNaming::locationCode($quote['country'], $quote['city'], $quote['location_ref']);
            $locations[$code] = ['code' => $code, 'country' => $quote['country'],
                'city' => $quote['city'], 'provider_location' => $quote['location_ref']];
            $plans[] = ['provider_ref' => $quote['product_id'], 'provider_location' => $quote['location_ref'],
                'location_code' => $code, 'name' => 'Monthly VPS', 'vcpu' => $quote['vcpu'],
                'ram_mb' => $quote['ram_mb'], 'disk_gb' => $quote['disk_gb'],
                'disk_type' => $quote['disk_type'], 'cpu_kind' => $quote['cpu_kind'],
                'arch' => $quote['arch'], 'traffic_gb' => $quote['traffic_gb'],
                'network_terms' => $quote['network_terms'], 'cost_eur_cents' => $quote['cost_eur_cents'],
                'setup_eur_cents' => $quote['setup_eur_cents'], 'cost_hour_eur_micro' => null,
                'in_stock' => $quote['in_stock']];
            foreach ($quote['images'] as $ref => $image) {
                $images[$ref] = ['provider_ref' => (string) $ref, 'key' => $image['key'],
                    'kind' => 'os', 'family' => $image['family'], 'version' => $image['version'],
                    'label' => $image['label'], 'arch' => $quote['arch'],
                    'min_ram_mb' => $image['min_ram_mb'], 'min_disk_gb' => $image['min_disk_gb']];
            }
        }
        return ['ok' => true, 'message' => 'فقط قراردادهای ماهانهٔ بررسی‌شده وارد شدند؛ فروش ساعتی پشتیبانی نمی‌شود.',
            'locations' => array_values($locations), 'plans' => $plans, 'images' => array_values($images)];
    }

    public function quote(string $productId): ?array
    {
        try {
            return $this->reviewedQuote($productId);
        } catch (\Throwable) {
            // A changed or malformed supplier contract must close this offer.
            return null;
        }
    }

    private function reviewedQuote(string $productId): ?array
    {
        if (! preg_match('/\A[1-9][0-9]{0,17}\z/D', $productId) || ! $this->pricing->configured()) {
            return null;
        }
        $manifest = $this->manifest();
        $mapping = collect($manifest['products'] ?? [])->first(fn ($row) =>
            is_array($row) && (string) ($row['product_id'] ?? '') === $productId);
        if (! is_array($mapping) || ! $this->validMapping($mapping)) {
            return null;
        }
        $balance = $this->api->balance();
        $currency = $balance['data']['details']['currency'] ?? null;
        if (! ($balance['ok'] ?? false) || $currency !== Setting::get('vpstc_currency')) {
            return null;
        }
        $form = $this->api->productOrderForm($productId);
        $product = $form['data']['product'] ?? null;
        if (! ($form['ok'] ?? false) || ! is_array($product)
            || (isset($product['id']) && ! is_string($product['id']) && ! is_int($product['id']))
            || (isset($product['id']) && (string) $product['id'] !== $productId)
            || ! is_array($product['config']['forms'] ?? null)
            || ! hash_equals($mapping['forms_sha256'], self::formFingerprint($product['config']['forms']))) {
            return null;
        }
        $available = data_get($product, $mapping['availability']['path']);
        if ($mapping['availability']['mode'] === 'boolean') {
            if (! is_bool($available)) { return null; }
            $inStock = $available;
        } else {
            if (! is_int($available) || $available < 0) { return null; }
            $inStock = $available > 0;
        }
        foreach ($mapping['images'] as $image) {
            $custom = array_replace_recursive($mapping['custom'], $image['custom']);
            if (! $this->selectionsKnownFree($product['config']['forms'], $custom)) {
                return null;
            }
        }
        // Never divide an annual tariff by twelve, or a monthly tariff by 720.
        $periods = [];
        if (! is_array($product['config']['product'] ?? null)) {
            return null;
        }
        foreach ($product['config']['product'] as $field) {
            if (is_array($field) && ($field['id'] ?? null) === 'cycle') {
                if ($periods !== [] || ! is_array($field['items'] ?? null)) {
                    return null;
                }
                $periods = $field['items'] ?? [];
            }
        }
        $monthly = array_values(array_filter($periods, fn ($row) =>
            is_array($row) && ($row['value'] ?? null) === 'm'));
        if (count($monthly) !== 1 || ! $this->amount($monthly[0]['price'] ?? null, false)
            || ! $this->amount($monthly[0]['setup'] ?? null, true)) {
            return null;
        }
        // The supported Credit Balance method must still exist and be fee-free.
        // Unknown fees cannot be hidden inside the administrator's profit.
        $payments = $this->api->paymentMethods();
        $fees = $this->api->paymentFees();
        $method = (string) $mapping['payment_method'];
        $name = $payments['data']['payments'][$method] ?? null;
        if (is_array($name)) {
            $name = $name['name'] ?? null;
        }
        $fee = collect($fees['data']['payments'] ?? [])->first(fn ($row) =>
            is_array($row) && (is_string($row['id'] ?? null) || is_int($row['id'] ?? null))
            && (string) $row['id'] === $method);
        if (! ($payments['ok'] ?? false) || ! ($fees['ok'] ?? false)
            || ! is_string($name) || strtolower(trim($name)) !== 'credit balance'
            || ! is_array($fee) || ! $this->amount($fee['fixed_fee'] ?? null, true)
            || ! $this->amount($fee['percent_fee'] ?? null, true)
            || BigDecimal::of((string) $fee['fixed_fee'])->isGreaterThan(0)
            || BigDecimal::of((string) $fee['percent_fee'])->isGreaterThan(0)) {
            return null;
        }
        $cost = $this->pricing->costEurCents($monthly[0]['price'], $currency);
        $setup = $this->pricing->costEurCents($monthly[0]['setup'], $currency, true);
        if ($cost === null || $setup === null) {
            return null;
        }
        $total = BigDecimal::of((string) $monthly[0]['price'])->plus((string) $monthly[0]['setup'])
            ->multipliedBy(BigDecimal::of((string) Setting::get('vpstc_supplier_tax_pct'))->plus(100))
            ->dividedBy(100, 2, RoundingMode::Ceiling);
        $renewal = BigDecimal::of((string) $monthly[0]['price'])
            ->multipliedBy(BigDecimal::of((string) Setting::get('vpstc_supplier_tax_pct'))->plus(100))
            ->dividedBy(100, 2, RoundingMode::Ceiling);
        return array_replace($mapping, ['product_id' => $productId, 'currency' => $currency, 'monthly_amount' => (string) $monthly[0]['price'],
            'setup_amount' => (string) $monthly[0]['setup'], 'total_amount' => (string) $total,
            'renewal_total_amount' => (string) $renewal, 'in_stock' => $inStock,
            'cost_eur_cents' => $cost, 'setup_eur_cents' => $setup,
            'fingerprint' => hash('sha256', json_encode([$mapping, $monthly[0], $currency,
                $this->pricing->fingerprint()], JSON_THROW_ON_ERROR))]);
    }

    public function manifest(): ?array
    {
        $raw = (string) Setting::get('vpstc_catalog_manifest', '');
        if ($raw === '' || strlen($raw) > 500000) {
            return null;
        }
        try {
            $manifest = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($manifest) || ($manifest['account_fingerprint'] ?? null) !== $this->api->accountFingerprint()
                || ! is_array($manifest['products'] ?? null) || ! array_is_list($manifest['products'])
                || count($manifest['products']) < 1 || count($manifest['products']) > 100) {
                return null;
            }
            $seen = [];
            foreach ($manifest['products'] as $row) {
                if (! is_array($row) || (! is_string($row['product_id'] ?? null) && ! is_int($row['product_id'] ?? null))
                    || ! preg_match('/\A[1-9][0-9]{0,17}\z/D', (string) $row['product_id'])
                    || isset($seen[(string) $row['product_id']]) || ! $this->validMapping($row)) {
                    return null;
                }
                $seen[(string) $row['product_id']] = true;
            }
            return $manifest;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function formFingerprint(array $forms): string
    {
        return hash('sha256', json_encode($forms, JSON_THROW_ON_ERROR));
    }

    private function validMapping(array $row): bool
    {
        if (($row['kind'] ?? null) !== 'vps' || ($row['cycle'] ?? null) !== 'm'
            || ($row['renewal_mode'] ?? null) !== 'manual_invoice_deduplicated'
            || ($row['parent_cancel_stops_billing'] ?? null) !== true
            || ! is_string($row['forms_sha256'] ?? null) || ! is_string($row['country'] ?? null)
            || ! preg_match('/\A[0-9a-f]{64}\z/D', $row['forms_sha256'] ?? '')
            || ! preg_match('/\A[A-Z]{2}\z/D', $row['country'] ?? '')
            || ! is_string($row['city'] ?? null) || trim($row['city']) === ''
            || ! is_string($row['location_ref'] ?? null) || $row['location_ref'] === ''
            || ! in_array($row['disk_type'] ?? null, ['nvme', 'ssd', 'hdd'], true)
            || ! in_array($row['cpu_kind'] ?? null, ['shared', 'dedicated'], true)
            || ! in_array($row['arch'] ?? null, ['x86', 'arm'], true)
            || ! is_array($row['availability'] ?? null) || ! is_string($row['availability']['path'] ?? null)
            || strlen($row['availability']['path']) > 100
            || ! preg_match('/\A[a-zA-Z_][a-zA-Z0-9_-]*(?:\.[a-zA-Z0-9_-]+)*\z/D', $row['availability']['path'])
            || ! in_array($row['availability']['mode'] ?? null, ['boolean', 'positive_integer'], true)
            || ! is_array($row['custom'] ?? null)
            || (! is_string($row['payment_method'] ?? null) && ! is_int($row['payment_method'] ?? null))
            || ! preg_match('/\A[1-9][0-9]{0,17}\z/D', (string) ($row['payment_method'] ?? ''))
            || ! is_array($row['images'] ?? null) || $row['images'] === []
            || ($row['network_terms']['extra_charges'] ?? null) !== false
            || ! in_array($row['network_terms']['charge_mode'] ?? null, ['hard_cap', 'unmetered'], true)) {
            return false;
        }
        foreach (['vcpu', 'ram_mb', 'disk_gb'] as $key) {
            if (! is_int($row[$key] ?? null) || $row[$key] <= 0) {
                return false;
            }
        }
        if (! is_int($row['traffic_gb'] ?? null) || $row['traffic_gb'] < 0
            || ($row['network_terms']['charge_mode'] === 'hard_cap' && $row['traffic_gb'] <= 0)) {
            return false;
        }
        foreach ($row['images'] as $ref => $image) {
            if (! is_string($ref) || ! str_starts_with($ref, $row['product_id'].'|') || strlen($ref) > 200
                || ! is_array($image) || ! is_array($image['custom'] ?? null)
                || ! is_string($image['key'] ?? null)
                || ! preg_match('/\A[a-z0-9][a-z0-9.-]{0,79}\z/D', $image['key'] ?? '')
                || ! is_string($image['family'] ?? null) || ! is_string($image['version'] ?? null)
                || ! is_string($image['label'] ?? null) || ! is_int($image['min_ram_mb'] ?? null)
                || ! is_int($image['min_disk_gb'] ?? null) || $image['min_ram_mb'] < 0 || $image['min_disk_gb'] < 0
                || $image['min_ram_mb'] > $row['ram_mb'] || $image['min_disk_gb'] > $row['disk_gb']) {
                return false;
            }
        }
        return true;
    }

    private function amount(mixed $value, bool $zero): bool
    {
        return (is_int($value) || is_string($value) || (is_float($value) && is_finite($value)))
            && preg_match('/\A(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,6})?\z/D', (string) $value)
            && ($zero || BigDecimal::of((string) $value)->isGreaterThan(0));
    }

    /**
     * This first adapter supports only zero-cost fixed form choices. A new paid
     * licence, add-on or resource price must be modelled before it can be sold.
     * Hash review alone cannot make an omitted surcharge disappear from costs.
     */
    private function selectionsKnownFree(array $forms, array $custom): bool
    {
        $known = [];
        foreach ($forms as $form) {
            if (! is_array($form) || (! is_int($form['id'] ?? null) && ! is_string($form['id'] ?? null))
                || ! preg_match('/\A[1-9][0-9]{0,17}\z/D', (string) $form['id'])
                || isset($known[(string) $form['id']])
                || ! in_array($form['type'] ?? null, ['select', 'radio', 'sshkeyselect', 'servicegroupselector', 'qty', 'slider'], true)
                || ! is_array($form['items'] ?? null)) {
                return false;
            }
            $id = (string) $form['id']; $known[$id] = true;
            $knownItems = [];
            foreach ($form['items'] as $item) {
                if (! is_array($item) || (! is_string($item['id'] ?? null) && ! is_int($item['id'] ?? null))
                    || ! preg_match('/\A[1-9][0-9]{0,17}\z/D', (string) $item['id'])
                    || isset($knownItems[(string) $item['id']])) {
                    return false;
                }
                $knownItems[(string) $item['id']] = $item;
            }
            foreach (['price', 'recurring_price', 'setup'] as $key) {
                if (array_key_exists($key, $form) && (! $this->amount($form[$key], true)
                    || ! BigDecimal::of((string) $form[$key])->isZero())) {
                    return false;
                }
            }
            $selected = $custom[$id] ?? $form['value'] ?? [];
            if (! is_array($selected)) {
                return false;
            }
            if ($selected === []) {
                foreach ($form['items'] as $item) {
                    if (is_array($item) && ($item['selected'] ?? false) === true) {
                        $selected[(string) ($item['id'] ?? '')] = 1;
                    }
                }
            }
            if ($selected === []) {
                // Explicitly choose each option-bearing form. Otherwise its
                // first item or server-side default might add an unknown cost.
                if (($form['required'] ?? false) || $form['items'] !== []) {
                    return false;
                }
                continue;
            }
            if (! in_array($form['type'], ['qty', 'slider'], true) && count($selected) !== 1) {
                return false;
            }
            foreach ($selected as $itemId => $quantity) {
                $item = $knownItems[(string) $itemId] ?? null;
                if (! is_array($item) || ! $this->amount($item['price'] ?? null, true)
                    || ! $this->amount($item['setup'] ?? null, true)
                    || ! BigDecimal::of((string) $item['price'])->isZero()
                    || ! BigDecimal::of((string) $item['setup'])->isZero()) {
                    return false;
                }
                if ($form['type'] === 'servicegroupselector') {
                    if ($quantity !== 'main') { return false; }
                } elseif (in_array($form['type'], ['qty', 'slider'], true)) {
                    foreach (['minvalue', 'maxvalue'] as $bound) {
                        $value = $form['config'][$bound] ?? null;
                        if ($value !== null && ((! is_string($value) && ! is_int($value))
                            || ! preg_match('/\A(?:0|[1-9][0-9]{0,8})\z/D', (string) $value))) { return false; }
                    }
                    if ((! is_int($quantity) && ! is_string($quantity)) || ! preg_match('/\A(?:0|[1-9][0-9]{0,8})\z/D', (string) $quantity)
                        || (int) $quantity < (int) ($form['config']['minvalue'] ?? 0)
                        || (int) $quantity > (int) ($form['config']['maxvalue'] ?? 1000000)) { return false; }
                } elseif (! in_array($quantity, [1, '1'], true)) {
                    return false;
                }
            }
        }
        return array_diff(array_keys($custom), array_keys($known)) === [];
    }
}
