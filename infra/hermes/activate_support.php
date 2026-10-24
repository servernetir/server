<?php
/**
 * Private CLI helper. Install outside public/; default invocation is preflight only.
 * php activate_support.php --app-root=/absolute/laravel/root --document-root=/actual/webroot [--enable]
 */
declare(strict_types=1);

use App\Models\Setting;
use App\Services\AiContent;
use App\Services\Bale\Admin\AdminBaleGate;
use App\Services\Hermes\HermesMiniAppErrors;
use App\Services\Hermes\HermesSupportDraftWriter;
use App\Services\Hermes\HermesSupportReviewService;
use App\Services\Hermes\HermesSupportState;
use App\Services\Ticket\TicketDraftWriter;
use App\Support\ErrorTracker;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Pure activation decision: a second activation must never replay older tickets. */
function hermes_support_activation_plan(array $settings, int $now): array
{
    $active = ($settings['hermes_support_enabled'] ?? null) === '1';
    $previousAt = (string) ($settings['hermes_support_enabled_at'] ?? '');
    if ($active && (! ctype_digit($previousAt) || (int) $previousAt < 1 || (int) $previousAt > $now + 30)) {
        throw new RuntimeException('Existing activation timestamp is invalid');
    }
    return [
        'hermes_support_enabled_at' => $active ? $previousAt : (string) $now,
        'hermes_support_auto_send' => '1',
        'hermes_support_enabled' => '1',
    ];
}

/** Resolve existing symlinks even when the final private directory is not created. */
function hermes_support_future_path(string $path): string
{
    $tail = [];
    while (! file_exists($path) && dirname($path) !== $path) {
        array_unshift($tail, basename($path));
        $path = dirname($path);
    }
    $real = realpath($path);
    if ($real === false) {
        throw new RuntimeException('Private path unavailable');
    }
    return rtrim($real, DIRECTORY_SEPARATOR).($tail ? DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $tail) : '');
}

function hermes_support_scheduled_command_valid(string $command): bool
{
    // Only normal review plus its bounded limit; status/dry-run/no-send are not activation.
    if (! preg_match('/^(\'[^\']+\'|"[^"]+"|[^\s\'"]+)\s+(\'[^\']+\'|"[^"]+"|[^\s\'"]+)\s+(hermes:support-review(?:\s+--limit(?:=|\s+)[\'"]?(?:[1-9]|10)[\'"]?)?)\s*$/D', $command, $parts)) {
        return false;
    }
    $binary = basename(trim($parts[1], '\'"'));
    $artisan = basename(trim($parts[2], '\'"'));
    return (bool) preg_match('/^(?:php(?:\d+(?:\.\d+)*)?|lsphp\d*)$/D', $binary) && $artisan === 'artisan';
}

// Tests may include the pure decision without bootstrapping any application.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
ini_set('display_errors', '0');
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (! (error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException('Activation preflight warning', 0, $severity, $file, $line);
});
$bufferLevel = ob_get_level();
ob_start();
$requestId = bin2hex(random_bytes(16));
$stage = 'arguments_invalid';
$lock = null;
$report = ['ok' => false, 'request_id' => $requestId, 'mode' => 'preflight', 'checks' => []];
$emit = static function (array $payload, int $exitCode) use ($bufferLevel): never {
    while (ob_get_level() > $bufferLevel) {
        ob_end_clean();
    }
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($exitCode);
};
try {
    $options = getopt('', ['app-root:', 'document-root:', 'enable', 'help']);
    foreach (array_slice($argv, 1) as $argument) {
        if (! str_starts_with($argument, '--') && ! is_dir($argument)) {
            throw new RuntimeException('Unknown activation argument');
        }
        if (str_starts_with($argument, '--') && ! preg_match('/^--(?:enable|help|(?:app-root|document-root)(?:=.+)?)$/D', $argument)) {
            throw new RuntimeException('Unknown activation option');
        }
    }
    if (isset($options['help'])) {
        $emit(['ok' => true, 'usage' => 'php activate_support.php --app-root=/absolute/laravel/root --document-root=/actual/webroot [--enable]', 'default' => 'Read-only preflight; no setting changes or ticket processing.'], 0);
    }
    if (! isset($options['app-root']) || ! is_string($options['app-root']) || ! str_starts_with($options['app-root'], DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Absolute Laravel root required');
    }
    $appRoot = realpath($options['app-root']);
    if ($appRoot === false || ! is_file($appRoot.'/vendor/autoload.php') || ! is_file($appRoot.'/bootstrap/app.php')) {
        throw new RuntimeException('Laravel root unavailable');
    }
    if (! isset($options['document-root']) || ! is_string($options['document-root']) || ! str_starts_with($options['document-root'], DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Actual document root required');
    }
    $documentRoot = realpath($options['document-root']);
    if ($documentRoot === false || ! is_dir($documentRoot)) {
        throw new RuntimeException('Actual document root unavailable');
    }
    $report['mode'] = isset($options['enable']) ? 'enable' : 'preflight';
    $stage = 'bootstrap_failed';
    require $appRoot.'/vendor/autoload.php';
    $app = require $appRoot.'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    $commands = $kernel->all();
    $stage = 'preflight_failed';
    $check = static function (string $name, callable $callback) use (&$report): void {
        try {
            $report['checks'][$name] = $callback() === true;
        } catch (Throwable) {
            $report['checks'][$name] = false;
        }
    };
    $check('owner_bound_and_enabled', static function () use ($app): bool {
        $gate = $app->make(AdminBaleGate::class);
        return $gate->enabled() && (bool) $gate->binding() && (bool) $gate->boundUser();
    });
    $check('command_registered', static fn (): bool => isset($commands['hermes:support-review']));
    $scheduleEntries = [];
    foreach ($app->make(Schedule::class)->events() as $event) {
        if (preg_match('/\bhermes:support-review(?=\s|$|[\'"])/', (string) ($event->command ?? ''))) {
            $scheduleEntries[] = $event;
        }
    }
    $report['schedule_entries'] = count($scheduleEntries);
    $check('one_hourly_schedule', static fn (): bool => count($scheduleEntries) === 1 && (bool) preg_match('/^[0-5]?\d \* \* \* \*$/D', (string) $scheduleEntries[0]->expression));
    $check('schedule_runs_real_review', static fn (): bool => count($scheduleEntries) === 1 && hermes_support_scheduled_command_valid((string) $scheduleEntries[0]->command));
    $check('scheduler_overlap_guard', static fn (): bool => count($scheduleEntries) === 1 && $scheduleEntries[0]->withoutOverlapping === true);
    $check('schedule_not_filtered_out', static function () use ($scheduleEntries, $app): bool {
        if (count($scheduleEntries) !== 1 || $app->isDownForMaintenance()) {
            return false;
        }
        $event = $scheduleEntries[0];
        $environments = (array) (new ReflectionProperty($event, 'environments'))->getValue($event);
        $filters = (array) (new ReflectionProperty($event, 'filters'))->getValue($event);
        $rejects = (array) (new ReflectionProperty($event, 'rejects'))->getValue($event);
        if (($environments !== [] && ! in_array($app->environment(), $environments, true)) || $filters !== [] || count($rejects) !== 1 || ! $rejects[0] instanceof Closure) {
            return false;
        }
        // Laravel withoutOverlapping() installs one mutex skip closure. Inspect
        // its source location; never execute arbitrary when()/skip() callbacks.
        $method = new ReflectionMethod($event, 'withoutOverlapping');
        $closure = new ReflectionFunction($rejects[0]);
        return $closure->getFileName() === $method->getFileName()
            && $closure->getStartLine() >= $method->getStartLine() && $closure->getEndLine() <= $method->getEndLine();
    });
    if (count($scheduleEntries) === 1) {
        $report['schedule_minute'] = (int) explode(' ', $scheduleEntries[0]->expression)[0];
    }
    $check('review_service_resolves', static fn (): bool => $app->make(HermesSupportReviewService::class) instanceof HermesSupportReviewService);
    $check('existing_support_provider_ready', static function () use ($app): bool {
        $writer = $app->make(HermesSupportDraftWriter::class);
        if (! $writer instanceof TicketDraftWriter || (new ReflectionMethod($writer, 'call'))->getDeclaringClass()->getName() !== AiContent::class
            || (new ReflectionProperty($writer, 'purpose'))->getValue($writer) !== 'support') {
            return false;
        }
        // Reuse the live router, inspect booleans only; never print provider configuration.
        $provider = (new ReflectionMethod($writer, 'provider'))->invoke($writer, 'support');
        return is_array($provider) && trim((string) ($provider['key'] ?? '')) !== ''
            && trim((string) ($provider['base'] ?? '')) !== '' && trim((string) ($provider['model'] ?? '')) !== '';
    });
    $check('encryption_ready', static function (): bool {
        return Crypt::decryptString(Crypt::encryptString('hermes-support-preflight')) === 'hermes-support-preflight';
    });
    $check('atomic_delivery_driver', static fn (): bool => in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb', 'pgsql'], true));
    $check('ticket_foreign_key_guard', static function (): bool {
        foreach (Schema::getForeignKeys('ticket_messages') as $key) {
            if (($key['columns'] ?? []) === ['ticket_id'] && ($key['foreign_table'] ?? '') === 'tickets' && ($key['foreign_columns'] ?? []) === ['id']) {
                return true;
            }
        }
        return false;
    });
    $check('error_tracker_compatible', static function (): bool {
        $parameters = (new ReflectionMethod(ErrorTracker::class, 'recent'))->getParameters();
        return count($parameters) >= 2 && $parameters[1]->isDefaultValueAvailable()
            && $parameters[1]->getDefaultValue() === 'error' && is_array(ErrorTracker::recent(1, 'error'));
    });
    $check('private_diagnostics_ready', static function () use ($app, $documentRoot): bool {
        $publicRoots = [hermes_support_future_path($app->publicPath()), $documentRoot];
        foreach ([__FILE__, storage_path('app/private/hermes-support'), storage_path('app/private/hermes-miniapp')] as $path) {
            foreach ($publicRoots as $publicRoot) {
                if (str_starts_with(hermes_support_future_path($path).DIRECTORY_SEPARATOR, rtrim($publicRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
                    return false;
                }
            }
        }
        foreach ([storage_path('app/private/hermes-support'), storage_path('app/private/hermes-miniapp'), storage_path('logs')] as $directory) {
            while (! is_dir($directory) && dirname($directory) !== $directory) {
                $directory = dirname($directory);
            }
            if (! is_writable($directory)) {
                return false;
            }
        }
        foreach ([storage_path('app/private/hermes-support/state.json'), storage_path('app/private/hermes-miniapp/errors.json')] as $path) {
            if (is_file($path) && (! is_readable($path) || ! is_array(json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR)))) {
                return false;
            }
        }
        return $app->make(HermesMiniAppErrors::class) instanceof HermesMiniAppErrors;
    });
    $keys = ['hermes_support_enabled', 'hermes_support_auto_send', 'hermes_support_enabled_at'];
    $settings = Setting::query()->whereIn('key', $keys)->pluck('value', 'key')->all();
    $report['enabled'] = ($settings['hermes_support_enabled'] ?? null) === '1';
    $report['auto_send'] = ($settings['hermes_support_auto_send'] ?? null) === '1';
    $report['enabled_at'] = ctype_digit((string) ($settings['hermes_support_enabled_at'] ?? '')) ? (int) $settings['hermes_support_enabled_at'] : null;
    $check('activation_state_valid', static function () use ($settings): bool {
        hermes_support_activation_plan($settings, time());
        return true;
    });
    $check('exclusions_valid', static function (): bool {
        $raw = Setting::query()->where('key', 'hermes_support_excluded_tickets')->value('value');
        $values = json_decode($raw ?? '[]', true, 32, JSON_THROW_ON_ERROR);
        return is_array($values) && array_is_list($values)
            && count(array_filter($values, static fn ($value): bool => (is_int($value) || is_string($value)) && trim((string) $value) !== '')) === count($values);
    });
    $report['ready'] = ! in_array(false, $report['checks'], true);
    if (! $report['ready']) {
        $emit($report + ['error' => 'preflight_failed'], 1);
    }
    if (! isset($options['enable'])) {
        $report['ok'] = true;
        $report['changed'] = false;
        $emit($report, 0);
    }
    $stage = 'activation_failed';
    $lock = Cache::lock('hermes:support-review', 60);
    if (! $lock->get()) {
        $emit($report + ['error' => 'support_review_running'], 1);
    }
    // Directory/lock initialization is confined to explicit activation.
    $state = $app->make(HermesSupportState::class);
    $state->status();
    $app->make(HermesMiniAppErrors::class)->recent(1);
    $plan = DB::transaction(static function () use ($keys): array {
        $current = Setting::query()->whereIn('key', $keys)->lockForUpdate()->pluck('value', 'key')->all();
        $next = hermes_support_activation_plan($current, time());
        foreach ($next as $key => $value) {
            Setting::put($key, $value);
        }
        return $next;
    });
    $lock->release();
    $lock = null;
    $report['ok'] = true;
    $report['changed'] = true;
    $report['enabled'] = true;
    $report['auto_send'] = true;
    $report['enabled_at'] = (int) $plan['hermes_support_enabled_at'];
    $report['status'] = $state->status();
    $emit($report, 0);
} catch (Throwable) {
    if ($lock !== null) {
        try { $lock->release(); } catch (Throwable) {}
    }
    $emit($report + ['error' => $stage], 1);
}
