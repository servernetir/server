<?php

namespace App\Services\Shop;

use App\Models\ServerPart;

/** Search public catalog fields, not long descriptions or internal inventory data. */
final class PartFinder
{
    public function __construct(private readonly PartsCatalog $catalog) {}

    public static function normalize(string $value): string
    {
        $value = strtr(mb_strtolower($value), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            'ي' => 'ی', 'ك' => 'ک', 'ı' => 'i',
        ]);

        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '');
    }

    /** @return list<string> */
    public static function identifiers(ServerPart $part, bool $includeModel = false): array
    {
        $labels = ['listed part number', 'part number', 'spare part number', 'option part number', 'mpn', 'p n'];
        if ($includeModel) {
            $labels[] = 'model identifier';
        }

        $values = [];
        foreach ($part->specs ?? [] as $row) {
            $label = $row['label']['en'] ?? '';
            $value = $row['value']['en'] ?? null;
            if (is_string($label) && is_string($value) && in_array(self::normalize($label), $labels, true)) {
                $values[] = $value;
            }
        }
        foreach (['part_number', 'mpn', 'spare_part_number', 'option_part_number'] as $key) {
            $value = $part->attrs[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    /** @return list<int> Active IDs in relevance order; cards are fetched only for the requested page. */
    public function ids(string $query, ?string $category, ?string $generation): array
    {
        if (! $this->catalog->available()) {
            return [];
        }

        $builder = ServerPart::query()->active()->whereIn('category', array_keys(ServerPart::CATEGORIES));
        if ($category !== null) {
            $builder->where('category', $category);
        }
        if ($generation !== null) {
            // Unknown or generic is not confirmed for a selected platform generation.
            $builder->whereNotIn('category', array_keys(ServerPart::EQUIPMENT_CATEGORIES))
                ->whereJsonContains('compat_gens', $generation);
        }

        if ($query === '') {
            return $builder->orderByDesc('popular')->orderBy('sort')->orderBy('id')->pluck('id')->all();
        }

        $terms = array_unique(explode(' ', $query));
        $compact = str_replace(' ', '', $query);
        $matches = [];
        foreach ($builder->select(['id', 'slug', 'category', 'brand', 'name', 'tagline', 'attrs', 'specs', 'popular', 'sort'])->lazyById(200) as $part) {
            $ids = array_map(self::normalize(...), self::identifiers($part, includeModel: true));
            $names = self::normalize(implode(' ', array_filter((array) $part->name, 'is_string')).' '.$part->slug);
            $fields = self::normalize($part->brand.' '.$part->categoryLabel().' '.$names.' '.implode(' ', $ids)
                .' '.implode(' ', array_filter((array) $part->tagline, 'is_string')));

            if (in_array($compact, array_map(fn ($id) => str_replace(' ', '', $id), $ids), true)) {
                $rank = 0;
            } elseif (str_contains(' '.$names.' ', ' '.$query.' ') || in_array($query, $ids, true)) {
                $rank = 1;
            } elseif (str_contains(str_replace(' ', '', $names.' '.implode(' ', $ids)), $compact)) {
                $rank = 2;
            } else {
                foreach ($terms as $term) {
                    if (! str_contains($fields, $term)) {
                        continue 2;
                    }
                }
                $rank = 3;
            }

            $matches[$part->id] = [$rank, $part->popular ? 0 : 1, $part->sort, $part->id];
        }

        asort($matches);

        return array_keys($matches);
    }

    public function quotedPart(mixed $slug): ?ServerPart
    {
        if (! is_string($slug) || ! preg_match('/\A[a-zA-Z0-9-]{1,180}\z/', $slug) || ! $this->catalog->available()) {
            return null;
        }

        return ServerPart::query()->active()->whereIn('category', array_keys(ServerPart::CATEGORIES))
            ->where('slug', $slug)->first(['slug', 'category', 'name', 'specs', 'attrs']);
    }
}
