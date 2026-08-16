<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * صفحات راهکار سازمانی (/solutions/{slug}) — هر راهکار یک صفحه‌ی فروش
 * سئوشده و سه‌زبانه با محتوای غنی از config/solutions.php.
 */
class SolutionController extends Controller
{
    /**
     * راهکارهایی که با صفحه‌ی محصول یکی شده‌اند → ریدایرکت دائمی
     *
     * ⚠️ `public` است چون `SiteController::llms()` هم باید همین‌ها را کنار
     * بگذارد. دو فهرستِ موازی یعنی روزی یکی به‌روز می‌شود و آن یکی نه — و
     * آن‌وقت `llms.txt` مدل را به آدرسی می‌فرستد که ۳۰۱ می‌خورد. (همان تستِ
     * `LlmsTxtIsCompleteTest` این را گرفت.)
     */
    public const MERGED = ['email' => 'email']; // solution slug => hosting slug

    /**
     * هابِ راهکارها (/solutions) — والدِ موضوعیِ همهٔ صفحات راهکار.
     *
     * چرا لازم بود: تا پیش از این، صفحات راهکار فقط از کارت‌های صفحهٔ اول و
     * مگامنو لینک می‌گرفتند و هیچ صفحهٔ «والد» نداشتند. برای گوگل، دسته‌ای که
     * صفحهٔ فهرست ندارد یعنی ساختارِ سیلوِ ناقص؛ و یکی از راهکارها
     * (تلفن ابری) عملاً یتیم مانده بود.
     */
    public function index(): View
    {
        // ترتیب عمداً از config می‌آید نه الفبایی: مهم‌ترین‌ها بالا بمانند.
        // راهکارهای ادغام‌شده (email) این‌جا نمی‌آیند چون صفحهٔ خودشان ۳۰۱ می‌شود.
        $solutions = collect(config('solutions'))
            ->except(array_keys(self::MERGED))
            ->map(function (array $s, string $slug) {
                $t = lc($s);

                // عنوانِ کارت از meta_t گرفته می‌شود ولی تا نخستین «—» بریده
                // می‌شود: «تلفن ابری سرورنت — منشی گویا و…» → «تلفن ابری سرورنت».
                // h1a برای کارت بلند است و meta_d از lead خلاصه‌تر و سئونوشته‌تر.
                $title = trim(explode('—', (string) ($t['meta_t'] ?? $slug))[0]);

                return [
                    'slug'   => $slug,
                    'icon'   => $s['icon'] ?? 'box',
                    'accent' => $s['accent'] ?? 'cyan',
                    'title'  => $title !== '' ? $title : $slug,
                    'lead'   => (string) ($t['meta_d'] ?? ''),
                    'badge'  => $t['badge'] ?? null,
                ];
            })
            ->values();

        return view('pages.solutions', ['solutions' => $solutions]);
    }

    public function show(string $slug): View|RedirectResponse
    {
        if (isset(self::MERGED[$slug])) {
            return redirect(lroute('hosting', self::MERGED[$slug]), 301);
        }

        $solutions = config('solutions');
        abort_unless(isset($solutions[$slug]), 404);

        return view('pages.solution', [
            'slug'    => $slug,
            'sol'     => $solutions[$slug],
            'release' => $slug === 'remote' ? $this->remoteRelease() : null,
        ]);
    }

    /**
     * نسخه و لینک‌های دانلودِ ریموت — از خودِ زیردامنه.
     *
     * 🔴 چرا فقط برای این یک اسلاگ: تماسِ شبکه‌ای (هرچند کش‌شده و کوتاه) نباید
     * روی **همهٔ** صفحات راهکار بنشیند. صفحهٔ تلفن ابری دلیلی ندارد منتظرِ
     * پورتالِ ریموت بماند.
     *
     * ⚠️ خروجی هرگز `null` بودنش صفحه را نمی‌شکند: ویو خودش به مقادیرِ
     * `config/solutions.php` برمی‌گردد.
     */
    private function remoteRelease(): array
    {
        $r = app(\App\Services\RemoteRelease::class);

        return [
            'version' => $r->version(),
            'files'   => $r->info()['files'],
        ];
    }
}
