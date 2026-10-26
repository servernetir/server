<?php
// Production acceptance: synthetic renders and rejected writes only; all SQL writes blocked.
chdir($argv[1]);
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Http::fake(fn () => Illuminate\Support\Facades\Http::response([], 503));
Illuminate\Support\Facades\View::share('errors', new Illuminate\Support\ViewErrorBag);
$writes = 0;
Illuminate\Support\Facades\DB::connection()->beforeExecuting(function ($sql) use (&$writes) {
    if (preg_match('/^\s*(select|show|pragma)\b/i', $sql)) return;
    $writes++;
    throw new RuntimeException('Unexpected SQL write blocked');
});
$checks = 0;
function releaseCheck($ok, $why) { global $checks; if (! $ok) throw new RuntimeException($why); $checks++; }
$defaults = App\Models\Setting::cached();
$customer = new App\Models\Customer(['code' => 'QA-INVOICE-ONLY', 'email' => 'invoice-only@example.test', 'status' => 'active', 'locale' => 'fa']);
$customer->id = 0;
Illuminate\Support\Facades\Auth::guard('customer')->setUser($customer);
$invoice = new App\Models\Invoice(['customer_id' => 0, 'number' => 'QA-INVOICE-ONLY', 'kind' => 'service', 'currency_code' => 'IRT', 'status' => 'unpaid', 'subtotal' => 10000, 'tax' => 1000, 'total' => 11000, 'paid' => 0, 'issued_at' => now()]);
$invoice->id = 0;
$invoice->setRelation('items', collect([new App\Models\InvoiceItem(['title' => 'Synthetic service', 'quantity' => 1, 'unit_price' => 10000, 'line_total' => 10000, 'tax_amount' => 1000])]));
$invoice->setRelation('payments', collect());
$matrix = [];
foreach (['0', '1'] as $global) {
    $app->instance('settings.request-memo', array_replace($defaults, ['invoice_discount_entry_enabled' => $global]));
    foreach (['inherit', 'enabled', 'disabled'] as $mode) {
        $customer->forceFill(['discount_entry_visibility' => $mode]);
        foreach (['fa', 'en', 'tr'] as $locale) {
            $app->setLocale($locale);
            $shell = App\Http\Controllers\Account\AccountController::shell('invoices');
            releaseCheck(! collect($shell['pnlNav'])->flatMap(fn ($group) => $group['items'])->contains('key', 'offers'), 'Offers menu remains');
            $html = view('account.invoice', $shell + ['invoice' => $invoice, 'creditBalance' => 0, 'giftCoupon' => null, 'gateways' => [], 'snapppay' => ['show' => false], 'bank' => null, 'pendingBank' => null, 'offline' => collect(), 'cryptoAssets' => [], 'cryptoOpen' => null])->render();
            $visible = $mode === 'enabled' || ($mode === 'inherit' && $global === '1');
            releaseCheck(str_contains($html, 'data-discount-entry') === $visible, 'Invoice access matrix failed');
            releaseCheck(! str_contains($html, '/account/offers') && ! str_contains($html, 'name="birth_'), 'Retired links or fields remain');
            $matrix[] = compact('global', 'mode', 'locale', 'visible');
        }
    }
}
$app->instance('settings.request-memo', $defaults);
$app->setLocale('fa');
$customer->forceFill(['discount_entry_visibility' => 'disabled']);
$controller = app(App\Http\Controllers\Account\OfferController::class);
releaseCheck($controller->index()->getTargetUrl() === lroute('account.marketing.unsubscribe'), 'Legacy opt-out redirect failed');
$unsubscribe = $controller->unsubscribePage()->render();
releaseCheck(! str_contains($unsubscribe, 'name="birth_') && ! str_contains($unsubscribe, 'name="code"'), 'Opt-out page exposes form fields');
releaseCheck(! view()->exists('account.offers'), 'Retired view still installed');
$request = Illuminate\Http\Request::create('/account/offers/preferences', 'POST', ['consent' => 'granted', 'birth_month' => 10]);
foreach (['retired' => fn () => $controller->preferences($request), 'campaign' => fn () => $controller->apply($request, $invoice, app(App\Services\Marketing\DiscountEngine::class)), 'gift' => fn () => app(App\Http\Controllers\Account\PaymentController::class)->applyCoupon($request, $invoice)] as $kind => $action) {
    try { $action(); throw new RuntimeException('Expected rejection missing'); }
    catch (Symfony\Component\HttpKernel\Exception\HttpException $exception) { releaseCheck($exception->getStatusCode() === ($kind === 'retired' ? 410 : 403), 'Wrong access rejection'); }
}
releaseCheck(! app(App\Services\Marketing\CampaignAudience::class)->birthday($customer, now()), 'Missing profile should not match birthday');
releaseCheck($writes === 0, 'Unexpected write');
echo json_encode(['status' => 'accepted', 'checks' => $checks, 'rendered_variants' => count($matrix), 'matrix' => $matrix, 'sql_writes' => $writes, 'external_http_calls' => 0, 'persisted_test_customers' => 0]);
