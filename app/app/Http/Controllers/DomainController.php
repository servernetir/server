<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class DomainController extends Controller
{
    /** صفحه‌ی اصلی */
    public function index()
    {
        // قیمت‌های نمونه؛ بعداً از DB بخوانید
        $prices = [
            '.ru' => 4.01, '.su' => 6.77, '.com' => 23.74, '.net' => 28.85, '.org' => 30.22,
            '.host' => 180.36, '.pro' => 46.92, '.city' => 41.13,
            '.report' => 41.13, '.care' => 34.50, '.recipes' => 99.36,
            '.wtf' => 74.52, '.glass' => 99.36, '.guitars' => 281.80, '.graphics' => 41.13,
        ];
        return view('domain', compact('prices'));
    }

    /** چک‌کردن یک دامنه: /api/domain/check?domain=example.com */
    public function check(Request $request)
    {
        $v = Validator::make($request->all(), [
            'domain' => ['required','string','regex:/^[a-z0-9-]{1,63}\.[a-z.]{2,}$/i']
        ]);

        if ($v->fails()) {
            return response()->json(['ok'=>false, 'error'=>'Invalid domain format'], 422);
        }

        $domain = strtolower($request->get('domain'));
        $status = $this->rdapLookup($domain); // available|taken|unknown

        return response()->json([
            'ok' => true,
            'domain' => $domain,
            'status' => $status,
        ]);
    }

    /** چک‌کردن چند پسوند برای یک برچسب (label): { "label":"bengo", "tlds":[".com",".net",...] } */
    public function bulkCheck(Request $request)
    {
        $v = Validator::make($request->all(), [
            'label' => ['required','string','regex:/^[a-z0-9-]{1,63}$/i'],
            'tlds'  => ['required','array','min:1'],
            'tlds.*'=> ['string','regex:/^\.[a-z.]{2,}$/i'],
        ]);
        if ($v->fails()) {
            return response()->json(['ok'=>false, 'error'=>'Invalid input'], 422);
        }

        $label = strtolower($request->label);
        $out = [];
        foreach ($request->tlds as $tld) {
            $domain = $label . $tld;
            $out[$tld] = $this->rdapLookup($domain);
        }

        return response()->json([
            'ok'=>true,
            'label'=>$label,
            'results'=>$out
        ]);
    }

    /** شبیه‌ساز ثبت سفارش؛ مقادیر پست‌شده را بگیرید و ذخیره/پرداخت کنید */
    public function submit(Request $r)
    {
        // TODO: ذخیره سفارش و هدایت به پرداخت
        return back()->with('ok', 'Order received.')->withInput();
    }

    /** ---------- RDAP lookup with caching ---------- */
    private function rdapLookup(string $domain): string
    {
        return Cache::remember("rdap:$domain", now()->addMinutes(10), function () use ($domain) {
            $url = "https://rdap.org/domain/" . urlencode($domain);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'DomainChecker/1.0 (+https://your-site.example)',
                CURLOPT_HEADER => true,
            ]);
            $resp = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $body = substr($resp, curl_getinfo($ch, CURLINFO_HEADER_SIZE));
            curl_close($ch);

            if ($http === 404) {
                return 'available';
            }
            if ($http === 200) {
                // اگر RDAP رکورد دامنه وجود دارد
                return 'taken';
            }

            // Fallback: چک DNS (ممکن است false-positive باشد)
            if (function_exists('checkdnsrr') && (checkdnsrr($domain, 'NS') || checkdnsrr($domain, 'A'))) {
                return 'taken';
            }
            return 'unknown';
        });
    }
}