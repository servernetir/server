<?php
require '/home/servernetcloud/servernet_app/vendor/autoload.php';
$app = require '/home/servernetcloud/servernet_app/bootstrap/app.php';
// cPanel separates the real web root from Laravel's application checkout.
// Bind it only in this private CLI process; no environment or global helper is changed.
$app->usePublicPath('/home/servernetcloud/public_html');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$import = app(App\Services\Shop\HardwareCatalogImport::class);
$receipt = __DIR__.'/catalog-receipt.json';
$result = ($argv[1] ?? '') === 'rollback' ? $import->rollback($receipt)
    : $import->apply(database_path('catalog/hardware-products-20261014.json'), $receipt, true);
echo json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
