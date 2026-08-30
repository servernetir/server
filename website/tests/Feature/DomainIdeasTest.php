<?php

namespace Tests\Feature;

use App\Services\DomainIdeas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * پیشنهادگر نام دامنه — پارس خروجی مدل، مولد fallback و سیم‌کشی HTTP.
 * هیچ تماس AI یا DNS واقعی: شبکه پشت درزهای protected استاب می‌شود.
 */
class DomainIdeasTest extends TestCase
{
    use RefreshDatabase;

    /* ═══════════════ پارس خروجی مدل ═══════════════ */

    /** خروجی مدل قابل‌اعتماد نیست: شماره، توضیح، TLD و آشغال باید دور ریخته شود */
    public function test_messy_ai_output_is_parsed_into_clean_names(): void
    {
        $out = implode("\n", [
            '1. CoffeHub',
            '2) beanly.com — great for coffee',
            '- kafino',
            'kafino',                               // تکراری
            'my coffee shop',                       // فاصله دارد
            'brandio',
            'x',                                    // خیلی کوتاه
            'دامنه',                                // لاتین نیست
            'averyverylongnamethatnevervalidates',  // خیلی بلند
        ]);

        $this->assertSame(['coffehub', 'beanly', 'kafino', 'brandio'], DomainIdeas::parseNames($out));
    }

    public function test_name_validation_reference_cases(): void
    {
        $this->assertTrue(DomainIdeas::validName('abc'));
        $this->assertTrue(DomainIdeas::validName('kafino24'));
        $this->assertFalse(DomainIdeas::validName('ab'));          // کوتاه‌تر از ۳
        $this->assertFalse(DomainIdeas::validName('-abc'));        // شروع با خط تیره
        $this->assertFalse(DomainIdeas::validName('ab--cd'));      // خط تیره‌ی دوتایی
        $this->assertFalse(DomainIdeas::validName('Abc'));         // حرف بزرگ (نرمال‌سازی قبلاً انجام شده)
        $this->assertFalse(DomainIdeas::validName('sixteencharslong1'));   // بلندتر از ۱۵
    }

    /* ═══════════════ مولد محلی (fallback) ═══════════════ */

    public function test_transliteration_reference_values(): void
    {
        $this->assertSame('froshgah', DomainIdeas::transliterate('فروشگاه'));
        $this->assertSame('ghhoh', DomainIdeas::transliterate('قهوه'));
        // نیم‌فاصله جداکننده نیست — «می‌شود» یک کلمه بماند
        $this->assertSame('mishod', DomainIdeas::transliterate('می‌شود'));
    }

    /** خروجی مولد باید قطعی و مرتب باشد — نه تصادفی */
    public function test_fallback_names_are_deterministic(): void
    {
        $names = DomainIdeas::fallbackNames('فروشگاه کتاب آنلاین');

        $this->assertSame(['froshgah', 'ktab', 'anlain', 'froshgahktab', 'ktabfroshgah'], array_slice($names, 0, 5));
        $this->assertSame($names, DomainIdeas::fallbackNames('فروشگاه کتاب آنلاین'));
        $this->assertLessThanOrEqual(DomainIdeas::MAX_IDEAS, count($names));

        foreach ($names as $n) {
            $this->assertTrue(DomainIdeas::validName($n), "نام نامعتبر از مولد: {$n}");
        }
    }

    public function test_fallback_with_no_usable_words_returns_empty(): void
    {
        $this->assertSame([], DomainIdeas::fallbackNames('یک !!! ۲'));
    }

    /* ═══════════════ ترکیب suggest() با درزهای استاب ═══════════════ */

    private function fake(?string $aiOut, array $nsTaken = []): DomainIdeas
    {
        return new class($aiOut, $nsTaken) extends DomainIdeas
        {
            public function __construct(private ?string $aiOut, private array $taken) {}

            public function enabled(): bool
            {
                return $this->aiOut !== null;
            }

            protected function call(string $system, string $user, int $maxTokens, int $timeout = 140, bool $stream = false): ?string
            {
                return $this->aiOut;
            }

            protected function nsTaken(array $names): array
            {
                return array_intersect_key($this->taken, array_flip($names));
            }
        };
    }

    public function test_suggest_marks_taken_definitively_and_never_claims_free(): void
    {
        $r = $this->fake("alpha\nbetaco\ngammax", ['alpha' => true, 'betaco' => false])->suggest('یک کسب‌وکار آزمایشی');

        $this->assertTrue($r['ok']);
        $this->assertSame('ai', $r['source']);

        $byName = collect($r['items'])->keyBy('name');
        $this->assertTrue($byName['alpha']['taken']);
        // 🔴 «NS ندارد» هرگز false (=آزاد) گزارش نمی‌شود — فقط null (نامعلوم)
        $this->assertNull($byName['betaco']['taken']);
        $this->assertNull($byName['gammax']['taken']);

        $this->assertSame('alpha.com', $byName['alpha']['domain']);
    }

    /** مدل در دسترس نیست ⇒ مولد محلی، نه خطا */
    public function test_suggest_falls_back_when_ai_is_unavailable(): void
    {
        $r = $this->fake(null)->suggest('فروشگاه کتاب آنلاین');

        $this->assertTrue($r['ok']);
        $this->assertSame('fallback', $r['source']);
        $this->assertNotEmpty($r['items']);
    }

    /* ═══════════════ HTTP ═══════════════ */

    public function test_the_ideas_page_renders_in_all_three_locales(): void
    {
        $this->get('/tools/domain-ideas')->assertOk()
            ->assertSee('نام دامنه‌ی بعدی‌تان را')
            ->assertDontSee('ui.tl_ideas');

        $this->get('/en/tools/domain-ideas')->assertOk()->assertSee('Your next domain name,');
        $this->get('/tr/tools/domain-ideas')->assertOk()->assertSee('Bir sonraki alan');
    }

    public function test_the_api_rejects_short_descriptions_with_json(): void
    {
        $this->postJson('/api/domain-ideas', ['description' => 'کوتاه'])
            ->assertOk()
            ->assertJson(['ok' => false, 'error' => 'too_short']);
    }

    public function test_the_api_returns_the_service_result(): void
    {
        $this->app->instance(DomainIdeas::class, $this->fake("alpha\nbetaco", ['alpha' => true]));

        $this->postJson('/api/domain-ideas', ['description' => 'فروشگاه آنلاین قهوه‌ی تخصصی'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('items.0.name', 'alpha')
            ->assertJsonPath('items.0.taken', true)
            ->assertJsonPath('items.1.taken', null);
    }
}
