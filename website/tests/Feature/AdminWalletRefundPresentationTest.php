<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FinanceAccount;
use App\Models\FinanceDocument;
use App\Models\Payment;
use App\Models\User;
use App\Models\WalletRefundRequest;
use App\Services\Finance\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminWalletRefundPresentationTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;
    private FinanceAccount $company;
    private FinanceAccount $partner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 31)->setTime(10, 0));
        Wallet::flushSchemaCache();
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        $admin = User::create([
            'name' => 'Synthetic refund reviewer', 'email' => 'admin-refund-ui@example.test',
            'role' => 'admin', 'password' => bcrypt('synthetic-password'),
        ]);
        $this->customer = Customer::create(['email' => 'admin-refund-customer@example.test', 'status' => 'active', 'locale' => 'fa']);
        $this->company = (new FinanceAccount)->forceFill([
            'id' => 501, 'name' => 'Synthetic company bank', 'type' => 'bank',
            'currency_code' => 'IRT', 'owner_name' => 'Synthetic company',
        ]);
        $this->partner = (new FinanceAccount)->forceFill([
            'id' => 502, 'name' => 'Synthetic personal bank', 'type' => 'partner',
            'currency_code' => 'IRR', 'owner_name' => str_repeat('Synthetic partner owner ', 8).'<script>owner-xss</script>',
        ]);
        $this->actingAs($admin, 'web');
        $this->withSession(['_token' => 'synthetic-admin-refund-ui-csrf']);
        $this->app['request']->setLaravelSession($this->app['session']->driver());
    }

    public static function locales(): array
    {
        return [['fa'], ['en'], ['tr']];
    }

    #[DataProvider('locales')]
    public function test_real_admin_view_preserves_decision_payout_and_evidence_contracts(string $locale): void
    {
        app()->setLocale($locale);
        foreach (['requested', 'approved', 'paid', 'rejected', 'cancelled', 'legacy'] as $state) {
            session()->forget('_old_input');
            $before = $this->financialSnapshot();
            [$html, $refund] = $this->renderState($state);
            $xpath = $this->xpath($html);
            $root = $xpath->query('//*[@data-refund-status]')->item(0);
            $this->assertNotNull($root, $state);
            $this->assertSame($refund->status, $root->getAttribute('data-refund-status'));
            $this->assertStringContainsString('assets/css/admin-wallet-refund.css', $html);
            $this->assertStringContainsString('assets/css/site-select.css', $html);
            $this->assertStringContainsString('assets/js/site-select.js', $html);
            $this->assertDoesNotMatchRegularExpression('/\\brefund\\.[a-z_]+/', $root->textContent);
            $this->assertStringContainsString(__('refund.status_'.$refund->status), $root->textContent);
            $this->assertStringContainsString(__('refund.toman'), $root->textContent);
            $this->assertStringNotContainsString('<script>note-xss</script>', $html);
            $this->assertStringNotContainsString('<script>owner-xss</script>', $html);
            $this->assertStringNotContainsString('<script>audit-xss</script>', $html);
            $this->assertStringContainsString('&lt;script&gt;note-xss&lt;/script&gt;', $html);

            foreach (['approve' => 'requested', 'paid' => 'approved'] as $action => $requiredState) {
                $forms = $xpath->query('.//form[@data-refund-form="'.$action.'"]', $root);
                $this->assertSame($state === $requiredState ? 1 : 0, $forms->length, $state.' '.$action);
                if ($forms->length) {
                    $this->assertSame(route('admin.wallet-refunds.'.$action, $refund), $forms->item(0)->getAttribute('action'));
                }
            }
            $reject = $xpath->query('.//form[@data-refund-form="reject"]', $root);
            $this->assertSame(in_array($refund->status, WalletRefundRequest::OPEN, true) ? 1 : 0, $reject->length);

            if ($state === 'requested') {
                $form = $xpath->query('.//form[@data-refund-form="approve"]', $root)->item(0);
                $this->assertSame(1, $xpath->query('.//textarea[@name="note" and @required and @minlength="5" and @maxlength="2000"]', $form)->length);
                $this->assertSame(1, $xpath->query('.//textarea[@name="source_note" and @required and @minlength="10" and @maxlength="2000"]', $form)->length);
                $this->assertSame(1, $xpath->query('.//select[@name="source_payment_id" and not(@required)]', $form)->length);
                $this->assertStringContainsString(__('refund.source_payment'), $form->textContent);
            }
            if ($state === 'approved') {
                $form = $xpath->query('.//form[@data-refund-form="paid"]', $root)->item(0);
                $this->assertSame('multipart/form-data', $form->getAttribute('enctype'));
                $this->assertTrue($form->hasAttribute('data-confirm-danger'));
                $this->assertNotSame('', $form->getAttribute('data-confirm'));
                $this->assertSame(1, $xpath->query('.//select[@name="funding_account_id" and @required]', $form)->length);
                foreach (['bank', 'partner'] as $type) {
                    $id = $type === 'bank' ? $this->company->id : $this->partner->id;
                    $this->assertSame(1, $xpath->query('.//select[@name="funding_account_id"]/optgroup/option[@value="'.$id.'"]', $form)->length);
                }
                $this->assertSame(2, $xpath->query('.//select[@name="funding_account_id"]/optgroup', $form)->length);
                $this->assertSame(1, $xpath->query('.//input[@name="payout_date" and @required]', $form)->length);
                $this->assertSame(1, $xpath->query('.//input[@name="bank_reference" and @required and @minlength="4" and @maxlength="150" and @dir="ltr"]', $form)->length);
                $this->assertSame(1, $xpath->query('.//input[@name="evidence" and @type="file" and @required]', $form)->length);
                $this->assertSame(1, $xpath->query('.//input[@name="transferred" and @type="checkbox" and @required and @value="1"]', $form)->length);
                $this->assertStringContainsString(__('refund.no_transfer_api'), $root->textContent);
            }
            if (in_array($state, ['paid', 'legacy'], true)) {
                $this->assertSame(1, $xpath->query('.//*[@data-funding-evidence="'.($state === 'paid' ? 'snapshot' : 'legacy').'"]', $root)->length);
                $this->assertSame(1, $xpath->query('.//a[@href="'.route('admin.accounting.voucher', $refund->voucher_id).'"]', $root)->length);
                $this->assertSame(1, $xpath->query('.//a[@href="'.route('admin.accounting.download', $refund->document_id).'"]', $root)->length);
                if ($state === 'paid') {
                    $this->assertStringContainsString('Synthetic original paying bank', $root->textContent);
                    $this->assertStringContainsString('Synthetic original payer', $root->textContent);
                    $this->assertStringNotContainsString('Synthetic renamed live account', $root->textContent);
                    $this->assertStringContainsString(str_repeat('a', 64), $root->textContent);
                }
            }

            foreach ($xpath->query('.//form', $root) as $form) {
                $this->assertSame('post', strtolower($form->getAttribute('method')));
                $this->assertSame(1, $xpath->query('.//input[@name="_token" and @value="synthetic-admin-refund-ui-csrf"]', $form)->length);
            }
            foreach ($xpath->query('.//input[not(@type="hidden")] | .//select | .//textarea', $root) as $control) {
                $this->assertNotSame('', $control->getAttribute('id'), $control->getAttribute('name'));
                $this->assertSame(1, $xpath->query('.//label[@for="'.$control->getAttribute('id').'"]', $root)->length, $control->getAttribute('name'));
            }

            $this->assertSame($before, $this->financialSnapshot(), 'Rendering must not perform a refund or accounting action.');
            Http::assertNothingSent();
            Mail::assertNothingSent();
            $this->capture($locale, $state, $html);
        }
    }

    #[DataProvider('locales')]
    public function test_payout_validation_keeps_values_and_selected_registered_partner(string $locale): void
    {
        app()->setLocale($locale);
        session()->put('_old_input', [
            '_refund_form' => 'paid', 'funding_account_id' => $this->partner->id, 'payout_date' => '2026-10-30',
            'bank_reference' => 'SYNTHETIC-REF-77', 'bank_transaction_id' => '91', 'transferred' => '1',
            'note' => 'Synthetic rejection note <script>old-xss</script>', 'not_transferred' => '1',
        ]);
        $errors = new ViewErrorBag;
        $errors->put('default', new MessageBag(['evidence' => __('refund.invalid_receipt')]));
        [$html] = $this->renderState('approved', $errors);
        $xpath = $this->xpath($html);
        $root = $xpath->query('//*[@data-refund-status]')->item(0);
        $this->assertGreaterThanOrEqual(1, $xpath->query('.//*[@role="alert"]', $root)->length);
        $this->assertStringContainsString(__('refund.invalid_receipt'), $root->textContent);
        $this->assertSame((string) $this->partner->id, $xpath->query('.//select[@name="funding_account_id"]/optgroup/option[@selected]', $root)->item(0)?->getAttribute('value'));
        $this->assertSame('SYNTHETIC-REF-77', $xpath->query('.//input[@name="bank_reference"]', $root)->item(0)->getAttribute('value'));
        $this->assertSame('91', $xpath->query('.//input[@name="bank_transaction_id"]', $root)->item(0)->getAttribute('value'));
        $this->assertSame(1, $xpath->query('.//input[@name="transferred" and @checked]', $root)->length);
        $this->assertStringNotContainsString('<script>old-xss</script>', $html);
        $this->capture($locale, 'validation', $html);

        session()->put('_old_input', [
            '_refund_form' => 'approve', 'source_payment_id' => 801,
            'note' => 'Synthetic approval note <script>approval-old-xss</script>',
            'source_note' => 'Synthetic customer payment provenance retained after validation',
        ]);
        [$approvalHtml] = $this->renderState('requested', $errors);
        $approvalXpath = $this->xpath($approvalHtml);
        $approval = $approvalXpath->query('//form[@data-refund-form="approve"]')->item(0);
        $this->assertNotNull($approval);
        $this->assertSame('801', $approvalXpath->query('.//select[@name="source_payment_id"]//option[@selected]', $approval)->item(0)?->getAttribute('value'));
        $this->assertStringContainsString('Synthetic approval note <script>approval-old-xss</script>', $approvalXpath->query('.//textarea[@name="note"]', $approval)->item(0)->textContent);
        $this->assertStringContainsString('Synthetic customer payment provenance retained after validation', $approvalXpath->query('.//textarea[@name="source_note"]', $approval)->item(0)->textContent);
        $this->assertStringNotContainsString('<script>approval-old-xss</script>', $approvalHtml);
    }

    private function renderState(string $state, ?ViewErrorBag $errors = null): array
    {
        $status = $state === 'legacy' ? 'paid' : $state;
        $refund = (new WalletRefundRequest)->forceFill([
            'id' => 42, 'public_id' => '30000000-0000-4000-8000-000000000042',
            'customer_id' => $this->customer->id, 'amount_irt' => 40000, 'status' => $status,
            'bank_snapshot' => ['iban' => 'IR880100000000000000000001', 'owner_name' => str_repeat('Synthetic destination owner ', 8), 'bank_name' => 'Synthetic destination bank'],
            'customer_note' => str_repeat('Synthetic customer explanation ', 15).'<script>note-xss</script>',
            'review_note' => 'Synthetic review decision', 'source_note' => 'Synthetic incoming payment evidence reviewed',
            'created_at' => now()->subDays(2), 'reviewed_at' => $status !== 'requested' ? now()->subDay() : null,
            'payout_date' => $status === 'paid' ? now()->toDateString() : null,
            'paid_at' => $status === 'paid' ? now() : null, 'bank_reference' => 'SYNTHETIC-'.str_repeat('LONG-REFERENCE-', 10),
            'voucher_id' => $status === 'paid' ? 21 : null, 'document_id' => $status === 'paid' ? 22 : null,
            'source_payment_id' => 801, 'reviewed_by' => 1, 'paid_by' => $status === 'paid' ? 1 : null,
        ])->setRelation('customer', $this->customer);
        $snapshot = ['id' => 502, 'name' => 'Synthetic original paying bank', 'type' => 'partner', 'currency_code' => 'IRR', 'owner_name' => 'Synthetic original payer'];
        $paidEvent = $status === 'paid' ? (object) [
            'id' => 2, 'action' => 'paid', 'actor_name' => 'Synthetic payout recorder', 'created_at' => now(),
            'details' => json_encode(['funding_account_id' => 502, 'bank_reference' => $refund->bank_reference, 'document_sha256' => str_repeat('a', 64)]
                + ($state === 'legacy' ? [] : ['funding_account_snapshot' => $snapshot]), JSON_THROW_ON_ERROR),
        ] : null;
        $events = collect([(object) [
            'id' => 1, 'action' => 'requested', 'actor_name' => 'Synthetic customer', 'created_at' => now()->subDays(2),
            'details' => json_encode(['note' => 'Synthetic audit <script>audit-xss</script>'], JSON_THROW_ON_ERROR),
        ]]);
        if ($paidEvent) {
            $events->push($paidEvent);
        }
        $receipt = (new FinanceDocument)->forceFill([
            'id' => 22, 'voucher_id' => 21, 'name' => 'synthetic-receipt.pdf', 'mime' => 'application/pdf',
            'size' => 1024, 'sha256' => str_repeat('a', 64),
        ]);
        $liveAccount = (new FinanceAccount)->forceFill(['id' => 502, 'name' => 'Synthetic renamed live account', 'type' => 'partner', 'currency_code' => 'IRR', 'owner_name' => 'Synthetic current owner']);
        return [view('admin.wallet-refund', [
            'refund' => $refund, 'events' => $events, 'accounts' => collect([$this->company, $this->partner]),
            'payments' => collect([(new Payment)->forceFill(['id' => 801, 'amount' => 90000, 'currency_code' => 'IRT', 'gateway' => 'mellat'])]),
            'fundingSnapshot' => $status === 'paid' && $state !== 'legacy' ? $snapshot : null,
            'fundingAccount' => $status === 'paid' ? $liveAccount : null,
            'fundingLegacyFallback' => $state === 'legacy', 'paidEvent' => $paidEvent,
            'receiptDocument' => $status === 'paid' ? $receipt : null, 'errors' => $errors ?? new ViewErrorBag,
        ])->render(), $refund];
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        return new \DOMXPath($dom);
    }

    private function financialSnapshot(): array
    {
        return collect(['credit_ledger', 'business_entries', 'wallet_refund_requests', 'wallet_refund_events', 'finance_vouchers', 'finance_lines', 'finance_documents'])
            ->filter(fn ($table) => Schema::hasTable($table))
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    private function capture(string $locale, string $state, string $html): void
    {
        $directory = getenv('ADMIN_WALLET_REFUND_UI_FIXTURES');
        if (!$directory) {
            return;
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create synthetic admin fixture directory');
        }
        if (file_put_contents($directory.'/'.$locale.'-'.$state.'.html', $html) === false) {
            throw new \RuntimeException('Cannot write synthetic admin fixture');
        }
    }
}
