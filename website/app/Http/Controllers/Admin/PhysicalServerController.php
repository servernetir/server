<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\PhysicalServer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * مدیریتِ فروشگاهِ سرورِ فیزیکی — افزودن/ویرایش/حذفِ مدل‌ها، مشخصاتِ سه‌زبانه و گالری.
 *
 * منبعِ داده جدولِ `physical_servers` است؛ همان چیزی که صفحاتِ عمومیِ `/servers`
 * نشان می‌دهند. عکس‌ها در `public/assets/servers/{slug}/` می‌نشینند.
 */
class PhysicalServerController extends Controller
{
    /** پسوندهای مجازِ عکس — SVG عمداً نیست (می‌تواند اسکریپت داشته باشد). */
    private const IMG_MIMES = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    public function index(): View
    {
        $ready = Schema::hasTable('physical_servers');

        return view('admin.physical-servers', [
            'servers'  => $ready ? PhysicalServer::query()->ordered()->get() : collect(),
            'brands'   => (array) config('servers.brands'),
            'notReady' => ! $ready,
        ]);
    }

    public function create(): View
    {
        return view('admin.physical-server-edit', [
            'server' => null,
            'brands' => (array) config('servers.brands'),
            'action' => '/admin/server-shop',
        ]);
    }

    public function edit(PhysicalServer $server): View
    {
        return view('admin.physical-server-edit', [
            'server' => $server,
            'brands' => (array) config('servers.brands'),
            'action' => '/admin/server-shop/'.$server->id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        $data['gallery'] = $this->handleUploads($request, $data['slug'], []);

        $server = PhysicalServer::create($data);

        ActivityLog::record(null, 'catalog', 'سرورِ فیزیکی «'.$server->slug.'» اضافه شد', $request, 'staff');

        return redirect('/admin/server-shop')->with('ok', 'سرورِ فیزیکی «'.$server->slug.'» اضافه شد.');
    }

    public function update(Request $request, PhysicalServer $server): RedirectResponse
    {
        $data = $this->validated($request, $server);

        // گالری: حذف‌های انتخابی، سپس افزودنِ آپلودهای تازه
        $keep = collect($server->gallery ?? [])
            ->reject(fn ($p) => in_array($p, (array) $request->input('remove_images', []), true))
            ->values()->all();

        $data['gallery'] = $this->handleUploads($request, $data['slug'], $keep);

        $server->update($data);

        ActivityLog::record(null, 'catalog', 'سرورِ فیزیکی «'.$server->slug.'» ویرایش شد', $request, 'staff');

        return redirect('/admin/server-shop')->with('ok', 'سرورِ فیزیکی «'.$server->slug.'» به‌روزرسانی شد.');
    }

    public function destroy(Request $request, PhysicalServer $server): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $slug = $server->slug;

        // پوشهٔ عکس‌ها را هم پاک کن تا فایلِ یتیم نماند
        $dir = public_path('assets/servers/'.$slug);
        if (Str::match('/^[a-z0-9-]+$/', $slug) === $slug && File::isDirectory($dir)) {
            File::deleteDirectory($dir);
        }

        $server->delete();

        ActivityLog::record(null, 'catalog', 'سرورِ فیزیکی «'.$slug.'» حذف شد', $request, 'staff');

        return redirect('/admin/server-shop')->with('ok', 'سرورِ فیزیکی «'.$slug.'» حذف شد.');
    }

    // ───────────────────────── کمکی‌ها ─────────────────────────

    /** اعتبارسنجی + ساختِ آرایهٔ ذخیره (بدونِ گالری، که جدا مدیریت می‌شود). */
    private function validated(Request $request, ?PhysicalServer $server): array
    {
        $brands = array_keys((array) config('servers.brands'));
        $id = $server?->id;

        $v = $request->validate([
            'slug'          => ['required', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/',
                \Illuminate\Validation\Rule::unique('physical_servers', 'slug')->ignore($id)],
            'brand'         => ['required', \Illuminate\Validation\Rule::in($brands)],
            'condition'     => ['required', 'in:new,refurb'],
            'sort'          => ['nullable', 'integer', 'min:0', 'max:99999'],
            'price_irt'     => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'price_eur'     => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'name_fa'       => ['required', 'string', 'max:150'],
            'name_en'       => ['required', 'string', 'max:150'],
            'name_tr'       => ['required', 'string', 'max:150'],
            'tag_fa'        => ['nullable', 'string', 'max:200'],
            'tag_en'        => ['nullable', 'string', 'max:200'],
            'tag_tr'        => ['nullable', 'string', 'max:200'],
            'hero_d_fa'     => ['nullable', 'string', 'max:600'],
            'hero_d_en'     => ['nullable', 'string', 'max:600'],
            'hero_d_tr'     => ['nullable', 'string', 'max:600'],
            'desc_fa'       => ['nullable', 'string', 'max:6000'],
            'desc_en'       => ['nullable', 'string', 'max:6000'],
            'desc_tr'       => ['nullable', 'string', 'max:6000'],
            'body_fa'       => ['nullable', 'string', 'max:12000'],
            'body_en'       => ['nullable', 'string', 'max:12000'],
            'body_tr'       => ['nullable', 'string', 'max:12000'],
            'strengths_fa'  => ['nullable', 'string', 'max:4000'],
            'strengths_en'  => ['nullable', 'string', 'max:4000'],
            'strengths_tr'  => ['nullable', 'string', 'max:4000'],
            'weaknesses_fa' => ['nullable', 'string', 'max:4000'],
            'weaknesses_en' => ['nullable', 'string', 'max:4000'],
            'weaknesses_tr' => ['nullable', 'string', 'max:4000'],
        ], [], [
            'slug' => 'شناسه', 'brand' => 'برند', 'name_fa' => 'نامِ فارسی',
            'name_en' => 'نامِ انگلیسی', 'name_tr' => 'نامِ ترکی',
        ]);

        return [
            'slug'          => $v['slug'],
            'brand'         => $v['brand'],
            'condition'     => $v['condition'],
            'popular'       => $request->boolean('popular'),
            'active'        => $request->boolean('active'),
            'sort'          => (int) ($v['sort'] ?? 0),
            'price_contact' => $request->boolean('price_contact'),
            'price_irt'     => $request->filled('price_irt') ? (int) $v['price_irt'] : null,
            'price_eur'     => $request->filled('price_eur') ? (int) $v['price_eur'] : null,
            'name'          => ['fa' => $v['name_fa'], 'en' => $v['name_en'], 'tr' => $v['name_tr']],
            'tag'           => ['fa' => $v['tag_fa'] ?? '', 'en' => $v['tag_en'] ?? '', 'tr' => $v['tag_tr'] ?? ''],
            'hero_d'        => ['fa' => $v['hero_d_fa'] ?? '', 'en' => $v['hero_d_en'] ?? '', 'tr' => $v['hero_d_tr'] ?? ''],
            'description'   => ['fa' => $v['desc_fa'] ?? '', 'en' => $v['desc_en'] ?? '', 'tr' => $v['desc_tr'] ?? ''],
            'body'          => ['fa' => $v['body_fa'] ?? '', 'en' => $v['body_en'] ?? '', 'tr' => $v['body_tr'] ?? ''],
            'strengths'     => ['fa' => $this->lines($v['strengths_fa'] ?? ''), 'en' => $this->lines($v['strengths_en'] ?? ''), 'tr' => $this->lines($v['strengths_tr'] ?? '')],
            'weaknesses'    => ['fa' => $this->lines($v['weaknesses_fa'] ?? ''), 'en' => $this->lines($v['weaknesses_en'] ?? ''), 'tr' => $this->lines($v['weaknesses_tr'] ?? '')],
            'specs'         => $this->buildSpecs($request),
        ];
    }

    /** هر خطِ ناخالی یک نکته — برای فهرستِ قوت/ضعف. */
    private function lines(string $raw): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $raw)), fn ($l) => $l !== ''));
    }

    /**
     * مشخصات از آرایه‌های موازیِ فرم ساخته می‌شوند؛ ردیفی که همه‌چیزش خالی است
     * حذف می‌شود. هر ردیف: {label:{fa,en,tr}, fa, en, tr}.
     */
    private function buildSpecs(Request $request): array
    {
        $lf = (array) $request->input('spec_label_fa', []);
        $le = (array) $request->input('spec_label_en', []);
        $lt = (array) $request->input('spec_label_tr', []);
        $vf = (array) $request->input('spec_val_fa', []);
        $ve = (array) $request->input('spec_val_en', []);
        $vt = (array) $request->input('spec_val_tr', []);

        $out = [];
        $count = max(count($lf), count($vf));

        for ($i = 0; $i < $count; $i++) {
            $row = [
                'label' => [
                    'fa' => trim((string) ($lf[$i] ?? '')),
                    'en' => trim((string) ($le[$i] ?? '')),
                    'tr' => trim((string) ($lt[$i] ?? '')),
                ],
                'fa' => trim((string) ($vf[$i] ?? '')),
                'en' => trim((string) ($ve[$i] ?? '')),
                'tr' => trim((string) ($vt[$i] ?? '')),
            ];

            // ردیفِ کاملاً خالی را نگه ندار
            if ($row['label']['fa'].$row['label']['en'].$row['label']['tr'].$row['fa'].$row['en'].$row['tr'] !== '') {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * آپلودِ عکس‌های تازه به `public/assets/servers/{slug}/` و افزودن به گالریِ
     * نگه‌داشته‌شده. مسیرِ وبِ نسبی ذخیره می‌شود.
     *
     * @param  array<int,string>  $keep  عکس‌هایی که باید بمانند
     * @return array<int,string>
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

        $dir = public_path('assets/servers/'.$slug);
        File::ensureDirectoryExists($dir);

        $added = [];
        foreach ($files as $file) {
            if (! $file || ! $file->isValid()) {
                continue;
            }
            $name = date('YmdHis').'-'.Str::random(6).'.'.strtolower($file->getClientOriginalExtension());
            $file->move($dir, $name);
            $added[] = '/assets/servers/'.$slug.'/'.$name;
        }

        return array_values(array_merge($keep, $added));
    }
}
