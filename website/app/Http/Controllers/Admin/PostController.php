<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostTranslation;
use App\Services\AiContent;
use App\Services\BlogRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PostController extends Controller
{
    public function __construct(private AiContent $ai) {}

    public function index(Request $request): View
    {
        $type = $request->query('type', 'blog');
        $posts = Post::query()->where('type', $type)->with('translations')->orderByDesc('id')->paginate(20);

        return view('admin.posts', ['posts' => $posts, 'type' => $type]);
    }

    public function edit(Request $request, ?Post $post = null): View
    {
        $post?->load('translations');

        return view('admin.post-edit', [
            'post' => $post,
            'type' => $post->type ?? $request->query('type', 'blog'),
        ]);
    }

    public function save(Request $request, ?Post $post = null): RedirectResponse
    {
        $data = $request->validate([
            'type'         => 'required|in:blog,kb',
            'slug'         => 'required|string|max:120|regex:~^[a-z0-9\-]+$~',
            'category'     => 'required|string|max:40',
            'status'       => 'required|in:draft,published',
            'cover'        => 'required|string|max:4',
            'icon'         => 'required|string|max:24',
            'fa_title'     => 'required|string|max:200',
            'fa_excerpt'   => 'nullable|string|max:500',
            'fa_content'   => 'required|string',
            'fa_tags'      => 'nullable|string|max:400',
            'en_title'     => 'nullable|string|max:200', 'en_excerpt' => 'nullable|string|max:500', 'en_content' => 'nullable|string', 'en_tags' => 'nullable|string|max:400',
            'tr_title'     => 'nullable|string|max:200', 'tr_excerpt' => 'nullable|string|max:500', 'tr_content' => 'nullable|string', 'tr_tags' => 'nullable|string|max:400',
        ]);

        // اسلاگ یکتا
        $exists = Post::where('slug', $data['slug'])->when($post, fn ($q) => $q->where('id', '!=', $post->id))->exists();
        if ($exists) {
            return back()->withInput()->withErrors(['slug' => 'این اسلاگ قبلاً استفاده شده است.']);
        }

        $post ??= new Post;
        $post->fill([
            'type'     => $data['type'],
            'slug'     => $data['slug'],
            'category' => $data['category'],
            'status'   => $data['status'],
            'cover'    => $data['cover'],
            'icon'     => $data['icon'],
            'reading'  => max(1, (int) ceil(str_word_count(strip_tags($data['fa_content'])) / 200) ?: 5),
            'author_id' => $request->user()->id,
        ]);
        if ($data['status'] === 'published' && ! $post->published_at) {
            $post->published_at = now();
        }
        $post->save();

        foreach (['fa', 'en', 'tr'] as $loc) {
            $title = trim((string) ($data[$loc.'_title'] ?? ''));
            if ($loc !== 'fa' && $title === '') {
                continue; // ترجمه‌ی خالی را ذخیره نکن (از دکمه‌ی ترجمه پر می‌شود)
            }
            PostTranslation::updateOrCreate(
                ['post_id' => $post->id, 'locale' => $loc],
                [
                    'title'   => $title,
                    'excerpt' => trim((string) ($data[$loc.'_excerpt'] ?? '')),
                    'content' => \App\Services\HtmlSanitizer::clean((string) ($data[$loc.'_content'] ?? '')),
                    'tags'    => array_values(array_filter(array_map('trim', explode(',', (string) ($data[$loc.'_tags'] ?? ''))))),
                    'auto'    => (bool) $request->boolean($loc.'_auto'),
                ]
            );
        }

        BlogRepository::flush();

        return redirect('/admin/posts?type='.$post->type)->with('ok', 'پست ذخیره شد.');
    }

    public function destroy(Post $post): RedirectResponse
    {
        $type = $post->type;
        $post->delete();
        BlogRepository::flush();

        return redirect('/admin/posts?type='.$type)->with('ok', 'پست حذف شد.');
    }

    /** AJAX: ترجمه‌ی فارسی به en/tr */
    public function translate(Request $request): JsonResponse
    {
        $d = $request->validate([
            'target'  => 'required|in:en,tr',
            'title'   => 'required|string',
            'excerpt' => 'nullable|string',
            'content' => 'required|string',
            'tags'    => 'nullable|string',
        ]);
        if (! $this->ai->enabled()) {
            return response()->json(['ok' => false, 'error' => 'not_configured']);
        }
        $res = $this->ai->translate([
            'title' => $d['title'], 'excerpt' => $d['excerpt'] ?? '', 'content' => $d['content'],
            'tags' => array_filter(array_map('trim', explode(',', $d['tags'] ?? ''))),
        ], $d['target']);

        return $res
            ? response()->json(['ok' => true] + $res + ['tags' => implode(', ', $res['tags'])])
            : response()->json(['ok' => false, 'error' => 'ai_error']);
    }

    /** AJAX: تحلیل سئو */
    public function seo(Request $request): JsonResponse
    {
        $d = $request->validate(['title' => 'required|string', 'excerpt' => 'nullable|string', 'content' => 'required|string']);
        if (! $this->ai->enabled()) {
            return response()->json(['ok' => false, 'error' => 'not_configured']);
        }
        $res = $this->ai->seo(['title' => $d['title'], 'excerpt' => $d['excerpt'] ?? '', 'content' => $d['content']]);

        return $res ? response()->json(['ok' => true] + $res) : response()->json(['ok' => false, 'error' => 'ai_error']);
    }
}
