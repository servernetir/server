<?php

namespace App\Http\Controllers;

use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * فروشگاهِ سرورِ فیزیکی — فهرست + صفحهٔ اختصاصیِ هر مدل با گالری.
 *
 * کاملاً config-driven از `config/servers.php`؛ افزودنِ مدلِ تازه فقط یک ردیفِ
 * config است. تحویلِ فیزیکی خودکار نیست، پس دکمهٔ صفحه به استعلام/تماس می‌رود.
 */
class ServerShopController extends Controller
{
    public function index(): View
    {
        return view('pages.servers-index', [
            'brands' => (array) config('servers.brands'),
            'models' => (array) config('servers.models'),
        ]);
    }

    public function show(string $slug): View
    {
        $models = (array) config('servers.models');

        abort_unless(isset($models[$slug]), 404);

        $model = $models[$slug];
        $brand = config('servers.brands.'.$model['brand']);

        // مرتبط‌ها: اول هم‌برند، بعد بقیه — تا ۳ تا، به‌جز خودش.
        $related = collect($models)->except($slug)
            ->sortByDesc(fn ($m) => ($m['brand'] ?? '') === ($model['brand'] ?? ''))
            ->take(3);

        return view('pages.server-detail', [
            'slug'    => $slug,
            'model'   => $model,
            'brand'   => $brand,
            'related' => $related instanceof Collection ? $related : collect($related),
        ]);
    }
}
