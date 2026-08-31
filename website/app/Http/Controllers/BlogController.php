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

        return view('pages.blog', array_merge([
            'paged'   => $paged,
            'heading' => $heading,
            'q'       => $q,
        ], $this->listingSeo($heading, (int) $paged['page'])));
    }

    /**
     * سیاستِ canonical/robots/hreflang برای حالت‌های فهرستِ بلاگ.
     *
     * ═══ باگی که این را لازم کرد (Search Console، ۹ شهریور ۱۴۰۵) ═══
     *
     * لایوت canonical را از `url()->current()` می‌سازد و آن متد **رشتهٔ
     * پرس‌وجو را دور می‌ریزد**. یعنی هر ۱۵ صفحهٔ فهرست خودشان را
     * `/blog` اعلام می‌کردند:
     *
     *   /blog?page=2 … ?page=15  →  <link rel="canonical" href="/blog">
     *
     * گوگل صفحهٔ صفحه‌بندی‌شده‌ای که خودش را صفحهٔ اول می‌خوانَد، «تکراری»
     * می‌گیرد: از ایندکس بیرون می‌گذارد و **خیلی کمتر می‌خزدش**. و پست‌های
     * ۱۰ تا ۱۲۹ فقط از همان صفحه‌ها لینک دارند — یعنی تنها پشتیبانِ
     * لینکِ داخلی‌شان بی‌صدا قطع شده بود. نتیجه در گزارشِ ایندکس:
     * **۶۵۴ نشانی «Discovered – currently not indexed»** با
     * «آخرین خزش: N/A» — گوگل می‌دانست وجود دارند و هرگز سراغشان نرفت.
     *
     * پس صفحهٔ N **خودش** را canonical می‌کند و ایندکس‌پذیر می‌مانَد
     * (توصیهٔ امروزِ گوگل بعد از بازنشستگیِ rel=next/prev).
     *
     * ⚠️ عدد از `$paged['page']`ِ **مهارشده** می‌آید نه از ورودیِ کاربر:
     * `paginate()` هر عددِ خارج از بازه را به آخرین صفحه می‌چسبانَد، پس
     * `?page=999` همان محتوای صفحهٔ ۱۵ را می‌دهد. با عددِ خام، هر یک از
     * بی‌نهایت آدرسِ `?page=…` خودش را canonical می‌کرد — یعنی فضای
     * خزشِ بی‌پایان. با عددِ مهارشده همه به یک canonical می‌رسند.
     *
     * ⚠️ دسته (`?cat=`) هم canonicalِ خودش را می‌گیرد: فهرستِ واقعی با
     * مجموعهٔ متفاوتی از پست‌هاست و صفحهٔ فرودِ ارزشمندی است. ولی جست‌وجو
     * (`?q=`) و تگ (`?tag=`) `noindex,follow` می‌شوند — مجموعه‌شان بی‌کران
     * است و canonicalِ دروغ («این همان /blog است») از هر دو بدتر: به گوگل
     * یاد می‌دهد canonicalهای این سایت را جدی نگیرد.
     *
     * ⚠️ hreflang هم باید همان پارامترها را ببرد. `$localeUrls` فقط
     * پارامترهای **روت** را می‌شناسد، پس بی‌این، صفحهٔ ۲ به گوگل می‌گفت
     * معادلِ انگلیسی‌اش `/en/blog` (صفحهٔ ۱) است.
     *
     * @return array{canonical:string,altUrls:array<string,string>,listingNoindex:bool}
     */
    private function listingSeo(?array $heading, int $page): array
    {
        $type = $heading['type'] ?? null;

        if ($type === 'search' || $type === 'tag') {
            return ['canonical' => '', 'altUrls' => [], 'listingNoindex' => true];
        }

        $params = [];

        if ($type === 'cat') {
            $params['cat'] = $heading['value'];
        }

        if ($page > 1) {
            $params['page'] = $page;
        }

        $suffix = $params === [] ? '' : '?'.http_build_query($params);

        $alt = [];
        foreach (\App\Providers\AppServiceProvider::LOCALES as $code => $prefix) {
            $alt[$code] = route($prefix.'blog.index').$suffix;
        }

        return [
            'canonical'      => lroute('blog.index').$suffix,
            'altUrls'        => $alt,
            'listingNoindex' => false,
        ];
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
            'locale'    => app()->getLocale(),
            'approved'  => false,
            'ip'        => $request->ip(),
        ]);

        $status = $this->screen($comment, $slug);

        // اسپم بی‌سروصدا حذف شد — به فرستنده همان پیام عادی نشان بده تا بازخوردِ دورزدن نگیرد
        if ($status === 'spam') {
            return back()->with('comment_status', 'pending')->withFragment('comments');
        }

        if ($status !== 'approved') {
            $this->notifyModeration($comment, $slug);
        }

        return back()->with('comment_status', $status === 'approved' ? 'published' : 'pending')->withFragment('comments');
    }

    /**
     * داوری هوشمند کامنت. خروجی: approved | pending | spam
     * در هر خطا یا ابهام، کامنت برای بررسی مدیر باقی می‌ماند (هرگز تأیید خودکارِ مشکوک).
     */
    private function screen(Comment $comment, string $slug): string
    {
        $post = $this->blog->find($slug);
        $ai = app(\App\Services\AiComments::class)->review($comment, $post['title'] ?? '');

        if ($ai === null) {
            return 'pending'; // AI در دسترس نبود → بررسی دستی
        }

        $comment->ai_verdict = $ai['verdict'];
        $comment->ai_score = $ai['score'];
        $comment->ai_reason = $ai['reason'];

        if ($ai['verdict'] === 'spam') {
            $comment->delete();

            return 'spam';
        }

        $comment->locale = $ai['locale'];
        $comment->translations = $ai['translations'] ?: null;

        if ($ai['verdict'] === 'approve') {
            $comment->approved = true;
            if (! empty($ai['reply'])) {
                $orig = $ai['reply'][$ai['locale']] ?? reset($ai['reply']);
                $comment->reply = $orig;
                $comment->reply_translations = $ai['reply'];
                $comment->replied_at = now();
            }
        }

        $comment->save();

        return $comment->approved ? 'approved' : 'pending';
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
