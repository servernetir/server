<?php

namespace App\Services\Hermes;

/** Factual owner-only sales answers from the current bounded application projection. */
final class HermesProviderReply
{
    private const LABELS = ['hetzner' => 'Hetzner Cloud', 'aeza' => 'Aeza', 'arvan' => 'آروان',
        'ovh' => 'OVHcloud', 'proxmox' => 'Proxmox', 'salad' => 'SaladCloud (GPU)',
        'hetzner-robot' => 'Hetzner Robot', 'lightnode' => 'LightNode', 'vpstc' => 'VPS.TC'];
    private const ALIASES = ['hetzner' => ['hetzner', 'هتزنر'], 'aeza' => ['aeza', 'آیزا', 'ایزا'],
        'arvan' => ['arvan', 'آروان', 'اروان', 'ابرآروان'], 'ovh' => ['ovh'], 'proxmox' => ['proxmox', 'پراکس'],
        'salad' => ['salad', 'سالاد'], 'hetzner-robot' => ['hetzner-robot', 'robot', 'ربات هتزنر'],
        'lightnode' => ['lightnode', 'لایت نود'], 'vpstc' => ['vpstc', 'vps.tc', 'vps tc']];
    private const REASONS = ['admin_disabled' => 'فروش در تنظیمات بسته است', 'not_configured' => 'اتصال حساب کامل نیست',
        'stale_price_snapshot' => 'قیمت‌ها با نرخ فعلی هماهنگ نیستند', 'provider_quote_unverified' => 'قیمت تأمین‌کننده تأیید نشده',
        'sales_preflight' => 'پیش‌شرط فروش کامل نیست', 'no_active_plan' => 'پلن فعال و باز باقی نمانده',
        'no_stock' => 'موجودی قابل عرضه ثبت نشده', 'no_valid_price' => 'قیمت قابل فروش معتبر نیست',
        'no_orderable_app' => 'برنامهٔ آمادهٔ قابل سفارش موجود نیست', 'account_currency_unverified' => 'ارز حساب تأیید نشده',
        'authentication_failed' => 'ورود API رد شده', 'connection_failed' => 'ارتباط تأمین‌کننده تأیید نشده',
        'rate_limited' => 'درخواست‌های API محدود شده', 'access_denied' => 'مجوز API کافی نیست',
        'supplier_cost_read_failed' => 'دریافت بهای تازه ناموفق بوده', 'missing_quote' => 'بهای معتبر کامل نیست',
        'invalid_quote' => 'پاسخ قیمت معتبر نیست'];

    public static function reply(string $query, array $providerContext): ?string
    {
        $intent = self::intent($query);
        if ($intent === null) { return null; }
        $now = time(); $at = $providerContext['checked_at'] ?? null;
        if (($providerContext['available'] ?? null) !== true || ($providerContext['audience'] ?? '') !== 'owner_private'
            || ($providerContext['version'] ?? '') !== 'infrastructure-context-v1-20261102'
            || ($providerContext['source'] ?? '') !== 'application_database_and_saved_receipts'
            || ($providerContext['product_scope'] ?? '') !== 'cloud_vps_gpu_sales' || ($providerContext['provider_live_checked'] ?? null) !== false
            || ! is_int($at) || $at <= 0 || $at > $now || $now - $at > 300
            || ! is_array($providerContext['providers'] ?? null) || count($providerContext['providers']) > 16) {
            return 'دادهٔ تازه و قابل‌تأیید فروش زیرساخت‌های سرورنت در این بررسی در دسترس نیست؛ باز یا بسته‌بودن فروش و علت آن را حدس نمی‌زنم.';
        }
        $rows = [];
        foreach ($providerContext['providers'] as $row) {
            if (! is_array($row) || ! is_string($row['id'] ?? null) || ! isset(self::LABELS[$row['id']])) { continue; }
            if (isset($rows[$row['id']])) { return 'دادهٔ فروش زیرساخت‌ها در این مشاهده یکدست نیست؛ وضعیت قطعی قابل گزارش نیست.'; }
            $rows[$row['id']] = $row;
        }
        $stamp = (new \DateTimeImmutable('@'.$at))->setTimezone(new \DateTimeZone('Asia/Tehran'))->format('Y-m-d H:i');
        if ($intent === 'list') {
            if ($rows === []) { return 'فهرست ثبت‌شدهٔ زیرساخت‌های فروش در این مشاهده در دسترس نیست.'; }
            $lines = ['زیرساخت‌های ثبت‌شدهٔ فروش سرورنت در همین پنل:'];
            foreach (self::LABELS as $id => $label) {
                if (isset($rows[$id])) { $lines[] = '• '.$label.' — '.self::salesText($rows[$id]); }
            }
            if (count($rows) !== count(self::LABELS) || ($providerContext['omitted_providers'] ?? 0) !== 0) {
                $lines[] = 'این مشاهده فهرست کامل را پوشش نمی‌دهد.';
            }
            $lines[] = 'مشاهدهٔ محلی: '.$stamp.' تهران؛ بازبودن فروش، سلامت زندهٔ زیرساخت نیست.';
            return implode("\n", $lines);
        }
        if (! isset($rows[$intent])) { return 'وضعیت فروش '.self::LABELS[$intent].' در این مشاهده در دسترس نیست؛ علت را حدس نمی‌زنم.'; }
        $row = $rows[$intent]; $sales = is_array($row['sales'] ?? null) ? $row['sales'] : [];
        $lines = ['فروش کلی '.self::LABELS[$intent].' در پنل: '.self::salesText($row).'.'];
        $catalog = is_array($row['catalog'] ?? null) ? $row['catalog'] : [];
        $total = self::count($catalog['plans'] ?? null); $blocked = self::count($catalog['admin_disabled'] ?? null);
        $active = self::count($catalog['active'] ?? null);
        if ($total !== null && $blocked !== null) {
            $lines[] = 'از '.self::number($total).' ردیف کاتالوگ، '.self::number($blocked).' ردیف برای فروش مسدودند؛ وضعیت کلی فروش با تک‌تک پلن‌ها فرق دارد.';
        }
        if ($active !== null) { $lines[] = self::number($active).' ردیف «فعال» کاتالوگ، تعداد عرضهٔ قابل‌فروش نیست؛ معیار فروش همان عرضه‌های قابل سفارش بالاست.'; }
        $sampled = self::count($sales['quarantine_records_sampled'] ?? null);
        $codes = is_array($sales['quarantine_reason_codes'] ?? null) ? $sales['quarantine_reason_codes'] : [];
        if ($blocked !== null && $blocked > 0 && $sampled !== null && $sampled <= 32) {
            $sample = 'نمونهٔ '.self::number($sampled).' یادداشت از ردیف‌های بسته';
            if (in_array('account_ram_limit', $codes, true)) {
                $lines[] = 'در '.$sample.' خطای سقف RAM حساب ثبت شده؛ این شاهد، علت همهٔ ردیف‌های بسته یا ظرفیت امروز حساب را ثابت نمی‌کند.';
            } else {
                $lines[] = 'علت همهٔ ردیف‌های بسته از '.$sample.' مشخص نیست؛ در این نمونه خطای سقف RAM تأیید نشده است.';
            }
        }
        $lines[] = 'مشاهدهٔ محلی: '.$stamp.' تهران؛ API و سهمیهٔ لحظه‌ای تأمین‌کننده در این پاسخ بررسی نشده‌اند.';
        return implode("\n", $lines);
    }

    /** Complete supported fact questions only. Unconsumed clauses belong to the model. */
    public static function intent(string $query): ?string
    {
        if (mb_strlen($query) > 1000) { return null; }
        $text = mb_strtolower(str_replace(['ي', 'ك', '‌'], ['ی', 'ک', ' '], trim($query)));
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        // Remove only address/politeness and final punctuation, never an internal sentence or clause.
        $text = preg_replace('/^(?:آراز|araz)[،,:]?\s+/u', '', $text) ?? $text;
        $text = preg_replace('/^(?:لطفا|لطفاً|please)\s+/u', '', $text) ?? $text;
        $text = trim(preg_replace('/[؟?!.]+$/u', '', $text) ?? '');
        if ($text === 'چرا زیرساخت آروان باز فروش پنل هاست بسته هست چیشده') { return 'arvan'; }
        if ($text === 'نه در مورد سرورنت اطلاعاتی نداری چه زیرساخت هایی میفروشیم') { return 'list'; }

        $supplier = '(?:زیرساخت(?:\s*(?:ها|های|هایی))?|(?:تامین|تأمین)\s*کننده(?:\s*(?:ها|های|هایی))?|دیتاسنتر(?:\s*(?:ها|های|هایی))?)';
        $listPatterns = [
            '/\A(?:چه|کدام|کدوم)\s+'.$supplier.'\s+فروش\s*(?:شون|شان)?\s+(?:باز|بسته|فعال|غیرفعال|مسدود)(?:\s+(?:است|هست|هستند|شده))?\z/u',
            '/\A(?:چه|کدام|کدوم)\s+'.$supplier.'(?:\s+(?:را|رو))?\s+(?:می\s*فروشیم|برای فروش داریم|عرضه می\s*کنیم|ارائه می\s*کنیم)\z/u',
            '/\A(?:فهرست|لیست)\s+'.$supplier.'\s+(?:فروش|قابل فروش|ثبت شده برای فروش)(?:\s+سرورنت)?(?:\s+(?:را|رو))?(?:\s+(?:بگو|بده|نشان بده))?\z/u',
            '/\A(?:which|what)\s+(?:providers|suppliers)\s+(?:do we sell|does servernet sell|do we offer|are registered for sales)\z/u',
            '/\A(?:show me )?(?:the )?list of (?:sales providers|sales suppliers|providers we sell|suppliers we sell)\z/u',
        ];
        foreach ($listPatterns as $pattern) { if (preg_match($pattern, $text) === 1) { return 'list'; } }

        foreach (self::ALIASES as $id => $aliases) {
            $aliases = array_values(array_unique($aliases));
            $name = '(?:زیرساخت\s+)?(?:'.implode('|', array_map(fn ($alias) => preg_quote($alias, '/'), $aliases)).')';
            $state = '(?:باز|بسته|مسدود|فعال|غیرفعال)';
            $end = '(?:\s+(?:است|هست|شده|شده است))?';
            $patterns = [
                '/\A(?:چرا|برای چه)\s+(?:فروش|عرضه)\s+'.$name.'\s+'.$state.$end.'\z/u',
                '/\Aچرا\s+'.$name.'\s+فروش(?:ش)?\s+'.$state.$end.'\z/u',
                '/\A(?:وضعیت(?: کلی)?|تعداد)\s+(?:فروش|کاتالوگ|پلن ها|پلن های قابل فروش|عرضه های قابل فروش)\s+'.$name.'(?:\s+(?:چیه|چیست|چطوره|چگونه است|چند است|چنده|چندتاست|را بگو|رو بگو|را نشان بده|رو نشان بده))?\z/u',
                '/\A(?:فروش|عرضه)\s+'.$name.'\s+(?:'.$state.$end.'|چطوره|چیه|چه وضعیتی دارد)\z/u',
                '/\A'.$name.'\s+(?:چند عرضه قابل فروش دارد|چند پلن قابل فروش دارد|برای فروش باز است|برای فروش بسته است)\z/u',
                '/\A(?:what is|what\x27s|why is|is)\s+(?:the )?'.$name.'\s+(?:sales status|sales|catalogue|catalog|plan sales)(?:\s+(?:open|closed|available))?\z/u',
            ];
            foreach ($patterns as $pattern) { if (preg_match($pattern, $text) === 1) { return $id; } }
        }
        return null;
    }

    private static function salesText(array $row): string
    {
        $sales = is_array($row['sales'] ?? null) ? $row['sales'] : [];
        $offers = self::count($sales['offers'] ?? null);
        if (($sales['state'] ?? '') === 'open') {
            return 'باز'.($offers !== null ? '؛ '.self::number($offers).' عرضهٔ قابل‌فروش' : '؛ تعداد عرضهٔ قابل‌فروش نامعلوم');
        }
        if (($sales['state'] ?? '') === 'closed') {
            $reason = $sales['reason_code'] ?? null;
            return 'بسته'.(is_string($reason) && isset(self::REASONS[$reason]) ? '؛ '.self::REASONS[$reason] : '؛ علت ثبت‌شدهٔ قابل گزارش نامعلوم');
        }
        return 'وضعیت فروش نامعلوم';
    }

    private static function count(mixed $value): ?int { return is_int($value) && $value >= 0 && $value <= 10000000 ? $value : null; }
    private static function number(int $value): string { return strtr((string) $value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']); }
}
