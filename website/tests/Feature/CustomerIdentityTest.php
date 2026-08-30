<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\TaxRate;
use Database\Seeders\BillingFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class CustomerIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_gets_a_public_code_automatically(): void
    {
        $c = Customer::create(['email' => 'a@example.com', 'password' => 'secret123']);

        $this->assertMatchesRegularExpression('/^SN-\d{6}$/', $c->code);
        // id عددی نباید جایی به مشتری نشان داده شود
        $this->assertNotEquals((string) $c->id, $c->code);
    }

    public function test_customer_guard_is_separate_from_admin_guard(): void
    {
        $customer = Customer::create(['email' => 'b@example.com', 'password' => 'secret123']);

        Auth::guard('customer')->login($customer);

        $this->assertTrue(Auth::guard('customer')->check());
        // حیاتی: ورود مشتری نباید هیچ دسترسی‌ای در guard ادمین بدهد
        $this->assertFalse(Auth::guard('web')->check());
    }

    public function test_national_id_is_encrypted_and_searchable_without_decrypting(): void
    {
        $c = Customer::create(['email' => 'c@example.com', 'password' => 'x']);
        $p = new CustomerProfile([
            'customer_id' => $c->id, 'type' => 'individual',
            'mobile' => '09120000000', 'email' => 'c@example.com', 'address' => 'تهران',
        ]);
        $p->setSecure('national_id', '0012345678');
        $p->save();

        // مقدار خام نباید در دیتابیس باشد
        $raw = \DB::table('customer_profiles')->where('id', $p->id)->first();
        $this->assertStringNotContainsString('0012345678', (string) $raw->national_id_enc);

        // ولی باید بدون رمزگشایی پیدا شود
        $found = CustomerProfile::findBySecure('national_id', '0012345678');
        $this->assertNotNull($found);
        $this->assertSame($p->id, $found->id);

        // و رمزگشایی مقدار درست بدهد
        $this->assertSame('0012345678', $found->getSecure('national_id'));
    }

    public function test_persian_digits_in_national_id_are_normalized(): void
    {
        $c = Customer::create(['email' => 'd@example.com', 'password' => 'x']);
        $p = new CustomerProfile([
            'customer_id' => $c->id, 'type' => 'individual',
            'mobile' => '0912', 'email' => 'd@example.com', 'address' => 'تهران',
        ]);
        $p->setSecure('national_id', '۰۰۱۲۳۴۵۶۷۹');   // ارقام فارسی
        $p->save();

        // جستجو با ارقام لاتین باید همان را پیدا کند
        $this->assertNotNull(CustomerProfile::findBySecure('national_id', '0012345679'));
    }

    public function test_seeder_creates_currencies_and_tax_rules(): void
    {
        $this->seed(BillingFoundationSeeder::class);

        $irt = Currency::find('IRT');
        $this->assertSame(0, $irt->exponent);          // تومان بدون اعشار
        $this->assertSame(10000, $irt->rounding_step);

        $eur = Currency::find('EUR');
        $this->assertSame(2, $eur->exponent);

        // ایران ۱۰٪
        $ir = TaxRate::resolve('IR');
        $this->assertSame(1000, $ir->rate_bp);
        $this->assertSame(100000, $ir->taxOn(1000000));  // ۱۰٪ از یک میلیون

        // خارج ۰٪ — مستقل از روش پرداخت
        $de = TaxRate::resolve('DE');
        $this->assertSame(0, $de->rate_bp);
        $this->assertSame(0, $de->taxOn(1000000));
    }

    public function test_money_rounding_uses_currency_step(): void
    {
        $this->seed(BillingFoundationSeeder::class);

        $irt = Currency::find('IRT');
        // ۱۲۳٬۴۵۶ تومان باید به نزدیک‌ترین ۱۰٬۰۰۰ گرد شود
        $this->assertSame(120000, $irt->round(123456));
        $this->assertSame(130000, $irt->round(127000));
    }
}
