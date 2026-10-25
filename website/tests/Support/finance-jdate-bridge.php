<?php

use App\Http\Controllers\Admin\JalaliDateController;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;

putenv('APP_ENV=testing');
putenv('APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('CACHE_STORE=array');
putenv('SESSION_DRIVER=array');
if (is_file($argv[1].'/.env')) {
    throw new RuntimeException('Use an isolated checkout without .env');
}
chdir($argv[1]);
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app()->setLocale('fa');
Carbon::setTestNow(Carbon::parse('2026-10-25 08:00:00', 'UTC'));
if ($argv[2] === 'component') {
    echo Blade::render('<form id="filters"><label>از تاریخ<x-admin-jdate name="from" value="2026-10-25" /></label><label>تا تاریخ<x-admin-jdate name="to" /></label><button id="submit-filter">اعمال فیلتر</button></form><form id="required"><label>تاریخ سند<x-admin-jdate name="occurred_at" required /></label><button id="submit-required">ثبت سند</button></form><form id="flows"><div data-flow-builder><label>تاریخ جریان<x-admin-jdate name="flow_date" /></label><input data-flow-amount><button type="button" data-flow-add>افزودن</button><span data-flow-error></span></div><textarea name="cash_flows"></textarea></form><form><label>سررسید سرویس<input type="hidden" name="next_due_at" value="2026-10-25" data-jdate data-min="2026-10-25" data-max="2026-10-27" required></label></form>');
} else {
    $request = Request::create('/admin/jdate?'.$argv[2]);
    $response = app(JalaliDateController::class)->month($request);
    echo $response->getContent();
}
