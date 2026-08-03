<?php

namespace App\Http\Controllers;

use App\Models\PhysicalServer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * فروشگاهِ سرورِ فیزیکی — فهرست + صفحهٔ اختصاصیِ هر مدل با گالری.
 *
 * منبعِ داده: جدولِ `physical_servers` (مدیریت‌شونده از `/admin/server-shop`).
 * اگر جدول نبود یا خالی بود، به `config/servers.php` برمی‌گردیم تا فروشگاه
 * هیچ‌وقت خالی نشود (مثلاً پیش از اجرای مهاجرت روی سرور).
 */
class ServerShopController extends Controller
{
    public function index(): View
    {
        $models = $this->catalog();

        return view('pages.servers-index', [
            'brands' => (array) config('servers.brands'),
            'models' => $models,
        ]);
    }

    public function show(string $slug): View
    {
        $models = $this->catalog();

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

    /**
     * کاتالوگِ سرورِ فیزیکی به‌صورتِ [slug => ساختارِ نمایشی].
     * DB اولویت دارد؛ نبودِ جدول/خالی‌بودن ⇒ fallbackِ config.
     */
    private function catalog(): array
    {
        if (Schema::hasTable('physical_servers')) {
            $rows = PhysicalServer::query()->active()->ordered()->get();

            if ($rows->isNotEmpty()) {
                return $rows->keyBy('slug')->map->toShopArray()->all();
            }
        }

        return (array) config('servers.models');
    }
}
