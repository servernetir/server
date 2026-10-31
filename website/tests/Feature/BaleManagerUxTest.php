<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankTransferReceipt;
use App\Models\CrmLead;
use App\Models\CrmMessage;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Bale\Admin\AdminBaleGate;
use App\Services\Bale\Admin\AdminBaleScreens;
use App\Services\Bale\Admin\AdminBaleWorker;
use App\Services\Bale\Management\BaleLeadReview;
use App\Services\Bale\Management\BaleRefundReview;
use App\Services\Billing\BankReceiptEvidence;
use App\Services\Finance\Wallet;
use App\Services\Finance\WalletRefunds;
use App\Services\Sms\SmsDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class BaleManagerUxTest extends TestCase
{
    use RefreshDatabase;

    private const BOT = 'manager-ux-test';
    private const CHAT = '700700';

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('bale_manager_policy_v2_enabled', '1');
        Setting::put(BaleLeadReview::PATTERN_SETTING, 'approvedTestCode');
        Setting::put('bank_sheba', '000000000000000000000000');
        Setting::put('bank_holder', 'Test Bank');
        config(['services.bale.token' => self::BOT, 'services.bale_safir.key' => null,
            'servernet.contact.email' => '', 'servernet.contact.notify_chat_id' => self::CHAT]);
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 10]])]);
        Mail::fake();
        Storage::fake('local');
        Wallet::flushSchemaCache();
    }

    private function owner(): User
    {
        $owner = User::create(['name' => 'Owner', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('test'), 'role' => 'admin']);
        Setting::putSecret(AdminBaleGate::KEY_BIND, json_encode(['chat_id' => self::CHAT, 'user_id' => $owner->id, 'at' => now()->toIso8601String()]));
        Setting::put(AdminBaleGate::KEY_ENABLED, '1');
        return $owner;
    }

    private function customer(): Customer
    {
        return Customer::create(['email' => Str::uuid().'@example.test', 'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('test'), 'status' => 'active', 'locale' => 'en']);
    }

    private function invoice(Customer $c): Invoice
    {
        return Invoice::create(['customer_id' => $c->id, 'kind' => 'credit', 'currency_code' => 'IRT',
            'subtotal' => 200000, 'tax' => 0, 'total' => 200000, 'paid' => 0, 'status' => 'unpaid', 'issued_at' => now()]);
    }

    private function lead(): CrmLead
    {
        $lead = CrmLead::create(['domain_hash' => hash('sha256', Str::uuid()), 'company' => 'Incoming request',
            'contact_name' => 'Visitor', 'phone' => '09121234567', 'email' => 'visitor@example.test', 'source' => 'site-chat', 'stage' => 'new']);
        CrmMessage::create(['lead_id' => $lead->id, 'direction' => 'in', 'channel' => 'chat', 'status' => 'received', 'body' => '{"summary":"Service question"}']);
        return $lead;
    }

    private function tap(string $data, string $from = self::CHAT): void
    {
        $this->postJson('/bale/webhook/'.substr(hash('sha256', self::BOT), 0, 32), ['update_id' => random_int(1, 10000000),
            'callback_query' => ['id' => (string) Str::uuid(), 'data' => $data,
                'from' => ['id' => $from, 'is_bot' => false],
                'message' => ['message_id' => 55, 'text' => 'card', 'chat' => ['id' => self::CHAT, 'type' => 'private']]]])->assertOk();
    }

    private function outbox(): string
    {
        $out = '';
        foreach (Http::recorded() as [$request]) { $out .= json_encode($request->data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
        return $out;
    }

    public function test_search_matches_multi_part_names_and_persian_arabic_variants(): void
    {
        $a = $this->customer(); $b = $this->customer();
        CustomerProfile::create(['customer_id' => $a->id, 'email' => $a->email, 'mobile' => $a->phone, 'address' => 'Test address', 'type' => 'individual', 'first_name' => 'علي', 'last_name' => 'كريمي', 'status' => 'draft']);
        CustomerProfile::create(['customer_id' => $b->id, 'email' => $b->email, 'mobile' => $b->phone, 'address' => 'Test address', 'type' => 'individual', 'first_name' => 'علی', 'last_name' => 'کریم‌پور', 'status' => 'draft']);
        $rows = app(AdminBaleScreens::class)->search('علی کریم')['rows'];
        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($rows, 'id'));
        $this->assertSame('0912', AdminBaleScreens::normalizeSearch('۰۹۱۲'));
    }

    public function test_unsigned_or_foreign_lead_callbacks_cannot_send_customer_acknowledgements(): void
    {
        $this->owner(); $lead = $this->lead();
        $this->mock(SmsDispatcher::class)->shouldNotReceive('event');
        $this->tap('v1:lr:'.$lead->id.':bad');
        $stamp = app(AdminBaleGate::class)->stamp('lr:'.$lead->id);
        $this->tap('v1:lr:'.$lead->id.':'.$stamp, '9999');
        $this->artisan('bale:work')->assertSuccessful();
        $this->assertSame('new', $lead->fresh()->stage);
        $this->assertSame(0, $lead->messages()->where('direction', 'out')->count());
        Mail::assertNothingSent();
    }

    public function test_review_is_durable_and_reports_failed_sms_without_resending(): void
    {
        $owner = $this->owner(); $lead = $this->lead();
        Mail::shouldReceive('raw')->once()->andReturnNull();
        $this->mock(SmsDispatcher::class)->shouldReceive('event')->once()->with('09121234567', 'lead_reviewed', ['code' => 'CHAT-'.$lead->id], \Mockery::type('string'))->andReturnFalse();
        $service = app(BaleLeadReview::class);
        $first = $service->reviewed($lead->id, $owner->id);
        $second = $service->reviewed($lead->id, $owner->id);
        $this->assertSame('reviewed', $lead->fresh()->stage);
        $this->assertEquals(['email' => 'sent', 'sms' => 'failed'], $first['channels']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame(2, $lead->messages()->where('direction', 'out')->count());
        $this->assertDatabaseHas('crm_messages', ['provider_id' => 'bale-reviewed:'.$lead->id.':sms', 'status' => 'failed']);
    }

    public function test_formatted_persian_mobile_is_normalized_and_missing_pattern_is_durably_blocked(): void
    {
        $owner = $this->owner(); $lead = $this->lead();
        $lead->forceFill(['phone' => '۰۹۱۲ ۱۲۳ ۴۵۶۷', 'email' => null])->save();
        Setting::put(BaleLeadReview::PATTERN_SETTING, null);
        $review = app(BaleLeadReview::class)->reviewed($lead->id, $owner->id);
        $this->assertSame('sms_pattern_not_configured', $review['channels']['sms']);
        $this->assertDatabaseHas('crm_messages', ['provider_id' => 'bale-reviewed:'.$lead->id.':sms', 'status' => 'blocked', 'error' => 'sms_pattern_not_configured']);
        Setting::put(BaleLeadReview::PATTERN_SETTING, 'approvedTestCode');
        $this->mock(SmsDispatcher::class)->shouldReceive('event')->once()->with('09121234567', 'lead_reviewed', ['code' => 'CHAT-'.$lead->id], \Mockery::type('string'))->andReturnTrue();
        $sent = app(BaleLeadReview::class)->reviewed($lead->id, $owner->id);
        $this->assertSame('sent', $sent['channels']['sms']);
        $this->assertSame(1, $lead->messages()->where('channel', 'sms')->where('direction', 'out')->count());
        app(BaleLeadReview::class)->reviewed($lead->id, $owner->id);
    }

    public function test_foreign_receipt_amount_is_displayed_in_its_native_currency(): void
    {
        $customer = $this->customer(); $invoice = $this->invoice($customer);
        $invoice->forceFill(['currency_code' => 'EUR', 'total' => 1234])->save();
        $receipt = BankTransferReceipt::create(['customer_id' => $customer->id, 'invoice_id' => $invoice->id, 'amount' => 1234, 'reference' => 'EUR', 'status' => 'pending']);
        $this->assertSame('12.34 EUR', AdminBaleScreens::receiptAmount($receipt));
        $text = app(AdminBaleScreens::class)->receipt($receipt);
        $this->assertStringContainsString('12.34 EUR', $text);
        $this->assertStringNotContainsString('1,234 تومان', $text);
    }

    public function test_configured_review_pattern_is_signed_and_gated_without_changing_other_templates(): void
    {
        $relay = new \App\Services\Sms\BaleRelaySender('synthetic-token', '-100999', 'synthetic-secret', 'https://relay.example.test');
        $this->assertTrue($relay->sendPattern('۰۹۱۲ ۱۲۳ ۴۵۶۷', 'lead_reviewed', ['code' => 'CHAT-42']));
        $wire = null;
        foreach (Http::recorded() as [$request]) {
            $text = $request->data()['text'] ?? '';
            if (str_starts_with($text, 'SMS_RELAY_V1:')) { $wire = $text; }
        }
        $this->assertNotNull($wire);
        [$encoded, $signature] = explode('.', substr($wire, strlen('SMS_RELAY_V1:')), 2);
        $payload = json_decode(base64_decode(strtr($encoded, '-_', '+/')), true);
        $this->assertSame(hash_hmac('sha256', $encoded, 'synthetic-secret'), $signature);
        $this->assertSame('approvedTestCode', $payload['pattern_code']);
        $this->assertSame('+989121234567', $payload['mobile']);
        $this->assertSame(['code' => 'CHAT-42'], $payload['params']);
        Setting::put('bale_manager_policy_v2_enabled', '0');
        $this->assertFalse($relay->sendPattern('09121234567', 'lead_reviewed', ['code' => 'CHAT-42']));
    }

    public function test_review_pattern_configuration_requires_admin_and_enabled_mode(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'web')->post('/admin/bale/review-sms', ['pattern_code' => 'ApprovedPattern42'])->assertSessionHasNoErrors();
        $this->assertSame('ApprovedPattern42', BaleLeadReview::patternCode());
        $this->post('/admin/bale/review-sms', ['pattern_code' => 'bad code'])->assertSessionHasErrors('pattern_code');
        $this->assertSame('ApprovedPattern42', BaleLeadReview::patternCode());
        Setting::put('bale_manager_policy_v2_enabled', '0');
        $this->post('/admin/bale/review-sms', ['pattern_code' => 'ChangedPattern42'])->assertForbidden();
        $this->assertSame('ApprovedPattern42', BaleLeadReview::patternCode());
    }

    public function test_outbound_marketing_lead_cannot_be_treated_as_a_customer_request(): void
    {
        $lead = CrmLead::create(['domain_hash' => hash('sha256', Str::uuid()), 'company' => 'Unrequested', 'email' => 'cold@example.test', 'source' => 'import', 'stage' => 'new']);
        $this->mock(SmsDispatcher::class)->shouldNotReceive('event');
        $owner = $this->owner();
        $this->expectException(\DomainException::class);
        app(BaleLeadReview::class)->reviewed($lead->id, $owner->id);
    }

    public function test_receipt_upload_is_private_and_retained_after_review_and_hash_tampering_is_rejected(): void
    {
        $customer = $this->customer(); $invoice = $this->invoice($customer); $owner = $this->owner();
        $this->actingAs($customer, 'customer')->post('/account/invoices/'.$invoice->id.'/bank-transfer', [
            'reference' => 'BANK-TEST', 'receipt_evidence' => UploadedFile::fake()->image('receipt.png'),
        ])->assertSessionHasNoErrors();
        $receipt = BankTransferReceipt::firstOrFail();
        $this->assertStringStartsWith('bank-receipts/'.$customer->id.'/', $receipt->evidence_path);
        Storage::disk('local')->assertExists($receipt->evidence_path);
        $receipt->forceFill(['status' => 'rejected', 'reject_reason' => 'Wrong reference'])->save();
        $this->assertNotNull(app(BankReceiptEvidence::class)->path($receipt));
        $this->actingAs($owner, 'web')->get('/admin/bank-transfers/'.$receipt->id.'/evidence')->assertOk()->assertDownload('receipt.png');
        Storage::disk('local')->put($receipt->evidence_path, 'tampered');
        $this->assertNull(app(BankReceiptEvidence::class)->path($receipt));
        $this->get('/admin/bank-transfers/'.$receipt->id.'/evidence')->assertNotFound();
    }

    public function test_pending_receipt_never_silently_accepts_an_unstored_replacement_file(): void
    {
        $customer = $this->customer(); $invoice = $this->invoice($customer);
        BankTransferReceipt::create(['customer_id' => $customer->id, 'invoice_id' => $invoice->id, 'amount' => 200000, 'reference' => 'PENDING', 'status' => 'pending']);
        $this->actingAs($customer, 'customer')->post('/account/invoices/'.$invoice->id.'/bank-transfer', [
            'reference' => 'REPLACEMENT', 'receipt_evidence' => UploadedFile::fake()->image('receipt.png'),
        ])->assertSessionHasErrors('receipt_evidence');
        $this->assertDatabaseCount('bank_transfer_receipts', 1);
        $this->assertSame([], Storage::disk('local')->allFiles('bank-receipts'));
    }

    public function test_invalid_receipt_files_and_foreign_invoices_do_not_store_evidence(): void
    {
        $mine = $this->customer(); $other = $this->customer();
        $invoice = $this->invoice($mine); $foreign = $this->invoice($other);
        $this->actingAs($mine, 'customer')->post('/account/invoices/'.$invoice->id.'/bank-transfer', [
            'reference' => 'BAD', 'receipt_evidence' => UploadedFile::fake()->create('evil.html', 2, 'text/html'),
        ])->assertSessionHasErrors('receipt_evidence');
        $this->post('/account/invoices/'.$foreign->id.'/bank-transfer', [
            'reference' => 'FOREIGN', 'receipt_evidence' => UploadedFile::fake()->image('receipt.png'),
        ])->assertNotFound();
        $this->assertDatabaseCount('bank_transfer_receipts', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('bank-receipts'));
    }

    public function test_customer_cannot_download_manager_receipt_evidence(): void
    {
        $customer = $this->customer(); $invoice = $this->invoice($customer);
        $fields = app(BankReceiptEvidence::class)->store(UploadedFile::fake()->image('receipt.png'), $customer->id);
        $receipt = BankTransferReceipt::create(['customer_id' => $customer->id, 'invoice_id' => $invoice->id, 'amount' => 200000, 'reference' => 'PRIVATE', 'status' => 'pending'] + $fields);
        $this->actingAs($customer, 'customer')->get('/admin/bank-transfers/'.$receipt->id.'/evidence')->assertRedirect();
    }

    public function test_replacing_kyc_keeps_original_file_and_review_does_not_rewrite_archived_evidence(): void
    {
        $customer = $this->customer();
        $data = ['type' => 'individual', 'first_name' => 'Mehmet', 'last_name' => 'Yilmaz', 'birth_date' => '1990-04-12',
            'country' => 'TR', 'address' => 'Bagdat Cd 1', 'city' => 'Istanbul', 'id_type' => 'passport'];
        $this->actingAs($customer, 'customer')->post('/en/account/verify', $data + [
            'doc_passport' => UploadedFile::fake()->image('passport.jpg'), 'doc_selfie' => UploadedFile::fake()->image('selfie.jpg'),
            'doc_address' => UploadedFile::fake()->image('address.png'),
        ])->assertSessionHasNoErrors();
        $profile = CustomerProfile::where('customer_id', $customer->id)->firstOrFail();
        $old = CustomerDocument::where('customer_profile_id', $profile->id)->where('kind', 'passport')->firstOrFail();
        $this->post('/en/account/verify', $data + ['doc_passport' => UploadedFile::fake()->image('new-passport.jpg')])->assertSessionHasNoErrors();
        $this->assertSame('superseded', $old->fresh()->status);
        Storage::disk('local')->assertExists($old->disk_path);
        $this->assertCount(3, $profile->fresh()->documents);
        app(\App\Services\Customer\KycReview::class)->approve($profile->fresh(), $this->owner()->id);
        $this->assertSame('superseded', $old->fresh()->status);
    }

    public function test_refund_approval_uses_existing_review_without_payout_and_rejects_changed_snapshot(): void
    {
        $owner = $this->owner(); $customer = $this->customer();
        $bank = BankAccount::create(['customer_id' => $customer->id, 'iban' => 'IR880100000000000000000001',
            'card_bin' => '603799', 'card_last4' => '1234', 'owner_name' => 'Test Customer', 'name_matched' => true, 'status' => 'verified', 'verified_at' => now()]);
        $wallet = app(Wallet::class); $wallet->credit($customer->id, 'IRT', 100000, 'topup', null);
        $service = app(WalletRefunds::class); $service->permission($customer, true, 'Approved special refund', $owner);
        $refund = $service->request($customer, (string) Str::uuid(), 40000, $bank->id, 'Unused wallet balance');
        $fingerprint = BaleRefundReview::fingerprint($refund);
        $worker = app(AdminBaleWorker::class);
        $context = ['manager_actor_id' => $owner->id, 'manager_binding_hash' => hash('sha256', (string) Setting::get(AdminBaleGate::KEY_BIND))];
        $worker->run(['verb' => 'refund_approve', 'args' => ['id' => $refund->id, 'note' => 'Reviewed original source and unused funds', 'fingerprint' => 'changed'] + $context]);
        $this->assertSame('requested', $refund->fresh()->status);
        $worker->run(['verb' => 'refund_approve', 'args' => ['id' => $refund->id, 'note' => 'Reviewed original source and unused funds', 'fingerprint' => $fingerprint] + $context]);
        $this->assertSame('approved', $refund->fresh()->status);
        $this->assertNull($refund->fresh()->paid_at);
        $this->assertSame(100000, $wallet->balanceOf($customer->id));
        $this->assertSame(40000, $wallet->reservedOf($customer->id));
    }

    public function test_queued_customer_acknowledgement_is_invalidated_by_owner_rebinding(): void
    {
        $owner = $this->owner(); $lead = $this->lead();
        $context = ['manager_actor_id' => $owner->id, 'manager_binding_hash' => hash('sha256', (string) Setting::get(AdminBaleGate::KEY_BIND))];
        Setting::putSecret(AdminBaleGate::KEY_BIND, json_encode(['chat_id' => '888888', 'user_id' => $owner->id, 'at' => now()->toIso8601String()]));
        $this->mock(SmsDispatcher::class)->shouldNotReceive('event');
        app(AdminBaleWorker::class)->run(['verb' => 'lead_reviewed', 'args' => ['id' => $lead->id] + $context]);
        $this->assertSame('new', $lead->fresh()->stage);
        $this->assertSame(0, $lead->messages()->where('direction', 'out')->count());
        Mail::assertNothingSent();
    }

    public function test_private_history_filters_customers_and_renders_all_three_locales(): void
    {
        $owner = $this->owner(); $customer = $this->customer(); $other = $this->customer();
        $documentFor = function (Customer $person, string $name): array {
            $profile = CustomerProfile::create(['customer_id' => $person->id, 'type' => 'individual',
                'email' => $person->email, 'mobile' => $person->phone, 'address' => 'Test address',
                'first_name' => 'Test', 'last_name' => 'Customer', 'status' => 'verified']);
            $document = CustomerDocument::create(['customer_profile_id' => $profile->id, 'kind' => 'passport',
                'status' => 'superseded', 'scan_status' => 'skipped', 'disk_path' => 'kyc/'.$person->id.'/'.$name,
                'original_name' => $name, 'mime' => 'application/pdf', 'size_bytes' => 100,
                'sha256' => str_repeat('a', 64), 'uploaded_at' => now()]);
            return [$profile, $document];
        };
        [$profile, $document] = $documentFor($customer, 'own-archived-passport.pdf');
        $documentFor($other, 'other-customer-private-passport.pdf');
        $response = $this->actingAs($owner, 'web')->get('/admin/customers/'.$customer->id.'/evidence')
            ->assertOk()->assertViewIs('admin.customer-evidence')
            ->assertSee('own-archived-passport.pdf')->assertDontSee('other-customer-private-passport.pdf');
        $data = $response->original->getData();
        $this->assertSame(1, $data['documents']->total());
        $profile->setRelation('dossierDocuments', collect([$document]));
        foreach (['fa', 'en', 'tr'] as $locale) {
            app()->setLocale($locale);
            $text = require lang_path($locale.'/bale-evidence.php');
            $history = view('admin.customer-evidence', $data)->render();
            $summary = view('admin.partials.customer-evidence', ['c' => $customer,
                'managerEvidenceProfiles' => collect([$profile]), 'managerEvidenceReceipts' => collect()])->render();
            $this->assertStringContainsString($text['identity_history'], $history);
            $this->assertStringContainsString($text['status_superseded'], $history);
            $this->assertStringContainsString($text['retention_hint'], $summary);
            $this->assertStringContainsString($text['status_superseded'], $summary);
        }
        Setting::put('bale_manager_policy_v2_enabled', '0');
        $this->get('/admin/customers/'.$customer->id.'/evidence')->assertForbidden();
    }

    public function test_disabled_mode_does_not_enable_new_manager_callbacks_or_acknowledgements(): void
    {
        $owner = $this->owner(); $lead = $this->lead(); Setting::put('bale_manager_policy_v2_enabled', '0');
        $stamp = app(AdminBaleGate::class)->stamp('lr:'.$lead->id);
        $this->tap('v1:lr:'.$lead->id.':'.$stamp);
        $this->artisan('bale:work')->assertSuccessful();
        $this->assertSame('disabled', app(BaleLeadReview::class)->reviewed($lead->id, $owner->id)['status']);
        $this->assertSame('new', $lead->fresh()->stage);
        $this->assertSame(0, $lead->messages()->where('direction', 'out')->count());
    }
}
