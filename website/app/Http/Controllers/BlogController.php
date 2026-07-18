<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Services\BlogRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * بلاگ سرورنت — فهرست، تک‌پست، دسته و تگ. اسلاگ‌محور (سازگار با 301 از servernet.ir).
 */
class BlogController extends Controller
{
    public function __construct(private BlogRepository $blog) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $cat = (string) $request->query('cat', '');
        $tag = (string) $request->query('tag', '');

        if ($q !== '') {
            $posts = $this->blog->search($q);
            $heading = ['type' => 'search', 'value' => $q];
        } elseif ($cat !== '' && isset(config('blog.categories')[$cat])) {
            $posts = $this->blog->byCategory($cat);
            $heading = ['type' => 'cat', 'value' => $cat];
        } elseif ($tag !== '') {
            $posts = $this->blog->byTag($tag);
            $heading = ['type' => 'tag', 'value' => $tag];
        } else {
            $posts = $this->blog->index();
            $heading = null;
        }

        $paged = $this->blog->paginate($posts, (int) $request->query('page', 1), config('blog.per_page', 9));

        return view('pages.blog', [
            'paged'   => $paged,
            'heading' => $heading,
            'q'       => $q,
        ]);
    }

    public function show(string $slug): View
    {
        $post = $this->blog->find($slug);
        abort_if($post === null, 404);

        return view('pages.blog-post', [
            'post'     => $post,
            'related'  => $this->blog->related($post, 3),
            'comments' => Comment::approvedForPost($post['slug']),
        ]);
    }

    /** ثبت کامنت جدید — تأییدنشده تا مدیر تأیید کند */
    public function comment(Request $request, string $slug): RedirectResponse
    {
        abort_if($this->blog->find($slug) === null, 404);

        $data = $request->validate([
            'name'    => 'required|string|max:80',
            'body'    => 'required|string|min:3|max:2000',
            'email'   => 'nullable|email|max:120',
            'website' => 'nullable|max:0', // honeypot: باید خالی بماند
        ], ['website.max' => 'spam']);

        // محدودیت نرخ ساده بر اساس IP
        $rk = 'blog.comment.'.md5((string) $request->ip());
        if ((int) Cache::get($rk, 0) >= 5) {
            return back()->with('comment_status', 'busy')->withFragment('comments');
        }
        Cache::put($rk, (int) Cache::get($rk, 0) + 1, 600);

        $comment = Comment::create([
            'post_slug' => $slug,
            'name'      => strip_tags($data['name']),
            'email'     => $data['email'] ?? null,
            'body'      => strip_tags($data['body']),
            'approved'  => false,
            'ip'        => $request->ip(),
        ]);

        $this->notifyModeration($comment, $slug);

        return back()->with('comment_status', 'pending')->withFragment('comments');
    }

    /** مدیریت کامنت با لینک امضاشده (approve|delete) — بدون نیاز به لاگین */
    public function moderate(Request $request, Comment $comment, string $action): string
    {
        abort_unless($request->hasValidSignature(), 403);

        if ($action === 'approve') {
            $comment->update(['approved' => true]);
            $msg = 'کامنت تأیید و منتشر شد ✓';
        } elseif ($action === 'delete') {
            $comment->delete();
            $msg = 'کامنت حذف شد.';
        } else {
            abort(404);
        }

        return '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><body style="font-family:sans-serif;background:#0b1020;color:#e5edff;display:grid;place-items:center;height:100vh;margin:0"><div style="text-align:center"><h2>'.$msg.'</h2><p style="opacity:.6">ServerNet</p></div></body></html>';
    }

    /** اطلاع به مدیر با لینک‌های تأیید/حذف امضاشده */
    private function notifyModeration(Comment $comment, string $slug): void
    {
        $approve = URL::signedRoute('blog.moderate', ['comment' => $comment->id, 'action' => 'approve']);
        $delete = URL::signedRoute('blog.moderate', ['comment' => $comment->id, 'action' => 'delete']);

        if ($webhook = config('services.n8n.chat_webhook')) {
            $ch = curl_init($webhook);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS     => json_encode([
                    'message' => "کامنت جدید روی «{$slug}» از {$comment->name}:\n{$comment->body}\n\n✓ تأیید: {$approve}\n✗ حذف: {$delete}",
                    'session' => 'blog-comment-'.$comment->id,
                ], JSON_UNESCAPED_UNICODE),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 6,
                CURLOPT_CONNECTTIMEOUT => 4,
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    }
}
