<?php
// Temporary, administrator-only presentation smoke check with unsaved synthetic models.
define('LARAVEL_START', microtime(true));
require '/home/servernetcloud/servernet_app/vendor/autoload.php';
$app = require '/home/servernetcloud/servernet_app/bootstrap/app.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__.'/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$request = Illuminate\Http\Request::capture();
$app->instance('request', $request);
Illuminate\Support\Facades\Facade::clearResolvedInstance('request');
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
Illuminate\Support\Facades\Route::get('/servernet-wallet-panel-preview-20261031.php', function (Illuminate\Http\Request $request) {
    $locale = $request->query('locale', 'fa');
    $state = $request->query('state', 'form');
    abort_unless(in_array($locale, ['fa','en','tr'], true) && in_array($state, ['form','pending','disabled','no-bank'], true), 404);
    app()->setLocale($locale);
    Illuminate\Support\Facades\DB::statement('SET TRANSACTION READ ONLY');
    Illuminate\Support\Facades\DB::beginTransaction();
    try {
        $customer = (new App\Models\Customer)->forceFill(['id'=>0, 'first_name'=>'Preview', 'last_name'=>'', 'email'=>'preview@example.test','code'=>'PREVIEW','locale'=>$locale]);
        Illuminate\Support\Facades\Auth::guard('customer')->setUser($customer);
        $bank = (new App\Models\BankAccount)->forceFill(['id'=>0,'customer_id'=>0,'iban'=>'IR880100000000000000000001','card_bin'=>'603799','owner_name'=>'Synthetic preview','status'=>'verified']);
        $pending = (new App\Models\WalletRefundRequest)->forceFill(['id'=>0,'public_id'=>'20000000-0000-4000-8000-000000000001','amount_irt'=>40000,'status'=>'requested','bank_snapshot'=>['iban'=>$bank->iban],'created_at'=>now()]);
        $shell = App\Http\Controllers\Account\AccountController::shell('wallet-refunds');
        $shell['pnlUser'] = ['name'=>'Preview','code'=>'PREVIEW','email'=>'preview@example.test','avatar'=>null];
        $data = ['balance'=>1234567,'available'=>765432,'enabled'=>$state!=='disabled','pending'=>$state==='pending'?$pending:null,
            'banks'=>$state==='no-bank'?collect():collect([$bank]),'errors'=>new Illuminate\Support\ViewErrorBag,
            'refunds'=>new Illuminate\Pagination\LengthAwarePaginator(collect(),0,20,1,['path'=>lroute('account.wallet-refunds')])];
        $html = view('account.wallet-refunds', $shell+$data)->render();
        $html = str_replace('</head>', '<meta name="robots" content="noindex,nofollow"></head>', $html);
        return response($html)->header('Cache-Control','private, no-store')->header('X-Robots-Tag','noindex, nofollow');
    } finally {
        Illuminate\Support\Facades\DB::rollBack();
    }
})->middleware(['web','auth:web','admin']);
$response=$kernel->handle($request);$response->send();$kernel->terminate($request,$response);
