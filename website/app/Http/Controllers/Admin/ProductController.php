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

    /**
     * ساختِ package همهٔ پکیج‌های فعال روی **همهٔ** سرورهای WHM.
     *
     * چرا همهٔ سرورها: مشتری در لحظهٔ خرید مکان (ایران/آلمان) را انتخاب می‌کند،
     * پس همان packageName باید روی هر دو سرور موجود باشد؛ وگرنه خریدِ یک مکان
     * در مرحلهٔ تحویل با «package نیست» شکست می‌خورد.
     */
    public function syncWhmAll(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $products = Product::where('is_active', true)->get();
        $done = 0;
        $fail = 0;
        foreach ($products as $p) {
            [$ok] = $this->createWhmPackage($p);
            $ok ? $done++ : $fail++;
        }

        $servers = $this->whmServers()->count();

        return back()->with('ok', "ساختِ package روی {$servers} سرورِ WHM: {$done} پکیجِ موفق، {$fail} ناموفق (از {$products->count()}).");
    }

    /** سرورهای WHMِ فعال — مقصدهای ساختِ package */
    private function whmServers()
    {
        return \App\Models\Server::where('type', 'whm')->where('status', '!=', 'maintenance')->get();
    }

    /** @return array{0:bool,1:string} */
    private function createWhmPackage(Product $product): array
    {
        $servers = $this->whmServers();

        if ($servers->isEmpty()) {
            return [false, 'هیچ سرورِ WHMِ فعالی ثبت نشده؛ اول از «سرورهای تحویل» اضافه کنید.'];
        }

        $name = $this->whmPackageName($product);
        $limits = $this->parseLimits($product->specs ?? []);

        $okOn = [];
        $failOn = [];

        foreach ($servers as $server) {
            $res = (new WhmClient($server))->addPackage(['name' => $name] + $limits);

            // «از قبل هست» خطا نیست — idempotent است و حدومرزِ موجود دست نمی‌خورد
            $exists = ! $res['ok'] && str_contains(strtolower($res['reason'] ?? ''), 'exist');

            if ($res['ok'] || $exists) {
                $okOn[] = $server->name;
            } else {
                $failOn[] = $server->name.': '.$res['reason'];
            }
        }

        // نامِ package روی خودِ پکیج قفل می‌شود تا تحویل بداند چه بخواهد
        if ($okOn !== []) {
            $product->update(['plan' => $name]);
        }

        if ($okOn === []) {
            return [false, 'ساختِ package «'.$name.'» روی هیچ سروری موفق نبود — '.implode(' | ', $failOn)];
        }

        return [true, 'package «'.$name.'» روی '.implode('، ', $okOn).' آماده است.'
            .($failOn !== [] ? ' ناموفق: '.implode(' | ', $failOn) : '')];
    }

    /** نامِ معتبرِ package در WHM (حروف/رقم/زیرخط) */
    private function whmPackageName(Product $product): string
    {
        return $product->packageName();      // منبعِ یگانه روی خودِ مدل
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
            'cycle'           => ['required', \Illuminate\Validation\Rule::in(\App\Models\Service::cycles())],
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
