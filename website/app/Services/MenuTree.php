<?php

namespace App\Services;

use App\Models\MenuOverride;

/**
 * فهرستِ **همهٔ** گره‌های منو برای صفحهٔ مدیریت.
 *
 * ═══ 🔴 چرا از config می‌خواند و نه از خروجیِ `MenuManager` ═══
 *
 * وسوسه‌انگیز بود که صفحهٔ مدیریت همان چیزی را نشان دهد که کاربر می‌بیند. ولی
 * `MenuManager` آیتم‌های خاموش را **حذف** می‌کند: مدیر لینکی را خاموش می‌کرد،
 * لینک از صفحهٔ مدیریت هم ناپدید می‌شد، و دیگر هیچ راهی برای روشن‌کردنش نبود —
 * یک درِ یک‌طرفه که فقط با دستکاریِ مستقیمِ دیتابیس باز می‌شد.
 *
 * پس این کلاس از **پیش‌فرضِ خام** می‌سازد و وضعیتِ رویه را کنارش می‌گذارد.
 *
 * ⚠️ گروهِ «موقعیت مکانی» از `SiteMenu` می‌آید نه از config، وگرنه کشورهای
 * زنده (که در config نیستند) در صفحهٔ مدیریت دیده نمی‌شدند و مدیر نمی‌توانست
 * مثلاً «سرور مجازی سنگاپور» را از منو بردارد.
 */
class MenuTree
{
    public function __construct(private SiteMenu $siteMenu) {}

    /**
     * @return array<string, array{label:string, nodes:list<array<string,mixed>>}>
     */
    public function all(): array
    {
        $rows = $this->rows();

        return [
            'mega'      => ['label' => 'مگامنوی «محصولات»', 'nodes' => $this->megaNodes($rows)],
            'services'  => ['label' => 'منوی «خدمات»', 'nodes' => $this->flatNodes('services', $rows)],
            'tools'     => ['label' => 'منوی «ابزارهای رایگان»', 'nodes' => $this->flatNodes('tools', $rows)],
            'knowledge' => ['label' => 'منوی «پایگاه دانش»', 'nodes' => $this->flatNodes('knowledge', $rows)],
            'footer'    => ['label' => 'فوترِ سایت', 'nodes' => $this->footerNodes($rows)],
        ];
    }

    /** @return array<string, MenuOverride> */
    private function rows(): array
    {
        try {
            return MenuOverride::query()->get()->keyBy('path')->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, MenuOverride>  $rows
     * @return list<array<string,mixed>>
     */
    private function megaNodes(array $rows): array
    {
        try {
            $mega = $this->siteMenu->mega();
        } catch (\Throwable) {
            $mega = (array) config('servernet.mega', []);
        }

        $out = [];

        foreach ($mega as $tab => $data) {
            $out[] = $this->node('mega', 'mega:'.$tab, 0, 'تب', [
                'fa' => $data['fa']['t'] ?? $tab,
                'en' => $data['en']['t'] ?? '',
                'tr' => $data['tr']['t'] ?? '',
            ], [
                'fa' => $data['fa']['d'] ?? '',
                'en' => $data['en']['d'] ?? '',
                'tr' => $data['tr']['d'] ?? '',
            ], $rows);

            foreach ((array) ($data['groups'] ?? []) as $g) {
                $out[] = $this->node('mega', 'mega:'.$tab.':g:'.($g['en'] ?? ''), 1, 'گروه', [
                    'fa' => $g['fa'] ?? '',
                    'en' => $g['en'] ?? '',
                    'tr' => $g['tr'] ?? '',
                ], [], $rows);

                foreach ((array) ($g['items'] ?? []) as $it) {
                    $out[] = $this->node('mega', MenuManager::itemPath('mega', $tab, $it), 2, 'لینک', [
                        'fa' => $this->text($it, 'fa'),
                        'en' => $this->text($it, 'en'),
                        'tr' => $this->text($it, 'tr'),
                    ], [], $rows);
                }
            }
        }

        return $this->withCustom($out, 'mega', $rows);
    }

    /**
     * @param  array<string, MenuOverride>  $rows
     * @return list<array<string,mixed>>
     */
    private function flatNodes(string $menu, array $rows): array
    {
        $out = [];

        foreach ((array) config('servernet.'.$menu.'_menu', []) as $it) {
            if (! is_array($it)) {
                continue;
            }

            $out[] = $this->node($menu, MenuManager::itemPath($menu, '', $it), 0, 'لینک', [
                'fa' => $this->text($it, 'fa'),
                'en' => $this->text($it, 'en'),
                'tr' => $this->text($it, 'tr'),
            ], [
                'fa' => is_array($it['fa'] ?? null) ? ($it['fa']['d'] ?? '') : '',
                'en' => is_array($it['en'] ?? null) ? ($it['en']['d'] ?? '') : '',
                'tr' => is_array($it['tr'] ?? null) ? ($it['tr']['d'] ?? '') : '',
            ], $rows);
        }

        return $this->withCustom($out, $menu, $rows);
    }

    /**
     * @param  array<string, MenuOverride>  $rows
     * @return list<array<string,mixed>>
     */
    private function footerNodes(array $rows): array
    {
        $out = [];

        foreach ((array) config('servernet.footer_menu', []) as $key => $col) {
            $head = (string) ($col['head'] ?? '');

            $out[] = $this->node('footer', 'footer:'.$key, 0, 'ستون', [
                'fa' => trans($head, [], 'fa'),
                'en' => trans($head, [], 'en'),
                'tr' => trans($head, [], 'tr'),
            ], [], $rows);

            foreach ((array) ($col['items'] ?? []) as $it) {
                $label = $it['label'] ?? '';

                $text = fn (string $l) => is_array($label)
                    ? (string) ($label[$l] ?? $label['fa'] ?? '')
                    : trans((string) $label, [], $l);

                $only = $it['locales'] ?? null;

                $out[] = $this->node('footer', 'footer:'.$key.':'.($it['key'] ?? 'x'), 1, 'لینک', [
                    'fa' => $text('fa'),
                    'en' => $text('en'),
                    'tr' => $text('tr'),
                ], [], $rows, $only === null ? null : implode('/', (array) $only));
            }
        }

        return $this->withCustom($out, 'footer', $rows);
    }

    /**
     * لینک‌های افزودهٔ مدیر هم باید در فهرست باشند، وگرنه فقط اضافه‌شدنی‌اند
     * و هرگز ویرایش یا حذف‌شدنی نیستند.
     *
     * @param  list<array<string,mixed>>  $out
     * @param  array<string, MenuOverride>  $rows
     * @return list<array<string,mixed>>
     */
    private function withCustom(array $out, string $menu, array $rows): array
    {
        $seen = array_column($out, 'path');

        foreach ($rows as $path => $r) {
            if ($r->menu !== $menu || ! $r->isCustom() || in_array($path, $seen, true)) {
                continue;
            }

            $out[] = [
                'path'    => $path,
                'menu'    => $menu,
                'depth'   => 1,
                'kind'    => 'افزوده',
                'custom'  => true,
                'default' => ['fa' => '', 'en' => '', 'tr' => ''],
                'desc'    => ['fa' => '', 'en' => '', 'tr' => ''],
                'row'     => $r,
                'note'    => (string) ($r->custom['url'] ?? $r->custom['route'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, MenuOverride>  $rows
     * @return array<string,mixed>
     */
    private function node(string $menu, string $path, int $depth, string $kind, array $default, array $desc, array $rows, ?string $note = null): array
    {
        return [
            'path'    => $path,
            'menu'    => $menu,
            'depth'   => $depth,
            'kind'    => $kind,
            'custom'  => false,
            'default' => $default,
            'desc'    => $desc + ['fa' => '', 'en' => '', 'tr' => ''],
            'row'     => $rows[$path] ?? null,
            'note'    => $note,
        ];
    }

    /** متنِ یک آیتم در یک زبان — هر دو شکلِ config را می‌فهمد. */
    private function text(array $item, string $locale): string
    {
        $v = $item[$locale] ?? null;

        if (is_array($v)) {
            return (string) ($v['t'] ?? '');
        }

        return (string) ($v ?? '');
    }
}
