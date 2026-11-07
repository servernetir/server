<?php
declare(strict_types=1);
/** Private FastCGI-only activation: no Laravel bootstrap, DB, routes or provider operations. */
const RELEASE_ID = 'traffic-exposure-20261105';
const APP_ROOT = '/home/servernetcloud/servernet_app';
const STAGE = '/home/servernetcloud/traffic-exposure-release-20261105';
const PLAN_HASH = 'ef286f7029c201b69117a443cd0866b90aab149abfdcf25c573dda04706277df';
const FILES = ['app/Services/Finance/ArvanTrafficExposure.php','app/Services/Finance/Wallet.php',
 'app/Services/Finance/ArvanTrafficCreditGuard.php','app/Services/Finance/ArvanTrafficMeter.php'];
function insist(bool $ok): void { if (!$ok) { throw new RuntimeException('activation_precondition_failed'); } }
function trusted(string $path, int $uid): string {
 insist(realpath($path) === $path && is_file($path) && !is_link($path));
 $st = lstat($path); insist($st !== false && $st['uid'] === $uid && $st['nlink'] === 1 && ($st['mode'] & 0022) === 0);
 $value = file_get_contents($path); insist(is_string($value)); return $value;
}
header('Content-Type: application/json');
$action = $_SERVER['SERVERNET_RELEASE_ACTION'] ?? '';
$locks = [];
try {
 insist(PHP_SAPI === 'fpm-fcgi' && ($_SERVER['SERVERNET_LOCAL_RELEASE'] ?? '') === RELEASE_ID
  && ($_SERVER['SERVERNET_EXPECTED_PLAN_SHA256'] ?? '') === PLAN_HASH
  && in_array($action, ['observe','activate','verify'], true));
 insist(realpath(APP_ROOT) === APP_ROOT && realpath(STAGE) === STAGE && __FILE__ === '/home/servernetcloud/public_html/_traffic_exposure_20261105/activate.php');
 $uid = fileowner(APP_ROOT); insist(is_int($uid) && $uid > 0 && fileowner(__FILE__) === $uid
  && (fileperms(STAGE) & 0777) === 0700 && fileowner(STAGE) === $uid);
 foreach (['storage/framework/arvan-meter-source-release.lock','storage/app/private/arvan-traffic-meter/run.lock'] as $relative) {
  $path = APP_ROOT.'/'.$relative; trusted($path, $uid);
  $h = fopen($path, 'r+'); insist($h !== false && flock($h, LOCK_EX | LOCK_NB));
  insist(lstat($path)['ino'] === fstat($h)['ino']); $locks[] = $h;
 }
 $bytes = trusted(STAGE.'/plan.json', $uid); insist(hash('sha256',$bytes) === PLAN_HASH);
 $plan = json_decode($bytes,true,32,JSON_THROW_ON_ERROR);
 insist(array_column($plan['entries'],'path') === FILES && $plan['candidate'] === '70402800682f6636ff9a7d7741857ae5de2369f4');
 $receipt = json_decode(trusted(STAGE.'/receipt.json',$uid),true,32,JSON_THROW_ON_ERROR);
 insist(($receipt['status'] ?? '') === 'installed_sources_activation_pending' && $receipt['candidate'] === $plan['candidate']);
 foreach ($plan['entries'] as $entry) { insist(hash('sha256',trusted(APP_ROOT.'/'.$entry['path'],$uid)) === $entry['after']); }
 $enabled = filter_var(ini_get('opcache.enable'),FILTER_VALIDATE_BOOLEAN);
 $invalidated = [];
 if ($action === 'activate' && $enabled) {
  insist(function_exists('opcache_invalidate'));
  foreach (FILES as $relative) { insist(opcache_invalidate(APP_ROOT.'/'.$relative,true)); $invalidated[] = $relative; }
 }
 $loader = require APP_ROOT.'/vendor/autoload.php'; insist(!$loader->isClassMapAuthoritative());
 $classes = [App\Services\Finance\ArvanTrafficExposure::class,App\Services\Finance\Wallet::class,
  App\Services\Finance\ArvanTrafficCreditGuard::class,App\Services\Finance\ArvanTrafficMeter::class];
 $compiled = [];
 foreach ($classes as $index => $class) {
  insist(class_exists($class)); insist((new ReflectionClass($class))->getFileName() === APP_ROOT.'/'.FILES[$index]);
  if ($enabled && $action !== 'observe') { insist(opcache_is_script_cached(APP_ROOT.'/'.FILES[$index])); }
  $compiled[] = FILES[$index];
 }
 insist(method_exists($classes[0],'observeLocked') && method_exists($classes[2],'holdPendingLocked'));
 echo json_encode(['release'=>RELEASE_ID,'action'=>$action,'status'=>'ok','php_version'=>PHP_VERSION,
  'sapi'=>PHP_SAPI,'opcache_enabled'=>$enabled,'invalidated'=>$invalidated,'verified_classes'=>$compiled,
  'plan_sha256'=>PLAN_HASH,'financial_action_performed'=>false],JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
 http_response_code(409); echo json_encode(['release'=>RELEASE_ID,'action'=>$action,'status'=>'stopped','reason'=>'activation_refused']);
} finally { foreach(array_reverse($locks) as $h) { flock($h,LOCK_UN); fclose($h); } }
