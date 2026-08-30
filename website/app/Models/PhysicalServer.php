<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * مدلِ سرورِ فیزیکیِ فروشگاه (HP/Dell/Lenovo/Supermicro).
 *
 * جای config/servers.php را می‌گیرد ولی همان **ساختار** را بازتولید می‌کند
 * (`toShopArray()`) تا Bladeهای فروشگاه (`servers-index` / `server-detail`)
 * بدونِ تغییر کار کنند. برندها هنوز در config('servers.brands') می‌مانند
 * (لیبل و رنگ به‌ندرت عوض می‌شوند).
 */
class PhysicalServer extends Model
{
    protected $fillable = [
        'slug', 'brand', 'condition', 'popular', 'active', 'sort',
        'price_contact', 'price_irt', 'price_eur',
        'name', 'tag', 'hero_d', 'description', 'body', 'strengths', 'weaknesses', 'specs', 'gallery',
    ];

    protected $casts = [
        'popular'       => 'boolean',
        'active'        => 'boolean',
        'price_contact' => 'boolean',
        'sort'          => 'integer',
        'price_irt'     => 'integer',
        'price_eur'     => 'integer',
        'name'          => 'array',
        'tag'           => 'array',
        'hero_d'        => 'array',
        'description'   => 'array',
        'body'          => 'array',
        'strengths'     => 'array',
        'weaknesses'    => 'array',
        'specs'         => 'array',
        'gallery'       => 'array',
    ];

    /** واژگانِ آماده‌ی برچسبِ مشخصات — برای پیشنهادِ خودکار در فرمِ مدیریت. */
    public const SPEC_LABELS = [
        ['fa' => 'پردازنده', 'en' => 'Processor', 'tr' => 'İşlemci'],
        ['fa' => 'حافظه (RAM)', 'en' => 'Memory (RAM)', 'tr' => 'Bellek (RAM)'],
        ['fa' => 'ذخیره‌سازی', 'en' => 'Storage', 'tr' => 'Depolama'],
        ['fa' => 'کنترلر RAID', 'en' => 'RAID controller', 'tr' => 'RAID denetleyici'],
        ['fa' => 'شبکه', 'en' => 'Network', 'tr' => 'Ağ'],
        ['fa' => 'منبع تغذیه', 'en' => 'Power supply', 'tr' => 'Güç kaynağı'],
        ['fa' => 'فرم‌فاکتور', 'en' => 'Form factor', 'tr' => 'Form faktörü'],
        ['fa' => 'مدیریت از راه دور', 'en' => 'Out-of-band mgmt', 'tr' => 'Uzaktan yönetim'],
        ['fa' => 'گارانتی', 'en' => 'Warranty', 'tr' => 'Garanti'],
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('active', true);
    }

    /** ترتیبِ نمایش در فروشگاه: sort، بعد محبوب، بعد جدیدترین. */
    public function scopeOrdered(Builder $q): Builder
    {
        return $q->orderBy('sort')->orderByDesc('popular')->orderBy('id');
    }

    /**
     * بازتولیدِ ساختارِ config برای Bladeها.
     *
     * `lc($m)` روی ['fa'=>['name','tag','hero_d','desc'], …] کار می‌کند و
     * `lc($spec)` روی خودِ ردیفِ مشخصات؛ پس دقیقاً همان شکلِ قدیمی را می‌سازیم.
     */
    public function toShopArray(): array
    {
        $lang = fn (array $arr) => [
            'fa' => (string) ($arr['fa'] ?? ''),
            'en' => (string) ($arr['en'] ?? ''),
            'tr' => (string) ($arr['tr'] ?? ''),
        ];

        $fa = $en = $tr = [];
        foreach (['name' => 'name', 'tag' => 'tag', 'hero_d' => 'hero_d', 'desc' => 'description'] as $out => $col) {
            $v = $lang((array) ($this->{$col} ?? []));
            $fa[$out] = $v['fa'];
            $en[$out] = $v['en'];
            $tr[$out] = $v['tr'];
        }

        // محتوای غنیِ هر زبان: بدنهٔ بلند + فهرستِ قوت/ضعف
        foreach (['fa', 'en', 'tr'] as $l) {
            $bucket = &${$l};
            $bucket['body'] = (string) data_get($this->body, $l, '');
            $bucket['strengths'] = array_values((array) data_get($this->strengths, $l, []));
            $bucket['weaknesses'] = array_values((array) data_get($this->weaknesses, $l, []));
            unset($bucket);
        }

        return [
            'brand'      => $this->brand,
            'condition'  => $this->condition,
            'popular'    => (bool) $this->popular,
            'fa'         => $fa,
            'en'         => $en,
            'tr'         => $tr,
            'price_from' => $this->price_contact
                ? ['contact' => true]
                : array_filter(['irt' => $this->price_irt, 'eur' => $this->price_eur], fn ($v) => $v !== null),
            'specs'      => array_values((array) ($this->specs ?? [])),
            'gallery'    => array_values((array) ($this->gallery ?? [])),
        ];
    }
}
