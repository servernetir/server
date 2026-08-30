<?php

namespace Tests\Feature;

use App\Services\Domain\DomainSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * دامنهٔ خودِ کاربر همیشه **سطرِ اول** نتایج است — حتی وقتی گرفته شده.
 *
 * ═══ نقصی که این را لازم کرد ═══
 *
 * 🔴 مرتب‌سازیِ «بهترین» صفحهٔ /domains گرفته‌شده‌ها را آخر می‌بَرد. یعنی
 * دقیقاً وقتی دامنهٔ خودِ کاربر آزاد نیست، پاسخِ سؤالِ او تهِ فهرست دفن
 * می‌شد و فقط دیواری از پیشنهاد می‌دید — بدونِ اینکه بفهمد دامنهٔ خودش چه
 * شد. هیچ خطایی، فقط گم‌شدنِ جواب.
 */
class DomainPrimaryRowFirstTest extends TestCase
{
    use RefreshDatabase;

    /** 🔴 سرور ردیفِ دامنهٔ صریحِ کاربر را پرچم می‌زند. */
    public function test_the_check_api_flags_the_users_exact_domain(): void
    {
        $this->partialMock(DomainSearch::class, function ($m) {
            $m->shouldReceive('search')->once()->andReturn([
                ['domain' => 'example.net', 'tld' => 'net', 'available' => true],
                ['domain' => 'example.com', 'tld' => 'com', 'available' => false],
            ]);
        });

        $r = $this->postJson(route('domain.search.check'), ['q' => 'Example.COM']);

        $r->assertOk();
        $rows = collect($r->json('results'));

        $this->assertTrue(
            (bool) $rows->firstWhere('domain', 'example.com')['primary'],
            'دامنهٔ خودِ کاربر پرچمِ primary ندارد'
        );
        $this->assertArrayNotHasKey('primary', $rows->firstWhere('domain', 'example.net'),
            'پیشنهاد نباید پرچمِ primary بگیرد');
    }

    /** ⚠️ جستجوی بی‌پسوند: سرور حدس نمی‌زند — پرچم فقط با پسوندِ صریح. */
    public function test_a_bare_name_gets_no_server_side_flag(): void
    {
        $this->partialMock(DomainSearch::class, function ($m) {
            $m->shouldReceive('search')->once()->andReturn([
                ['domain' => 'example.com', 'tld' => 'com', 'available' => false],
            ]);
        });

        $r = $this->postJson(route('domain.search.check'), ['q' => 'example']);

        $this->assertArrayNotHasKey('primary', collect($r->json('results'))->first(),
            'بی‌پسوند نباید سمتِ سرور پرچم بخورد — هر دستهٔ بعدی یک ردیفِ اشتباه پرچم می‌گرفت');
    }

    /** primaryFqdn: فقط با پسوندِ صریح جواب می‌دهد. */
    public function test_primary_fqdn_requires_an_explicit_tld(): void
    {
        $s = app(DomainSearch::class);

        $this->assertSame('example.com', $s->primaryFqdn('  https://Example.COM/path '));
        $this->assertNull($s->primaryFqdn('example'));
        $this->assertNull($s->primaryFqdn(''));
    }

    /**
     * ⚠️ رابط: سنجاق، معافیت از فیلتر، و حدسِ بی‌پسوند — همه باید در HTMLِ
     * صفحه باشند. ادعا روی سورسِ رندرشده تا حذفشان در ویرایشِ آینده دیده شود.
     */
    public function test_the_page_pins_and_protects_the_primary_row(): void
    {
        $html = $this->get(route('domain.search'))->assertOk()->getContent();

        $this->assertStringContainsString('dsx-primary', $html, 'کلاسِ ردیفِ اصلی نیست');
        $this->assertStringContainsString('dataset.primary', $html, 'نشانه‌گذاریِ dataset نیست');
        $this->assertStringContainsString('primaryGuess', $html, 'حدسِ بی‌پسوند در رابط نیست');
        // معافیت از فیلتر: پنهان‌کردنِ «گرفته‌شده‌ها» نباید پاسخِ خودِ کاربر را ببلعد
        $this->assertStringContainsString('!el.dataset.primary', $html, 'ردیفِ اصلی از فیلترها معاف نیست');
        // سنجاق در مرتب‌سازی
        $this->assertMatchesRegularExpression('~b\.dataset\.primary \? 1 : 0~', $html, 'سنجاقِ مرتب‌سازی نیست');
    }
}
