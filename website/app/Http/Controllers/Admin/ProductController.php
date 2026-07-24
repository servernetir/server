<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Server;
use App\Services\Provisioning\WhmClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * مدیریتِ پکیج‌های فروش — چیزی که مشتری در فروشگاهِ پنل می‌خرد.
 */
class ProductController extends Controller
{
    public function index(): View
    {
        $ready = Schema::hasTable('products');

        return view('admin.products', [
            'products' => $ready ? Product::with('server')->orderBy('category')->orderBy('sort')->get() : collect(),
            'servers'  => Schema::hasTable('servers') ? Server::orderBy('name')->get() : collect(),
            'notReady' => ! $ready,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['specs'] = $this->parseSpecs($request->input('specs_raw'));
        Product::create($data);

        return back()->with('ok', 'پکیج «'.$data['name'].'» اضافه شد.');
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $data = $this->validated($request);
        $data['specs'] = $this->parseSpecs($request->input('specs_raw'));
        $product->update($data);

        return back()->with('ok', 'پکیج «'.$product->name.'» به‌روزرسانی شد.');
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $name = $product->name;
        $product->delete();

        return back()->with('ok', 'پکیج «'.$name.'» حذف شد.');
    }

    /** ساختِ package این پکیج در WHM و وصل‌کردنش */
    public function syncWhm(Request $request, Product $product): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        [$ok, $msg] = $this->createWhmPackage($product);

        return $ok ? back()->with('ok', $msg) : back()->withErrors($msg);
    }

    /** ساختِ package همهٔ پکیج‌هایِ متصل به سرورِ WHM */
    public function syncWhmAll(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $products = Product::whereHas('server', fn ($q) => $q->where('type', 'whm'))->get();
        $done = 0;
        $fail = 0;
        foreach ($products as $p) {
            [$ok] = $this->createWhmPackage($p);
            $ok ? $done++ : $fail++;
        }

        return back()->with('ok', "ساختِ package در WHM: {$done} موفق، {$fail} ناموفق (از {$products->count()}).");
    }

    /** @return array{0:bool,1:string} */
    private function createWhmPackage(Product $product): array
    {
        $server = $product->server;
        if (! $server || $server->type !== 'whm') {
            return [false, 'برای ساختِ package، پکیج باید به یک سرورِ WHM وصل باشد.'];
        }

        $name = $this->whmPackageName($product);
        $res = (new WhmClient($server))->addPackage(['name' => $name] + $this->parseLimits($product->specs ?? []));

        // اگر package از قبل هست، خطا نیست — فقط وصلش می‌کنیم (حدومرزش را دست نمی‌زنیم)
        $exists = ! $res['ok'] && str_contains(strtolower($res['reason'] ?? ''), 'exist');
        if (! $res['ok'] && ! $exists) {
            return [false, 'ساختِ package «'.$name.'» ناموفق: '.$res['reason']];
        }

        $product->update(['plan' => $name]);

        return [true, $exists
            ? 'package «'.$name.'» از قبل بود؛ به پکیج وصل شد.'
            : 'package «'.$name.'» در WHM ساخته و به پکیج وصل شد.'];
    }

    /** نامِ معتبرِ package در WHM (حروف/رقم/زیرخط) */
    private function whmPackageName(Product $product): string
    {
        return 'sn_'.substr(preg_replace('/[^a-z0-9]+/i', '_', $product->slug), 0, 40);
    }

    /** استخراجِ حدومرزِ WHM از مشخصاتِ نمایشیِ پکیج — best-effort (نبود = نامحدود) */
    private function parseLimits(array $specs): array
    {
        $limits = [];
        foreach ($specs as $spec) {
            $t = $this->latinDigits(mb_strtolower((string) ($spec['label'] ?? '')));

            if (! isset($limits['quota']) && preg_match('/(فضا|گیگابایت|disk|storage|space|ssd|nvme)/u', $t)) {
                if (preg_match('/(\d+(?:\.\d+)?)\s*(tb|ترابایت)/u', $t, $m)) {
                    $limits['quota'] = (int) round($m[1] * 1024 * 1024);
                } elseif (preg_match('/(\d+(?:\.\d+)?)\s*(gb|گیگ|مگ|mb)/u', $t, $m)) {
                    $limits['quota'] = str_contains($m[2], 'م') || $m[2] === 'mb'
                        ? (int) round($m[1])
                        : (int) round($m[1] * 1024);
                }
            }

            if (! isset($limits['bwlimit']) && preg_match('/(پهنای|ترافیک|bandwidth|transfer)/u', $t)) {
                if (preg_match('/(نامحدود|unlimited)/u', $t)) {
                    $limits['bwlimit'] = 0;
                } elseif (preg_match('/(\d+(?:\.\d+)?)\s*(tb|ترابایت)/u', $t, $m)) {
                    $limits['bwlimit'] = (int) round($m[1] * 1024 * 1024);
                } elseif (preg_match('/(\d+(?:\.\d+)?)\s*(gb|گیگ)/u', $t, $m)) {
                    $limits['bwlimit'] = (int) round($m[1] * 1024);
                }
            }
        }

        return $limits;
    }

    private function latinDigits(string $s): string
    {
        return strtr($s, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name'            => ['required', 'string', 'max:150'],
            'category'        => ['required', 'in:'.implode(',', array_keys(Product::CATEGORIES))],
            'server_id'       => ['nullable', 'integer', 'exists:servers,id'],
            'plan'            => ['nullable', 'string', 'max:80'],
            'price'           => ['required', 'integer', 'min:0', 'max:100000000000'],
            'setup_fee'       => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'cycle'           => ['required', 'in:once,monthly,quarterly,yearly'],
            'tax_percent'     => ['nullable', 'integer', 'min:0', 'max:100'],
            'description'     => ['nullable', 'string', 'max:2000'],
            'requires_domain' => ['nullable', 'boolean'],
            'is_active'       => ['nullable', 'boolean'],
            'sort'            => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]) + [
            'setup_fee'       => (int) $request->input('setup_fee', 0),
            'tax_percent'     => (int) $request->input('tax_percent', 10),
            'requires_domain' => $request->boolean('requires_domain'),
            'is_active'       => $request->boolean('is_active'),
            'sort'            => (int) $request->input('sort', 0),
        ];
    }

    /** «برچسب: مقدار» در هر خط → [{label,value}] */
    private function parseSpecs(?string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', (string) $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode(':', $line, 2);
            $out[] = ['label' => trim($parts[0]), 'value' => trim($parts[1] ?? '')];
        }

        return $out;
    }
}
