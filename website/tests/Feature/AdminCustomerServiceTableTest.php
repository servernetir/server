<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * جدولِ سرویس‌های پروندهٔ مشتری باید واقعاً **جدول** باشد.
 *
 * ═══ چیزی که کارفرما دید ═══
 *
 * «لیستی که نشون میده سرویس‌ها و خدمات حالت جدولی نیست، ردیفش بهم می‌ریزه.»
 *
 * علت: خانهٔ **اول** هم‌زمان نام، توضیح، پکیج، دامنه، IP، کاربر، آخرین پرداخت،
 * نشانِ تحویل، وضعیتِ خامِ صف و متنِ خطا را می‌گرفت. پس ارتفاعش از یک خط تا
 * هشت خط فرق می‌کرد و چشم دیگر ستون‌ها را در یک راستا نمی‌دید.
 *
 * ⚠️ و مهم‌تر از ظاهر: «مشتری این سرور را با چه IPای دارد؟» پرتکرارترین سؤالِ
 * پشتیبانی است. چیزی که در هر ردیف لازم است باید ستونِ خودش را داشته باشد، نه
 * یک چیپِ گم‌شده وسطِ متن.
 */
class AdminCustomerServiceTableTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'مدیر', 'email' => 'ad'.random_int(1000, 9999).'@example.test',
            'password' => bcrypt('secret-for-test'), 'role' => 'admin',
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'cu'.random_int(1000, 9999).'@example.test',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('secret-for-test'), 'status' => 'active',
        ]);
    }

    private function page(Customer $c): string
    {
        return $this->actingAs($this->admin())
            ->get('/admin/customers/'.$c->id)
            ->assertOk()
            ->getContent();
    }

    /** ستونِ «آدرس» باید در سرستون وجود داشته باشد */
    public function test_the_table_has_a_dedicated_address_column(): void
    {
        $c = $this->customer();

        Service::create([
            'customer_id' => $c->id, 'name' => 'هاست', 'currency_code' => 'IRT',
            'price' => 100000, 'cycle' => 'monthly', 'status' => 'active',
        ]);

        $html = $this->page($c);

        $this->assertMatchesRegularExpression(
            '~<thead>.*?<th>\s*آدرس\s*</th>.*?</thead>~s',
            $html,
            'ستونِ آدرس در سرستونِ جدول نیست'
        );
    }

    /** دامنهٔ سرویس در ستونِ خودش بیاید، نه داخلِ خانهٔ نام */
    public function test_a_hosting_service_shows_its_domain(): void
    {
        $c = $this->customer();

        Service::create([
            'customer_id' => $c->id, 'name' => 'هاست حرفه‌ای', 'currency_code' => 'IRT',
            'price' => 100000, 'cycle' => 'monthly', 'status' => 'active',
            'domain' => 'example-shop.com',
        ]);

        $this->assertStringContainsString('example-shop.com', $this->page($c));
    }

    /**
     * 🔴 IPِ سرور از `provision_meta` می‌آید.
     *
     * ⚠️ `provision_meta` ممکن است null باشد و `null['ip']` در PHP ۸ اخطار
     * می‌دهد که لاراول به استثنا تبدیلش می‌کند ⇒ ۵۰۰ روی کلِ صفحه. یعنی یک
     * سرویسِ بی‌متا، پروندهٔ مشتری را کامل می‌خواباند.
     */
    public function test_a_server_shows_its_ip(): void
    {
        $c = $this->customer();

        Service::create([
            'customer_id' => $c->id, 'name' => 'سرور مجازی', 'currency_code' => 'IRT',
            'price' => 900000, 'cycle' => 'monthly', 'status' => 'active',
            'provision_meta' => ['ip' => '203.0.113.44'],
        ]);

        $this->assertStringContainsString('203.0.113.44', $this->page($c));
    }

    /** هاست باید با نامِ زیرساخت و DNSهای همان سرور قابلِ تشخیص باشد */
    public function test_a_hosting_service_shows_its_infrastructure_and_nameservers(): void
    {
        $c = $this->customer();
        $server = Server::create([
            'name' => 'هاست آلمان ۲', 'type' => 'whm', 'hostname' => 'de2.example.test',
            'server_ip' => '203.0.113.20', 'nameservers' => 'ns1.example.test,ns2.example.test',
            'status' => 'active',
        ]);

        Service::create([
            'customer_id' => $c->id, 'server_id' => $server->id,
            'name' => 'هاست حرفه‌ای', 'currency_code' => 'IRT',
            'price' => 100000, 'cycle' => 'monthly', 'status' => 'active',
            'domain' => 'hosted.example',
        ]);

        $html = $this->page($c);
        $this->assertStringContainsString('هاست آلمان ۲', $html);
        $this->assertStringContainsString('ns1.example.test · ns2.example.test', $html);
        $this->assertStringContainsString('203.0.113.20', $html);
    }

    /** نامِ گروهِ Salad همان شناسه‌ای است که مدیر در salad.com جستجو می‌کند */
    public function test_a_salad_gpu_shows_its_provider_group_name(): void
    {
        $c = $this->customer();
        $service = Service::create([
            'customer_id' => $c->id, 'name' => 'GPU ساعتی', 'currency_code' => 'IRT',
            'price' => 0, 'cycle' => 'monthly', 'status' => 'active',
            'billing_mode' => 'hourly', 'hourly_rate_irt' => 10000,
        ]);
        CloudInstance::create([
            'service_id' => $service->id, 'provider' => 'salad',
            'provider_ref' => 'sn-svc-'.$service->id, 'location_code' => 'global-gpu',
            'image_key' => 'gpu-ollama', 'hostname' => 'gateway.salad.cloud',
            // IPِ داخلی/زیرساختیِ GPU نباید به‌جای نامِ گروه به مدیر تحمیل شود.
            'ipv4' => '198.51.100.88', 'status' => 'running',
        ]);

        $html = $this->page($c);
        $this->assertStringContainsString('نام GPU در Salad:', $html);
        $this->assertStringContainsString('sn-svc-'.$service->id, $html);
        $this->assertStringNotContainsString('198.51.100.88', $html,
            'GPU آدرس SSH قابلِ استفاده ندارد؛ نامِ گروه Salad شناسهٔ عملیاتی است.');
    }

    /** دامنهٔ یک سرویس، خریدِ دامنه از ما نیست و نباید کارتِ بالا را بسازد */
    public function test_a_service_domain_does_not_count_as_a_purchased_domain(): void
    {
        $c = $this->customer();
        Service::create([
            'customer_id' => $c->id, 'name' => 'هاست', 'currency_code' => 'IRT',
            'price' => 100000, 'cycle' => 'monthly', 'status' => 'active',
            'domain' => 'external-domain.example',
        ]);

        $this->assertStringNotContainsString('data-kpi="purchased-domains"', $this->page($c));
    }

    /** کارتِ دامنه فقط از ردیفِ خریدِ واقعیِ رجیسترار تغذیه می‌شود */
    public function test_a_purchased_domain_is_counted_in_the_top_card(): void
    {
        $c = $this->customer();
        Domain::create([
            'customer_id' => $c->id, 'domain' => 'bought.example',
            'sld' => 'bought', 'tld' => 'example', 'status' => 'active',
            'provision_status' => 'done',
        ]);

        $html = $this->page($c);
        $this->assertStringContainsString('data-kpi="purchased-domains"', $html);
        $this->assertStringContainsString('دامنهٔ خریداری‌شده', $html);
    }

    /** سرویسِ بی‌متا نباید صفحه را بشکند */
    public function test_a_service_without_meta_does_not_break_the_page(): void
    {
        $c = $this->customer();

        Service::create([
            'customer_id' => $c->id, 'name' => 'بدون متا', 'currency_code' => 'IRT',
            'price' => 100000, 'cycle' => 'monthly', 'status' => 'active',
        ]);

        // خودِ assertOk داخلِ page() ادعای اصلی است
        $this->assertStringContainsString('بدون متا', $this->page($c));
    }

    /**
     * ⚠️ نیمهٔ دومِ قاعده: خانهٔ نام دیگر نباید دامنه/IP را **هم** چاپ کند.
     *
     * بی‌این، ستونِ تازه فقط یک تکرارِ اضافه می‌شد و ارتفاعِ نامتوازنِ خانهٔ
     * اول — که کلِ شکایت بود — سرِ جایش می‌مانْد.
     */
    public function test_the_name_cell_no_longer_repeats_the_address(): void
    {
        $c = $this->customer();

        Service::create([
            'customer_id' => $c->id, 'name' => 'سرور', 'currency_code' => 'IRT',
            'price' => 900000, 'cycle' => 'monthly', 'status' => 'active',
            'domain' => 'dup-check.com', 'provision_meta' => ['ip' => '198.51.100.7'],
        ]);

        $html = $this->page($c);

        /*
         * ⚠️ `>دامنه<` شمرده می‌شود نه خودِ رشته: دامنه یک بار در `href` و یک
         * بار به‌عنوانِ متنِ **همان** لینک می‌آید، پس شمارشِ خام همیشه ۲ است و
         * ادعا را بی‌معنا می‌کند. نسخهٔ اولِ همین تست دقیقاً همین اشتباه را
         * داشت و کدِ سالم را قرمز کرد.
         */
        $this->assertSame(1, substr_count($html, '>dup-check.com<'),
            'دامنه دو بار به‌عنوان متن چاپ شد — یک بار در ستونِ آدرس و یک بار در خانهٔ نام');
        $this->assertSame(1, substr_count($html, '198.51.100.7'),
            'IP دو بار چاپ شد');
    }

    /** خانهٔ خالی یعنی «یادم رفت»؛ `—` یعنی «هست و چیزی ندارد» */
    public function test_a_service_with_no_address_shows_an_explicit_dash(): void
    {
        $c = $this->customer();

        Service::create([
            'customer_id' => $c->id, 'name' => 'بدون آدرس', 'currency_code' => 'IRT',
            'price' => 100000, 'cycle' => 'monthly', 'status' => 'awaiting_provision',
        ]);

        $this->assertStringContainsString('—', $this->page($c));
    }
}
