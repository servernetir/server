<?php

namespace Tests\Feature;

use App\Models\{BankTransferReceipt, CryptoPayment, CryptoWallet, Customer, Invoice, Payment, PaymentAccount, Setting, User};
use App\Services\Payment\{CryptoIssuer, GatewayRegistry, PaymentService, PaymentVisibility, ZarinPalGateway};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentLanguageVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake([
            'api.zarinpal.com/*' => fn ($r) => Http::response(['data' => str_contains($r->url(), 'request.json')
                ? ['code' => 100, 'authority' => 'test-authority'] : ['code' => 100, 'ref_id' => 19999]]),
            '*' => Http::response([]),
        ]);
        $registry = new GatewayRegistry;
        $registry->register(new ZarinPalGateway('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'));
        $this->app->instance(GatewayRegistry::class, $registry);
        $this->app->instance(PaymentService::class, new PaymentService($registry));
    }

    private function invoice(): Invoice
    {
        $c = Customer::create(['email' => uniqid().'@example.test', 'password' => 'test-only', 'status' => 'active']);
        $this->actingAs($c, 'customer');
        return Invoice::create(['customer_id' => $c->id, 'kind' => 'topup', 'currency_code' => 'IRT',
            'subtotal' => 500000, 'tax' => 0, 'total' => 500000, 'paid' => 0, 'status' => 'unpaid', 'issued_at' => now()]);
    }

    private function saveMatrix(array $matrix): void
    {
        Setting::put(PaymentVisibility::SETTING, json_encode($matrix, JSON_THROW_ON_ERROR));
    }

    private function admin(): User
    {
        return User::create(['name' => 'Admin', 'email' => uniqid().'@example.test', 'password' => bcrypt('test-only'), 'role' => 'admin']);
    }

    public function test_iranian_bank_classification_covers_currency_iban_and_country(): void
    {
        $policy = app(PaymentVisibility::class);
        foreach ([['currency_code' => 'IRR'], ['iban' => 'ir00112233'], ['country' => 'Islamic Republic of Iran'], ['country' => 'ایران']] as $data) {
            $account = new PaymentAccount($data + ['kind' => 'bank', 'currency_code' => 'EUR']);
            $this->assertFalse($policy->allowsAccount($account, 'en'));
            $this->assertTrue($policy->allowsAccount($account, 'fa'));
        }
        $this->assertTrue($policy->allowsAccount(new PaymentAccount(['kind' => 'bank', 'currency_code' => 'TRY', 'country' => 'TR']), 'tr'));
    }

    public function test_hidden_snapppay_does_not_make_an_eligibility_request(): void
    {
        $inv = $this->invoice();
        config(['snapppay.enabled' => true]);
        $matrix = app(PaymentVisibility::class)->matrix();
        $matrix['fa']['snapppay'] = false;
        $this->saveMatrix($matrix);
        $client = \Mockery::mock(\App\Services\Payment\SnappPay\SnappPayClient::class);
        $client->shouldReceive('configured')->once()->andReturn(true);
        $client->shouldNotReceive('eligible');
        $availability = new \App\Services\Payment\SnappPay\SnappPayAvailability($client);
        $this->assertSame('locale', $availability->forInvoice($inv, $inv->customer)['reason']);
        Http::assertNothingSent();
    }

    public function test_default_policy_blocks_iranian_methods_in_en_and_tr_only(): void
    {
        $p = app(PaymentVisibility::class);
        foreach (PaymentVisibility::METHODS as $key => $_) {
            $this->assertTrue($p->allows($key, 'fa'));
            foreach (['en', 'tr'] as $locale) {
                $this->assertSame(in_array($key, ['crypto', 'bank_foreign'], true), $p->allows($key, $locale));
            }
        }
        $this->assertFalse($p->allows('zarinpal', 'de'));
        $this->assertFalse($p->allows('unknown', 'fa'));
    }

    public function test_admin_can_save_all_unchecked_without_clearing_other_settings(): void
    {
        Setting::put('bank_sheba', 'KEEP-THIS');
        $matrix = array_map(fn ($row) => array_map(fn () => '0', $row), app(PaymentVisibility::class)->matrix());
        $this->actingAs($this->admin())->post('/admin/settings/payment-visibility', ['visibility' => $matrix])
            ->assertRedirect('/admin/settings?tab=accounts#payment-languages');
        foreach (app(PaymentVisibility::class)->matrix() as $row) {
            $this->assertNotContains(true, $row);
        }
        $this->assertSame('KEEP-THIS', Setting::get('bank_sheba'));
        $this->get('/admin/settings?tab=accounts')->assertOk()->assertSee('visibility[tr][crypto]', false);
        $this->post('/admin/settings', ['tab' => 'accounts'])->assertRedirect();
        $this->assertFalse(app(PaymentVisibility::class)->allows('crypto', 'fa'));
    }

    public function test_incomplete_or_unknown_policy_is_rejected_atomically(): void
    {
        $admin = $this->admin();
        $matrix = app(PaymentVisibility::class)->matrix();
        foreach (['missing', 'unknown', 'invalid'] as $case) {
            $bad = $matrix;
            if ($case === 'missing') { unset($bad['tr']['crypto']); }
            if ($case === 'unknown') { $bad['de'] = $bad['en']; }
            if ($case === 'invalid') { $bad['fa']['crypto'] = 'yes'; }
            $res = $this->actingAs($admin)->postJson('/admin/settings/payment-visibility', ['visibility' => $bad]);
            $res->assertRedirect()->assertSessionHasErrors();
            $this->assertNull(Setting::get(PaymentVisibility::SETTING));
        }
    }

    public function test_non_admin_cannot_change_policy(): void
    {
        $this->invoice();
        $this->post('/admin/settings/payment-visibility', ['visibility' => app(PaymentVisibility::class)->matrix()])->assertRedirect();
        $this->assertNull(Setting::get(PaymentVisibility::SETTING));
    }

    public function test_foreign_pages_hide_iran_accounts_and_reject_direct_posts(): void
    {
        $inv = $this->invoice();
        Setting::put('bank_account', 'IRAN-LEGACY-ACCOUNT');
        $iran = PaymentAccount::create(['kind' => 'bank', 'currency_code' => 'EUR', 'iban' => 'IR0011223344', 'is_active' => true]);
        PaymentAccount::create(['kind' => 'bank', 'currency_code' => 'TRY', 'iban' => 'TR0011223344', 'country' => 'TR', 'is_active' => true]);
        foreach (['en', 'tr'] as $locale) {
            $path = '/'.$locale.'/account/invoices/'.$inv->id;
            $html = $this->get($path)->assertOk()->getContent();
            foreach (['data-m="zarinpal"', 'data-m="bank"', 'IRAN-LEGACY-ACCOUNT', 'IR0011223344'] as $hidden) {
                $this->assertStringNotContainsString($hidden, $html);
            }
            $this->assertStringContainsString('TR0011223344', $html);
            $this->post($path.'/pay', ['gateway' => 'zarinpal'])->assertSessionHasErrors('gateway');
            $this->post($path.'/bank-transfer', ['reference' => 'test'])->assertSessionHasErrors('reference');
            $this->post($path.'/bank-transfer', ['reference' => 'test', 'payment_account_id' => $iran->id])->assertSessionHasErrors('reference');
        }
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, BankTransferReceipt::count());
        Http::assertNothingSent();
    }

    public function test_disabled_gateway_does_not_cancel_an_existing_attempt_or_call_provider(): void
    {
        $inv = $this->invoice();
        $old = Payment::create(['invoice_id' => $inv->id, 'customer_id' => $inv->customer_id, 'gateway' => 'zarinpal',
            'currency_code' => 'IRT', 'amount' => $inv->total, 'status' => 'redirected']);
        app()->setLocale('en');
        $out = app(PaymentService::class)->begin($inv, 'zarinpal', Request::create('/en/account'));
        $this->assertFalse($out->ok);
        $this->assertSame('redirected', $old->fresh()->status);
        $this->assertSame(1, Payment::count());
        Http::assertNothingSent();
    }

    public function test_admin_can_explicitly_allow_a_gateway_for_english_pages(): void
    {
        $inv = $this->invoice();
        $matrix = app(PaymentVisibility::class)->matrix();
        $matrix['en']['zarinpal'] = true;
        $this->saveMatrix($matrix);
        $this->get('/en/account/invoices/'.$inv->id)->assertOk()->assertSee('data-m="zarinpal"', false);
        $this->post('/en/account/invoices/'.$inv->id.'/pay', ['gateway' => 'zarinpal'])->assertRedirect();
        $this->assertSame(1, Payment::where('status', 'redirected')->count());
        Http::assertSentCount(1);
    }

    public function test_disabling_crypto_blocks_manual_and_automatic_new_payments(): void
    {
        $inv = $this->invoice();
        CryptoWallet::create(['chain' => 'tron', 'address' => 'TEST-WALLET', 'is_active' => true]);
        $manual = PaymentAccount::create(['kind' => 'crypto', 'currency_code' => 'USDT', 'network' => 'TRC20', 'address' => 'MANUAL-ADDRESS', 'is_active' => true]);
        $matrix = app(PaymentVisibility::class)->matrix();
        $matrix['fa']['crypto'] = false;
        $this->saveMatrix($matrix);
        $this->get('/account/invoices/'.$inv->id)->assertOk()->assertDontSee('MANUAL-ADDRESS')->assertDontSee('data-m="crypto"', false);
        $this->post('/account/invoices/'.$inv->id.'/crypto', ['asset' => 'USDT'])->assertSessionHasErrors('crypto');
        $this->post('/account/invoices/'.$inv->id.'/bank-transfer', ['reference' => 'test', 'payment_account_id' => $manual->id])->assertSessionHasErrors('reference');
        $this->assertNull(app(CryptoIssuer::class)->issue($inv, 'USDT'));
        $this->assertSame(0, CryptoPayment::count());
        $this->assertSame(0, BankTransferReceipt::count());
        $this->assertNull(CryptoWallet::first()->busy_payment_id);
        Http::assertNothingSent();
    }

    public function test_open_crypto_and_pending_bank_status_survive_policy_changes(): void
    {
        $inv = $this->invoice();
        $open = CryptoPayment::create(['invoice_id' => $inv->id, 'customer_id' => $inv->customer_id, 'chain' => 'tron',
            'asset' => 'USDT', 'network' => 'TRC20', 'address' => 'EXISTING-ADDRESS', 'amount_atomic' => 5000000,
            'decimals' => 6, 'invoice_amount' => $inv->total, 'invoice_currency' => 'IRT', 'rate_micro' => 100000000000,
            'status' => 'pending', 'expires_at' => now()->addMinutes(20)]);
        BankTransferReceipt::create(['invoice_id' => $inv->id, 'customer_id' => $inv->customer_id, 'amount' => $inv->total,
            'reference' => 'EXISTING-RECEIPT', 'status' => 'pending']);
        $matrix = app(PaymentVisibility::class)->matrix();
        $matrix['fa']['crypto'] = false;
        $matrix['fa']['bank_iran'] = false;
        $this->saveMatrix($matrix);
        $this->get('/account/invoices/'.$inv->id)->assertOk()->assertSee('EXISTING-ADDRESS')->assertSee('EXISTING-RECEIPT');
        $this->assertSame($open->id, app(CryptoIssuer::class)->issue($inv, 'USDT')->id);
        $this->assertNull(app(CryptoIssuer::class)->issue($inv, 'TRX'));
        $this->assertSame(1, CryptoPayment::count());
    }

    public function test_callback_still_settles_once_after_gateway_is_hidden(): void
    {
        $inv = $this->invoice();
        $payment = Payment::create(['invoice_id' => $inv->id, 'customer_id' => $inv->customer_id, 'gateway' => 'zarinpal',
            'external_ref' => 'existing-authority', 'currency_code' => 'IRT', 'amount' => $inv->total,
            'status' => 'redirected', 'expires_at' => now()->addMinutes(20)]);
        $matrix = app(PaymentVisibility::class)->matrix();
        $matrix['fa']['zarinpal'] = false;
        $this->saveMatrix($matrix);
        $svc = app(PaymentService::class);
        $this->assertTrue($svc->settle($payment, ['Status' => 'OK', 'Authority' => 'existing-authority'])->ok);
        $this->assertTrue($svc->settle($payment->fresh(), ['Status' => 'OK', 'Authority' => 'existing-authority'])->alreadySettled);
        $this->assertSame((int) $inv->total, (int) $inv->fresh()->paid);
        Http::assertSentCount(1);
    }

    public function test_malformed_saved_data_fails_closed_and_no_choices_has_translated_message(): void
    {
        $inv = $this->invoice();
        Setting::put(PaymentVisibility::SETTING, '{broken');
        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            $this->assertFalse(app(PaymentVisibility::class)->allows('crypto', $locale));
            $this->get($prefix.'/account/invoices/'.$inv->id)->assertOk()->assertSee(__('ui.iv_no_payment_methods'));
        }
    }
}
