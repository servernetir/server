<?php
/** Pure activation tests. No Laravel boot, settings writes or ticket actions. */
declare(strict_types=1);

require dirname(__DIR__, 5).'/infra/hermes/activate_support.php';

function check(bool $condition): void
{
    if (! $condition) { throw new RuntimeException('Activation decision check failed'); }
}
$tests = [];
$tests['new activation starts now, excluding existing messages'] = static function (): void {
    $plan = hermes_support_activation_plan([], 1000);
    check($plan === ['hermes_support_enabled_at' => '1000', 'hermes_support_auto_send' => '1', 'hermes_support_enabled' => '1']);
};
$tests['already active activation timestamp is preserved'] = static function (): void {
    $plan = hermes_support_activation_plan(['hermes_support_enabled' => '1', 'hermes_support_enabled_at' => '900', 'hermes_support_auto_send' => '0'], 1000);
    check($plan['hermes_support_enabled_at'] === '900' && $plan['hermes_support_auto_send'] === '1');
};
$tests['fresh reactivation does not replay the disabled interval'] = static function (): void {
    $plan = hermes_support_activation_plan(['hermes_support_enabled' => '0', 'hermes_support_enabled_at' => '900'], 1000);
    check($plan['hermes_support_enabled_at'] === '1000');
};
$tests['invalid active timestamp fails closed'] = static function (): void {
    foreach (['', 'bad', '0', '1031'] as $timestamp) {
        $failed = false;
        try { hermes_support_activation_plan(['hermes_support_enabled' => '1', 'hermes_support_enabled_at' => $timestamp], 1000); }
        catch (Throwable) { $failed = true; }
        check($failed);
    }
};
$tests['only an actual bounded review can pass schedule preflight'] = static function (): void {
    check(hermes_support_scheduled_command_valid("'/usr/bin/php' 'artisan' hermes:support-review --limit=5"));
    check(hermes_support_scheduled_command_valid("php artisan hermes:support-review --limit='10'"));
    check(hermes_support_scheduled_command_valid("'/usr/bin/php8.3' '/home/app/artisan' hermes:support-review --limit=5"));
    check(hermes_support_scheduled_command_valid('php artisan hermes:support-review'));
    check(! hermes_support_scheduled_command_valid('echo hermes:support-review --limit=5'));
    foreach (['--status', '--dry-run', '--no-send', '--limit=0', '--limit=100', '--limit=5 --dry-run'] as $option) {
        check(! hermes_support_scheduled_command_valid('php artisan hermes:support-review '.$option));
    }
};
$results = [];
foreach ($tests as $name => $test) {
    try { $test(); $results[] = ['name' => $name, 'ok' => true]; }
    catch (Throwable $e) { $results[] = ['name' => $name, 'ok' => false, 'error' => $e->getMessage()]; }
}
$failed = count(array_filter($results, static fn (array $row): bool => ! $row['ok']));
echo json_encode(['tests' => count($results), 'failed' => $failed, 'results' => $results], JSON_PRETTY_PRINT).PHP_EOL;
exit($failed === 0 ? 0 : 1);
