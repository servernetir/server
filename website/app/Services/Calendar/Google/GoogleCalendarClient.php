<?php

namespace App\Services\Calendar\Google;

use App\Models\GoogleCalendarToken;
use App\Models\Setting;
use App\Support\ErrorTracker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * گوگل‌کلندر — تبادلِ توکن و خواندن/نوشتنِ رویداد.
 *
 * ⚠️ **هیچ متدی استثنا پرتاب نمی‌کند.** خطا در آرایهٔ برگشتی می‌آید، دقیقاً
 * مثلِ `CloudProvider` در این پروژه. دلیلش این است که این کلاس از داخلِ یک
 * provider صدا زده می‌شود که خودش حق ندارد تقویم را بکشد.
 */
class GoogleCalendarClient
{
    /**
     * دسترسی‌های خواسته‌شده — **کم‌ترین ممکن**.
     *
     * `calendar.events` اجازهٔ خواندن و ساختِ رویداد می‌دهد ولی نه ساخت/حذفِ
     * خودِ تقویم‌ها. `openid email` فقط برای این است که بتوانیم بنویسیم «وصل
     * به: x@gmail.com» — بی‌آن، کاربر نمی‌داند کدام حسابش وصل است.
     */
    public const SCOPES = 'openid email https://www.googleapis.com/auth/calendar.events';

    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const API = 'https://www.googleapis.com/calendar/v3';

    /** ثانیه — تماسِ کند نباید رندرِ صفحه را گروگان بگیرد */
    private const TIMEOUT = 12;

    public static function clientId(): ?string
    {
        return Setting::get('google_client_id') ?: null;
    }

    public static function clientSecret(): ?string
    {
        return Setting::getSecret('google_client_secret') ?: null;
    }

    public static function configured(): bool
    {
        return filled(self::clientId()) && filled(self::clientSecret());
    }

    /**
     * نشانیِ صفحهٔ رضایتِ گوگل.
     *
     * ⚠️ `access_type=offline` + `prompt=consent` هر دو لازم‌اند: بی‌اولی
     * refresh token نمی‌آید، و بی‌دومی گوگل برای کاربری که قبلاً یک بار
     * پذیرفته **دیگر refresh token نمی‌فرستد** — یعنی اتصالِ دوباره ظاهراً
     * موفق می‌شود ولی توکنِ ماندگار ندارد و فردا می‌میرد.
     */
    public static function authUrl(string $state, string $redirectUri): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id'     => self::clientId(),
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => self::SCOPES,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'include_granted_scopes' => 'true',
            'state'         => $state,
        ]);
    }

    /**
     * کدِ بازگشتی → توکن‌ها.
     *
     * @return array{ok:bool, error?:string, email?:string, access_token?:string,
     *               refresh_token?:string, expires_at?:Carbon}
     */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        $res = $this->post(self::TOKEN_URL, [
            'code'          => $code,
            'client_id'     => self::clientId(),
            'client_secret' => self::clientSecret(),
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
        ]);

        if (! $res['ok']) {
            return $res;
        }

        $body = $res['body'];

        /*
         * 🔴 نبودِ refresh_token یعنی اتصال **ناقص** است، نه موفق.
         *
         * بی‌آن، دسترسی یک ساعت بعد می‌میرد و دیگر راهی برای تازه‌کردنش نیست.
         * اگر این را «موفق» می‌شمردیم، کاربر یک اتصالِ سبز می‌دید که فردا بی‌صدا
         * از کار می‌افتاد — بدترین حالتِ ممکن.
         */
        if (blank($body['refresh_token'] ?? null)) {
            return ['ok' => false, 'error' => 'no_refresh_token'];
        }

        return [
            'ok'            => true,
            'access_token'  => (string) ($body['access_token'] ?? ''),
            'refresh_token' => (string) $body['refresh_token'],
            'expires_at'    => Carbon::now()->addSeconds(max(60, (int) ($body['expires_in'] ?? 3600))),
            'email'         => $this->emailFromIdToken($body['id_token'] ?? null),
        ];
    }

    /**
     * تازه‌کردنِ access token. موفق ⇒ ردیف به‌روز و ذخیره می‌شود.
     */
    public function refresh(GoogleCalendarToken $token): bool
    {
        if (blank($token->refresh_token)) {
            $token->noteError('refresh token ندارد — دوباره وصل کنید');

            return false;
        }

        $res = $this->post(self::TOKEN_URL, [
            'client_id'     => self::clientId(),
            'client_secret' => self::clientSecret(),
            'refresh_token' => $token->refresh_token,
            'grant_type'    => 'refresh_token',
        ]);

        if (! $res['ok']) {
            /*
             * ⚠️ `invalid_grant` معنیِ خاص دارد: کاربر دسترسی را پس گرفته، یا
             * توکن به‌خاطرِ حالتِ Testing منقضی شده. این با «گوگل الان در
             * دسترس نیست» فرق دارد و پیامش هم باید فرق کند، وگرنه کاربر منتظرِ
             * رفعِ خودبه‌خودی می‌مانَد که هرگز نمی‌آید.
             */
            $token->noteError(($res['error'] ?? '') === 'invalid_grant'
                ? 'دسترسی باطل شده — دوباره وصل کنید'
                : 'تازه‌سازی توکن ناموفق: '.($res['error'] ?? 'نامشخص'));

            return false;
        }

        $body = $res['body'];

        $token->forceFill([
            'access_token' => (string) ($body['access_token'] ?? ''),
            'expires_at'   => Carbon::now()->addSeconds(max(60, (int) ($body['expires_in'] ?? 3600))),
            'last_error'   => null,
            // گوگل معمولاً refresh token تازه نمی‌دهد؛ اگر داد، جایگزین می‌شود
            'refresh_token' => filled($body['refresh_token'] ?? null)
                ? (string) $body['refresh_token']
                : $token->refresh_token,
        ])->save();

        return true;
    }

    /**
     * توکنِ معتبر — در صورت لزوم تازه می‌شود. نال یعنی نشد.
     */
    public function validAccessToken(GoogleCalendarToken $token): ?string
    {
        if ($token->isExpired() && ! $this->refresh($token)) {
            return null;
        }

        return blank($token->access_token) ? null : $token->access_token;
    }

    /**
     * رویدادهای بازه.
     *
     * @return array{ok:bool, error?:string, items?:list<array<string,mixed>>}
     */
    public function listEvents(GoogleCalendarToken $token, Carbon $from, Carbon $to): array
    {
        $access = $this->validAccessToken($token);

        if ($access === null) {
            return ['ok' => false, 'error' => $token->last_error ?: 'no_token'];
        }

        $res = $this->get(self::API.'/calendars/'.rawurlencode($token->calendar_id ?: 'primary').'/events', $access, [
            'timeMin'      => $from->copy()->utc()->toRfc3339String(),
            'timeMax'      => $to->copy()->utc()->toRfc3339String(),
            // تکرارها را خودِ گوگل باز می‌کند — ما قاعدهٔ RRULE را پیاده نمی‌کنیم
            'singleEvents' => 'true',
            'orderBy'      => 'startTime',
            'maxResults'   => 250,
        ]);

        if (! $res['ok']) {
            $token->noteError('خواندن رویدادها ناموفق: '.($res['error'] ?? 'نامشخص'));

            return $res;
        }

        $token->markSynced();

        return ['ok' => true, 'items' => (array) ($res['body']['items'] ?? [])];
    }

    /**
     * ساختِ رویداد در تقویمِ گوگل.
     *
     * `$day` روزِ میلادی (`Y-m-d`) و رویداد **تمام‌روز** ساخته می‌شود، چون
     * یادآوری‌های این پنل ساعت ندارند.
     *
     * ⚠️ `end.date` در گوگل **انحصاری** است: برای یک روزِ کامل باید فردا را
     * بدهی. اگر همان روز را بدهی، گوگل رویداد را رد می‌کند یا صفرروزه می‌سازد.
     *
     * @return array{ok:bool, error?:string, id?:string, link?:string}
     */
    public function insertEvent(GoogleCalendarToken $token, string $day, string $title, ?string $description = null): array
    {
        $access = $this->validAccessToken($token);

        if ($access === null) {
            return ['ok' => false, 'error' => $token->last_error ?: 'no_token'];
        }

        $res = $this->postJson(
            self::API.'/calendars/'.rawurlencode($token->calendar_id ?: 'primary').'/events',
            $access,
            [
                'summary'     => $title,
                'description' => $description,
                'start'       => ['date' => $day],
                'end'         => ['date' => Carbon::parse($day)->addDay()->toDateString()],
            ],
        );

        if (! $res['ok']) {
            return $res;
        }

        return [
            'ok'   => true,
            'id'   => (string) ($res['body']['id'] ?? ''),
            'link' => (string) ($res['body']['htmlLink'] ?? ''),
        ];
    }

    /**
     * حذفِ رویداد از تقویمِ گوگل.
     *
     * ⚠️ گوگل برای حذفِ موفق `204 No Content` می‌دهد — بدنهٔ خالی، نه JSON.
     *
     * ⚠️ رویدادی که **از قبل نیست** (۴۰۴ یا ۴۱۰) موفق شمرده می‌شود: مقصد همان
     * چیزی است که کاربر خواسته («این نباشد»), و خطادادن بابتِ کاری که لازم
     * نبوده فقط او را سردرگم می‌کند. همان قاعدهٔ `releaseServer()` در این
     * پروژه که حذفِ سرورِ ازقبل‌نبود را «موفق» می‌شمارد.
     *
     * @return array{ok:bool, error?:string}
     */
    public function deleteEvent(GoogleCalendarToken $token, string $eventId): array
    {
        $access = $this->validAccessToken($token);

        if ($access === null) {
            return ['ok' => false, 'error' => $token->last_error ?: 'no_token'];
        }

        try {
            $response = Http::withToken($access)->timeout(self::TIMEOUT)->delete(
                self::API.'/calendars/'.rawurlencode($token->calendar_id ?: 'primary')
                .'/events/'.rawurlencode($eventId),
            );
        } catch (\Throwable $e) {
            ErrorTracker::noteOnce('google-calendar', 'حذفِ رویدادِ گوگل شکست خورد: '.$e->getMessage(), 900);

            return ['ok' => false, 'error' => 'network'];
        }

        if ($response->successful() || in_array($response->status(), [404, 410], true)) {
            return ['ok' => true];
        }

        $body = (array) ($response->json() ?? []);
        $err = is_array($body['error'] ?? null)
            ? (string) ($body['error']['message'] ?? 'http_'.$response->status())
            : (string) ($body['error'] ?? 'http_'.$response->status());

        return ['ok' => false, 'error' => $err];
    }

    /* ==================================================================== */

    /** @return array{ok:bool, body?:array<string,mixed>, error?:string} */
    private function post(string $url, array $form): array
    {
        return $this->wrap(fn () => Http::asForm()->timeout(self::TIMEOUT)->post($url, $form));
    }

    /** @return array{ok:bool, body?:array<string,mixed>, error?:string} */
    private function postJson(string $url, string $access, array $payload): array
    {
        return $this->wrap(fn () => Http::withToken($access)->timeout(self::TIMEOUT)->post($url, $payload));
    }

    /** @return array{ok:bool, body?:array<string,mixed>, error?:string} */
    private function get(string $url, string $access, array $query): array
    {
        return $this->wrap(fn () => Http::withToken($access)->timeout(self::TIMEOUT)->get($url, $query));
    }

    /**
     * تنها جایی که با شبکه حرف می‌زند — و تنها جایی که استثنا گرفته می‌شود.
     *
     * @return array{ok:bool, body?:array<string,mixed>, error?:string}
     */
    private function wrap(callable $call): array
    {
        try {
            $response = $call();
        } catch (\Throwable $e) {
            ErrorTracker::noteOnce('google-calendar', 'تماس با گوگل شکست خورد: '.$e->getMessage(), 900);

            return ['ok' => false, 'error' => 'network'];
        }

        $body = (array) ($response->json() ?? []);

        if ($response->failed()) {
            /*
             * گوگل خطا را در دو شکل می‌دهد: `error` رشته‌ای (روی مسیرِ توکن) و
             * `error.message` شیئی (روی مسیرِ API). هر دو خوانده می‌شود، وگرنه
             * نیمی از خطاها «نامشخص» گزارش می‌شدند.
             */
            $err = is_array($body['error'] ?? null)
                ? (string) ($body['error']['message'] ?? 'http_'.$response->status())
                : (string) ($body['error'] ?? 'http_'.$response->status());

            return ['ok' => false, 'error' => $err];
        }

        return ['ok' => true, 'body' => $body];
    }

    /**
     * ایمیل از `id_token` — بدونِ تأییدِ امضا، و این عمدی است.
     *
     * ⚠️ توکن **مستقیم از خودِ گوگل روی TLS** آمده، نه از مرورگرِ کاربر، پس
     * برای یک برچسبِ نمایشی («وصل به: …») اعتبارش کافی است. اگر روزی این مقدار
     * جایی برای **تصمیم‌گیری** استفاده شد، آن‌وقت امضا باید تأیید شود.
     */
    private function emailFromIdToken(?string $idToken): ?string
    {
        if (blank($idToken)) {
            return null;
        }

        $parts = explode('.', $idToken);

        if (count($parts) < 2) {
            return null;
        }

        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), false), true);

        return is_array($payload) ? ($payload['email'] ?? null) : null;
    }
}
