<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Services\BlogRepository;
use App\Services\DocsRepository;
use Illuminate\Console\Command;

/**
 * انتشار پیش‌نویس‌هایی که زمان انتشارشان رسیده است.
 * با کرانِ ساعتی اجرا می‌شود تا مقاله‌ها به‌صورت طبیعی و پراکنده منتشر شوند.
 */
class PublishDue extends Command
{
    protected $signature = 'content:publish-due {--limit=10 : حداکثر انتشار در هر اجرا}';

    protected $description = 'انتشار مقاله‌های پیش‌نویسی که زمانشان فرا رسیده';

    /** زبان‌هایی که یک مقالهٔ «کامل» باید داشته باشد */
    private const LOCALES = ['fa', 'en', 'tr'];

    /**
     * چند ساعت منتظرِ ترجمه بمانیم پیش از انتشارِ ناقص.
     *
     * ۲۴ ساعت عمدی است: `content:translate-missing` دو بار در روز می‌دود، پس
     * یک شکستِ گذرای هوش مصنوعی دو فرصتِ جبران دارد. بلندتر از این یعنی
     * تقویمِ انتشار عقب می‌افتد و روزهای خالی می‌سازد.
     */
    private const GRACE_HOURS = 24;

    public function handle(): int
    {
        $due = Post::query()
            ->with('translations')
            ->where('status', 'draft')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->orderBy('published_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        /*
         * 🔴 مقالهٔ ناقص نباید سه‌زبانه وانمود شود.
         *
         * `Post::tr()` وقتی ترجمه نباشد به فارسی برمی‌گردد، و لایوت برای هر سه
         * زبان hreflang می‌دهد. یعنی مقالهٔ فارسی‌تنها روی `/en/blog/x` با
         * `lang="en"` سرو می‌شود و همان متنِ فارسی را نشان می‌دهد. از دید گوگل
         * سه آدرسِ ایندکس‌شدنی با محتوای یکسان و زبانِ اعلام‌شدهٔ غلط — یعنی هم
         * محتوای تکراری، هم hreflangِ بی‌اعتبار. بدتر از دیرتر منتشر شدن.
         *
         * ⚠️ ولی نگه‌داشتنِ ابدی هم جواب نیست: اگر ترجمه برای همیشه خراب باشد،
         * سایت دوباره ساکت می‌شود — همان خرابیِ مردادی از درِ دیگر. پس مهلت
         * می‌گیرد، و بعد از مهلت **منتشر می‌شود** و صدایش درمی‌آید.
         */
        $held = $due->filter(fn (Post $p) => $this->isIncomplete($p) && ! $this->graceExpired($p));

        foreach ($held as $p) {
            $this->line('⏸ نگه داشته شد تا ترجمه کامل شود: '.$p->slug
                .'  (دارد: '.implode('،', $p->translations->pluck('locale')->all()).')');
        }

        $late = $due->filter(fn (Post $p) => $this->isIncomplete($p) && $this->graceExpired($p));

        foreach ($late as $p) {
            $missing = array_diff(self::LOCALES, $p->translations->pluck('locale')->all());
            $this->warn('⚠️ بدونِ ترجمهٔ کامل منتشر شد (مهلت تمام شد): '.$p->slug
                .' — نبود: '.implode('، ', $missing));
            \App\Support\ErrorTracker::noteOnce('content',
                'مقاله بدون ترجمهٔ کامل منتشر شد: '.$p->slug.' (نبود: '.implode(',', $missing).')',
                3600);
        }

        $due = $due->reject(fn (Post $p) => $held->contains('id', $p->id));

        if ($due->isEmpty()) {
            $this->line('چیزی برای انتشار نیست.');

            return self::SUCCESS;
        }

        foreach ($due as $p) {
            $p->update(['status' => 'published']);
            $this->line('✓ منتشر شد: '.$p->slug.'  ('.$p->published_at->format('Y-m-d H:i').')');
        }

        BlogRepository::flush();
        DocsRepository::flush();
        $this->info('منتشر شد: '.$due->count().' مقاله');

        return self::SUCCESS;
    }

    /** ترجمه‌ای از سه زبان کم دارد؟ (عنوانِ خالی هم یعنی ناقص) */
    private function isIncomplete(Post $p): bool
    {
        $have = $p->translations
            ->filter(fn ($t) => trim((string) $t->title) !== '')
            ->pluck('locale')
            ->all();

        return array_diff(self::LOCALES, $have) !== [];
    }

    /** از زمانِ مقررِ انتشارش بیشتر از مهلت گذشته؟ */
    private function graceExpired(Post $p): bool
    {
        return $p->published_at->lt(now()->subHours(self::GRACE_HOURS));
    }
}
