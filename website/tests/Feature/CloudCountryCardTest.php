<?php

namespace Tests\Feature;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * کارت‌های صفحهٔ `/cloud` باید **کشوری** باشند، نه شهری.
 *
 * مشتری «سرور آلمان» می‌خواهد، نه «سرور فالکن‌اشتاین»؛ شهر را وقتی انتخاب
 * می‌کند که پلن‌ها را کنار هم ببیند — یعنی داخلِ صفحهٔ کشور.
 *
 * 🔴 این تجمیع یک خرابیِ دادهٔ واقعی را هم می‌پوشاند: بعضی زیرساخت‌ها شهر
 * نمی‌دهند و کدِ مکانشان از **ردهٔ محصول** ساخته شده (`fi-shared`،
 * `fi-dedicated`، `de-amd`). با کارتِ شهری، فینلاند سه مکانِ جعلی نشان می‌داد.
 */
class CloudCountryCardTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function loc(string $code, string $country, ?string $city): CloudLocation
    {
        return CloudLocation::create([
            'code' => $code, 'country' => $country, 'city' => $city,
            'is_active' => true, 'sort' => 0,
        ]);
    }

    private function plan(string $loc, int $price = 1000000): CloudPlan
    {
        $this->n++;

        return CloudPlan::create([
            'provider' => 'p', 'provider_ref' => 'r'.$this->n,
            'location_code' => $loc, 'slug' => 'cv-'.$this->n.'-'.$loc,
            'public_name' => 'CV-'.$this->n,
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'traffic_gb' => 20000, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 400, 'price_eur_cents' => 600, 'price_irt' => $price,
            'is_active' => true, 'in_stock' => true, 'admin_disabled' => false,
        ]);
    }

    private function html(): string
    {
        return $this->get('/cloud')->assertOk()->getContent();
    }

    /** 🔴 ادعای اصلی: کارت به صفحهٔ کشور می‌رود، نه صفحهٔ شهر */
    public function test_a_country_card_links_to_the_country_page(): void
    {
        $this->loc('de-falkenstein', 'DE', 'Falkenstein');
        $this->plan('de-falkenstein');

        $html = $this->html();

        $this->assertStringContainsString('class="cvps-country" href="', $html);
        $this->assertStringContainsString('/vps/germany', $html);
    }

    /**
     * 🔴 سه مکانِ جعلیِ یک کشور باید **یک** کارت شوند.
     *
     * دقیقاً وضعیتِ واقعیِ فینلاند روی سایتِ زنده: `fi-helsinki` کنارِ
     * `fi-shared` و `fi-dedicated`.
     */
    public function test_fake_product_tier_locations_collapse_into_one_country_card(): void
    {
        $this->loc('fi-helsinki', 'FI', 'Helsinki');
        $this->loc('fi-shared', 'FI', 'Shared');
        $this->loc('fi-dedicated', 'FI', 'Dedicated');
        $this->plan('fi-helsinki', 900000);
        $this->plan('fi-shared', 1100000);
        $this->plan('fi-dedicated', 1300000);

        $html = $this->html();

        $this->assertSame(1, substr_count($html, 'class="cvps-country" href='),
            'فینلاند باید یک کارت باشد، نه سه تا');
        $this->assertStringNotContainsString('Shared', $html);
        $this->assertStringNotContainsString('Dedicated', $html);
    }

    /** نامِ شهرِ تکراری نباید دو بار در کارت بیاید */
    public function test_duplicate_city_labels_are_not_repeated(): void
    {
        // هر دو شهر ندارند، پس هر دو به پایتخت برمی‌گردند
        $this->loc('at-shared', 'AT', 'Shared');
        $this->loc('at-dedicated', 'AT', 'Dedicated');
        $this->plan('at-shared');
        $this->plan('at-dedicated');

        $html = $this->html();

        // ⚠️ شمارشِ خودِ واژه گمراه‌کننده است: «وین» زیررشتهٔ واژه‌های دیگرِ
        // فارسیِ صفحه هم هست. فقط برچسب‌های داخلِ کارت شمرده می‌شوند.
        preg_match_all('~<span class="cvps-city-tag">([^<]*)</span>~u', $html, $m);

        $this->assertSame(['وین'], $m[1],
            'پایتخت نباید به ازای هر مکانِ جعلی تکرار شود');
    }

    /** شهرهای واقعی به‌عنوان برچسب داخلِ کارت دیده می‌شوند */
    public function test_real_cities_appear_as_tags_inside_the_card(): void
    {
        $this->loc('de-falkenstein', 'DE', 'Falkenstein');
        $this->loc('de-nuremberg', 'DE', 'Nuremberg');
        $this->plan('de-falkenstein', 900000);
        $this->plan('de-nuremberg', 950000);

        $html = $this->html();

        $this->assertStringContainsString('cvps-city-tag', $html);
        $this->assertStringContainsString('فالکن‌اشتاین', $html);
        $this->assertStringContainsString('نورنبرگ', $html);
        $this->assertSame(1, substr_count($html, 'class="cvps-country" href='));
    }

    /** کاتالوگِ خالی نباید ۵۰۰ بدهد */
    public function test_an_empty_catalogue_still_renders(): void
    {
        $this->get('/cloud')->assertOk();
    }
}
