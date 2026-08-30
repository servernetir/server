<?php

namespace App\Services;

use App\Models\MenuOverride;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * منوی هدر و فوتر با **رویهٔ مدیر** روی پیش‌فرضِ config.
 *
 * ═══ چه کاری می‌کند ═══
 *
 * config می‌گوید منو چه چیزهایی دارد؛ این کلاس می‌گذارد مدیر هرکدام را خاموش
 * کند، اسمش را در هر سه زبان عوض کند، جایش را بالا/پایین ببرد، و لینکِ تازه
 * اضافه کند — بی‌آنکه چیزی در کد عوض شود.
 *
 * ═══ 🔴 قاعدهٔ اول: منو هرگز صفحه را نمی‌اندازد ═══
 *
 * فوتر روی **هر** صفحهٔ سایت رندر می‌شود. مرداد ۱۴۰۵ یک `lroute()` به روتی که
 * نام نداشت، کلِ نسخهٔ en/tr سایت را ۵۰۰ کرد. حالا که مقصدِ لینک‌ها را **مدیر**
 * می‌نویسد، همان تله هزار برابر محتمل‌تر است.
 *
 * پس هر نشانی داخلِ `try` ساخته می‌شود و آیتمی که ساخته نشود **رد** می‌شود.
 * لینکِ غایب یک نقصِ کوچک است؛ سایتِ ۵۰۰ یک فاجعه.
 *
 * ⚠️ و این جدا از اعتبارسنجیِ لحظهٔ ذخیره است، نه به‌جایش: اعتبارسنجی خطا را
 * به مدیر نشان می‌دهد، این یکی نمی‌گذارد خطا به بازدیدکننده برسد. روتی که
 * امروز هست، ممکن است فردا با یک دیپلوی نباشد.
 *
 * ═══ 🔴 قاعدهٔ دوم: هرگز منوی خالی ═══
 *
 * اگر مدیر همهٔ آیتم‌های یک ستون را خاموش کند، خودِ ستون هم نمایش داده نمی‌شود
 * (سرستونِ بی‌لینک بدتر از نبودن است) — ولی اگر جدول نباشد یا خطا بدهد،
 * پیش‌فرضِ config برمی‌گردد. منوی کهنه از منوی خالی بهتر است.
 */
class MenuManager
{
    private const TTL = 600;

    private const KEY = 'site.menu.overrides';

    /**
     * رویه‌ها بر اساس `path`.
     *
     * ⚠️ `Schema::hasTable` عمداً هست: تا وقتی مهاجرت روی سرور اجرا نشده، منو
     * باید دقیقاً مثلِ امروز کار کند، نه اینکه هدر ۵۰۰ بدهد.
     *
     * @return array<string, MenuOverride>
     */
    public function overrides(): array
    {
        return Cache::remember(self::KEY, self::TTL, function () {
            try {
                if (! Schema::hasTable('menu_overrides')) {
                    return [];
                }

                return MenuOverride::query()->get()->keyBy('path')->all();
            } catch (\Throwable) {
                return [];
            }
        });
    }

    public static function forget(): void
    {
        Cache::forget(self::KEY);
    }

    // ═══════════════════════ شناسهٔ پایدارِ گره ═══════════════════════

    /**
     * `path` یک آیتم — از **هویتش**، نه از جایش.
     *
     * 🔴 اگر به شمارهٔ ردیف گره می‌خورد، افزودنِ یک لینک در کد، ویرایش‌های مدیر
     * را جابه‌جا می‌کرد: عنوانی که برای «هاست وردپرس» نوشته بود روی «هاست
     * پایتون» می‌نشست — خرابی‌ای که هیچ خطایی نمی‌دهد و فقط غلط نشان می‌دهد.
     */
    public static function itemPath(string $menu, string $scope, array $item): string
    {
        $route = $item['route'] ?? null;

        $id = $item['slug']
            ?? (is_array($route) ? ($route[0] ?? null) : $route)
            ?? ($item['anchor'] ?? null)
            ?? ($item['key'] ?? null)
            ?? ($item['iso'] ?? null)
            ?? 'x';

        return $scope === ''
            ? $menu.':'.$id
            : $menu.':'.$scope.':'.$id;
    }

    // ═══════════════════════ منوهای تخت ═══════════════════════

    /**
     * `services_menu` / `tools_menu` / `knowledge_menu` با رویه.
     *
     * ⚠️ عمداً `config()` را بازنویسی **نمی‌کند**. صفحهٔ `pages/knowledge` به
     * `config('servernet.knowledge_menu')[0]` تا `[3]` دسترسیِ **موقعیتی** دارد؛
     * اگر مدیر آیتمی را خاموش یا جابه‌جا می‌کرد، نشانِ آن صفحه یا عوض می‌شد یا
     * ایندکسِ تعریف‌نشده می‌داد. رویه فقط جایی اثر می‌کند که منو رندر می‌شود.
     *
     * @return list<array<string,mixed>>
     */
    public function flat(string $menu): array
    {
        $items = (array) config('servernet.'.$menu.'_menu', []);

        return $this->apply($menu, '', $items);
    }

    // ═══════════════════════ مگا-منو ═══════════════════════

    /**
     * رویه را روی خروجیِ `SiteMenu::mega()` بگذار.
     *
     * ⚠️ **بعد** از `SiteMenu`، نه به‌جایش: آن کلاس گروهِ «موقعیت مکانی» را زنده
     * از کاتالوگ می‌سازد. اگر رویه جای آن می‌نشست، کشورِ تازه دیگر هرگز در منو
     * نمی‌آمد — و کسی تا ماه‌ها نمی‌فهمید.
     *
     * @param  array<string, array<string,mixed>>  $mega
     * @return array<string, array<string,mixed>>
     */
    public function mega(array $mega): array
    {
        $ov = $this->overrides();

        if ($ov === []) {
            return $mega;
        }

        $out = [];

        foreach ($mega as $tab => $data) {
            $row = $ov['mega:'.$tab] ?? null;

            if ($row && ! $row->visible) {
                continue;
            }

            if ($row) {
                foreach (['fa', 'en', 'tr'] as $l) {
                    if ($t = $row->label($l)) {
                        $data[$l]['t'] = $t;
                    }

                    if ($d = $row->desc($l)) {
                        $data[$l]['d'] = $d;
                    }
                }
            }

            $groups = [];

            foreach ((array) ($data['groups'] ?? []) as $g) {
                $gRow = $ov['mega:'.$tab.':g:'.($g['en'] ?? '')] ?? null;

                if ($gRow && ! $gRow->visible) {
                    continue;
                }

                if ($gRow) {
                    foreach (['fa', 'en', 'tr'] as $l) {
                        if ($t = $gRow->label($l)) {
                            $g[$l] = $t;
                        }
                    }
                }

                $g['items'] = $this->apply('mega', $tab, (array) ($g['items'] ?? []));

                // گروهی که همهٔ لینک‌هایش خاموش شده، سرستونِ بی‌محتواست
                if ($g['items'] === []) {
                    continue;
                }

                $groups[] = $g + ['__sort' => $gRow?->sort];
            }

            $groups = $this->sorted($groups);

            if ($groups === []) {
                continue;
            }

            $data['groups'] = $groups;
            $out[$tab] = $data + ['__sort' => $row?->sort];
        }

        $out = $this->sorted($out, keyed: true);

        // 🔴 هرگز منوی خالی: اگر همه‌چیز خاموش شد، پیش‌فرض برگردد.
        return $out === [] ? $mega : $out;
    }

    // ═══════════════════════ فوتر ═══════════════════════

    /**
     * ستون‌های فوتر، آمادهٔ رندر: هر آیتم `href` و `text` دارد.
     *
     * @return list<array{head:string,items:list<array<string,mixed>>}>
     */
    public function footer(string $locale): array
    {
        $cols = (array) config('servernet.footer_menu', []);
        $ov = $this->overrides();
        $out = [];

        foreach ($cols as $key => $col) {
            $row = $ov['footer:'.$key] ?? null;

            if ($row && ! $row->visible) {
                continue;
            }

            $items = [];

            foreach ((array) ($col['items'] ?? []) as $it) {
                $iRow = $ov['footer:'.$key.':'.($it['key'] ?? 'x')] ?? null;

                if ($iRow && ! $iRow->visible) {
                    continue;
                }

                if (! $this->allowedLocale($it, $locale)) {
                    continue;
                }

                $href = $this->hrefFor($it, $iRow);

                // 🔴 مقصدِ ناساختنی ⇒ رد، نه استثنا. فوتر روی هر صفحه است.
                if ($href === null) {
                    continue;
                }

                $items[] = [
                    'href'   => $href,
                    'text'   => $iRow?->label($locale) ?? $this->textOf($it, $locale),
                    'strong' => (bool) ($it['strong'] ?? false),
                    'arrow'  => (bool) ($it['arrow'] ?? false),
                    '__sort' => $iRow?->sort,
                ];
            }

            // لینک‌های افزودهٔ مدیر برای همین ستون
            foreach ($ov as $r) {
                if ($r->menu !== 'footer' || ! $r->isCustom() || ! $r->visible) {
                    continue;
                }

                if ((string) ($r->custom['column'] ?? '') !== (string) $key) {
                    continue;
                }

                $href = $this->customHref($r);

                if ($href === null) {
                    continue;
                }

                $items[] = [
                    'href'   => $href,
                    'text'   => $r->label($locale) ?? $r->label('fa') ?? '—',
                    'strong' => false,
                    'arrow'  => false,
                    '__sort' => $r->sort,
                ];
            }

            $items = $this->sorted($items);

            // سرستونِ بی‌لینک از نبودنش بدتر است
            if ($items === []) {
                continue;
            }

            $out[] = [
                'head'   => $row?->label($locale) ?? __((string) ($col['head'] ?? '')),
                'items'  => $items,
                '__sort' => $row?->sort,
            ];
        }

        return $this->sorted($out);
    }

    // ═══════════════════════ درونی ═══════════════════════

    /**
     * رویه را روی یک فهرستِ تختِ آیتم بگذار (خاموشی، متن، ترتیب، افزوده‌ها).
     *
     * @param  array<int|string, mixed>  $items
     * @return list<array<string,mixed>>
     */
    private function apply(string $menu, string $scope, array $items): array
    {
        $ov = $this->overrides();
        $out = [];

        foreach ($items as $it) {
            if (! is_array($it)) {
                continue;
            }

            $row = $ov[self::itemPath($menu, $scope, $it)] ?? null;

            if ($row && ! $row->visible) {
                continue;
            }

            if ($row) {
                foreach (['fa', 'en', 'tr'] as $l) {
                    /*
                    | ⚠️ دو شکلِ متن در config هست: در مگامنو رشتهٔ ساده، و در
                    | منوهای تخت آرایهٔ «t/d». جایگزینیِ کورکورانه یکی از این دو
                    | را خراب می‌کرد: رشته‌ای که به‌جای آرایه بنشیند، در ویو
                    | «t» می‌شود حرفِ اولِ رشته — خرابی‌ای بی‌خطا و کاملاً دیدنی.
                    */
                    if ($t = $row->label($l)) {
                        if (is_array($it[$l] ?? null)) {
                            $it[$l]['t'] = $t;
                        } else {
                            $it[$l] = $t;
                        }
                    }

                    if (($d = $row->desc($l)) && is_array($it[$l] ?? null)) {
                        $it[$l]['d'] = $d;
                    }
                }
            }

            $out[] = $it + ['__sort' => $row?->sort];
        }

        // آیتم‌های افزودهٔ مدیر در همین دامنه
        foreach ($ov as $r) {
            if ($r->menu !== $menu || ! $r->isCustom() || ! $r->visible) {
                continue;
            }

            if ((string) ($r->custom['scope'] ?? '') !== $scope) {
                continue;
            }

            $item = ['__sort' => $r->sort];

            foreach (['fa', 'en', 'tr'] as $l) {
                $item[$l] = [
                    't' => $r->label($l) ?? $r->label('fa') ?? '—',
                    'd' => $r->desc($l) ?? '',
                ];
            }

            if ($icon = ($r->custom['icon'] ?? null)) {
                $item['icon'] = $icon;
            }

            if ($slug = ($r->custom['slug'] ?? null)) {
                $item['slug'] = $slug;
            } elseif ($url = ($r->custom['url'] ?? null)) {
                $item['url'] = $url;
            } else {
                continue; // بی‌مقصد ⇒ اصلاً آیتم نیست
            }

            $out[] = $item;
        }

        return $this->sorted($out);
    }

    /**
     * ترتیب: `sort`ِ مدیر اول، بقیه به ترتیبِ config.
     *
     * 🔴 `sort`ِ نال یعنی «دست نزن». مرتب‌سازیِ ساده، نال را صفر می‌خواند و
     * هر آیتمِ دست‌نخورده را به ابتدای فهرست می‌بُرد — یعنی ویرایشِ عنوانِ یک
     * لینک، ترتیبِ کلِ منو را هم عوض می‌کرد.
     */
    private function sorted(array $items, bool $keyed = false): array
    {
        $i = 0;
        $rows = [];

        foreach ($items as $k => $v) {
            $rows[] = [$k, $v, $v['__sort'] ?? null, $i++];
        }

        usort($rows, function ($a, $b) {
            [$as, $bs] = [$a[2], $b[2]];

            if ($as === null && $bs === null) {
                return $a[3] <=> $b[3];
            }

            if ($as === null) {
                return 1;   // دست‌نخورده‌ها بعد از مرتب‌شده‌ها
            }

            if ($bs === null) {
                return -1;
            }

            return $as <=> $bs ?: $a[3] <=> $b[3];
        });

        $out = [];

        foreach ($rows as [$k, $v]) {
            unset($v['__sort']);

            if ($keyed) {
                $out[$k] = $v;
            } else {
                $out[] = $v;
            }
        }

        return $out;
    }

    private function allowedLocale(array $item, string $locale): bool
    {
        $only = $item['locales'] ?? null;

        return $only === null || in_array($locale, (array) $only, true);
    }

    private function textOf(array $item, string $locale): string
    {
        $label = $item['label'] ?? '';

        if (is_array($label)) {
            return (string) ($label[$locale] ?? $label['fa'] ?? '—');
        }

        return __((string) $label);
    }

    /** نشانیِ یک آیتمِ config — یا null اگر ساخته نشد. */
    private function hrefFor(array $item, ?MenuOverride $row): ?string
    {
        if ($row && $row->isCustom()) {
            return $this->customHref($row);
        }

        try {
            $route = (array) ($item['route'] ?? []);

            if ($route === []) {
                return null;
            }

            $name = (string) $route[0];
            $params = $route[1] ?? null;

            return ! empty($item['console'])
                ? console_lroute($name, $params)
                : lroute($name, $params);
        } catch (\Throwable) {
            return null;
        }
    }

    /** نشانیِ لینکِ افزودهٔ مدیر — یا null اگر ساخته نشد. */
    private function customHref(MenuOverride $row): ?string
    {
        $c = (array) $row->custom;

        try {
            if (! empty($c['url'])) {
                $u = (string) $c['url'];

                /*
                | 🔴 فقط نشانیِ امن. `javascript:` در فوتری که روی **هر** صفحه
                | رندر می‌شود یعنی XSS روی کلِ سایت — و این فیلد را مدیر پر
                | می‌کند، پس ورودیِ آزاد است حتی اگر ورودیِ غریبه نباشد.
                */
                return preg_match('~^(https?://|/|#)~i', $u) ? $u : null;
            }

            if (! empty($c['route'])) {
                return lroute((string) $c['route'], $c['params'] ?? null);
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
