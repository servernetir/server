<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\IdentityVerification;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * صفحه‌های پنل کاربری واقعاً رندر می‌شوند.
 *
 * کد ۲۰۰ اینجا کافی نیست: خطای Blade و کلید ترجمهٔ جامانده هر دو می‌توانند
 * صفحه‌ای بدهند که «کار می‌کند» ولی به کاربر متن خام نشان می‌دهد. پس هم
 * وضعیت بررسی می‌شود هم نبودِ کلید خام.
 */
class AccountPanelTest extends TestCase
{
    use RefreshDatabase;

    private function customer(bool $verified = true): Customer
    {
        $c = Customer::create([
            'email' => 'panel'.random_int(1, 99999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'fa',
        ]);

        if ($verified) {
            IdentityVerification::create([
                'customer_id'      => $c->id,
                'national_id_enc'  => Crypt::encryptString('0084575948'),
                'national_id_hash' => hash_hmac('sha256', '0084575948', config('app.key')),
                'birth_date'       => '1370-05-12',
                'mobile'           => $c->phone,
                'shahkar_matched'  => true,
                'first_name'       => 'علی',
                'last_name'        => 'محمدی',
                'father_name'      => 'رضا',
                'status'           => 'verified',
                'verified_at'      => now(),
            ]);
        }

        return $c->fresh();
    }

    /** @return array{0:int,1:string} */
    private function render(Customer $c, string $uri): array
    {
        $res = $this->actingAs($c, 'customer')->get($uri);

        return [$res->getStatusCode(), $res->getContent()];
    }

    public function test_every_account_page_renders_for_a_signed_in_customer(): void
    {
        $c = $this->customer();

        foreach (['/account', '/account/profile', '/account/bank', '/account/invoices', '/account/topup'] as $uri) {
            [$code, $html] = $this->render($c, $uri);

            $this->assertSame(200, $code, "{$uri} کد {$code} داد");
            // کلید ترجمهٔ رندرنشده — کاربر «ui.pnl_x» می‌بیند
            $this->assertDoesNotMatchRegularExpression('/\bui\.[a-z_]+/', $html, "{$uri} کلید خام دارد");
        }
    }

    public function test_the_dashboard_shows_pending_work_and_hides_it_when_done(): void
    {
        // بدون احراز هویت → باید کارِ معلق نشان دهد
        $new = $this->customer(verified: false);
        [, $html] = $this->render($new, '/account');
        $this->assertStringContainsString(__('ui.pnl_todo'), $html);

        // با احراز هویت و حساب بانکی و بدون فاکتور باز → هیچ کار معلقی نیست
        $done = $this->customer();
        BankAccount::create([
            'customer_id' => $done->id, 'card_bin' => '603799', 'card_last4' => '7893',
            'iban' => 'IR060540105180021273113007', 'status' => 'verified',
            'name_matched' => true, 'is_default' => true, 'verified_at' => now(),
        ]);

        [, $html] = $this->render($done->fresh(), '/account');
        $this->assertStringNotContainsString(__('ui.pnl_todo'), $html);
    }

    public function test_an_unpaid_invoice_appears_as_pending_work(): void
    {
        $c = $this->customer();
        BankAccount::create([
            'customer_id' => $c->id, 'card_bin' => '603799', 'card_last4' => '7893',
            'iban' => 'IR060540105180021273113008', 'status' => 'verified',
            'name_matched' => true, 'is_default' => true, 'verified_at' => now(),
        ]);

        $invoice = Invoice::create([
            'customer_id' => $c->id, 'kind' => 'service', 'currency_code' => 'IRT',
            'subtotal' => 250000, 'tax' => 0, 'total' => 250000, 'status' => 'unpaid',
            'issued_at' => now(),
        ]);

        [, $html] = $this->render($c->fresh(), '/account');

        $this->assertStringContainsString($invoice->number, $html);
    }

    /** نام پدر باید در پروفایل دیده شود — کارفرما صریح خواست */
    public function test_the_fathers_name_is_shown_on_the_profile(): void
    {
        [, $html] = $this->render($this->customer(), '/account/profile');

        $this->assertStringContainsString(__('ui.pnl_father'), $html);
        $this->assertStringContainsString('رضا', $html);
    }

    /**
     * شماره حساب و شبا هر کدام ردیف خودشان را دارند و با هم قاطی نمی‌شوند.
     * ارقام در نسخهٔ فارسی، فارسی‌اند — پس انتظار هم با fa_num ساخته می‌شود،
     * نه با رشتهٔ خام.
     */
    public function test_the_account_number_and_iban_each_have_their_own_row(): void
    {
        $c = $this->customer();
        BankAccount::create([
            'customer_id' => $c->id, 'card_bin' => '603799', 'card_last4' => '7893',
            'account_number' => '1234567890', 'iban' => 'IR060540105180021273113009',
            'bank_name' => 'ملت', 'status' => 'verified', 'name_matched' => true,
            'is_default' => true, 'verified_at' => now(),
        ]);

        [, $html] = $this->render($c->fresh(), '/account/bank');

        $this->assertStringContainsString(__('ui.pnl_account_no'), $html);
        $this->assertStringContainsString(__('ui.pnl_iban'), $html);
        $this->assertStringContainsString(fa_num('1234567890'), $html);
        $this->assertStringContainsString(fa_num('IR060540105180021273113009'), $html);

        // شمارهٔ کارت کامل هرگز نباید روی صفحه بیاید
        $this->assertStringNotContainsString('6037991234567893', $html);
    }

    /** تاریخ‌ها در نسخهٔ فارسی باید شمسی باشند، نه میلادی */
    public function test_dates_are_jalali_in_persian(): void
    {
        $c = $this->customer();
        [, $html] = $this->render($c, '/account/profile');

        $jalali = blog_date((string) $c->created_at);

        $this->assertStringContainsString(__('ui.pnl_member_since'), $html);
        $this->assertStringContainsString($jalali, $html);
        // سال میلادی نباید خام روی صفحه باشد
        $this->assertStringNotContainsString($c->created_at->format('Y-m-d'), $html);
    }

    /** نام سرویس استعلام به مشتری مربوط نیست و نباید نشان داده شود */
    public function test_the_identity_provider_is_not_named_to_the_customer(): void
    {
        [, $html] = $this->render($this->customer(), '/account/profile');

        foreach (['شاهکار', 'زحل', 'zohal', 'Shahkar'] as $vendor) {
            $this->assertStringNotContainsString($vendor, $html, "نام «{$vendor}» به مشتری نشان داده شد");
        }
    }

    /** خروج باید POST باشد — با GET هر سایتی می‌تواند کاربر را بیرون بیندازد */
    public function test_sign_out_works_and_is_not_reachable_by_get(): void
    {
        $c = $this->customer();

        $this->actingAs($c, 'customer')->get('/account')->assertOk();
        $this->actingAs($c, 'customer')->get('/logout')->assertStatus(405);

        $this->actingAs($c, 'customer')->post('/logout')->assertRedirect();
        $this->assertGuest('customer');
    }

    public function test_the_panel_is_closed_to_visitors(): void
    {
        foreach (['/account', '/account/profile', '/account/bank', '/account/invoices'] as $uri) {
            $this->get($uri)->assertRedirect(route('login'));
        }
    }
}
