<?php

namespace App\Services\Shop;

use App\Models\ServerPart;

final class HardwarePresentation
{
    public static function description(?string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $text)));
        return mb_strlen($text) > 160 ? rtrim(mb_substr($text, 0, 157)).'…' : $text;
    }

    public static function productDescription(string $name, ?string $summary): string
    {
        $summary = trim((string) $summary);
        return self::description(mb_stripos($summary, $name) === 0 ? $summary : $name.' — '.$summary);
    }

    public static function text(string $key): string
    {
        return (string) data_get(config('hardware_shop.copy', []), $key.'.'.app()->getLocale(), $key);
    }

    public static function metadata(string $slug): array
    {
        return (array) config('hardware_catalog.'.$slug, []);
    }

    public static function images(string $slug, array $gallery): array
    {
        return (array) (self::metadata($slug)['optimized_gallery'] ?? $gallery);
    }

    public static function imageAlt(string $slug, string $name, string $image): string
    {
        $meta = self::metadata($slug);
        if ($image === ($meta['credit']['path'] ?? null)) {
            return ($meta['credit']['title'] ?? $name).' — '.self::text('reference_image');
        }
        return $name.(str_ends_with($image, '.svg') ? ' — '.self::text('schematic_label') : '');
    }

    public static function guide(string $category, ?string $slug = null): array
    {
        $subtype = $slug ? (self::metadata($slug)['subtype'] ?? $category) : $category;
        return (array) config('hardware_shop.guides.'.$subtype, config('hardware_shop.guides.'.$category, []));
    }

    public static function condition(string $condition): string
    {
        return $condition === 'catalog' ? self::text('configuration') : (string) __('ui.parts_cond_'.$condition);
    }

    public static function stock(ServerPart $part): string
    {
        return $part->condition === 'catalog'
            ? self::text('stock_check')
            : (string) ($part->in_stock ? __('ui.parts_in_stock') : __('ui.parts_out_stock'));
    }

    public static function compatibility(ServerPart $part): string
    {
        $gens = (array) $part->compat_gens;
        if ($gens !== []) {
            return implode(' · ', array_map(fn ($g) => str_replace('gen', 'Gen', $g), $gens));
        }

        return $part->condition === 'catalog' || $part->compat_gens !== null
            ? self::text('compat_check') : (string) __('ui.parts_compat_any');
    }

    /** The Offer always matches the amount and currency actually shown on this page. */
    public static function offer(ServerPart $part): ?array
    {
        if ($part->price_contact || $part->displayPrice() === null) {
            return null;
        }
        if (app()->getLocale() === 'fa') {
            $toman = (int) $part->price_irt > 0 ? (int) $part->price_irt
                : (int) round(((int) $part->price_eur / 100) * cloud_eur_rate(), -4);
            if ($toman <= 0) {
                return null;
            }
            $currency = 'IRR';
            $price = schema_price_irr($toman);
        } else {
            $price = $part->eurAmount();
            if ($price === null) {
                return null;
            }
            $currency = 'EUR';
        }

        return [
            '@type' => 'Offer', 'url' => url()->current(),
            'priceCurrency' => $currency, 'price' => (string) $price,
            'availability' => $part->in_stock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'seller' => ['@type' => 'Organization', 'name' => 'ServerNet'],
        ];
    }
}
