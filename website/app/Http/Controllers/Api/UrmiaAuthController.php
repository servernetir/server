<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OtpChallenge;
use App\Services\Otp\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Private, SMS-only adapter for Urmia City. It cannot send arbitrary text,
 * issue a ServerNet session, or run paid identity inquiries.
 * Its independent key is deliberately outside .env and the source repository.
 */
class UrmiaAuthController extends Controller
{
    private const PURPOSE = 'urmia_city_login';

    protected function signingKey(): string
    {
        $key = @file_get_contents(storage_path('app/private/urmia-city-otp.key'));
        abort_unless(is_string($key) && strlen(trim($key)) >= 48, 503, 'urmia_auth_not_configured');
        return trim($key);
    }

    private function authenticate(Request $request): void
    {
        abort_if(strlen($request->getContent()) > 2048, 413);
        abort_unless($request->isJson(), 415);
        $time = (string) $request->header('X-Urmia-Timestamp');
        $nonce = (string) $request->header('X-Urmia-Nonce');
        $signature = (string) $request->header('X-Urmia-Signature');
        abort_unless(ctype_digit($time) && abs(time() - (int) $time) <= 90
            && preg_match('/^[a-f0-9-]{36}$/D', $nonce)
            && preg_match('/^[a-f0-9]{64}$/D', $signature), 403, 'invalid_signature');
        $canonical = implode("\n", ['v1', 'POST', $request->getPathInfo(), $time, $nonce, hash('sha256', $request->getContent())]);
        abort_unless(hash_equals(hash_hmac('sha256', $canonical, $this->signingKey()), $signature), 403, 'invalid_signature');
        abort_unless(Cache::add('urmia-auth:nonce:'.$nonce, true, 300), 409, 'replayed_signature');
    }

    private function response(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control'=>'private, no-store', 'X-Robots-Tag'=>'noindex', 'X-Content-Type-Options'=>'nosniff']);
    }

    private function ledgerGet(string $key): ?array
    {
        $raw = DB::table('urmia_auth_requests')->where('request_key', $key)->value('payload');
        return is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
    }

    private function ledgerPut(string $key, array $entry): void
    {
        DB::table('urmia_auth_requests')->where('request_key',$key)->update([
            'challenge_id'=>$entry['challenge_id'] ?? null,
            'payload'=>json_encode($entry, JSON_THROW_ON_ERROR), 'updated_at'=>now(),
        ]);
    }

    private function publicStart(array $entry): JsonResponse
    {
        return $this->response([
            'accepted' => $entry['status'] === 'sent',
            'status' => $entry['status'],
            'challenge_id' => $entry['challenge_id'] ?? null,
            'expires_at' => $entry['expires_at'] ?? null,
            'retry_after' => 60,
        ], in_array($entry['status'], ['sending', 'unknown'], true) ? 202 : 200);
    }

    public function start(Request $request, OtpService $otp): JsonResponse
    {
        $this->authenticate($request);
        $body = Validator::make($request->json()->all(), [
            'request_id' => ['required', 'uuid'],
            'mobile' => ['required', 'string', 'regex:/^09[0-9]{9}$/D'],
            'client_ip' => ['required', 'ip'],
            'session_binding' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
        ])->validate();
        $requestKey = 'urmia-auth:request:'.$body['request_id'];
        $fingerprint = hash('sha256', json_encode([
            $body['mobile'], $body['client_ip'], $body['session_binding'],
        ], JSON_THROW_ON_ERROR));

        return Cache::lock('urmia-auth:mobile:'.hash('sha256', $body['mobile']), 60)->block(3, function () use ($otp, $body, $requestKey, $fingerprint) {
            $existing = $this->ledgerGet($requestKey);
            if (is_array($existing)) {
                abort_unless(hash_equals($existing['fingerprint'], $fingerprint), 409, 'idempotency_conflict');
                if (in_array($existing['status'], ['sending', 'unknown'], true) && time() >= ($existing['deadline'] ?? PHP_INT_MAX)) {
                    $existing['status'] = 'expired'; $this->ledgerPut($requestKey, $existing);
                }
                return $this->publicStart($existing);
            }
            // The ledger is written before issue(): a timeout never authorizes another send.
            $entry = [
                'status' => 'sending', 'fingerprint' => $fingerprint,
                'mobile' => $body['mobile'], 'binding' => $body['session_binding'],
                'started_at' => time(), 'deadline' => time() + OtpService::TTL_MINUTES * 60 + 120,
            ];
            $claimed = DB::table('urmia_auth_requests')->insertOrIgnore([
                'request_key'=>$requestKey, 'challenge_id'=>null,
                'payload'=>json_encode($entry, JSON_THROW_ON_ERROR), 'updated_at'=>now(),
            ]);
            if ($claimed !== 1) {
                $prior=$this->ledgerGet($requestKey);
                abort_unless(is_array($prior) && hash_equals($prior['fingerprint'],$fingerprint),409,'idempotency_conflict');
                return $this->publicStart($prior);
            }
            try {
                $issued = $otp->issue('sms', $body['mobile'], self::PURPOSE, $body['client_ip'], smsOnly: true);
                if (! $issued->ok) {
                    $entry['status'] = 'failed';
                } else {
                    $entry += [
                        'challenge_id' => (string) Str::uuid(),
                        'otp_id' => $issued->challengeId,
                        'expires_at' => $issued->expiresAt->toIso8601String(),
                    ];
                    $entry['status'] = 'sent';
                }
            } catch (\Throwable) {
                $entry['status'] = 'unknown';
                $known = OtpChallenge::where('channel', 'sms')->where('destination', $body['mobile'])
                    ->where('purpose', self::PURPOSE)->where('created_at', '>=', now()->subMinutes(2))->latest('id')->first();
                if ($known) { $entry['deadline'] = $known->expires_at->getTimestamp(); }
            }
            $this->ledgerPut($requestKey, $entry);
            return $this->publicStart($entry);
        });
    }

    public function verify(Request $request, OtpService $otp): JsonResponse
    {
        $this->authenticate($request);
        $body = Validator::make($request->json()->all(), [
            'challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/D'],
            'session_binding' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
        ])->validate();
        $requestKey = DB::table('urmia_auth_requests')->where('challenge_id', $body['challenge_id'])->value('request_key');
        $entry = is_string($requestKey) ? $this->ledgerGet($requestKey) : null;
        abort_unless(is_array($entry) && hash_equals($entry['binding'], $body['session_binding']), 403, 'challenge_not_owned');
        return Cache::lock('urmia-auth:mobile:'.hash('sha256', $entry['mobile']), 60)->block(3, function () use ($requestKey, $body, $otp) {
            $entry = $this->ledgerGet($requestKey);
            abort_unless(is_array($entry) && ($entry['status'] ?? '') === 'sent', 409, 'challenge_not_active');
            abort_unless(hash_equals($entry['binding'], $body['session_binding']), 403);
            $codeHash = hash_hmac('sha256', $body['code'], $this->signingKey());
            if (isset($entry['verified_hash'])) {
                return $this->response(['verified' => hash_equals($entry['verified_hash'], $codeHash)
                    && ($entry['verified_at'] ?? 0) >= time() - 120]);
            }
            $latest = OtpChallenge::where('channel', 'sms')->where('destination', $entry['mobile'])
                ->where('purpose', self::PURPOSE)->whereNull('verified_at')
                ->where('expires_at', '>', now())->latest('id')->value('id');
            if ((int) $latest !== (int) $entry['otp_id']) {
                return $this->response(['verified' => false]);
            }
            $checked = $otp->verify('sms', $entry['mobile'], self::PURPOSE, $body['code']);
            if ($checked->ok) {
                $entry['verified_hash'] = $codeHash;
                $entry['verified_at'] = time();
                $this->ledgerPut($requestKey, $entry);
            }
            return $this->response(['verified' => $checked->ok]);
        });
    }
}
