<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    protected $fillable = [
        'post_slug', 'name', 'email', 'body', 'locale', 'approved', 'ip',
        'ai_verdict', 'ai_score', 'ai_reason', 'translations', 'reply', 'reply_translations', 'replied_at',
    ];

    protected $casts = [
        'approved'           => 'boolean',
        'translations'       => 'array',
        'reply_translations' => 'array',
        'replied_at'         => 'datetime',
    ];

    /** کامنت‌های تأییدشده‌ی یک پست، قدیمی‌ترها اول */
    public static function approvedForPost(string $slug): \Illuminate\Support\Collection
    {
        return static::query()
            ->where('post_slug', $slug)
            ->where('approved', true)
            ->orderBy('created_at')
            ->get();
    }

    /** تعداد کامنت تأییدشده‌ی هر پست (برای نمایش در فهرست) */
    public static function countFor(string $slug): int
    {
        return static::query()->where('post_slug', $slug)->where('approved', true)->count();
    }

    /** متن کامنت در زبان جاری؛ اگر ترجمه نبود، متن اصلی */
    public function bodyIn(?string $locale = null): string
    {
        return $this->pick($this->translations, $this->body, $locale);
    }

    /** پاسخ هوشمند در زبان جاری */
    public function replyIn(?string $locale = null): ?string
    {
        if (! $this->reply) {
            return null;
        }

        return $this->pick($this->reply_translations, $this->reply, $locale);
    }

    /** آیا آنچه نمایش داده می‌شود ترجمه‌ی ماشینی است؟ (برای برچسب شفاف‌سازی) */
    public function isTranslated(?string $locale = null): bool
    {
        $locale ??= app()->getLocale();

        return $locale !== $this->locale && ! empty($this->translations[$locale] ?? null);
    }

    private function pick(?array $map, string $fallback, ?string $locale): string
    {
        $locale ??= app()->getLocale();
        if ($locale === $this->locale) {
            return $fallback;
        }
        $t = $map[$locale] ?? null;

        return is_string($t) && trim($t) !== '' ? $t : $fallback;
    }
}
