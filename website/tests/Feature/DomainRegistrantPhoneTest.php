<?php

namespace Tests\Feature;

use App\Models\CustomerProfile;
use App\Services\Domain\DomainRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * شمارهٔ مالک باید همان کشوری برود که کاربر نوشته.
 *
 * ═══ باگی که این تست از آن آمد ═══
 *
 * `splitPhone()` کدِ کشور را **فقط** از فیلدِ کشورِ پروفایل می‌ساخت و برای هر
 * کشوری جز ایران به پیش‌فرضِ `default_cc` (۹۸) می‌افتاد.
 *
 * مالکِ واقعیِ شرکت کشورش `TR` است و شماره‌اش `+1716…` (آمریکا). یعنی به
 * رجیسترار این می‌رفت:
 *
 *     +98 171 6666425      ← شماره‌ای که وجود ندارد
 *
 * و نتیجه‌اش دقیقاً همان حلقه‌ای بود که می‌خواستیم ببندیم: رجیسترار مخاطب را رد
 * می‌کند، ثبت شکست می‌خورد، دامنهٔ پرداخت‌شده در صفِ دستی پارک می‌شود.
 *
 * ⚠️ کشور و کدِ تلفن دو چیزِ متفاوت‌اند و **لازم نیست بخوانند**. شرکتی که
 * نشانی‌اش ترکیه است می‌تواند شمارهٔ آمریکا داشته باشد — و همین حالا دارد.
 */
class DomainRegistrantPhoneTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{country_code:string, area_code:string, subscriber_number:string}|null */
    private function phoneOf(string $mobile, string $country): ?array
    {
        $p = new CustomerProfile([
            'first_name' => 'A', 'last_name' => 'B', 'email' => 'a@b.test',
            'address' => 'St 1', 'city' => 'X', 'postal_code' => '1',
            'mobile' => $mobile, 'country' => $country,
        ]);

        $payload = app(DomainRegistrar::class)->profileToCustomer($p);

        return $payload['phone'] ?? null;
    }

    /** 🔴 قلبِ باگ: `+` صریحِ کاربر بر کشورِ پروفایل مقدم است */
    public function test_an_explicit_plus_prefix_wins_over_the_profile_country(): void
    {
        $phone = $this->phoneOf('+17166660425', 'TR');

        $this->assertNotNull($phone);
        $this->assertSame('+1', $phone['country_code'],
            'شمارهٔ +1 با کشورِ TR به کدِ اشتباه رفت — همان باگی که ثبت را می‌شکست');
        $this->assertSame('716', $phone['area_code']);
        $this->assertSame('6660425', $phone['subscriber_number']);
    }

    /** کدِ دورقمی هم درست جدا شود، نه اینکه رقمِ اول را کدِ کشور بگیرد */
    public function test_a_two_digit_country_code_is_not_split_as_one(): void
    {
        $phone = $this->phoneOf('+905321234567', 'TR');

        $this->assertSame('+90', $phone['country_code']);
        $this->assertSame('532', $phone['area_code']);
    }

    /** شمارهٔ داخلیِ ایران دست‌نخورده می‌مانَد — رفتارِ قبلی نشکند */
    public function test_a_local_iranian_number_still_maps_to_98(): void
    {
        $phone = $this->phoneOf('09121234567', 'IR');

        $this->assertSame('+98', $phone['country_code']);
        $this->assertSame('912', $phone['area_code']);
        $this->assertSame('1234567', $phone['subscriber_number']);
    }

    /**
     * ⚠️ بدونِ `+`، کشورِ پروفایل تعیین می‌کند — و ترکیه دیگر به ۹۸ نمی‌افتد.
     * پیش از این هر کشوری جز ایران کدِ ایران می‌گرفت.
     */
    public function test_a_local_number_uses_its_own_country_not_the_iran_default(): void
    {
        $phone = $this->phoneOf('05321234567', 'TR');

        $this->assertSame('+90', $phone['country_code'],
            'شمارهٔ ترکیه کدِ ایران گرفت — پیش‌فرضِ default_cc هنوز همه را می‌بلعد');
    }

    /** ورودیِ بی‌معنا باید `null` بدهد، نه شماره‌ای ساختگی */
    public function test_a_too_short_number_is_rejected_rather_than_invented(): void
    {
        $this->assertNull($this->phoneOf('12', 'IR'));
        $this->assertNull($this->phoneOf('', 'IR'));
    }
}
