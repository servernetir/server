<?php

namespace App\Http\Controllers;

use App\Services\Whmcs;
use Illuminate\View\View;

class SiteController extends Controller
{
    public function home(): View
    {
        return view('pages.home', [
            'products'   => config('servernet.products'),
            'plans'      => config('servernet.plans'),
            'enterprise' => config('servernet.enterprise'),
            'why'        => config('servernet.why'),
            'locations'  => config('servernet.locations'),
            'faqs'       => config('servernet.faqs'),
            'brands'     => config('servernet.brands'),
            'tlds'       => $this->tlds(),
        ]);
    }

    public function contact(): View
    {
        return view('pages.contact', [
            'faqs' => config('servernet.faqs'),
        ]);
    }

    /**
     * پایگاه دانش.
     *
     * ⚠️ بخشِ «تازه‌های وبلاگ» قبلاً از config/knowledge.php خوانده می‌شد — یک
     * فایلِ ثابت — پس هرچه در پنل مدیریت منتشر می‌شد این‌جا دیده نمی‌شد و
     * لینک‌های کارت‌ها هم `href="#"` مرده بودند. حالا از همان منبعِ بلاگ
     * (دیتابیس) می‌آید و به نوشتهٔ واقعی لینک می‌شود.
     */
    public function knowledge(): View
    {
        return view('pages.knowledge', [
            'kb'    => config('knowledge'),
            'posts' => array_slice(app(\App\Services\BlogRepository::class)->index(), 0, 6),
        ]);
    }

    public function page(string $slug): View
    {
        $pages = config('pages');
        abort_unless(isset($pages[$slug]), 404);

        return view('pages.content', ['slug' => $slug, 'page' => $pages[$slug]]);
    }

    public function sitemap(): \Illuminate\Http\Response
    {
        $locales = \App\Providers\AppServiceProvider::LOCALES;
        $urls = [];

        // $lastmod اختیاری است: برای نوشته‌های بلاگ تاریخِ واقعی داریم و دادنش
        // به گوگل کمک می‌کند صفحاتِ تازه را زودتر بازدید کند. برای صفحاتِ ثابت
        // تاریخِ ساختگی نمی‌دهیم — lastmod دروغ، اعتمادِ خزنده را کم می‌کند.
        $add = function (string $name, string|array $params = [], ?string $lastmod = null) use (&$urls, $locales) {
            foreach ($locales as $prefix) {
                $urls[] = ['loc' => route($prefix.$name, $params), 'lastmod' => $lastmod];
            }
        };

        $add('home');
        // /domains صفحهٔ فرودِ «ثبت دامنه» با کلیدواژهٔ ارزشمند است و جا افتاده بود
        $add('domain.search');
        foreach (['contact', 'knowledge', 'about', 'privacy', 'terms', 'careers'] as $n) {
            $add($n);
        }
        foreach (['seo', 'whois', 'ip', 'meet', 'app-builder'] as $slug) {
            $add('tools', $slug);
        }
        $add('hub.dns');
        $add('hub.network');
        $add('blog.index');
        foreach (app(\App\Services\BlogRepository::class)->index() as $post) {
            $add('blog', $post['slug'], $post['date'] ?? null);
        }
        $add('webtools.index');
        foreach (\App\Http\Controllers\WebToolsController::slugs() as $wt) {
            $add('webtools', $wt);
        }
        $add('docs.index');
        foreach (app(\App\Services\DocsRepository::class)->tree() as $sec) {
            foreach ($sec['items'] as $item) {
                $add('docs', $item['slug']);
            }
        }
        foreach (array_keys(config('lookup.types')) as $type) {
            $add('lookup', $type);
        }
        $add('solutions.index');                 // هابِ راهکارها — والدِ این دسته
        foreach (array_keys(config('solutions')) as $slug) {
            if ($slug === 'email') {
                continue; // با /hosting/email یکی شده
            }
            $add('solution', $slug);
        }
        foreach (array_keys(config('hosting.products')) as $slug) {
            $add('hosting', $slug);
        }
        foreach (config('catalog') as $category => $products) {
            foreach (array_keys($products) as $slug) {
                $add('catalog', ['category' => $category, 'slug' => $slug]);
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $u) {
            $xml .= '  <url><loc>'.htmlspecialchars($u['loc'], ENT_XML1).'</loc>';

            if (! empty($u['lastmod'])) {
                $xml .= '<lastmod>'.htmlspecialchars($u['lastmod'], ENT_XML1).'</lastmod>';
            }

            $xml .= '</url>'."\n";
        }
        $xml .= '</urlset>'."\n";

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    /** قیمت زنده از WHMCS؛ اگر API تنظیم/در دسترس نبود، نمونه‌های config */
    private function tlds(): array
    {
        $pricing = Whmcs::forLocale()->tldPricing();

        if ($pricing === null) {
            return config('servernet.tlds');
        }

        $out = [];
        foreach (config('servernet.featured_tlds') as $tld) {
            if (isset($pricing['prices'][$tld])) {
                $out[] = ['tld' => $tld, 'display' => whmcs_price($pricing['prices'][$tld], $pricing['currency'])];
            }
        }

        // اگر هیچ‌کدام از منتخب‌ها در WHMCS نبود، ۱۰ پسوند اول
        if ($out === []) {
            foreach (array_slice($pricing['prices'], 0, 10, true) as $tld => $price) {
                $out[] = ['tld' => $tld, 'display' => whmcs_price($price, $pricing['currency'])];
            }
        }

        return $out;
    }
}
