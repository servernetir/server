<?php

namespace App\Services\IdentityApi;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class BalanceSync
{
    public const INTERVAL_MINUTES = 5;

    public function __construct(private Controls $controls, private Provider $provider) {}

    /** Refresh actual provider evidence; never buys credit or changes spending limits. */
    public function refreshIfDue(bool $force = false): string
    {
        if (! $this->controls->ready() || $this->controls->get('balance_sync_enabled', false) !== true) {
            return 'disabled';
        }
        if (! $this->provider->configured() || ! filled(config('identity_api.balance_token'))) {
            return 'not_configured';
        }
        $lock = Cache::lock('identity:balance-sync', 45);
        if (! $lock->get()) {
            return 'busy';
        }
        try {
            $state = $this->controls->get('balance_sync_state', []);
            $state = is_array($state) ? $state : [];
            $attempt = $this->date($state['last_attempt_at'] ?? null);
            if (! $force && $attempt && ! $attempt->isFuture() && $attempt->gt(now()->subMinutes(self::INTERVAL_MINUTES))) {
                return 'not_due';
            }
            // Use request START, rounded down to DB timestamp precision. Charges racing
            // this observation remain reserved by Engine even if the API included them.
            $started = now()->startOfSecond();
            $state['last_attempt_at'] = $started->toIso8601String();
            $state['status'] = 'checking';
            $this->controls->put('balance_sync_state', $state, 0);
            try {
                $response = Http::acceptJson()->withToken(config('identity_api.balance_token'))
                    ->withHeaders(['Cache-Control' => 'no-cache'])->connectTimeout(5)->timeout(15)
                    ->withOptions(['allow_redirects' => false, 'debug' => false,
                        'progress' => function ($total, $downloaded) {
                            if ($downloaded > 65536) {
                                throw new \RuntimeException('response_limit');
                            }
                        }, 'on_headers' => function ($r) {
                            if ((int) $r->getHeaderLine('Content-Length') > 65536) {
                                throw new \RuntimeException('response_limit');
                            }
                        }])->get('https://service.zohal.io/api/v0/financial/wallet/');
                $data = $response->json();
                $raw = is_array($data) ? ($data['balance'] ?? null) : null;
                if ($response->status() !== 200 || strlen($response->body()) > 65536
                    || ! preg_match('/^application\/json(?:\s*;|$)/i', $response->header('Content-Type'))
                    || ! (is_int($raw) || is_string($raw) && preg_match('/^(0|[1-9][0-9]{0,12})$/D', $raw))
                    || (int) $raw < 0 || (int) $raw > 1000000000000
                    || isset($data['currency']) && $data['currency'] !== 'IRR'
                    || ! empty($data['error']) || ! empty($data['error_code']) || ! empty($data['detail'])
                    || isset($data['result']) && $data['result'] !== 1) {
                    return $this->failed($state, 'invalid_response');
                }
            } catch (\Throwable) {
                return $this->failed($state, 'request_failed');
            }

            return DB::transaction(function () use ($raw, $started, $state) {
                DB::table('identity_controls')->where('key', 'provider_mutex')->lockForUpdate()->firstOrFail();
                $previous = $this->date($this->controls->get('upstream_balance_verified_at'));
                if ($this->controls->get('balance_sync_enabled', false) !== true || $previous && $previous->gte($started)) {
                    return 'superseded';
                }
                // The wallet API unit is IRR, witnessed against the provider wallet UI.
                // Floor fractional IRT so an observation can never create extra funding.
                $this->controls->put('upstream_balance_irt', intdiv((int) $raw, 10), 0);
                $this->controls->put('upstream_balance_verified_at', $started->toIso8601String(), 0);
                $state['status'] = 'ok';
                $state['last_success_at'] = $started->toIso8601String();
                $state['error'] = null;
                $this->controls->put('balance_sync_state', $state, 0);
                $this->controls->audit(null, 'balance_observed', ['source' => 'provider_wallet_api',
                    'source_currency' => 'IRR', 'currency' => 'IRT', 'observed_at' => $started->toIso8601String(),
                    'limits_changed' => false, 'credit_purchased' => false]);

                return 'updated';
            });
        } finally {
            $lock->release();
        }
    }

    private function failed(array $state, string $error): string
    {
        $state['status'] = 'failed';
        $state['error'] = $error;
        $this->controls->put('balance_sync_state', $state, 0);

        return 'failed'; // Keep both previous amount and evidence timestamp exactly intact.
    }

    private function date(mixed $value): ?Carbon
    {
        try {
            return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
