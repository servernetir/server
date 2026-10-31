<?php

namespace Tests\Feature;

use App\Http\Controllers\Account\AccountController;
use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\WalletRefundRequest;
use App\Services\Finance\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WalletRefundPresentationTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;
    private BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 31)->setTime(10, 0));
        Wallet::flushSchemaCache();
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();

        $this->customer = Customer::create([
            'email' => 'refund-ui@example.test',
            'status' => 'active',
            'locale' => 'fa',
        ]);
        $this->bank = BankAccount::create([
            'customer_id' => $this->customer->id,
            'iban' => 'IR880100000000000000000001',
            'card_bin' => '603799',
            'card_last4' => '1234',
            'owner_name' => str_repeat('Synthetic account owner ', 8).'<script>owner-xss</script>',
            'status' => 'verified',
            'name_matched' => true,
            'verified_at' => now(),
        ]);
        $this->actingAs($this->customer, 'customer');
        $this->withSession(['_token' => 'synthetic-refund-ui-csrf']);
        $this->app['request']->setLaravelSession($this->app['session']->driver());
    }

    public static function locales(): array
    {
        return [['fa'], ['en'], ['tr']];
    }

    #[DataProvider('locales')]
    public function test_real_view_preserves_refund_contracts_in_every_display_state(string $locale): void
    {
        app()->setLocale($locale);

        foreach (['disabled', 'pending', 'no-bank', 'form', 'history', 'validation', 'empty'] as $state) {
            $before = $this->financialSnapshot();
            [$html, $rows] = $this->renderState($locale, $state);
            $xpath = $this->xpath($html);
            $root = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," wr-workspace ")]')->item(0);
            $this->assertNotNull($root, $state);
            $this->assertStringContainsString('assets/css/wallet-refunds.css', $html);
            $this->assertDoesNotMatchRegularExpression('/\brefund\.[a-z_]+/', $root->textContent);
            $this->assertStringContainsString(__('refund.toman'), $root->textContent);
            $this->assertStringNotContainsString('€', $root->textContent);
            $this->assertStringContainsString('1,234,567', $root->textContent);
            $this->assertStringNotContainsString('-25,000', $root->textContent);
            $this->assertStringNotContainsString('<script>owner-xss</script>', $html);
            $this->assertStringNotContainsString('<script>review-xss</script>', $html);

            $form = $xpath->query('.//form[@action="'.lroute('account.wallet-refunds.store').'"]', $root)->item(0);
            $hasForm = in_array($state, ['form', 'validation', 'empty'], true);
            $this->assertSame($hasForm, $form !== null, $state);
            if ($hasForm) {
                $this->assertSame('post', strtolower($form->getAttribute('method')));
                $this->assertSame(1, $xpath->query('.//input[@name="amount_irt" and @required and @maxlength="24" and @inputmode="numeric" and @dir="ltr"]', $form)->length);
                $this->assertSame(1, $xpath->query('.//select[@name="bank_account_id" and @required]', $form)->length);
                $this->assertSame(1, $xpath->query('.//textarea[@name="note" and @required and @minlength="5" and @maxlength="2000"]', $form)->length);
                $this->assertSame(1, $xpath->query('.//input[@name="acknowledge" and @type="checkbox" and @value="1" and @required]', $form)->length);
                $key = $xpath->query('.//input[@name="idempotency_key"]', $form)->item(0);
                $this->assertNotNull($key);
                $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $key->getAttribute('value'));
            }

            $expectedState = match ($state) {
                'disabled', 'history' => 'disabled',
                'pending' => 'pending',
                'no-bank' => 'no-bank',
                default => 'form',
            };
            $this->assertSame(1, $xpath->query('.//*[@data-refund-state="'.$expectedState.'"]', $root)->length);
            if ($state === 'history') {
                // Synthetic display coverage, not a financial state transition.
                foreach ($rows as $row) {
                    $card = $xpath->query('.//*[@data-status="'.$row->status.'"]', $root)->item(0);
                    $this->assertNotNull($card, $row->status);
                    $this->assertStringContainsString(__('refund.status_'.$row->status), $card->textContent);
                    $cancel = $xpath->query('.//form[@action="'.lroute('account.wallet-refunds.cancel', $row).'"]', $card);
                    $receipt = $xpath->query('.//a[@href="'.lroute('account.wallet-refunds.receipt', $row).'"]', $card);
                    $this->assertSame($row->status === 'requested' ? 1 : 0, $cancel->length);
                    $this->assertSame($row->status === 'paid' ? 1 : 0, $receipt->length);
                }
                $this->assertStringContainsString('&lt;script&gt;review-xss&lt;/script&gt;', $html);
            }
            if ($state === 'validation') {
                $this->assertNotNull($xpath->query('.//*[@role="alert"]', $root)->item(0));
                $this->assertStringContainsString(__('refund.invalid_amount'), $root->textContent);
                $this->assertSame('۴۰۰۰۰', $xpath->query('.//input[@name="amount_irt"]', $form)->item(0)->getAttribute('value'));
                $this->assertSame('10000000-0000-4000-8000-000000000001', $xpath->query('.//input[@name="idempotency_key"]', $form)->item(0)->getAttribute('value'));
                $this->assertSame((string) $this->bank->id, $xpath->query('.//select[@name="bank_account_id"]/option[@selected]', $form)->item(0)?->getAttribute('value'));
                $this->assertStringContainsString('Synthetic request <script>note-xss</script>', $xpath->query('.//textarea[@name="note"]', $form)->item(0)->textContent);
                $this->assertStringNotContainsString('<script>note-xss</script>', $html);
            }
            if ($state === 'empty') {
                $this->assertSame(0, $xpath->query('.//*[@data-status]', $root)->length);
                $this->assertStringContainsString(__('refund.empty'), $root->textContent);
            }

            foreach ($xpath->query('.//form[translate(@method,"POST","post")="post"]', $root) as $post) {
                $token = $xpath->query('.//input[@name="_token"]', $post)->item(0);
                $this->assertNotNull($token);
                $this->assertNotSame('', $token->getAttribute('value'));
            }
            foreach ($xpath->query('.//input[not(@type="hidden")] | .//select | .//textarea', $root) as $control) {
                $this->assertNotSame('', $control->getAttribute('id'));
                $this->assertSame(1, $xpath->query('.//label[@for="'.$control->getAttribute('id').'"]', $root)->length);
            }

            $this->assertSame($before, $this->financialSnapshot());
            Http::assertNothingSent();
            Mail::assertNothingSent();
            $this->capture($locale, $state, $html);
        }
    }

    public function test_history_keeps_pagination_and_escapes_long_customer_visible_notes(): void
    {
        app()->setLocale('en');
        $items = collect(range(1, 20))->map(fn ($i) => $this->row('cancelled', $i));
        $data = $this->data(true, false, $items->all());
        $data['refunds'] = new LengthAwarePaginator($items, 21, 20, 1, [
            'path' => lroute('account.wallet-refunds'),
        ]);
        $before = $this->financialSnapshot();
        $html = view('account.wallet-refunds', AccountController::shell('wallet-refunds') + $data)->render();
        $this->assertSame(20, $this->xpath($html)->query('//*[@data-status="cancelled"]')->length);
        $this->assertStringContainsString('page=2', $html);
        $this->assertStringNotContainsString('<script>review-xss</script>', $html);
        $this->assertSame($before, $this->financialSnapshot());
        Http::assertNothingSent();
    }

    private function renderState(string $locale, string $state): array
    {
        session()->forget(['_old_input', 'ok']);
        $errors = new ViewErrorBag;
        if ($state === 'validation') {
            session()->put('_old_input', [
                'idempotency_key' => '10000000-0000-4000-8000-000000000001',
                'amount_irt' => '۴۰۰۰۰',
                'bank_account_id' => $this->bank->id,
                'note' => 'Synthetic request <script>note-xss</script>',
                'acknowledge' => '1',
            ]);
            $errors->put('default', new MessageBag(['amount_irt' => __('refund.invalid_amount')]));
        }
        if ($state === 'form') {
            session()->put('ok', __('refund.cancelled_saved'));
        }

        $rows = match ($state) {
            'history' => collect(['requested', 'approved', 'paid', 'rejected', 'cancelled'])
                ->map(fn ($status, $i) => $this->row($status, $i + 1))->all(),
            'disabled', 'form', 'validation' => [$this->row('cancelled', 1)],
            'pending' => [$this->row('requested', 1)],
            default => [],
        };
        $enabled = !in_array($state, ['disabled', 'history'], true);
        $data = $this->data($enabled, $state === 'pending', $rows);
        $data['errors'] = $errors;
        if ($state === 'history') {
            $data['pending'] = $rows[0]; // Disabled state must still take precedence.
        }
        if ($state === 'no-bank') {
            $data['banks'] = collect();
        }
        if ($state === 'disabled') {
            $data['available'] = -25000;
        }

        return [
            view('account.wallet-refunds', AccountController::shell('wallet-refunds') + $data)->render(),
            $rows,
        ];
    }

    private function data(bool $enabled, bool $pending, array $rows): array
    {
        return [
            'balance' => 1234567,
            'available' => 765432,
            'enabled' => $enabled,
            'pending' => $pending ? $rows[0] : null,
            'banks' => collect([$this->bank]),
            'refunds' => new LengthAwarePaginator(collect($rows), count($rows), 20, 1, [
                'path' => lroute('account.wallet-refunds'),
            ]),
            'errors' => new ViewErrorBag,
        ];
    }

    private function row(string $status, int $index): WalletRefundRequest
    {
        return (new WalletRefundRequest)->forceFill([
            'id' => $index,
            'customer_id' => $this->customer->id,
            'public_id' => '20000000-0000-4000-8000-'.sprintf('%012d', $index),
            'amount_irt' => 40000,
            'status' => $status,
            'bank_snapshot' => ['iban' => $this->bank->iban],
            'review_note' => str_repeat('Synthetic review note ', 15).'<script>review-xss</script>',
            'bank_reference' => 'BANK-'.str_repeat('LONG-REFERENCE-', 12),
            'created_at' => now()->subDay(),
            'payout_date' => $status === 'paid' ? now()->toDateString() : null,
        ]);
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        return new \DOMXPath($dom);
    }

    private function financialSnapshot(): array
    {
        return collect([
            'credit_ledger', 'business_entries', 'wallet_refund_requests',
            'wallet_refund_events', 'finance_vouchers', 'finance_lines', 'finance_documents',
        ])->filter(fn ($table) => Schema::hasTable($table))
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])
            ->all();
    }

    private function capture(string $locale, string $state, string $html): void
    {
        $directory = getenv('WALLET_REFUND_UI_FIXTURES');
        if (!$directory) {
            return;
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create synthetic fixture directory');
        }
        if (file_put_contents($directory.'/'.$locale.'-'.$state.'.html', $html) === false) {
            throw new \RuntimeException('Cannot write synthetic fixture');
        }
    }
}
