<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * محافظِ «فروشِ ساعتی زیرِ بها» — درسِ sn-svc-76 (۶ شهریور ۱۴۰۵).
 *
 * نرخِ ساعتی در لحظهٔ خرید قفل می‌شود؛ اگر کاتالوگِ آن روز خراب بوده باشد یا
 * تحویل روی زیرساختِ گران‌ترِ همان اسلاگ رفته باشد، هر ساعتِ روشن ضررِ نقدِ
 * بی‌صداست. cloud:hourly-audit باید ببیندش و cloud:hourly-reprice فقط با
 * --apply و فقط رو به بالا اصلاح کند.
 */
class HourlyUnderwaterGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Setting::put('pricing_rate_override', '100000'); // ۱€ = ۱۰۰هزار تومان
        CloudLocation::create(['code' => 'de-frankfurt', 'country' => 'DE', 'city' => 'Frankfurt', 'is_active' => true]);
    }

    private function plan(string $provider, int $costCents, int $priceIrt, int $priceCents): CloudPlan
    {
        return CloudPlan::create([
            'provider' => $provider, 'provider_ref' => $provider.'-x', 'provider_location' => 'l',
            'location_code' => 'de-frankfurt', 'public_name' => 'CV-4-8',
            'slug' => 'cv-4c-8g-80d-de-frankfurt',
            'vcpu' => 4, 'ram_mb' => 8192, 'disk_gb' => 80, 'disk_type' => 'nvme',
            'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => $costCents, 'price_eur_cents' => $priceCents, 'price_irt' => $priceIrt,
            'is_active' => true, 'in_stock' => true,
        ]);
    }

    private function hourlyService(Customer $c, CloudPlan $bought, int $rateIrt, int $rateEur, ?string $deliveredProvider = null): Service
    {
        $s = Service::create([
            'customer_id' => $c->id, 'kind' => 'cloud', 'status' => 'active',
            'name' => 'Cloud VPS t (hourly)', 'currency_code' => 'IRT', 'price' => 0,
            'cycle' => 'monthly', 'billing_mode' => 'hourly',
            'hourly_rate_irt' => $rateIrt, 'hourly_rate_eur' => $rateEur,
            'cloud_plan_id' => $bought->id, 'provision_status' => 'done',
        ]);

        CloudInstance::create([
            'service_id' => $s->id, 'provider' => $deliveredProvider ?: $bought->provider,
            'provider_ref' => 'srv-'.$s->id, 'location_code' => 'de-frankfurt',
            'image_key' => 'ubuntu-24.04', 'hostname' => 'sn-svc-'.$s->id,
            'ipv4' => '10.9.0.'.($s->id % 250 + 1), 'status' => 'running',
            'ready_notified_at' => now()->subDay(),
        ]);

        return $s;
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'uw'.random_int(1, 999999).'@example.com',
            'phone' => '+9053'.random_int(10000000, 99999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'en',
        ]);
    }

    /** 🔴 نرخِ قفل‌شدهٔ زیرِ بها باید ممیزی را قرمز کند */
    public function test_the_audit_fails_when_a_locked_rate_is_below_todays_cost(): void
    {
        // بها €36/ماه ⇒ کفِ ساعتی €0.05؛ قفل‌شده €0.02 ⇒ ضرر
        $plan = $this->plan('hetzner', 3600, 5_220_000, 5220);
        $this->hourlyService($this->customer(), $plan, 1500, 2); // €0.02/h

        $this->artisan('cloud:hourly-audit')->assertFailed();
    }

    /** نرخِ سالم (بالای بها) ⇒ سبز */
    public function test_the_audit_passes_when_rates_cover_cost(): void
    {
        $plan = $this->plan('hetzner', 3600, 5_220_000, 5220);
        // قیمتِ روز: 5,220,000/720 ⇒ ~7,250 تومان/ساعت (€0.0725)
        $this->hourlyService($this->customer(), $plan, 7300, 8);

        $this->artisan('cloud:hourly-audit')->assertSuccessful();
    }

    /** 🔴 تحویل روی زیرساختِ گران‌ترِ همان اسلاگ ⇒ بها از ردیفِ تحویل‌شده سنجیده شود */
    public function test_the_audit_measures_cost_against_the_delivered_providers_row(): void
    {
        // ردیفِ خرید (ارزان): بها €7/ماه — نرخِ قفل‌شده رویش سالم است
        $cheap = $this->plan('aeza', 700, 1_020_000, 1020);
        // ردیفِ همان اسلاگ روی زیرساختِ تحویل‌شده (گران): بها €36/ماه
        $this->plan('hetzner', 3600, 5_220_000, 5220);

        // €0.02/h روی ردیفِ ارزان سالم، روی ردیفِ تحویل‌شده زیرِ بها
        $this->hourlyService($this->customer(), $cheap, 1500, 2, deliveredProvider: 'hetzner');

        $this->artisan('cloud:hourly-audit')->assertFailed();
    }

    /** reprice بدونِ --apply هیچ‌چیز نمی‌نویسد؛ با --apply فقط بالا می‌برد */
    public function test_reprice_previews_by_default_and_only_raises_with_apply(): void
    {
        $plan = $this->plan('hetzner', 3600, 5_220_000, 5220);
        $s = $this->hourlyService($this->customer(), $plan, 1500, 2);

        $this->artisan('cloud:hourly-reprice')->assertSuccessful();
        $this->assertSame(1500, (int) $s->fresh()->hourly_rate_irt, 'بدونِ --apply نباید بنویسد.');

        $this->artisan('cloud:hourly-reprice --apply')->assertSuccessful();
        $s = $s->fresh();
        $this->assertSame($plan->hourlyIrt(), (int) $s->hourly_rate_irt, 'با --apply باید به نرخِ روز برسد.');
        $this->assertSame($plan->hourlyEurCents(), (int) $s->hourly_rate_eur);

        // نرخِ قفل‌شدهٔ بالاتر از نرخِ روز هرگز پایین نمی‌آید
        $s->forceFill(['hourly_rate_irt' => 99_999, 'hourly_rate_eur' => 999])->save();
        $this->artisan('cloud:hourly-reprice --apply')->assertSuccessful();
        $this->assertSame(99_999, (int) $s->fresh()->hourly_rate_irt, 'reprice هرگز نرخ را پایین نمی‌آورد.');
    }

    /**
     * 🔴 بازتولیدِ دقیقِ sn-svc-76: زیرساخت نرخِ ساعتیِ جدا دارد (aeza LND-1:
     * ماهانه €12.18 ولی ساعتی €0.05) و تحویلِ ساعتی با term=hour از همان
     * نرخِ گران می‌خرد. «ماهانه÷۷۲۰» می‌گفت €0.0169 و همه‌چیز سبز بود؛
     * بهایِ واقعی €0.05 بود و مشتری €0.02 می‌داد.
     */
    public function test_the_provider_hourly_cost_floors_the_sale_price_and_trips_the_audit(): void
    {
        $plan = $this->plan('aeza', 1218, 1_020_000, 1440);

        /*
        | 🔴 عمداً mass-assignment، نه forceFill: همگام‌ساز با updateOrCreate
        | می‌نویسد و ستونِ بیرون از $fillable **بی‌صدا** دور ریخته می‌شود —
        | دقیقاً همین رخ داد: روی پروداکشن sync سبز بود و ستون NULL می‌مانْد،
        | چون تستِ اولیه با forceFill این تله را دور زده بود.
        */
        $plan->update(['cost_hour_eur_micro' => 50_000]); // €0.05/h
        $this->assertSame(50_000, (int) $plan->fresh()->cost_hour_eur_micro,
            'cost_hour_eur_micro باید fillable باشد وگرنه sync بی‌صدا دورش می‌ریزد.');

        // کفِ فروش: €0.05 × ۱٫۴۵ = €0.0725 ⇒ ۸ سنت (ceil) / ۷٬۳۰۰ تومان
        $this->assertSame(8, $plan->fresh()->hourlyEurCents(),
            'کفِ ساعتی باید از بهایِ ساعتیِ واقعی بیاید، نه ماهانه÷۷۲۰.');
        $this->assertSame(7300, $plan->fresh()->hourlyIrt());

        // سرویسی که با قیمتِ قدیمِ زیرِ بها قفل شده ⇒ ممیزی قرمز
        $s = $this->hourlyService($this->customer(), $plan, 1500, 2);
        $this->artisan('cloud:hourly-audit')->assertFailed();

        // و reprice به کفِ درست می‌رساندش
        $this->artisan('cloud:hourly-reprice --apply')->assertSuccessful();
        $s = $s->fresh();
        $this->assertSame(7300, (int) $s->hourly_rate_irt);
        $this->assertSame(8, (int) $s->hourly_rate_eur);
    }

    /** کرونِ ممیزی ثبت شده باشد — فرمانِ ثبت‌نشده اجرا نمی‌شود */
    public function test_the_audit_is_scheduled(): void
    {
        $this->assertStringContainsString("cloud:hourly-audit",
            (string) file_get_contents(base_path('routes/console.php')));
    }
}
