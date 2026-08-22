<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ServerPart;
use App\Services\Shop\PartsCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * مدیریتِ فروشگاهِ قطعات — `/admin/parts`.
 *
 * همان الگوی `PhysicalServerController` (فرمِ سه‌زبانه، اسلاگِ یکتا، فعال/غیرفعال)
 * با دو تفاوتِ عمدی:
 *
 * 🔴 قیمتِ مبنا **یورو** است. تومان فقط override است — برای قطعه‌ای که از
 * بازارِ داخلی می‌خریم و قیمتش به نرخِ ارز وصل نیست. اگر یورو مبنا نبود، با هر
 * جهشِ ارز باید کلِ کاتالوگ دستی به‌روز می‌شد و در عمل فروشگاه زیرِ قیمتِ خرید
 * می‌فروخت.
 *
 * ⚠️ هر نوشتن `PartsCatalog::flush()` می‌زند. بی‌این، مدیر قطعه‌ای اضافه می‌کرد،
 * صفحهٔ عمومی را باز می‌کرد و تا ۱۰ دقیقه نه در شمارشِ سایدبار می‌دیدش نه در
 * فیلترها — و نتیجه می‌گرفت ذخیره نشده.
 */
class ServerPartController extends Controller
{
    /** ⚠️ SVG عمداً نیست — می‌تواند اسکریپت داشته باشد و روی صفحهٔ عمومی اجرا شود. */
    private const IMG_MIMES = ['jpg', 'jpeg', 'png', 'webp'];

    public function __construct(private readonly PartsCatalog $catalog) {}

    public function index(Request $request): View
    {
        $ready = Schema::hasTable('server_parts');

        $category = $request->query('category');
        $q = trim((string) $request->query('q', ''));

        $parts = collect();

        if ($ready) {
            $parts = ServerPart::query()
                ->when(isset(ServerPart::CATEGORIES[$category]), fn ($b) => $b->where('category', $category))
                // ⚠️ جستجو روی `name` (JSON) هم لازم است: مدیر نامِ فارسی را
                //    می‌داند نه اسلاگِ لاتین را.
                ->when($q !== '', fn ($b) => $b->where(function ($w) use ($q) {
                    $w->where('slug', 'like', '%'.$q.'%')
                        ->orWhere('name', 'like', '%'.$q.'%')
                        ->orWhere('brand', 'like', '%'.$q.'%');
                }))
                ->orderBy('category')
                ->orderBy('sort')
                ->get();
        }

        return view('admin.parts', [
            'parts'      => $parts,
            'categories' => ServerPart::CATEGORIES,
            'category'   => $category,
            'q'          => $q,
            'notReady'   => ! $ready,
            'eurRate'    => cloud_eur_rate(),
        ]);
    }

    public function create(): View
    {
        return view('admin.part-edit', [
            'part'   => null,
            'action' => '/admin/parts',
        ]);
    }

    public function edit(ServerPart $part): View
    {
        return view('admin.part-edit', [
            'part'   => $part,
            'action' => '/admin/parts/'.$part->id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        $data['gallery'] = $this->handleUploads($request, $data['slug'], []);

        $part = ServerPart::create($data);

        $this->catalog->flush();
        ActivityLog::record(null, 'catalog', 'قطعهٔ «'.$part->slug.'» اضافه شد', $request, 'staff');

        return redirect('/admin/parts')->with('ok', 'قطعهٔ «'.$part->slug.'» اضافه شد.');
    }

    public function update(Request $request, ServerPart $part): RedirectResponse
    {
        $data = $this->validated($request, $part);

        // عکس‌های تیک‌خوردهٔ حذف کنار می‌روند، بعد آپلودهای تازه اضافه می‌شوند
        $keep = collect($part->gallery ?? [])
            ->reject(fn ($path) => in_array($path, (array) $request->input('remove_images', []), true))
            ->values()->all();

        $data['gallery'] = $this->handleUploads($request, $data['slug'], $keep);

        $part->update($data);

        $this->catalog->flush();
        ActivityLog::record(null, 'catalog', 'قطعهٔ «'.$part->slug.'» ویرایش شد', $request, 'staff');

        return redirect('/admin/parts')->with('ok', 'قطعهٔ «'.$part->slug.'» به‌روزرسانی شد.');
    }

    public function destroy(Request $request, ServerPart $part): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $slug = $part->slug;

        // ⚠️ پوشهٔ عکس هم پاک شود، وگرنه فایلِ یتیم روی دیسک می‌مانَد و اسلاگِ
        //    بعدی با همان نام، عکس‌های قطعهٔ حذف‌شده را به ارث می‌برد.
        $dir = public_path('assets/parts/'.$slug);
        if (Str::match('/^[a-z0-9-]+$/', $slug) === $slug && File::isDirectory($dir)) {
            File::deleteDirectory($dir);
        }

        $part->delete();

        $this->catalog->flush();
        ActivityLog::record(null, 'catalog', 'قطعهٔ «'.$slug.'» حذف شد', $request, 'staff');

        return redirect('/admin/parts')->with('ok', 'قطعهٔ «'.$slug.'» حذف شد.');
    }

    // ───────────────────────── کمکی‌ها ─────────────────────────

    /**
     * آپلودِ عکس‌های تازه به `public/assets/parts/{slug}/`.
     *
     * ⚠️ همان الگوی `PhysicalServerController` — عمداً کپی نشده بلکه هم‌شکل
     * نگه داشته شده: مسیرِ وبِ نسبی ذخیره می‌شود نه مسیرِ دیسک، چون روی
     * پروداکشن اپ بیرونِ webroot است و مسیرِ مطلق آن‌جا معنا ندارد.
     *
     * @param  list<string>  $keep
     * @return list<string>
     */
    private function handleUploads(Request $request, string $slug, array $keep): array
    {
        $files = $request->file('images', []);

        if (empty($files)) {
            return array_values($keep);
        }

        $request->validate([
            'images.*' => ['image', 'mimes:'.implode(',', self::IMG_MIMES), 'max:5120'],
        ], [], ['images.*' => 'عکس']);

        $dir = public_path('assets/parts/'.$slug);
        File::ensureDirectoryExists($dir);

        $added = [];
        foreach ($files as $file) {
            if (! $file || ! $file->isValid()) {
                continue;
            }
            $name = date('YmdHis').'-'.Str::random(6).'.'.strtolower($file->getClientOriginalExtension());
            $file->move($dir, $name);
            $added[] = '/assets/parts/'.$slug.'/'.$name;
        }

        return array_values(array_merge($keep, $added));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?ServerPart $part): array
    {
        $v = $request->validate([
            'slug'      => ['required', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('server_parts', 'slug')->ignore($part?->id)],
            'category'  => ['required', Rule::in(array_keys(ServerPart::CATEGORIES))],
            'brand'     => ['required', 'string', 'max:40'],
            'condition' => ['required', 'in:new,refurb,used'],
            'sort'      => ['nullable', 'integer', 'min:0', 'max:99999'],
            // ⚠️ یورو به **سنت** ذخیره می‌شود؛ فرم هم همان را می‌گیرد تا هیچ
            //    گردکردنِ پنهانی بینِ فرم و دیتابیس نباشد.
            'price_eur' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'price_irt' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'gens'      => ['nullable', 'array'],
            'gens.*'    => [Rule::in(array_keys((array) config('hp_generations', [])))],
            'name_fa'   => ['required', 'string', 'max:180'],
            'name_en'   => ['required', 'string', 'max:180'],
            'name_tr'   => ['required', 'string', 'max:180'],
            'tag_fa'    => ['nullable', 'string', 'max:250'],
            'tag_en'    => ['nullable', 'string', 'max:250'],
            'tag_tr'    => ['nullable', 'string', 'max:250'],
            'sum_fa'    => ['nullable', 'string', 'max:900'],
            'sum_en'    => ['nullable', 'string', 'max:900'],
            'sum_tr'    => ['nullable', 'string', 'max:900'],
            'body_fa'   => ['nullable', 'string', 'max:12000'],
            'body_en'   => ['nullable', 'string', 'max:12000'],
            'body_tr'   => ['nullable', 'string', 'max:12000'],
        ], [], [
            'slug' => 'شناسه', 'category' => 'دسته', 'brand' => 'برند',
            'name_fa' => 'نامِ فارسی', 'name_en' => 'نامِ انگلیسی', 'name_tr' => 'نامِ ترکی',
        ]);

        /*
        | ⚠️ فهرستِ **خالی** ⇒ `null` نه `[]`.
        |
        | `scopeForGeneration()` می‌گوید «قطعهٔ بدونِ فهرست عمومی است و در همهٔ
        | نسل‌ها دیده می‌شود» و این را با `orWhereNull` پیاده کرده. آرایهٔ خالی
        | در JSON نه null است نه شاملِ چیزی، پس قطعه در **هیچ** نسلی نمی‌آمد —
        | بی‌خطا، فقط نبودن.
        */
        $gens = array_values(array_filter((array) ($v['gens'] ?? [])));

        return [
            'slug'          => $v['slug'],
            'category'      => $v['category'],
            'brand'         => $v['brand'],
            'condition'     => $v['condition'],
            'compat_gens'   => $gens ?: null,
            'in_stock'      => $request->boolean('in_stock'),
            'popular'       => $request->boolean('popular'),
            'active'        => $request->boolean('active'),
            'sort'          => (int) ($v['sort'] ?? 0),
            'price_contact' => $request->boolean('price_contact'),
            'price_eur'     => $request->filled('price_eur') ? (int) $v['price_eur'] : null,
            'price_irt'     => $request->filled('price_irt') ? (int) $v['price_irt'] : null,
            'name'          => ['fa' => $v['name_fa'], 'en' => $v['name_en'], 'tr' => $v['name_tr']],
            'tagline'       => ['fa' => $v['tag_fa'] ?? '', 'en' => $v['tag_en'] ?? '', 'tr' => $v['tag_tr'] ?? ''],
            'summary'       => ['fa' => $v['sum_fa'] ?? '', 'en' => $v['sum_en'] ?? '', 'tr' => $v['sum_tr'] ?? ''],
            'body'          => ['fa' => $v['body_fa'] ?? '', 'en' => $v['body_en'] ?? '', 'tr' => $v['body_tr'] ?? ''],
            'specs'         => $this->buildSpecs($request),
            'attrs'         => $this->buildAttrs($request),
        ];
    }

    /**
     * مشخصاتِ آدم‌خوان از آرایه‌های موازیِ فرم؛ ردیفِ کاملاً خالی حذف می‌شود.
     *
     * @return list<array{label:array<string,string>, value:array<string,string>}>
     */
    private function buildSpecs(Request $request): array
    {
        $get = fn (string $k) => (array) $request->input($k, []);

        $lf = $get('spec_label_fa');
        $vf = $get('spec_val_fa');
        $out = [];

        for ($i = 0, $n = max(count($lf), count($vf)); $i < $n; $i++) {
            $row = [
                'label' => [
                    'fa' => trim((string) ($lf[$i] ?? '')),
                    'en' => trim((string) ($get('spec_label_en')[$i] ?? '')),
                    'tr' => trim((string) ($get('spec_label_tr')[$i] ?? '')),
                ],
                'value' => [
                    'fa' => trim((string) ($vf[$i] ?? '')),
                    'en' => trim((string) ($get('spec_val_en')[$i] ?? '')),
                    'tr' => trim((string) ($get('spec_val_tr')[$i] ?? '')),
                ],
            ];

            if (trim(implode('', $row['label']).implode('', $row['value'])) === '') {
                continue;
            }

            // نبودِ ترجمه ⇒ برگشت به فارسی، تا جدولِ en/tr خانهٔ خالی نشان ندهد
            foreach (['label', 'value'] as $part) {
                foreach (['en', 'tr'] as $l) {
                    if ($row[$part][$l] === '') {
                        $row[$part][$l] = $row[$part]['fa'];
                    }
                }
            }

            $out[] = $row;
        }

        return $out;
    }

    /**
     * ویژگی‌های ماشین‌خوان — فقط کلیدهای شناخته‌شدهٔ `ATTR_LABELS`.
     *
     * 🔴 کلیدِ ناشناخته دور ریخته می‌شود و ذخیره نمی‌شود: جدولِ مقایسه برای هر
     * کلید به برچسب و واحد نیاز دارد، و کلیدی که برچسب ندارد یا اصلاً رندر
     * نمی‌شد یا خام («cores») نشان داده می‌شد.
     *
     * @return array<string, float|int>
     */
    private function buildAttrs(Request $request): array
    {
        $out = [];

        foreach (array_keys(ServerPart::ATTR_LABELS) as $key) {
            $raw = $request->input('attr_'.$key);

            if ($raw === null || trim((string) $raw) === '' || ! is_numeric($raw)) {
                continue;
            }

            // عددِ صحیح، صحیح بماند: «۱۲.۰» در جدولِ مقایسه بد است
            $num = (float) $raw;
            $out[$key] = $num == (int) $num ? (int) $num : $num;
        }

        return $out;
    }
}
