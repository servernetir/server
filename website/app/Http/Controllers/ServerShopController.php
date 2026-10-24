<?php

namespace App\Http\Controllers;

use App\Models\PhysicalServer;
use App\Models\ServerPart;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
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
    public function index(Request $request): View
    {
        $catalog = $this->catalog();
        $brand = request()->query('brand', 'all');
        $activeBrand = is_string($brand) && array_key_exists($brand, (array) config('servers.brands')) ? $brand : 'all';
        $search = is_string($request->query('q')) ? mb_substr(trim($request->query('q')), 0, 60) : '';
        $models = collect($catalog)->filter(function ($model) use ($search, $activeBrand) {
            if ($activeBrand !== 'all' && $model['brand'] !== $activeBrand) {
                return false;
            }
            return $search === '' || mb_stripos(json_encode([$model['fa']['name'] ?? '', $model['en']['name'] ?? '', $model['specs'] ?? []], JSON_UNESCAPED_UNICODE), $search) !== false;
        });
        $compareRaw = is_string($request->query('compare')) ? mb_substr($request->query('compare'), 0, 600) : '';
        if ($compareRaw === '' && is_array($request->query('models'))) {
            $compareRaw = implode(',', array_slice(array_filter($request->query('models'), fn ($value) => is_string($value) && strlen($value) <= 150), 0, 4));
        }
        $compareSlugs = array_slice(array_values(array_unique(array_filter(explode(',', $compareRaw), fn ($slug) => isset($catalog[$slug])))), 0, 4);
        $comparison = collect($compareSlugs)->mapWithKeys(fn ($slug) => [$slug => $catalog[$slug]]);
        $pageRaw = $request->query('page');
        $page = is_scalar($pageRaw) ? max(1, (int) $pageRaw) : 1;
        abort_if($page > max(1, (int) ceil($models->count() / 24)), 404);
        $pagination = new LengthAwarePaginator($models->forPage($page, 24), $models->count(), 24, $page, ['path' => url()->current()]);
        $pagination->appends(array_filter(['q' => $search, 'brand' => $activeBrand === 'all' ? null : $activeBrand]));
        $altUrls = [];
        foreach (\App\Providers\AppServiceProvider::LOCALES as $locale => $prefix) {
            $altUrls[$locale] = route($prefix.'servers.index').($page > 1 ? '?page='.$page : '');
        }

        return view('pages.servers-index', [
            'brands' => (array) config('servers.brands'),
            'models' => $pagination->getCollection()->all(),
            'activeBrand' => $activeBrand,
            'catalogModels' => $catalog,
            'pagination' => $pagination,
            'comparison' => $comparison,
            'search' => $search,
            'altUrls' => $altUrls,
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
            'slug' => $slug,
            'model' => $model,
            'brand' => $brand,
            'related' => $related instanceof Collection ? $related : collect($related),
        ]);
    }

    /**
     * کاتالوگِ سرورِ فیزیکی به‌صورتِ [slug => ساختارِ نمایشی].
     * DB اولویت دارد؛ نبودِ جدول/خالی‌بودن ⇒ fallbackِ config.
     */
    public function sitemap(): \Illuminate\Http\Response
    {
        $entries = [];
        $add = function (string $name, array|string $params = [], ?string $modified = null, array $images = []) use (&$entries): void {
            foreach (\App\Providers\AppServiceProvider::LOCALES as $prefix) {
                $entries[] = ['loc' => route($prefix.$name, $params), 'modified' => $modified, 'images' => $images];
            }
        };
        $add('servers.index');
        $add('parts.index');
        foreach (array_keys(ServerPart::CATEGORIES) as $category) $add('parts.category', $category);
        foreach (array_keys((array) config('hp_generations')) as $generation) $add('servers.generation', $generation);
        if (Schema::hasTable('physical_servers') && PhysicalServer::active()->exists()) {
            foreach (PhysicalServer::active()->get(['slug', 'gallery', 'updated_at']) as $server) {
                $add('servers.show', $server->slug, $server->updated_at?->toAtomString(), (array) $server->gallery);
            }
        } else {
            foreach ((array) config('servers.models') as $slug => $server) $add('servers.show', $slug, null, (array) ($server['gallery'] ?? []));
        }
        if (Schema::hasTable('server_parts')) {
            foreach (ServerPart::active()->get(['slug', 'category', 'gallery', 'updated_at']) as $part) {
                $add('parts.show', [$part->category, $part->slug], $part->updated_at?->toAtomString(), (array) $part->gallery);
            }
        }
        $escape = fn ($value) => htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">';
        foreach ($entries as $entry) {
            $xml .= '<url><loc>'.$escape($entry['loc']).'</loc>';
            if ($entry['modified']) $xml .= '<lastmod>'.$escape($entry['modified']).'</lastmod>';
            foreach (array_unique(array_filter(array_map('img_url', $entry['images']))) as $image) {
                $xml .= '<image:image><image:loc>'.$escape(url($image)).'</image:loc></image:image>';
            }
            $xml .= '</url>';
        }
        return response($xml.'</urlset>', 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

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
