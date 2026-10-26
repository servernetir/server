<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Rates in toman from Navasan's remittance/digital currency table. */
class ExchangeRate
{
    // This is the JSON feed used by #Ctable2. Initial page HTML contains
    // unrelated placeholder numbers and must never be used for pricing.
    public const SOURCE_URL = 'https://www.navasan.net/aed_based_rates.php';
    public const SOURCE_ITEMS = [
        'USD' => 'usd_shakhs', // دلار آمریکا شخص — owner's selected buying rate
        'EUR' => 'eur_hav',
        'USDT' => 'usd_usdt',
        'TRY' => 'try_hav', // حواله لیر — same keyless #Ctable2 feed, already toman (no /10)
    ];

    private const SNAPSHOT_KEY = 'fx.navasan.rates';
    private const MIN_TOMAN = 20_000;
    private const MAX_TOMAN = 5_000_000;
    private const FALLBACK_MAX_AGE_HOURS = 48;

    private function key(string $currency): string
    {
        return 'fx.'.strtolower($currency).'_irt';
    }

    /** Cached/last successful rate only; no network request. */
    public function current(string $currency = 'USD'): ?array
    {
        $currency = strtoupper($currency);
        if (! isset(self::SOURCE_ITEMS[$currency])) {
            return null;
        }

        try {
            $snapshot = Cache::get(self::SNAPSHOT_KEY);
            // Legacy cache keys are accepted only with the same verified Navasan provenance.
            $row = is_array($snapshot) ? ($snapshot[$currency] ?? null) : Cache::get($this->key($currency));
        } catch (\Throwable) {
            Log::warning('ExchangeRate cache read failed', ['currency' => $currency]);
            return $this->lastKnown($currency);
        }

        if (! is_array($row)) {
            return $this->lastKnown($currency);
        }

        if (! $this->validStoredRate($row, $currency)) {
            \App\Support\ErrorTracker::noteOnce('pricing',
                'ردیف نرخ ارز ذخیره‌شده معتبر نیست و نادیده گرفته شد ('.$currency.').', 900);
            return $this->lastKnown($currency);
        }

        return ['rate_toman' => (int) $row['rate_toman']] + $row + ['currency' => $currency, 'at' => null];
    }

    /** Read the last valid stored Navasan rate; only explicit synchronization fetches rates. */
    public function toToman(string $currency = 'USD'): ?int
    {
        $currency = strtoupper($currency);
        if (in_array($currency, ['IRT', 'IRR'], true)) {
            return $currency === 'IRR' ? 10 : 1;
        }

        $row = $this->current($currency);
        return $row['rate_toman'] ?? null;
    }

    public function refresh(string $currency = 'USD'): ?array
    {
        $currency = strtoupper($currency);
        if (! isset(self::SOURCE_ITEMS[$currency])) {
            return null;
        }

        return $this->refreshAll()[$currency] ?? null;
    }

    /**
     * Validate all procurement rates before publishing a single cached snapshot.
     * An incomplete/failed response never replaces any previous price.
     *
     * @return array<string,array>|null
     */
    public function refreshAll(): ?array
    {
        if (! config('services.exchange.enabled', true)) {
            return null;
        }

        try {
            // Scheduled, manual and cold-start callers share this lock. Fetch
            // under it so a slower response cannot overwrite a newer quote.
            $lock = Cache::lock('fx.navasan.refresh', 90);
            if (! $lock->get()) {
                return null;
            }
        } catch (\Throwable $e) {
            Log::warning('Navasan rate refresh lock failed', ['exception' => $e::class]);

            return null;
        }

        try {
            return $this->refreshLocked();
        } finally {
            try {
                $lock->release();
            } catch (\Throwable $e) {
                // Expiry still releases the lock. A release failure must not
                // turn a completed publication into a failed refresh result.
                Log::warning('Navasan rate refresh lock release failed', ['exception' => $e::class]);
            }
        }
    }

    private function refreshLocked(): ?array
    {
        $previousSnapshot = null;
        $publicationAttempted = false;
        try {
            $response = Http::acceptJson()->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; ServerNetBot/1.0)',
                'Referer' => 'https://www.navasan.net/',
            ])->connectTimeout(10)->timeout(30)
                ->get(self::SOURCE_URL, ['_' => now()->timestamp]);

            if (! $response->successful()) {
                Log::warning('Navasan rate request failed', ['status' => $response->status()]);
                return null;
            }

            $rows = $this->extractRates($response->body());
            if ($rows === null) {
                Log::warning('Navasan rates invalid or stale; keeping previous prices');
                return null;
            }

            foreach ($rows as $currency => $row) {
                $previous = $this->current($currency);
                if (isset($previous['source_at'])
                    && Carbon::parse($row['source_at'])->lt(Carbon::parse($previous['source_at']))) {
                    Log::warning('Navasan returned an older quote; keeping previous prices', ['currency' => $currency]);
                    return null;
                }
            }

            $previousSnapshot = Cache::get(self::SNAPSHOT_KEY);
            // Durable rates roll back together. The production file cache is
            // a separate backend; restore its prior snapshot if publication or
            // database commit fails. Readers always see a complete cache batch,
            // but may briefly read it before the database transaction commits.
            DB::transaction(function () use ($rows, &$publicationAttempted) {
                foreach ($rows as $currency => $row) {
                    Setting::put('fx_last_'.strtolower($currency), json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                }
                $publicationAttempted = true;
                if (! Cache::put(self::SNAPSHOT_KEY, $rows, now()->addHours(6))) {
                    throw new \RuntimeException('Navasan rate snapshot could not be cached.');
                }
            });

            return $rows;
        } catch (\Throwable $e) {
            if ($publicationAttempted) {
                try {
                    if (is_array($previousSnapshot)) {
                        if (! Cache::put(self::SNAPSHOT_KEY, $previousSnapshot, now()->addMinutes(5))) {
                            throw new \RuntimeException('Navasan previous rate snapshot could not be restored.');
                        }
                    } else {
                        Cache::forget(self::SNAPSHOT_KEY);
                    }
                } catch (\Throwable $restoreError) {
                    try {
                        // If restoration cannot write, prefer the rolled-back
                        // durable rates over a possibly published new snapshot.
                        Cache::forget(self::SNAPSHOT_KEY);
                    } catch (\Throwable) {
                    }
                    Log::error('Navasan rate cache restoration failed', ['exception' => $restoreError::class]);
                }
            }
            // Setting::put invalidates these during the transaction. Clear any
            // view rebuilt from its uncommitted values before reading fallback.
            app()->forgetInstance('settings.request-memo');
            try {
                Cache::forget('settings.all');
            } catch (\Throwable) {
                // Preserve the original failure; the durable rows rolled back.
            }
            // Do not log response bodies, request URLs or provider credentials.
            Log::warning('Navasan rate refresh failed', ['exception' => $e::class]);
            return null;
        }
    }

    /** Parse data, never execute upstream JavaScript or guess from HTML. */
    public function extractRates(string $json): ?array
    {
        try {
            $payload = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (! is_array($payload)) {
            return null;
        }

        $rows = [];
        foreach (self::SOURCE_ITEMS as $currency => $item) {
            $quote = $payload[$item] ?? null;
            $rate = is_array($quote) ? ($quote['value'] ?? null) : null;
            $timestamp = is_array($quote) ? ($quote['date'] ?? null) : null;
            if (! $this->validRate($rate, $currency) || (isset($quote['unit']) && $quote['unit'] !== 'IRT')
                || ! is_numeric($timestamp)
                || (float) $timestamp !== (float) (int) $timestamp
                || ! $this->validTime((int) $timestamp)) {
                return null;
            }

            $rows[$currency] = [
                'currency' => $currency,
                'rate_toman' => (int) $rate,
                'unit' => 'IRT',
                'source' => 'navasan.net',
                'source_item' => $item,
                'source_at' => Carbon::createFromTimestampUTC((int) $timestamp)->toIso8601String(),
                'at' => now()->toIso8601String(),
            ];
        }

        return $rows;
    }

    private function validRate(mixed $rate, string $currency): bool
    {
        // TRY is worth fewer toman per unit than USD/EUR/USDT. This is only
        // a sanity bound; its value still comes exclusively from try_hav.
        $minimum = $currency === 'TRY' ? 100 : self::MIN_TOMAN;
        $maximum = $currency === 'TRY' ? 100_000 : self::MAX_TOMAN;
        return (is_int($rate) || is_float($rate) || is_string($rate))
            && (! is_string($rate) || preg_match('/\A[1-9][0-9]{0,9}\z/D', $rate))
            && is_numeric($rate) && is_finite((float) $rate)
            && (float) $rate === (float) (int) $rate
            && (int) $rate >= $minimum && (int) $rate <= $maximum;
    }

    private function validTime(mixed $value): bool
    {
        if ((! is_string($value) && ! is_int($value)) || $value === '') {
            return false;
        }
        try {
            $time = is_int($value) ? Carbon::createFromTimestampUTC($value) : Carbon::parse($value);
            return $time->gte(now()->subHours(self::FALLBACK_MAX_AGE_HOURS)) && $time->lte(now()->addMinutes(5));
        } catch (\Throwable) {
            return false;
        }
    }

    private function validStoredRate(array $row, string $currency): bool
    {
        return ($row['source'] ?? null) === 'navasan.net'
            && ($row['source_item'] ?? null) === self::SOURCE_ITEMS[$currency]
            && ($row['currency'] ?? null) === $currency
            && ($currency !== 'TRY' || ($row['unit'] ?? null) === 'IRT')
            && (! isset($row['unit']) || $row['unit'] === 'IRT')
            && $this->validRate($row['rate_toman'] ?? null, $currency)
            && ($currency !== 'TRY' || ($this->absoluteTimestamp($row['at'] ?? null)
                && $this->absoluteTimestamp($row['source_at'] ?? null)))
            && $this->validTime($row['at'] ?? null)
            && $this->validTime($row['source_at'] ?? null);
    }

    private function absoluteTimestamp(mixed $value): bool
    {
        // Relative strings such as "now" would renew a cached quote on every read.
        return is_string($value)
            && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:[0-9]{2})\z/D', $value);
    }

    /** Durable Navasan fallback, bounded by both fetch time and upstream quote time. */
    public function lastKnown(string $currency = 'USD'): ?array
    {
        $currency = strtoupper($currency);
        if (! isset(self::SOURCE_ITEMS[$currency])) {
            return null;
        }
        try {
            $row = json_decode((string) Setting::get('fx_last_'.strtolower($currency)), true);
            if (! is_array($row) || ! $this->validStoredRate($row, $currency)) {
                return null;
            }

            return ['rate_toman' => (int) $row['rate_toman'], 'currency' => $currency, 'stale' => true]
                + $row + ['source' => 'last-known'];
        } catch (\Throwable) {
            return null;
        }
    }
}
