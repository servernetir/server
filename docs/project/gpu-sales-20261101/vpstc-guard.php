<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$live='/home/servernetcloud/servernet_app';
require $live.'/vendor/autoload.php';
$app=require $live.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$catalog=app(App\Services\Cloud\VpsTcCatalog::class);
$manifest=$catalog->manifest();
echo json_encode(['manifest_valid'=>$manifest!==null,'pricing_configured'=>app(App\Services\Cloud\VpsTcPricing::class)->configured(),
    'sales_gate'=>app(App\Services\Cloud\VpsTcSales::class)->enabled()],JSON_THROW_ON_ERROR).PHP_EOL;
if ($manifest===null) { exit; }
// Diagnostic copy only. Exact rejection line, no raw API data or credentials.
$lines=file($live.'/app/Services/Cloud/VpsTcCatalog.php');
$inside=false;
foreach($lines as $i=>&$line) {
    if(str_contains($line,'private function reviewedQuote(')) { $inside=true; }
    if(str_contains($line,'public function manifest(')) { $inside=false; }
    if($inside) { $line=str_replace('return null;', "throw new \\RuntimeException('quote_guard_line_".($i+1)."');", $line); }
}
unset($line);
$source=str_replace('class VpsTcCatalog','class VpsTcGuardDiagnostic',implode('',$lines));
$path=__DIR__.'/VpsTcGuardDiagnostic.php';
file_put_contents($path,$source); require $path;
$probe=new App\Services\Cloud\VpsTcGuardDiagnostic(app(App\Services\Cloud\VpsTcApi::class),app(App\Services\Cloud\VpsTcPricing::class));
$method=new ReflectionMethod($probe,'reviewedQuote');
try {
    $quote=$method->invoke($probe,(string)$manifest['products'][0]['product_id']);
    echo json_encode(['first_quote_valid'=>$quote!==null],JSON_THROW_ON_ERROR).PHP_EOL;
} catch(Throwable $e) {
    echo json_encode(['first_quote_valid'=>false,'guard'=>preg_match('/^quote_guard_line_[0-9]+$/D',$e->getMessage())?$e->getMessage():'quote_exception'],JSON_THROW_ON_ERROR).PHP_EOL;
}
