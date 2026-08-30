<?php

namespace Tests\Feature;

use App\Models\CloudLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ستونِ مکان هرگز نباید چیزی جز نامِ یک جا نشان دهد.
 *
 * 🔴 روی سایتِ زنده «AMD»، «Shared» و «Dedicated» در ستونِ شهر دیده شد. سینک
 * اصلاح شد، ولی **ردیف‌های غلط از قبل در دیتابیس نشسته‌اند** و تا اجرای
 * `cloud:sync` پاک نمی‌شوند. پس مدل هم باید نگهبان داشته باشد، وگرنه بین
 * دیپلوی و سینکِ بعدی مشتری همان زباله را می‌بیند.
 *
 * ⚠️ ستونِ مکان مهم‌ترین ستونِ این جدول است: تأخیرِ شبکه را تعیین می‌کند و
 * مشتری بر اساسش خرید می‌کند. «AMD» به او نمی‌گوید سرورش کجا بالا می‌آید.
 */
class CloudCityLabelTest extends TestCase
{
    use RefreshDatabase;

    private function loc(?string $city, string $country = 'DE'): CloudLocation
    {
        return CloudLocation::create([
            'code' => 'x-'.uniqid(), 'country' => $country, 'city' => $city,
            'is_active' => true, 'sort' => 0,
        ]);
    }

    public function test_a_product_tier_never_shows_as_a_city(): void
    {
        foreach (['Shared', 'DEDICATED', 'amd', 'Intel', 'NVMe', 'Premium'] as $junk) {
            $label = $this->loc($junk)->cityLabel('fa');

            $this->assertSame('برلین', $label, "«{$junk}» نباید به‌عنوان شهر چاپ شود");
        }
    }

    public function test_an_empty_city_falls_back_to_the_capital(): void
    {
        $this->assertSame('برلین', $this->loc(null)->cityLabel('fa'));
        $this->assertSame('', trim((string) $this->loc('')->city));
        $this->assertSame('Berlin', $this->loc('')->cityLabel('en'));
    }

    public function test_the_capital_is_localised(): void
    {
        $loc = $this->loc(null, 'TR');

        $this->assertSame('آنکارا', $loc->cityLabel('fa'));
        $this->assertSame('Ankara', $loc->cityLabel('en'));
        $this->assertSame('Ankara', $loc->cityLabel('tr'));
    }

    /** شهرِ واقعی باید دست‌نخورده بماند و به فارسی ترجمه شود */
    public function test_a_real_city_still_wins(): void
    {
        $this->assertSame('فرانکفورت', $this->loc('Frankfurt')->cityLabel('fa'));
        $this->assertSame('Frankfurt', $this->loc('Frankfurt')->cityLabel('en'));
    }

    /** کشورِ ناشناس نباید صفحه را بشکند */
    public function test_an_unknown_country_returns_an_empty_label(): void
    {
        $this->assertSame('', $this->loc(null, 'ZZ')->cityLabel('fa'));
    }

    /** برچسبِ کاملِ مکان هم باید پایتخت را بگیرد، نه رشتهٔ خالی */
    public function test_the_full_label_uses_the_capital_too(): void
    {
        $this->assertSame('آلمان — برلین', $this->loc('Shared')->label('fa'));
    }
}
