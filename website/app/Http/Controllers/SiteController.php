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
