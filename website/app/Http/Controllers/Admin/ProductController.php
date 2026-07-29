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

        $products = $ready
            ? Product::with('server')->orderBy('group')->orderBy('sort')->orderBy('id')->get()
            : collect();

        return view('admin.products', [
            'products' => $products,
            // گروه‌بندی برای تغییرِ قیمتِ گروهی: «همهٔ هاست‌های وردپرس» یک‌جا
            'groups'   => $products->groupBy(fn (Product $p) => $p->group ?: 'بدون گروه'),
            'servers'  => Schema::hasTable('servers') ? Server::orderBy('name')->get() : collect(),
            'notReady' => ! $ready,
        ]);
    }

    /**
     * تغییرِ قیمتِ **گروهی** — درصدی یا مبلغِ ثابت، با گردکردنِ رو به بالا.
     *
     * چرا این‌جا و نه ویرایشِ تک‌تکِ ۵۲ پکیج: کارفنی روزمرهٔ کارفرما «۱۰٪ به همهٔ
     * هاست‌های لینوکس اضافه کن» است. دستیِ آن، ۵۲ بار خطای انسانی است.
     *
     * ⚠️ فقط قیمتِ **پایه** عوض می‌شود. ضریبِ نرخِ ارز (price_factor) و تخفیفِ
     * دوره‌ها جای خودشان می‌مانند، پس محاسبهٔ نهایی دست‌نخورده است.
     */
    public function reprice(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'group'     => ['required', 'string', 'max:60'],
            'mode'      => ['required', 'in:percent,amount,set'],
            'value'     => ['required', 'numeric'],
            'round'     => ['nullable', 'integer', 'min:1000', 'max:1000000'],
            'also_eur'  => ['nullable', 'boolean'],
            'confirm'   => ['nullable', 'boolean'],
        ], [], [
            'group' => 'گروه', 'mode' => 'نوعِ تغییر', 'value' => 'مقدار',
        ]);

        $step = (int) ($data['round'] ?? 10000);
        $items = Product::where('group', $data['group'])->get();

        if ($items->isEmpty()) {
            return back()->withErrors('در گروهِ «'.$data['group'].'» پکیجی نیست.');
        }

        $changed = 0;
        $preview = [];

        foreach ($items as $p) {
            $old = (int) $p->price;

            $new = match ($data['mode']) {
                'percent' => $old * (1 + ((float) $data['value'] / 100)),
                'amount'  => $old + (float) $data['value'],
                'set'     => (float) $data['value'],
            };

            $new = Product::roundUpToman(max(0, $new), $step);

            // یورو هم به همان نسبت (فقط در حالتِ درصدی معنا دارد)
            $newEur = $p->price_eur;
            if (! empty($data['also_eur']) && $p->price_eur !== null && $data['mode'] === 'percent') {
                $newEur = Product::roundUpEur($p->price_eur * (1 + ((float) $data['value'] / 100)));
            }

            $preview[] = $p->name.': '.number_format($old).' → '.number_format($new);

            if ($new !== $old || $newEur !== $p->price_eur) {
                $p->forceFill(['price' => $new, 'price_eur' => $newEur])->save();
                $changed++;
            }
        }

        // کشِ قیمتِ کاتالوگ را بریز تا سایت فوراً عددِ تازه را نشان دهد
        \App\Services\CatalogPricing::forget();

        \App\Models\ActivityLog::record(null, 'pricing',
            'قیمتِ گروهِ «'.$data['group'].'» تغییر کرد ('.$data['mode'].' '.$data['value'].') — '
            .$changed.' پکیج', $request, 'staff');

        return back()->with('ok', $changed.' پکیج در گروهِ «'.$data['group'].'» به‌روزرسانی شد. '
            .'نمونه: '.implode(' · ', array_slice($preview, 0, 3)));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['specs'] = $this->parseSpecs($request->input('specs_raw'));
        $product = Product::create($data);

        \App\Services\CatalogPricing::forget();

        // پکیجِ تازه بی‌درنگ روی WHM ساخته می‌شود. نگرانیِ درست کارفرما: پکیجی که
        // در سایت هست ولی در WHM نیست، در لحظهٔ سفارشِ مشتری با خطا می‌خورد.
        [, $whm] = $this->createWhmPackage($product);

        return back()->with('ok', 'پکیج «'.$data['name'].'» اضافه شد. '.$whm);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $data = $this->validated($request);
        $data['specs'] = $this->parseSpecs($request->input('specs_raw'));

        // آیا مشخصاتِ فنی عوض شد؟ اگر بله، حدومرزِ packageِ WHM هم باید عوض شود
        $specsChanged = json_encode($data['specs']) !== json_encode($product->specs);

        $product->update($data);

        \App\Services\CatalogPricing::forget();

        $note = '';
        if ($specsChanged) {
            // editpkg روی همهٔ سرورهای WHM — وگرنه سایت و سرور از هم می‌پاشند:
            // مشتری «۱۰ گیگ» می‌خرد و روی سرور همان ۵ گیگِ قبلی را می‌گیرد.
            [, $note] = $this->createWhmPackage($product);
            $note = ' مشخصات عوض شد → '.$note;
        }

        return back()->with('ok', 'پکیج «'.$product->name.'» به‌روزرسانی شد.'.$note);
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

        $servers = $this->whmServers();

        // اگر ارتباط با سرورها برقرار نیست، ۵۲ بار تلاشِ بی‌فایده نکن — یک‌بار
        // آزمون کن و همان خطا را نشان بده. (قبلاً دلیلِ خطا دور ریخته می‌شد و
        // پیام فقط می‌گفت «۵۲ ناموفق» که با آن نمی‌شد کاری کرد.)
        $probe = [];
        foreach ($servers as $server) {
            $v = (new WhmClient($server))->call('version');
            if (! $v['ok']) {
                $probe[] = $server->name.' → '.mb_substr($v['reason'], 0, 120);
            }
        }

        if ($probe !== [] && count($probe) === $servers->count()) {
            return back()->withErrors(
                'به هیچ سرورِ WHM وصل نشدیم، پس package ساخته نشد. خطای هر سرور: '.implode(' ⟪|⟫ ', $probe)
                .' — توکنِ API و دسترسیِ پورتِ '.($servers->first()?->effectivePort() ?? 2087).' را بررسی کنید.'
            );
        }

        $products = Product::where('is_active', true)->get();
        $done = 0;
        $fail = 0;
        $reasons = [];
        foreach ($products as $p) {
            [$ok, $msg] = $this->createWhmPackage($p);
            if ($ok) {
                $done++;
            } else {
                $fail++;
                $reasons[$msg] = ($reasons[$msg] ?? 0) + 1;   // دلیل‌های یکسان را یکی کن
            }
        }

        $summary = "ساختِ package روی {$servers->count()} سرورِ WHM: {$done} پکیجِ موفق، {$fail} ناموفق (از {$products->count()}).";

        if ($fail > 0) {
            arsort($reasons);
            $top = array_slice(array_keys($reasons), 0, 2);

            return back()->withErrors($summary.' دلیل: '.implode(' ⟪|⟫ ', $top));
        }

        return back()->with('ok', $summary);
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
            $client = new WhmClient($server);
            $res = $client->addPackage(['name' => $name] + $limits);

            $exists = ! $res['ok'] && str_contains(strtolower($res['reason'] ?? ''), 'exist');

            // اگر از قبل هست، **اصلاحش کن**. قبلاً فقط رد می‌شد، پس packageی که
            // یک‌بار با حدومرزِ غلط ساخته شده بود با اجرای دوباره درست نمی‌شد.
            if ($exists) {
                $edit = $client->editPackage(['name' => $name] + $limits);
                $okOn[] = $server->name.($edit['ok'] ? ' (به‌روزرسانی شد)' : ' (بود، اصلاح نشد)');

                continue;
            }

            if ($res['ok']) {
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

            // چیزهایی که «فضای دیسک» نیستند و نباید با quota اشتباه شوند
            $notDisk = preg_match('/(پهنای|ترافیک|bandwidth|transfer|ram|رم|cpu|core|هسته|vcpu|صندوق|mailbox|سایت|دامنه|اسنپ|snapshot|روزه)/u', $t);

            // یا کلیدواژهٔ دیسک دارد («5 GB NVMe»)، یا خودش صرفاً یک اندازه است
            // («10 GB» در پکیج‌های ایمیل/بکاپ). بدونِ حالتِ دوم، ۹ پکیج quota
            // نمی‌گرفتند و WHM با «Invalid value "0" for quota» ردشان می‌کرد.
            $hasDiskWord = preg_match('/(فضا|گیگابایت|disk|storage|space|ssd|nvme|هارد)/u', $t);
            $bareSize = preg_match('/^\s*\d+(?:\.\d+)?\s*(tb|gb|mb|ترابایت|گیگ|مگ)\b/u', $t);

            if (! isset($limits['quota']) && ! $notDisk && ($hasDiskWord || $bareSize)) {
                if (preg_match('/(\d+(?:\.\d+)?)\s*(tb|ترابایت)/u', $t, $m)) {
                    $limits['quota'] = (int) round($m[1] * 1024 * 1024);
                } elseif (preg_match('/(\d+(?:\.\d+)?)\s*(gb|گیگ|مگ|mb)/u', $t, $m)) {
                    $limits['quota'] = str_contains($m[2], 'م') || $m[2] === 'mb'
                        ? (int) round($m[1])
                        : (int) round($m[1] * 1024);
                }
            }

            // تعدادِ صندوقِ ایمیل → maxpop (پکیج‌های ایمیل روی همین می‌فروشند؛
            // پیش‌فرضِ unlimited یعنی مشتریِ پلنِ ۲۰ صندوقی نامحدود می‌گرفت)
            if (! isset($limits['maxpop']) && preg_match('/(صندوق|mailbox)/u', $t)) {
                if (preg_match('/(نامحدود|unlimited)/u', $t)) {
                    $limits['maxpop'] = 'unlimited';
                } elseif (preg_match('/(\d+)/', $t, $m)) {
                    $limits['maxpop'] = (int) $m[1];
                }
            }

            if (! isset($limits['bwlimit']) && preg_match('/(پهنای|ترافیک|bandwidth|transfer)/u', $t)) {
                if (preg_match('/(نامحدود|unlimited)/u', $t)) {
                    // رشته، نه 0 — WHM مقدارِ «0» را برای bwlimit رد می‌کند و
                    // مشخصاتِ کاتالوگ «ترافیک نامحدود» دارد، پس این مسیر داغ است.
                    $limits['bwlimit'] = 'unlimited';
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
            // گروه: برای تغییرِ قیمتِ گروهی و نمایشِ دسته‌بندی‌شده
            'group'           => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9-]*$/'],
            'server_id'       => ['nullable', 'integer', 'exists:servers,id'],
            'plan'            => ['nullable', 'string', 'max:80'],
            'price'           => ['required', 'integer', 'min:0', 'max:100000000000'],
            // یورو به سنت — نسخهٔ انگلیسی/ترکی همین را نشان می‌دهد
            'price_eur'       => ['nullable', 'integer', 'min:0', 'max:100000000'],
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
