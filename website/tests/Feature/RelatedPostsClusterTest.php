<?php

namespace Tests\Feature;

use App\Services\BlogRepository;
use Tests\TestCase;

/**
 * «مرتبط» یعنی هم‌موضوع، نه هم‌دسته.
 *
 * ═══ خرابی‌ای که روی سایتِ زنده اندازه‌گیری شد ═══
 *
 * `related()` هم‌دسته‌ها را به **ترتیبِ انتشار** برمی‌داشت و سه تای اول را
 * می‌داد. نتیجه‌اش این بود که در هر دسته، همهٔ نوشته‌ها به همان سه تای ثابت
 * لینک می‌دادند:
 *
 *     voip           → virtualization · ipv6 · green-hosting
 *     virtualization → voip · ipv6 · green-hosting
 *     ipv6           → voip · virtualization · green-hosting
 *
 * یعنی از ۱۰۳ نوشته فقط چند تای هر دسته لینکِ داخلی می‌گرفتند. هیچ خطایی،
 * هیچ صفحهٔ خرابی — فقط ارزشی که هرگز پخش نمی‌شد.
 *
 * ⚠️ ادعای ممیزی («ویجت سخت‌کد است») **غلط** بود: تابع واقعاً از دادهٔ زنده
 * می‌خواند. خرابیِ واقعی ظریف‌تر بود و فقط با مقایسهٔ خروجیِ چند صفحه دیده شد.
 *
 * این تست بدونِ دیتابیس کار می‌کند: `index()` را جایگزین می‌کند تا فقط خودِ
 * منطقِ رتبه‌بندی سنجیده شود.
 */
class RelatedPostsClusterTest extends TestCase
{
    /** ریپازیتوریِ درون‌حافظه‌ای — بی‌دیتابیس، بی‌کش. */
    private function repo(array $posts): BlogRepository
    {
        return new class($posts) extends BlogRepository
        {
            public function __construct(private array $rows) {}

            public function index(): array
            {
                return $this->rows;
            }
        };
    }

    private function row(string $slug, string $cat, array $tags): array
    {
        return ['slug' => $slug, 'title' => $slug, 'category' => $cat, 'tags' => $tags, 'date' => '2026-01-01'];
    }

    /**
     * 🔴 ادعای اصلی: دو نوشتهٔ هم‌دسته با موضوعِ متفاوت نباید یک فهرست بگیرند.
     */
    public function test_two_posts_in_one_category_do_not_get_the_same_neighbours(): void
    {
        $rows = [
            $this->row('ipv6', 'net', ['IPv6', 'DNS', 'شبکه']),
            $this->row('dns-basics', 'net', ['DNS', 'شبکه']),
            $this->row('voip', 'net', ['VoIP', 'تلفن']),
            $this->row('sip-trunk', 'net', ['VoIP', 'تلفن', 'SIP']),
            $this->row('green', 'net', ['محیط زیست']),
        ];
        $repo = $this->repo($rows);

        $forIpv6 = array_column($repo->related($rows[0], 2), 'slug');
        $forVoip = array_column($repo->related($rows[2], 2), 'slug');

        $this->assertNotSame($forIpv6, $forVoip,
            'دو نوشتهٔ هم‌دسته با موضوعِ کاملاً متفاوت همان همسایه‌ها را گرفتند');

        $this->assertContains('dns-basics', $forIpv6, 'نوشتهٔ هم‌برچسب باید اول بیاید');
        $this->assertContains('sip-trunk', $forVoip, 'نوشتهٔ هم‌برچسب باید اول بیاید');
    }

    /** برچسبِ مشترک باید از دستهٔ مشترک بچربد. */
    public function test_a_shared_tag_beats_a_shared_category(): void
    {
        $rows = [
            $this->row('subject', 'a', ['کوبرنتیز', 'داکر']),
            $this->row('same-cat-no-tag', 'a', ['ووکامرس']),
            $this->row('other-cat-same-tag', 'b', ['کوبرنتیز']),
        ];

        $first = $this->repo($rows)->related($rows[0], 1);

        $this->assertSame('other-cat-same-tag', $first[0]['slug'],
            'هم‌برچسبِ دستهٔ دیگر باید از هم‌دستهٔ بی‌برچسب جلوتر باشد');
    }

    /** خودِ نوشته هرگز در فهرستِ خودش نیست. */
    public function test_a_post_is_never_related_to_itself(): void
    {
        $rows = [
            $this->row('me', 'a', ['x']),
            $this->row('other', 'a', ['x']),
        ];

        $slugs = array_column($this->repo($rows)->related($rows[0], 3), 'slug');

        $this->assertNotContains('me', $slugs);
    }

    /**
     * ⚠️ نیم‌فاصله و یِ عربی نباید خوشه را تکه کنند.
     *
     * «زیرساخت شبکه» و «زیرساخت‌شبکه» و «زيرساخت شبكه» یک موضوع‌اند؛ اگر سه
     * برچسبِ متفاوت شمرده شوند، ربط بی‌صدا از بین می‌رود.
     */
    public function test_persian_tag_variants_still_cluster(): void
    {
        $rows = [
            $this->row('a', 'x', ['زیرساخت شبکه']),
            $this->row('b', 'y', ["زیرساخت\u{200c}شبکه"]),
            $this->row('c', 'z', ['زيرساخت شبكه']),   // ی و ک عربی
            $this->row('d', 'q', ['چیزِ دیگر']),
        ];

        $slugs = array_column($this->repo($rows)->related($rows[0], 3), 'slug');

        $this->assertContains('b', $slugs, 'نیم‌فاصله خوشه را تکه کرد');
        $this->assertContains('c', $slugs, 'یِ عربی خوشه را تکه کرد');
        $this->assertSame(['b', 'c', 'd'], $slugs, 'بی‌ربط باید آخر بیاید');
    }

    /** ویجت هرگز خالی نمی‌مانَد، حتی برای نوشتهٔ بی‌برچسب و تنها. */
    public function test_the_widget_never_comes_back_empty(): void
    {
        $rows = [
            $this->row('lonely', 'solo', []),
            $this->row('x', 'other', ['t']),
            $this->row('y', 'other', ['t']),
        ];

        $this->assertCount(2, $this->repo($rows)->related($rows[0], 2),
            'نوشتهٔ بی‌برچسب هم باید همسایه بگیرد');
    }

    /**
     * 🔴 قطعی است — هیچ تصادفی در کار نیست.
     *
     * فهرستی که در هر بارگذاری عوض شود، هم کش را بی‌فایده می‌کند هم برای
     * خزنده لینک‌های ناپایدار می‌سازد.
     */
    public function test_the_order_is_deterministic(): void
    {
        $rows = [
            $this->row('s', 'a', ['t1', 't2']),
            $this->row('p1', 'a', ['t1']),
            $this->row('p2', 'a', ['t2']),
            $this->row('p3', 'b', ['t1', 't2']),
        ];
        $repo = $this->repo($rows);

        $first = array_column($repo->related($rows[0], 3), 'slug');

        foreach (range(1, 4) as $ignored) {
            $this->assertSame($first, array_column($repo->related($rows[0], 3), 'slug'));
        }
    }
}
