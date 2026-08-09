<?php

namespace App\Services\Calendar\Providers;

use App\Models\Post;
use App\Services\Calendar\CalendarEventProvider;
use App\Services\Calendar\CalendarItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * تقویمِ انتشارِ محتوا — از `posts.published_at`.
 *
 * 🔴 **چرا این provider زیرِ لایهٔ `social_post` نشسته:** هیچ جدولی برای
 * زمان‌بندیِ شبکه‌های اجتماعی در این پروژه وجود ندارد (نه `scheduled_posts`،
 * نه `content_calendar`). ولی یک زمان‌بندیِ انتشارِ **واقعی** هست: کرونِ
 * `content:publish-due` هر ساعت پیش‌نویس‌هایی را منتشر می‌کند که
 * `published_at` شان رسیده باشد. یعنی `posts` دقیقاً همان چیزی است که آن لایه
 * می‌خواست نشان دهد.
 *
 * برچسبِ فارسیِ لایه در `config/calendar.php` عمداً «انتشار محتوا» است، نه
 * «شبکه‌های اجتماعی» — رابط کاربری نباید چیزی را ادعا کند که پشتش نیست.
 * اگر روزی جدولِ شبکه‌های اجتماعی ساخته شد، فقط `provider` آن ردیف عوض می‌شود.
 */
class ContentPostProvider implements CalendarEventProvider
{
    use CapsLayerRows;

    public function getEvents(Carbon $from, Carbon $to): Collection
    {
        if (! Schema::hasTable('posts')) {
            return collect();
        }

        return Post::query()
            ->with('translations:id,post_id,locale,title')
            ->whereNotNull('published_at')
            ->whereBetween('published_at', [$from, $to])
            ->orderBy('published_at')
            ->limit($this->rowCap())
            ->get()
            ->map(fn (Post $post) => new CalendarItem(
                type: 'social_post',
                source: 'post',
                sourceId: $post->id,
                title: $this->title($post),
                description: $this->describe($post),
                at: $post->published_at,
                /*
                 * منتشرشده = انجام‌شده. این تنها لایه‌ای است که وضعیتِ واقعیِ
                 * «تمام شد» را از منبع می‌گیرد، پس تقویم به‌جای یک فهرستِ
                 * یک‌دست، تفاوتِ «منتشر شد» و «هنوز در صف است» را نشان می‌دهد.
                 */
                status: $post->status === 'published' ? 'done' : 'pending',
                meta: [
                    'post_id' => $post->id,
                    'slug'    => $post->slug,
                    'kind'    => $post->type,
                    'state'   => $post->status,
                ],
                url: '/admin/posts/'.$post->id.'/edit',
                editable: false,
            ));
    }

    private function title(Post $post): string
    {
        // عنوانِ فارسی، وگرنه هر ترجمه‌ای که هست، وگرنه اسلاگ — هرگز رشتهٔ خالی،
        // چون چیپِ بی‌عنوان در تقویم یک مربعِ رنگیِ بی‌معنی است.
        return $post->tr('fa')?->title
            ?: ($post->tr()?->title ?: (string) $post->slug);
    }

    private function describe(Post $post): string
    {
        $kind = $post->type === 'kb' ? 'پایگاه دانش' : 'بلاگ';
        $state = $post->status === 'published' ? 'منتشر شده' : 'در صفِ انتشار';

        return $kind.' — '.$state;
    }
}
