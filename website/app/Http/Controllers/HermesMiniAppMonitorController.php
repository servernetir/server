<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\Bale\Admin\HermesMiniAppAuth;
use App\Services\Hermes\HermesMiniAppErrors;
use App\Services\Hermes\HermesSupportState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/** Additive owner monitoring. Existing mini-app chat, memory and sessions remain independent. */
final class HermesMiniAppMonitorController extends Controller
{
    public function clientError(Request $request, HermesMiniAppAuth $auth, HermesMiniAppErrors $errors): JsonResponse
    {
        $id = HermesMiniAppErrors::requestId($request);
        if (! $this->owner($request, $auth)) {
            return $this->respond(HermesMiniAppErrors::failure('owner_unauthorized', $id, 403));
        }
        $code = HermesMiniAppErrors::clientCode(is_string($request->input('code')) ? $request->input('code') : '');
        $operation = is_string($request->input('operation')) ? $request->input('operation') : '';
        $status = is_int($request->input('status')) ? $request->input('status') : 0;
        $type = is_string($request->input('content_type')) ? $request->input('content_type') : '';
        // Correlate only server-generated identifiers; arbitrary client strings are not persisted.
        $sourceId = $request->input('request_id');
        $sourceId = is_string($sourceId) && preg_match('/^[a-f0-9]{32}$/D', $sourceId) ? $sourceId : $id;
        $errors->record($code, $operation, $status, $type, $sourceId);
        return $this->respond(['ok' => true, 'request_id' => $id, 'http_status' => 202]);
    }

    public function errors(Request $request, HermesMiniAppAuth $auth, HermesMiniAppErrors $errors): JsonResponse
    {
        $id = HermesMiniAppErrors::requestId($request);
        if (! $this->owner($request, $auth)) {
            return $this->respond(HermesMiniAppErrors::failure('owner_unauthorized', $id, 403));
        }
        try {
            $rows = array_map(static function (array $row): array {
                $code = is_string($row['code'] ?? null) && HermesMiniAppErrors::knownCode($row['code']) ? $row['code'] : 'internal_error';
                $at = is_string($row['utc'] ?? null) ? strtotime($row['utc']) : false;
                return ['utc' => gmdate('c', $at !== false ? $at : time()), 'code' => $code,
                    'message' => HermesMiniAppErrors::message($code), 'count' => max(1, (int) ($row['count'] ?? 1)),
                    'request_id' => is_string($row['request_id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{8,80}$/D', $row['request_id']) ? $row['request_id'] : 'unavailable'];
            }, $errors->recent(100));
            if (class_exists(HermesSupportState::class)) {
                foreach (array_slice(app(HermesSupportState::class)->status()['errors'] ?? [], -100) as $row) {
                    $supportCode = in_array($row['code'] ?? '', ['owner_binding_unavailable', 'retry', 'sent_notification_error', 'delivery_uncertain', 'ticket_review_failed', 'review_run_failed', 'tracked_hermes_incident', 'worker_interrupted'], true) ? $row['code'] : 'review';
                    $rows[] = ['utc' => gmdate('c', (int) ($row['last_at'] ?? time())),
                        'code' => 'support_'.$supportCode, 'message' => 'یک مورد در پایش پشتیبانی برای بررسی ثبت شده است.',
                        'count' => max(1, (int) ($row['count'] ?? 1)),
                        'request_id' => is_string($row['request_id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{8,80}$/D', $row['request_id']) ? $row['request_id'] : 'unavailable'];
                }
            }
            usort($rows, static fn (array $a, array $b): int => strcmp($b['utc'], $a['utc']));
            return $this->respond(['ok' => true, 'errors' => array_slice($rows, 0, 100), 'request_id' => $id, 'http_status' => 200]);
        } catch (Throwable) {
            $errors->record('state_unavailable', 'errors', 503, 'application/json', $id);
            return $this->respond(HermesMiniAppErrors::failure('state_unavailable', $id, 503));
        }
    }

    public function automation(Request $request, HermesMiniAppAuth $auth, HermesMiniAppErrors $errors): JsonResponse
    {
        $id = HermesMiniAppErrors::requestId($request);
        if (! $this->owner($request, $auth)) {
            return $this->respond(HermesMiniAppErrors::failure('owner_unauthorized', $id, 403));
        }
        try {
            if (! class_exists(HermesSupportState::class)) {
                throw new \RuntimeException('Support state not installed');
            }
            $state = app(HermesSupportState::class)->status();
            $runs = $state['recent_runs'] ?? [];
            $counts = [];
            foreach (['queued', 'retry', 'stale', 'excluded', 'review_required', 'dispatching', 'message_written', 'sent', 'sent_notification_error', 'delivery_uncertain'] as $status) {
                if (isset($state['counts'][$status]) && is_int($state['counts'][$status])) {
                    $counts[$status] = max(0, $state['counts'][$status]);
                }
            }
            $last = $runs === [] ? [] : $runs[array_key_last($runs)];
            $enabled = Setting::get('hermes_support_enabled') === '1';
            return $this->respond(['ok' => true, 'automation' => [
                'enabled' => $enabled, 'auto_send' => Setting::get('hermes_support_auto_send') === '1', 'interval_minutes' => 60,
                'last_run_at' => $this->timestamp($state['last_run_at'] ?? null), 'last_success_at' => $this->timestamp($state['last_success_at'] ?? null),
                'last_error_review_at' => $this->timestamp($state['last_error_review_at'] ?? null),
                'next_run_at' => $enabled ? (intdiv(time(), 3600) + 1) * 3600 : null,
                'checked_tickets' => max(0, (int) ($last['scanned'] ?? 0)),
                'sent_replies' => (int) ($counts['sent'] ?? 0) + (int) ($counts['sent_notification_error'] ?? 0),
                'counts' => $counts,
            ], 'request_id' => $id, 'http_status' => 200]);
        } catch (Throwable) {
            $errors->record('state_unavailable', 'automation', 503, 'application/json', $id);
            return $this->respond(HermesMiniAppErrors::failure('state_unavailable', $id, 503));
        }
    }

    /** Font names map to existing files; no filesystem input or webroot directory is created. */
    public function font(Request $request, string $file)
    {
        $name = [
            'IRANSans-regular.woff2' => 'IRANSans-web.woff2',
            'IRANSans-medium.woff2' => 'IRANSans-Medium-web.woff2',
            'IRANSans-bold.woff2' => 'IRANSans-Bold-web.woff2',
        ][$file] ?? null;
        $id = HermesMiniAppErrors::requestId($request);
        if (! $name) {
            return $this->respond(HermesMiniAppErrors::failure('request_not_found', $id, 404));
        }
        $candidates = [public_path('assets/font/woff2/'.$name), dirname(base_path()).'/public_html/assets/font/woff2/'.$name];
        foreach (array_unique($candidates) as $path) {
            if (is_file($path) && is_readable($path)) {
                return response()->file($path, ['Content-Type' => 'font/woff2', 'Cache-Control' => 'public, max-age=86400', 'X-Content-Type-Options' => 'nosniff']);
            }
        }
        return $this->respond(HermesMiniAppErrors::failure('request_not_found', $id, 404));
    }

    private function timestamp(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }

    private function owner(Request $request, HermesMiniAppAuth $auth): ?array
    {
        // Always call the installed owner gate, including its current session and revocation checks.
        return $auth->owner($request);
    }

    private function respond(array $payload): JsonResponse
    {
        $status = (int) ($payload['http_status'] ?? 200);
        if (($payload['ok'] ?? false) === true) {
            unset($payload['http_status']);
        }
        return response()->json($payload, $status)
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')
            ->header('X-Content-Type-Options', 'nosniff')->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('X-Request-Id', (string) ($payload['request_id'] ?? ''));
    }
}
