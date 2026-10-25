<?php

namespace App\Services\Growth;

use App\Models\GrowthContent;
use App\Models\GrowthJob;
use App\Models\Post;
use App\Models\PostTranslation;
use App\Services\BlogRepository;
use App\Services\HtmlSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ContentWorkflow
{
    public const CHANNELS = ['manual', 'blog', 'instagram', 'linkedin', 'telegram', 'bale', 'youtube'];

    public const FORMATS = ['post', 'article', 'story', 'carousel', 'reel', 'short', 'video'];

    public const STATUSES = ['draft', 'review', 'approved', 'scheduled', 'published', 'failed'];

    public function create(array $data, int $actor): GrowthContent
    {
        return DB::transaction(function () use ($data, $actor) {
            $hash = hash('sha256', mb_strtolower(preg_replace('/\s+/u', ' ', trim($data['title']))).'|'.$data['locale'].'|'.$data['channel']);
            if (GrowthContent::where('topic_hash', $hash)->exists()) {
                throw ValidationException::withMessages(['title' => 'این موضوع برای همین زبان و کانال قبلاً ثبت شده است.']);
            }
            $item = GrowthContent::create($data + ['created_by' => $actor, 'topic_hash' => $hash])->fresh();
            $this->revision($item, $actor);
            GrowthAudit::record('content.created', 'content:'.$item->id, $actor);

            return $item;
        });
    }

    public function edit(int $id, array $data, int $version, int $actor): GrowthContent
    {
        return DB::transaction(function () use ($id, $data, $version, $actor) {
            $item = GrowthContent::lockForUpdate()->findOrFail($id);
            $this->editable($item, $version);
            $item->fill($data)->fill(['version' => $version + 1, 'status' => 'draft', 'approved_at' => null, 'approved_by' => null, 'scheduled_at' => null])->save();
            $this->revision($item, $actor);
            GrowthAudit::record('content.edited', 'content:'.$id, $actor, ['version' => $item->version]);

            return $item;
        });
    }

    public function transition(int $id, string $to, int $version, int $actor, array $extra = []): GrowthContent
    {
        return DB::transaction(function () use ($id, $to, $version, $actor, $extra) {
            $item = GrowthContent::lockForUpdate()->findOrFail($id);
            if (GrowthJob::where('content_id', $id)->whereIn('status', ['queued', 'processing'])->exists()) {
                abort(409, 'پردازش فعال باید پایان یابد.');
            }
            if ((int) $item->version !== $version) {
                abort(409, 'نسخه تغییر کرده است؛ صفحه را تازه کنید.');
            }
            $allowed = ['draft' => ['review'], 'review' => ['draft', 'approved'], 'approved' => ['draft', 'scheduled'],
                'scheduled' => ['draft'], 'failed' => ['draft'], 'published' => []];
            if (! in_array($to, $allowed[$item->status] ?? [], true)) {
                abort(409, 'این تغییر وضعیت مجاز نیست.');
            }
            if ($to === 'approved' && blank($item->body)) {
                throw ValidationException::withMessages(['body' => 'پیش از تأیید، متن را کامل کنید.']);
            }
            if ($to === 'scheduled' && (! $item->approved_by || empty($extra['scheduled_at']))) {
                abort(409);
            }
            $changes = ['status' => $to];
            if ($to === 'approved') {
                $changes += ['approved_by' => $actor, 'approved_at' => now()];
            }
            if ($to === 'draft') {
                $changes += ['approved_by' => null, 'approved_at' => null, 'scheduled_at' => null];
            }
            if ($to === 'scheduled') {
                $changes += $extra;
            }
            $item->fill($changes)->save();
            GrowthAudit::record('content.'.$to, 'content:'.$id, $actor, ['version' => $version]);

            return $item;
        });
    }

    public function publishBlog(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $item = GrowthContent::lockForUpdate()->findOrFail($id);
            if ($item->status !== 'scheduled' || $item->channel !== 'blog' || ! $item->approved_by
                || ! $item->scheduled_at || $item->scheduled_at->isFuture()) {
                return false;
            }
            $post = Post::create(['slug' => 'growth-'.$item->id, 'type' => 'blog', 'status' => 'published', 'category' => 'tutorial',
                'author_id' => $item->approved_by, 'published_at' => now()]);
            PostTranslation::create(['post_id' => $post->id, 'locale' => $item->locale, 'title' => $item->title,
                'excerpt' => mb_substr(strip_tags($item->body), 0, 158),
                'content' => self::html((string) $item->body), 'tags' => [], 'auto' => false]);
            $item->update(['status' => 'published', 'published_at' => now(), 'post_id' => $post->id,
                'publication_url' => 'https://servernet.cloud/'.($item->locale === 'fa' ? '' : $item->locale.'/').'blog/'.$post->slug]);
            GrowthAudit::record('content.published', 'content:'.$id, null, ['post_id' => $post->id]);
            BlogRepository::flush();

            return true;
        });
    }

    public function editable(GrowthContent $item, int $version): void
    {
        if ((int) $item->version !== $version || in_array($item->status, ['scheduled', 'published'], true)
            || GrowthJob::where('content_id', $item->id)->where('status', 'processing')->exists()) {
            abort(409, 'محتوا قفل است یا نسخه تغییر کرده است.');
        }
    }

    public function revision(GrowthContent $item, ?int $actor): void
    {
        DB::table('growth_revisions')->insert(['content_id' => $item->id, 'version' => $item->version,
            'snapshot' => json_encode($item->only(['title', 'brief', 'body', 'locale', 'channel', 'format', 'objective', 'target_url']), JSON_THROW_ON_ERROR),
            'actor_id' => $actor, 'created_at' => now()]);
    }

    public static function html(string $markdown): string
    {
        return HtmlSanitizer::clean(Str::markdown($markdown, [
            'html_input' => 'escape', 'allow_unsafe_links' => false,
        ]));
    }
}
