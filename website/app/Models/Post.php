<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    protected $fillable = ['slug', 'type', 'category', 'status', 'cover', 'image', 'source', 'icon', 'reading', 'author_id', 'published_at'];

    protected $casts = ['published_at' => 'datetime'];

    public function translations(): HasMany
    {
        return $this->hasMany(PostTranslation::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** ترجمه‌ی یک زبان با fallback به fa سپس en */
    public function tr(?string $locale = null): ?PostTranslation
    {
        $locale ??= app()->getLocale();
        $all = $this->relationLoaded('translations') ? $this->translations : $this->translations()->get();

        return $all->firstWhere('locale', $locale)
            ?? $all->firstWhere('locale', 'fa')
            ?? $all->first();
    }

    public function scopePublished($q)
    {
        return $q->where('status', 'published');
    }
}
