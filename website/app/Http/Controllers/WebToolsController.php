<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * ابزارهای رایگان وب‌مستر — همه سمت کاربر اجرا می‌شوند.
 * هاب: /webtools     ابزار: /webtools/{slug}
 */
class WebToolsController extends Controller
{
    public function index(): View
    {
        return view('pages.webtools', ['categories' => config('webtools.categories', [])]);
    }

    public function show(string $slug): View
    {
        [$catKey, $cat, $tool] = $this->find($slug);
        abort_if($tool === null, 404);

        return view('pages.webtool', [
            'slug'     => $slug,
            'tool'     => $tool,
            'catKey'   => $catKey,
            'cat'      => $cat,
            'siblings' => $this->siblings($catKey, $slug),
        ]);
    }

    /** پیدا کردن ابزار و دسته‌اش */
    private function find(string $slug): array
    {
        foreach (config('webtools.categories', []) as $key => $cat) {
            if (isset($cat['tools'][$slug])) {
                return [$key, $cat, $cat['tools'][$slug]];
            }
        }

        return [null, null, null];
    }

    /** بقیه‌ی ابزارهای همان دسته (برای لینک داخلی و کشف‌پذیری) */
    private function siblings(?string $catKey, string $slug): array
    {
        $tools = config('webtools.categories.'.$catKey.'.tools', []);
        unset($tools[$slug]);

        return array_slice($tools, 0, 6, true);
    }

    /** همه‌ی اسلاگ‌ها — برای sitemap */
    public static function slugs(): array
    {
        $out = [];
        foreach (config('webtools.categories', []) as $cat) {
            foreach (array_keys($cat['tools'] ?? []) as $slug) {
                $out[] = $slug;
            }
        }

        return $out;
    }
}
