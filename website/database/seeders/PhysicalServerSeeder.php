<?php

namespace Database\Seeders;

use App\Models\PhysicalServer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * پرکردنِ کاتالوگِ سرورِ فیزیکی از `config/servers.php` — **insert-missing**.
 *
 * فقط اسلاگ‌هایی که هنوز در DB نیستند اضافه می‌شوند؛ ردیف‌های موجود (که ممکن است
 * مدیر ویرایش کرده باشد) دست‌نخورده می‌مانند. پس هر بار امن اجرا می‌شود:
 * روتِ دیپلوی بعد از هر migrate صدایش می‌زند و مدل‌های تازهٔ config را می‌افزاید.
 */
class PhysicalServerSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('physical_servers')) {
            return;
        }

        $models = (array) config('servers.models');
        $existing = PhysicalServer::pluck('slug')->all();
        $sort = ((int) PhysicalServer::max('sort')) ?: 0;

        foreach ($models as $slug => $m) {
            if (in_array($slug, $existing, true)) {
                continue;   // موجود است — ویرایشِ مدیر را پاک نکن
            }

            $lang = fn (string $k) => [
                'fa' => (string) ($m['fa'][$k] ?? ''),
                'en' => (string) ($m['en'][$k] ?? ''),
                'tr' => (string) ($m['tr'][$k] ?? ''),
            ];
            // محتوای غنی سطحِ بالای مدل است: ['fa'=>…, 'en'=>…, 'tr'=>…]
            $rich = fn (string $k) => isset($m[$k]) && is_array($m[$k]) ? [
                'fa' => $m[$k]['fa'] ?? ($k === 'body' ? '' : []),
                'en' => $m[$k]['en'] ?? ($k === 'body' ? '' : []),
                'tr' => $m[$k]['tr'] ?? ($k === 'body' ? '' : []),
            ] : null;

            $price = (array) ($m['price_from'] ?? ['contact' => true]);

            PhysicalServer::create([
                'slug'          => $slug,
                'brand'         => (string) ($m['brand'] ?? 'hp'),
                'condition'     => (string) ($m['condition'] ?? 'new'),
                'popular'       => (bool) ($m['popular'] ?? false),
                'active'        => true,
                'sort'          => $sort += 10,
                'price_contact' => (bool) ($price['contact'] ?? true),
                'price_irt'     => $price['irt'] ?? null,
                'price_eur'     => $price['eur'] ?? null,
                'name'          => $lang('name'),
                'tag'           => $lang('tag'),
                'hero_d'        => $lang('hero_d'),
                'description'   => $lang('desc'),
                'body'          => $rich('body'),
                'strengths'     => $rich('strengths'),
                'weaknesses'    => $rich('weaknesses'),
                'specs'         => array_values((array) ($m['specs'] ?? [])),
                'gallery'       => array_values((array) ($m['gallery'] ?? [])),
            ]);
        }
    }
}
