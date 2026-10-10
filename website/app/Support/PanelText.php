<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Facades\Schema;

/** Customer-facing text boundary. Raw business/audit data is never changed here. */
final class PanelText
{
    private static ?array $patterns = null;

    public static function text(?string $text, ?string $locale = null): string
    {
        $text = (string) $text;
        $locale ??= app()->getLocale();
        if ($locale === 'fa' || $text === '') {
            return $text;
        }

        if (($known = CustomerMessage::known($text, $locale)) !== null
            && ! str_contains($known, trans('activity.detail', [], $locale))) {
            return $known;
        }

        foreach (self::patterns() as [$key, $pattern, $names]) {
            if (preg_match($pattern, $text, $match) !== 1) {
                continue;
            }
            $vars = [];
            foreach ($names as $name) {
                $vars[$name] = self::plain($match[$name], $locale);
            }
            return trans('panel.'.$key, $vars, $locale);
        }

        return self::plain($text, $locale);
    }

    private static function plain(string $text, string $locale): string
    {
        // Product titles have explicit translations. Reuse them inside historical line items.
        if (! app()->bound('panel.language.products')) {
            app()->scoped('panel.language.products', fn () => Schema::hasTable('products')
                ? Product::select('id', 'name', 'name_en', 'name_tr', 'slug', 'category')->get()
                : collect());
        }
        $replacements = [];
        foreach (app('panel.language.products') as $product) {
            foreach (['name', 'name_en', 'name_tr'] as $column) {
                $source = (string) $product->{$column};
                if ($source !== '') {
                    $replacements[$source] = $product->displayName($locale);
                }
            }
        }
        // Keep the registered seller identity readable on foreign-language invoices.
        foreach (['seller_legal_name', 'seller_street', 'seller_number', 'seller_city', 'seller_province'] as $key) {
            $replacements[trans('panel.'.$key, [], 'fa')] = trans('panel.'.$key, [], $locale);
        }
        uksort($replacements, fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ((array) config('billing.cycles', []) as $cycle) {
            foreach ((array) ($cycle['label'] ?? []) as $label) {
                $replacements[$label] = $cycle['label'][$locale] ?? $cycle['label']['en'];
            }
        }
        return CustomerMessage::fragment(strtr($text, $replacements), $locale);
    }

    public static function email(string $subject, string $html, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        if (in_array($locale, ['en', 'tr'], true)
            && preg_match('/[\x{0600}-\x{06FF}]/u', html_entity_decode(strip_tags($subject.' '.$html), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) {
            $url = 'https://console.servernet.cloud/'.$locale.'/account';
            return [trans('ui.ntf_generic_s', [], $locale), nl2br(e(trans('ui.ntf_generic_b', ['url' => $url], $locale)))];
        }
        return [$subject, $html];
    }

    public static function bank(?string $label): string
    {
        $label = (string) $label;
        if (app()->getLocale() === 'fa') {
            return $label;
        }
        foreach ((array) config('banks.banks', []) as $bank) {
            if (in_array($label, [$bank['fa'], 'بانک '.$bank['fa']], true)) {
                return $bank['en'];
            }
        }
        return CustomerMessage::fragment(strtr($label, ['بانک تجارت' => 'Tejarat', 'تجارت' => 'Tejarat', 'بانک رسالت' => 'Resalat', 'رسالت' => 'Resalat']));
    }

    private static function patterns(): array
    {
        if (self::$patterns !== null) {
            return self::$patterns;
        }
        $out = [];
        foreach (['fa', 'en', 'tr'] as $source) {
            foreach (require lang_path($source.'/panel.php') as $key => $template) {
                if (! str_starts_with($key, 'stored_')) {
                    continue;
                }
                $names = [];
                $pattern = '';
                foreach (preg_split('/(:[a-z_]+)/i', $template, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
                    if (preg_match('/^:([a-z_]+)$/i', $part, $m)) {
                        $pattern .= '(?<'.$m[1].'>'.($key === 'stored_product_cycle' && $m[1] === 'name' ? '.*' : '.*?').')';
                        $names[] = $m[1];
                    } else {
                        $pattern .= preg_quote($part, '~');
                    }
                }
                $out[] = [$key, '~^'.$pattern.'$~suD', $names];
            }
        }
        usort($out, fn ($a, $b) => strlen($b[1]) <=> strlen($a[1]));
        return self::$patterns = $out;
    }
}
