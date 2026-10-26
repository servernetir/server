<?php
// Synthetic production acceptance. No provider calls or financial/customer records persist.
chdir($argv[1]);
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$stage = $argv[2];
function ensureRelease($ok, $message) { if (! $ok) throw new RuntimeException($message); }
$checks = 0;
function checkedRelease($ok, $message) { global $checks; ensureRelease($ok, $message); $checks++; }
Illuminate\Support\Facades\Http::fake(fn () => Illuminate\Support\Facades\Http::response([], 503));
Illuminate\Support\Facades\View::share('errors', new Illuminate\Support\ViewErrorBag);
$defaults = App\Models\Setting::cached();
checkedRelease(App\Models\Setting::get('invoice_discount_entry_enabled', '0') === '0', 'Default is not off');
checkedRelease(Illuminate\Support\Facades\Schema::hasColumn('customers', 'discount_entry_visibility'), 'Missing preference column');
checkedRelease(App\Models\Customer::where('discount_entry_visibility', '!=', 'inherit')->count() === 0, 'Unexpected real overrides');
$financialWrites = 0;
$fixtureWrites = 0;
Illuminate\Support\Facades\DB::connection()->beforeExecuting(function ($sql) use (&$financialWrites, &$fixtureWrites) {
    if (preg_match('/^\s*(select|show|pragma|set|savepoint|release|rollback|begin|start)\b/i', $sql)) return;
    if (preg_match('/^\s*(insert\s+into|update)\s+[`"]?customers[`"]?(?:\s|\()/i', $sql)) { $fixtureWrites++; return; }
    $financialWrites++;
    throw new RuntimeException('Unexpected SQL write blocked during acceptance');
});
$admin = new App\Models\User(['name' => 'Synthetic release administrator', 'email' => 'discount-release@example.test', 'role' => 'admin']);
$admin->id = 0;
Illuminate\Support\Facades\Auth::guard('web')->setUser($admin);
$customer = new App\Models\Customer(['code' => 'QA-DISCOUNT', 'email' => 'discount-preview@example.test', 'status' => 'active', 'locale' => 'fa']);
$customer->id = 0;
Illuminate\Support\Facades\Auth::guard('customer')->setUser($customer);
$invoice = new App\Models\Invoice(['customer_id' => 0, 'number' => 'QA-DISCOUNT-PREVIEW', 'kind' => 'service', 'currency_code' => 'IRT', 'status' => 'unpaid', 'subtotal' => 10000, 'tax' => 1000, 'total' => 11000, 'paid' => 0, 'issued_at' => now()]);
$invoice->id = 0;
$invoice->setRelation('items', collect([new App\Models\InvoiceItem(['title' => 'Synthetic service', 'quantity' => 1, 'unit_price' => 10000, 'line_total' => 10000, 'tax_amount' => 1000])]));
$invoice->setRelation('payments', collect());
$rows = [];
foreach (['0', '1'] as $global) {
    $app->instance('settings.request-memo', array_replace($defaults, ['invoice_discount_entry_enabled' => $global]));
    foreach (['inherit', 'enabled', 'disabled'] as $mode) {
        $customer->forceFill(['discount_entry_visibility' => $mode]);
        foreach (['fa', 'en', 'tr'] as $locale) {
            $app->setLocale($locale);
            $html = view('account.invoice', App\Http\Controllers\Account\AccountController::shell('invoices') + ['invoice' => $invoice, 'creditBalance' => 0, 'giftCoupon' => null, 'gateways' => [], 'snapppay' => ['show' => false], 'bank' => null, 'pendingBank' => null, 'offline' => collect(), 'cryptoAssets' => [], 'cryptoOpen' => null])->render();
            $expected = $mode === 'enabled' || ($mode === 'inherit' && $global === '1');
            checkedRelease(str_contains($html, 'data-discount-entry') === $expected, 'Visibility matrix failed');
            if ($expected) {
                checkedRelease(preg_match('/<details[^>]*data-discount-entry[^>]*>/', $html, $m) === 1 && ! preg_match('/\sopen(?:\s|=|>)/', $m[0]), 'Disclosure is not closed');
                $position = strpos($html, 'data-discount-entry');
                checkedRelease($position > strpos($html, __('ui.inv_payable')) && $position < strpos($html, __('ui.inv_pay_heading')), 'Disclosure is not before payment');
            }
            $rows[] = ['global' => $global, 'mode' => $mode, 'locale' => $locale, 'visible' => $expected, 'bytes' => strlen($html), 'sha256' => hash('sha256', $html)];
            if ($locale === 'fa' && $global === '0' && in_array($mode, ['inherit', 'enabled'], true)) file_put_contents($stage.'/preview-'.$mode.'.html', $html);
        }
    }
}
$app->instance('settings.request-memo', $defaults);
$app->setLocale('fa');
$settingsHtml = view('admin.settings.discount-entry')->render();
checkedRelease(preg_match('/<input[^>]*type="checkbox"[^>]*name="enabled"[^>]*>/', $settingsHtml, $m) === 1 && ! str_contains($m[0], 'checked'), 'Default checkbox is not off');
$customer->forceFill(['discount_entry_visibility' => 'inherit']);
$customerHtml = view('admin.partials.customer-discount-entry', ['c' => $customer])->render();
checkedRelease(substr_count($customerHtml, 'type="radio"') === 3, 'Customer radios missing');
$routes = app('router')->getRoutes();
foreach (['admin/settings/discount-entry', 'admin/customers/{customer}/discount-entry'] as $uri) {
    $route = collect($routes)->first(fn ($r) => $r->uri() === $uri && in_array('POST', $r->methods()));
    checkedRelease($route && in_array('admin', $route->gatherMiddleware()), 'Admin route protection missing');
}
// Exercise the actual controller against one uncommitted synthetic row, then roll it back.
$fixtureEmail = 'discount-rollback-'.bin2hex(random_bytes(5)).'@example.test';
Illuminate\Support\Facades\DB::beginTransaction();
try {
    App\Models\Customer::withoutEvents(function () use ($fixtureEmail, $admin) {
        $fixture = App\Models\Customer::create(['code' => 'QA-'.bin2hex(random_bytes(4)), 'email' => $fixtureEmail, 'status' => 'active']);
        foreach (['enabled', 'disabled', 'inherit'] as $mode) {
            $request = Illuminate\Http\Request::create('/admin/customers/'.$fixture->id.'/discount-entry', 'POST', ['visibility' => $mode]);
            $request->setUserResolver(fn () => $admin);
            app(App\Http\Controllers\Admin\DiscountVisibilityController::class)->updateCustomer($request, $fixture);
            checkedRelease($fixture->fresh()->discount_entry_visibility === $mode, 'Customer override did not persist in transaction');
        }
    });
} finally {
    Illuminate\Support\Facades\DB::rollBack();
}
checkedRelease(! App\Models\Customer::where('email', $fixtureEmail)->exists(), 'Synthetic row persisted');
checkedRelease($financialWrites === 0, 'Unexpected financial writes');
$report = ['status' => 'accepted', 'checks' => $checks, 'rendered_variants' => count($rows), 'matrix' => $rows, 'fixture_writes_rolled_back' => $fixtureWrites, 'persisted_test_customers' => 0, 'financial_sql_writes' => $financialWrites, 'external_http_calls' => 0, 'global_default' => '0'];
file_put_contents($stage.'/acceptance.json', json_encode($report, JSON_PRETTY_PRINT));
echo json_encode($report);
