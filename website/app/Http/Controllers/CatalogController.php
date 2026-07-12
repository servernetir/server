<?php

namespace App\Http\Controllers;

use App\Services\Whmcs;
use Illuminate\View\View;

/**
 * صفحات محصول: هاست (config/hosting.php) و بقیه دسته‌ها (config/catalog.php).
 * features/faqs هر محصول می‌توانند کلید pool مشترک یا آرایه اختصاصی باشند.
 */
class CatalogController extends Controller
{
    public function hosting(string $slug): View
    {
        return $this->render('hosting', $slug);
    }

    public function show(string $category, string $slug): View
    {
        return $this->render($category, $slug);
    }

    private function render(string $category, string $slug): View
    {
        $products = $category === 'hosting'
            ? config('hosting.products')
            : config("catalog.$category");

        abort_unless(is_array($products) && isset($products[$slug]), 404);

        $product = $products[$slug];
        $featurePool = config('hosting.feature_pool');
        $faqPool = config('hosting.faq_pool');

        $features = array_values(array_filter(array_map(
            fn ($f) => is_array($f) ? $f : ($featurePool[$f] ?? null),
            $product['features']
        )));

        $faqs = array_values(array_filter(array_map(
            fn ($f) => is_array($f) ? $f : ($faqPool[$f] ?? null),
            $product['faqs']
        )));

        // چیپ‌های TLD فقط برای امضای «جستجوی دامنه» لازم‌اند
        $tlds = ($product['signature']['type'] ?? null) === 'domainsearch' ? $this->tlds() : [];

        return view('pages.hosting', [
            'category' => $category,
            'slug'     => $slug,
            'product'  => $product,
            'features' => $features,
            'faqs'     => $faqs,
            'related'  => array_diff_key($products, [$slug => null]),
            'tlds'     => $tlds,
        ]);
    }

    /** قیمت زنده TLD از WHMCS با fallback به config — همان منطق صفحه اصلی */
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

        if ($out === []) {
            foreach (array_slice($pricing['prices'], 0, 10, true) as $tld => $price) {
                $out[] = ['tld' => $tld, 'display' => whmcs_price($price, $pricing['currency'])];
            }
        }

        return $out;
    }
}
