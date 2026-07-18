<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    protected $fillable = ['post_slug', 'name', 'email', 'body', 'approved', 'ip'];

    protected $casts = ['approved' => 'boolean'];

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
}
