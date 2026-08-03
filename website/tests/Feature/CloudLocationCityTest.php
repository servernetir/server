<?php

namespace Tests\Feature;

use App\Services\Cloud\AezaClient;
use Tests\TestCase;

/**
 * نامِ شهرِ مکان باید واقعاً شهر باشد.
 *
 * 🔴 روی سایتِ زنده دیده شد: صفحهٔ آلمان ستونِ مکان را برای ۵ پلن «AMD» نشان
 * می‌داد و فرانسه و هلند «Shared» و «Dedicated». علتش این بود که در فهرستِ
 * نامزدهای شهر، `group.names.en` هم بود — که نامِ **ردهٔ محصول** است نه مکان.
 *
 * ⚠️ این فقط زشتی نیست: `CloudNaming::locationCode` از همین شهر ساخته می‌شود،
 * پس مکان‌های ساختگی و صفحاتِ `/cloud/{code}` بی‌معنا هم تولید می‌شدند — و
 * مشتری‌ای که «AMD» را انتخاب می‌کند نمی‌داند سرورش کجا بالا می‌آید.
 */
class CloudLocationCityTest extends TestCase
{
    private function locationOf(array $product): array
    {
        $m = new \ReflectionMethod(AezaClient::class, 'locationOf');
        $m->setAccessible(true);

        return $m->invoke(new AezaClient, $product);
    }

    /** @return string شهرِ استخراج‌شده */
    private function city(array $product): string
    {
        return $this->locationOf($product)[1];
    }

    // ═══════════ ردهٔ محصول هرگز شهر نیست ═══════════

    public function test_a_product_tier_is_never_mistaken_for_a_city(): void
    {
        foreach (['Shared', 'Dedicated', 'AMD', 'Intel', 'Premium', 'NVMe'] as $tier) {
            $city = $this->city([
                'name'  => 'CV-2-4',
                'group' => ['names' => ['en' => $tier], 'payload' => ['code' => 'DE']],
            ]);

            $this->assertSame('', $city, "«{$tier}» ردهٔ محصول است، نه شهر");
        }
    }

    /** نامِ مکان که در واقع برچسبِ گروه است هم نباید شهر شود */
    public function test_a_group_style_location_name_is_not_a_city(): void
    {
        $this->assertSame('', $this->city([
            'name'     => 'CV-2-4',
            'location' => ['name' => 'NL-SHARED'],
            'group'    => ['payload' => ['code' => 'NL']],
        ]));
    }

    // ═══════════ شهرِ واقعی باید بیاید ═══════════

    public function test_a_real_city_field_is_trusted(): void
    {
        $this->assertSame('Frankfurt', $this->city([
            'name'     => 'CV-2-4',
            'location' => ['city' => 'Frankfurt', 'country' => 'DE'],
        ]));
    }

    /**
     * شهرِ شناخته‌شده باید از متنِ آزاد هم دربیاید — همان چیزی که باعث می‌شود
     * «فرانکفورتِ» دو زیرساخت به یک کدِ مکان برسد و سفیدبرچسبی حفظ شود.
     */
    public function test_a_known_city_is_still_mined_from_free_text(): void
    {
        $this->assertSame('frankfurt', $this->city([
            'name'  => 'VPS Frankfurt AMD 4/8',
            'group' => ['payload' => ['code' => 'DE']],
        ]));
    }

    /** حتی اگر شهر داخلِ نامِ گروه باشد، باید پیدا شود */
    public function test_a_known_city_inside_a_group_name_is_found(): void
    {
        $this->assertSame('helsinki', $this->city([
            'name'  => 'CV-4-8',
            'group' => ['names' => ['en' => 'Helsinki Shared'], 'payload' => ['code' => 'FI']],
        ]));
    }

    /** کشور نباید قربانی این سخت‌گیری شود */
    public function test_the_country_is_still_resolved(): void
    {
        $this->assertSame('DE', strtoupper($this->locationOf([
            'name'  => 'CV-2-4',
            'group' => ['names' => ['en' => 'AMD'], 'payload' => ['code' => 'DE']],
        ])[0]));
    }

    // ═══════════ کدِ کشورِ ساختگی ═══════════

    /**
     * 🔴 دو حرفِ اولِ برچسبِ گروه همیشه کدِ کشور نیست.
     *
     * روی سایتِ زنده یک «کشور» به نامِ `WS` ساخته شده بود: برچسب `WS-SHARED`
     * یعنی **Warsaw**، ولی `WS` در ISO یعنی **ساموآ**. سرورِ لهستان به مشتری
     * به‌عنوان ساموآ معرفی می‌شد — و مشتری دقیقاً بر اساسِ کشور و تأخیرِ شبکه
     * خرید می‌کند.
     */
    public function test_a_two_letter_prefix_that_is_not_a_known_country_is_refused(): void
    {
        $country = $this->locationOf([
            'name'  => 'CV-2-4',
            'group' => ['payload' => ['label' => 'WS-SHARED']],
        ])[0];

        $this->assertSame('', $country,
            'حدسِ کشور از دو حرفِ اول، سرور را در کشورِ اشتباه می‌فروشد');
    }

    /** ولی کدِ کشورِ واقعی باید همچنان از برچسب خوانده شود */
    public function test_a_real_country_prefix_is_still_accepted(): void
    {
        $this->assertSame('NL', strtoupper($this->locationOf([
            'name'  => 'CV-2-4',
            'group' => ['payload' => ['label' => 'NL-SHARED']],
        ])[0]));
    }
}
