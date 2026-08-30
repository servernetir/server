<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ویرایشِ مدیر روی یک گرهِ منو — یا لینکی که خودش افزوده.
 *
 * ⚠️ نبودِ ردیف = پیش‌فرضِ config. پس حذفِ یک ردیف همیشه بی‌خطر است و
 * بدترین اشتباهِ ممکن در پنل، برگشت به همان چیزی است که امروز کار می‌کند.
 */
class MenuOverride extends Model
{
    protected $fillable = [
        'menu', 'path', 'visible', 'sort',
        'label_fa', 'label_en', 'label_tr',
        'desc_fa', 'desc_en', 'desc_tr',
        'custom', 'updated_by',
    ];

    protected $casts = [
        'visible' => 'boolean',
        'custom'  => 'array',
    ];

    /** منوهایی که رویه می‌پذیرند. */
    public const MENUS = ['mega', 'services', 'tools', 'knowledge', 'footer'];

    /** برچسبِ یک زبان، یا null اگر مدیر چیزی ننوشته. */
    public function label(string $locale): ?string
    {
        $v = $this->{'label_'.$locale};

        return ($v === null || $v === '') ? null : $v;
    }

    public function desc(string $locale): ?string
    {
        $v = $this->{'desc_'.$locale};

        return ($v === null || $v === '') ? null : $v;
    }

    /** لینکی که مدیر خودش افزوده (در config نیست). */
    public function isCustom(): bool
    {
        return ! empty($this->custom);
    }
}
