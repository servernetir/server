<?php

namespace App\Services\Bale\Admin;

use App\Models\Setting;
use App\Models\User;
use App\Services\Notify\AdminNotifier;
use App\Services\Otp\OtpService;
use App\Support\ErrorTracker;
use Illuminate\Support\Facades\DB;

/**
 * دروازهٔ کنسولِ مدیر در بله — «آیا این پیام واقعاً از کارفرماست؟»
 *
 * ═══ فرضِ پایه: آدرسِ وب‌هوک لو رفته است ═══
 *
 * مسیرِ وب‌هوک `substr(sha256(BALE_BOT_TOKEN), 0, 32)` است. آن رشته:
 *   • در لاگِ دسترسیِ cPanel و Cloudflare می‌نشیند (هر دو میزبان)
 *   • در `ErrorTracker::exception()` با `$request->fullUrl()` ثبت می‌شود
 *   • در بدنهٔ پاسخِ `POST /system/bale-setup` چاپ می‌شود
 *   • و **قابلِ چرخاندن نیست** مگر توکنِ ربات عوض شود
 *
 * و بدتر: `setWebhook` بله — برخلافِ تلگرام — پارامترِ `secret_token` ندارد
 * (مستنداتِ رسمی، مرداد ۱۴۰۵)، پس هیچ هدرِ امضاداری هم در کار نیست. یعنی
 * **تنها** محافظِ وب‌هوک همان رشتهٔ داخلِ URL است.
 *
 * پس این کلاس طوری نوشته شده که لو رفتنِ آن آدرس، مهاجم را مدیر **نکند**:
 *
 * ┌─ وب‌هوک برای مهاجم فقط‌نوشتنی است ─────────────────────────────────┐
 * │ هر کدی (اتصال، تأیید) سمتِ سرور ساخته می‌شود و فقط از دو راه بیرون   │
 * │ می‌رود: ایمیلِ مدیر، یا چتِ **متصل‌شده**. پاسخِ وب‌هوک هم روی هر مسیر   │
 * │ ثابتِ {"ok":true} است، پس مهاجم تزریق می‌کند ولی چیزی نمی‌بیند.       │
 * └────────────────────────────────────────────────────────────────────┘
 *
 * ═══ 🔴 حفره‌ای که در بازبینیِ تهاجمی پیدا شد و این‌جا بسته شده ═══
 *
 * طرحِ اولیه یک «کدِ اتصال» دست‌ساز داشت بدونِ طول، بدونِ انقضا و بدونِ سقفِ
 * تلاش — و در حالیکه هر چتِ نامتصلی حق داشت `/pair` بزند. یعنی هر کسی با
 * آدرسِ وب‌هوک می‌توانست کد را brute-force کند و **خودش** را به حسابِ مدیر
 * ببندد؛ از آن لحظه همهٔ کدهای تأیید هم به خودش می‌رسید.
 *
 * رفع: کدِ اتصال از `OtpService` می‌آید — ۶ رقم، ۳ دقیقه اعتبار، **سقفِ ۱۰
 * تلاش** که خودِ چالش اعمالش می‌کند، ذخیره به‌صورت HMAC، یک‌بارمصرف، و
 * `purpose`ِ جدا (`admin_bale_bind`) تا هرگز با کدِ ورودِ مدیر قاطی نشود. کد
 * فقط به **ایمیلِ** مدیر می‌رود — کانالی که دارندهٔ وب‌هوک در اختیار ندارد.
 * ۱۰ حدس از ۱۰۰۰۰۰۰ حالت، در پنجرهٔ ۳ دقیقه‌ای، آن هم فقط وقتی خودِ مدیر
 * اتصال را از پنلِ دومرحله‌ای شروع کرده باشد.
 */
class AdminBaleGate
{
    /** کلیدِ خاموش/روشن — عمداً ساده و **بدونِ** رمزنگاری تا همیشه خوانده شود */
    public const KEY_ENABLED = 'bale_admin_enabled';

    /** اتصالِ فعلی: {chat_id, user_id, at} — رمزنگاری‌شده */
    public const KEY_BIND = 'bale_admin_bind';

    /** اتصالِ در انتظار: {user_id, email, at} — رمزنگاری‌شده */
    public const KEY_PENDING = 'bale_admin_pair';

    /** کارِ در انتظارِ تأیید + حلقهٔ update_idها — رمزنگاری‌شده */
    public const KEY_STATE = 'bale_admin_state';

    /** هدفِ کدِ اتصال — جدا از `admin_login` تا کدها جابه‌جا مصرف نشوند */
    public const PAIR_PURPOSE = 'admin_bale_bind';

    /** عمرِ کدِ تأییدِ هر نوشتن (ثانیه) */
    public const CONFIRM_TTL = 180;

    /** بیش از این کدِ تأییدِ اشتباه ⇒ کار لغو و به مدیر ایمیل می‌رود */
    public const CONFIRM_MAX_TRIES = 3;

    /** چند update_idِ اخیر یادمان بماند (ضدِ ارسالِ دوبارهٔ بله) */
    private const SEEN_RING = 20;

    // ───────────────────────────── وضعیت ─────────────────────────────

    public function enabled(): bool
    {
        try {
            return Setting::get(self::KEY_ENABLED) === '1';
        } catch (\Throwable) {
            return false;                 // دیتابیس نبود ⇒ بسته، نه باز
        }
    }

    /**
     * اتصالِ فعلی، یا null.
     *
     * 🔴 **تفکیکِ «هرگز متصل نشده» از «اتصال خوانده نشد».**
     *
     * `Setting::getSecret()` وقتی رمزگشایی شکست بخورد بی‌صدا `null` می‌دهد —
     * یعنی چرخاندنِ `APP_KEY`، یا بازگرداندنِ یک بکاپِ قدیمیِ `settings`، یا یک
     * بایتِ خرابِ ciphertext، همگی شبیهِ «هنوز متصل نشده» می‌شوند. و «هنوز متصل
     * نشده» یعنی پنجرهٔ `/pair` دوباره برای کلِ اینترنت باز است، در حالی که
     * کلیدِ روشن/خاموش (که رمزنگاری‌شده **نیست**) دست‌نخورده روی `1` مانده.
     *
     * یعنی دقیقاً برعکسِ چیزی که انتظار می‌رود: نه «بسته می‌میرد»، بلکه **باز**
     * می‌میرد. پس اگر مقدارِ خام باشد ولی رمزگشایی نشود، خودمان کلید را
     * می‌بندیم و فریاد می‌زنیم.
     *
     * @return array{chat_id:string,user_id:int,at:?string}|null
     */
    public function binding(): ?array
    {
        try {
            $raw = Setting::get(self::KEY_BIND);

            if (blank($raw)) {
                return null;                          // واقعاً هرگز متصل نشده
            }

            $dec = Setting::getSecret(self::KEY_BIND);

            if ($dec === null) {
                Setting::put(self::KEY_ENABLED, '0');  // fail-closed، نه fail-open
                ErrorTracker::noteOnce('bale-admin',
                    'اتصالِ رباتِ بله رمزگشایی نشد (APP_KEY عوض شده؟) — کنسول بسته شد.', 3600);

                return null;
            }

            $bind = json_decode($dec, true);

            return is_array($bind) && isset($bind['chat_id'], $bind['user_id']) ? $bind : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * آیا همین حالا یک «اتصالِ در انتظار» باز است؟
     *
     * 🔴 این متد از یک بن‌بستِ واقعی آمد (کارفرما گزارش داد): کلیدِ روشن/خاموش
     * پیش‌فرضش خاموش است، `matches()` پیش از هر چیز آن را می‌سنجید، و پنل هم
     * روشن‌کردن را به «اول متصل شو» مشروط می‌کرد. یعنی:
     *
     *     نمی‌شد /pair زد چون کنسول خاموش بود
     *     نمی‌شد روشن کرد چون هنوز متصل نبود
     *
     * و نشانه‌اش دقیقاً همان چیزی بود که کارفرما دید: `/pair` از شاخهٔ کنسول رد
     * می‌شد و به دکمهٔ «اشتراکِ شماره» می‌افتاد.
     *
     * ⚠️ رفع با «کلید را پیش از اتصال روشن کن» **نبود** — آن پنجرهٔ `/pair` را
     * برای کلِ اینترنت باز نگه می‌داشت. حالا دروازهٔ پنجرهٔ اتصال، وجودِ همین
     * رکوردِ در انتظار است: فقط وقتی ساخته می‌شود که مدیر در پنلِ دومرحله‌ای
     * دکمه را زده باشد، و خودش هم عمر دارد. یعنی از کلیدِ روشن/خاموش **تنگ‌تر**
     * است، نه گشادتر.
     */
    public function pairingPending(): bool
    {
        try {
            $p = json_decode((string) Setting::getSecret(self::KEY_PENDING), true);

            if (! is_array($p) || ! isset($p['user_id'], $p['at'])) {
                return false;
            }

            // پنجره‌ای که خیلی از عمرِ کدِ ۳ دقیقه‌ایِ OTP بازتر نباشد
            return \Illuminate\Support\Carbon::parse($p['at'])->gt(now()->subMinutes(15));
        } catch (\Throwable) {
            return false;
        }
    }

    /** کاربرِ متصل، فقط اگر **هنوز** مدیر باشد */
    public function boundUser(): ?User
    {
        $bind = $this->binding();

        if ($bind === null) {
            return null;
        }

        try {
            $user = User::find((int) $bind['user_id']);
        } catch (\Throwable) {
            return null;
        }

        // ⚠️ هر بار دوباره پرسیده می‌شود، نه یک بار در لحظهٔ اتصال: اگر کسی از
        // نقشِ مدیر برداشته شود، همان پیامِ بعدی باید بی‌اثر باشد.
        return ($user !== null && $user->isAdmin()) ? $user : null;
    }

    /** آیا این `from.id` همان چتِ متصل است؟ */
    public function isBoundChat(string $chatId): bool
    {
        $bind = $this->binding();

        return $bind !== null && $chatId !== '' && hash_equals((string) $bind['chat_id'], $chatId);
    }

    // ───────────────────────── اتصال (pairing) ─────────────────────────

    /**
     * شروعِ اتصال — کد به **ایمیلِ** همان مدیر می‌رود، نه روی صفحه.
     *
     * ⚠️ کد عمداً در پنل نمایش داده نمی‌شود. اگر روی صفحه بیاید، تنها چیزی که
     * برای تصاحبِ کنسول لازم است یک جلسهٔ بازِ مرورگر روی لپ‌تاپِ مشترک است؛
     * با ایمیل، مهاجم باید هم پنل را داشته باشد هم صندوقِ ایمیل را.
     *
     * @return array{ok:bool,message:string}
     */
    public function beginPairing(User $admin): array
    {
        if (! $admin->isAdmin()) {
            return ['ok' => false, 'message' => 'فقط مدیر می‌تواند ربات را متصل کند.'];
        }

        if (blank($admin->email)) {
            return ['ok' => false, 'message' => 'این حساب ایمیل ندارد؛ کدِ اتصال جایی برای رفتن ندارد.'];
        }

        $issue = app(OtpService::class)->issue('email', (string) $admin->email, self::PAIR_PURPOSE, null);

        if (! $issue->ok) {
            return ['ok' => false, 'message' => (string) ($issue->error ?: 'ارسالِ کدِ اتصال انجام نشد.')];
        }

        Setting::putSecret(self::KEY_PENDING, json_encode([
            'user_id' => (int) $admin->id,
            'email'   => (string) $admin->email,
            'at'      => now()->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE));

        return [
            'ok' => true,
            'message' => 'کدِ ۶ رقمی به '.$admin->email.' فرستاده شد. ظرفِ ۳ دقیقه در بله بزنید: '
                .'«/pair ۱۲۳۴۵۶» (با رقمِ انگلیسی).',
        ];
    }

    /**
     * مصرفِ کدِ اتصال از سمتِ بله.
     *
     * سقفِ تلاش را **خودِ `OtpService`** اعمال می‌کند (۱۰ تلاش روی همان چالش، و
     * پس از آن چالش سوخته می‌شود) — پس این‌جا شمارندهٔ موازی نمی‌سازیم که روزی
     * با آن یکی اختلاف پیدا کند.
     *
     * @return array{ok:bool,message:string}
     */
    public function completePairing(string $code, string $chatId): array
    {
        if ($chatId === '') {
            return ['ok' => false, 'message' => 'اتصال ممکن نشد.'];
        }

        $pending = json_decode((string) Setting::getSecret(self::KEY_PENDING), true);

        if (! is_array($pending) || ! isset($pending['user_id'], $pending['email'])) {
            return ['ok' => false, 'message' => 'درخواستِ اتصالی در جریان نیست. از پنلِ مدیریت شروع کنید.'];
        }

        $check = app(OtpService::class)->verify('email', (string) $pending['email'], self::PAIR_PURPOSE, $code);

        if (! $check->ok) {
            /*
            | تلاشِ ناموفق **از راهِ ایمیل** هم به مدیر خبر می‌دهد، نه فقط در چت:
            | اگر کسی دارد کد را حدس می‌زند، او دقیقاً همان کسی است که چت را در
            | اختیار ندارد — پس هشدارِ درونِ چت به دستِ مهاجم می‌رسد نه صاحبِ کار.
            */
            ErrorTracker::noteOnce('bale-admin', 'کدِ اتصالِ رباتِ بله اشتباه وارد شد.', 900,
                ['chat' => substr(hash('sha256', $chatId), 0, 8)]);

            return ['ok' => false, 'message' => (string) ($check->error ?: 'کد درست نیست.')];
        }

        $user = User::find((int) $pending['user_id']);

        if ($user === null || ! $user->isAdmin()) {
            Setting::putSecret(self::KEY_PENDING, null);

            return ['ok' => false, 'message' => 'حسابِ مدیرِ این درخواست دیگر معتبر نیست.'];
        }

        Setting::putSecret(self::KEY_BIND, json_encode([
            'chat_id' => $chatId,
            'user_id' => (int) $user->id,
            'at'      => now()->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE));

        Setting::putSecret(self::KEY_PENDING, null);
        Setting::putSecret(self::KEY_STATE, null);      // هیچ کارِ نیمه‌کاره‌ای از اتصالِ قبلی نمانَد

        /*
        | ⚠️ اتصالِ موفق، کنسول را هم روشن می‌کند.
        |
        | وگرنه کارفرما کدِ ایمیل را می‌زند، «اتصال برقرار شد» می‌بیند، و بعد هر
        | فرمانی بی‌پاسخ می‌مانَد چون کلید هنوز خاموش است — یک بن‌بستِ خاموشِ
        | دیگر. کسی که همین حالا از پنلِ دومرحله‌ای اتصال را شروع کرده، آشکارا
        | می‌خواهد کنسول کار کند. خاموش‌کردن همیشه یک دکمه فاصله دارد.
        */
        Setting::put(self::KEY_ENABLED, '1');

        // ایمیل هم می‌رود: تنها کانالی که یک گوشیِ دزدیده‌شده در اختیار ندارد
        $this->alertOwner('رباتِ بله به یک چتِ تازه متصل شد', [
            'کاربر'   => (string) $user->name,
            'اثرِ چت' => substr(hash('sha256', $chatId), 0, 8),
            'اگر کارِ شما نبود' => 'در /admin/bale اتصال را قطع کنید',
        ]);

        return ['ok' => true, 'message' => 'اتصال برقرار شد.'];
    }

    /** قطعِ اتصال + خاموش‌کردن — یک دکمه، هر دو کار */
    public function revoke(): void
    {
        try {
            Setting::putSecret(self::KEY_BIND, null);
            Setting::putSecret(self::KEY_PENDING, null);
            Setting::putSecret(self::KEY_STATE, null);
            Setting::put(self::KEY_ENABLED, '0');
        } catch (\Throwable $e) {
            ErrorTracker::note('bale-admin', $e, ['step' => 'revoke']);
        }
    }

    public function setEnabled(bool $on): void
    {
        Setting::put(self::KEY_ENABLED, $on ? '1' : '0');
    }

    // ──────────────────────── تأییدِ هر نوشتن ────────────────────────

    /**
     * یک کارِ نوشتنی را مسلح کن و کدِ ۶ رقمی‌اش را برگردان.
     *
     * ۶ رقم و نه ۴: این کد تنها چیزی است که بینِ یک پیامِ جعلی و یک پیامِ
     * **برگشت‌ناپذیرِ پولی به مشتریِ واقعی** ایستاده. ۴ رقم در پنجرهٔ ۳ دقیقه‌ای
     * حدس‌زدنی است؛ ۶ رقم با سقفِ ۳ تلاش نیست.
     *
     * @param  array<string,mixed>  $args
     */
    public function armConfirm(string $verb, array $args, string $human): string
    {
        $code = (string) random_int(100000, 999999);

        $pending = [
            'verb'  => $verb,
            'args'  => $args,
            'human' => $human,
            'hash'  => $this->hash($code),
            'tries' => 0,
            'exp'   => now()->addSeconds(self::CONFIRM_TTL)->getTimestamp(),
        ];

        $this->putState(['pending' => $pending]);

        return $code;
    }

    /**
     * مصرفِ کدِ تأیید. یک‌بارمصرف و اتمی.
     *
     * 🔴 دو محافظ که در بازبینیِ تهاجمی اضافه شدند:
     *
     * ۱) **شمارندهٔ تلاش، پیش از مقایسه نوشته می‌شود.** بی‌آن، کدِ ۶ رقمی در یک
     *    پنجرهٔ ۳ دقیقه‌ای با نرخِ throttle قابلِ جاروب بود و «مسلح‌کردن» هم
     *    رایگان و تکرارپذیر است؛ یعنی سقفِ واقعی نداشت.
     * ۲) **سومین اشتباه کار را لغو می‌کند و به ایمیل خبر می‌دهد.** هشدارِ درونِ
     *    چت به دستِ همان کسی می‌رسد که دارد حدس می‌زند.
     *
     * قفلِ ردیف لازم است: دو `/ok` هم‌زمان نباید هر دو اجرا شوند (یعنی دو پیامِ
     * یکسان به مشتری).
     *
     * @return array{verb:string,args:array,human:string}|null
     */
    public function takeConfirm(string $code): ?array
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        try {
            return DB::transaction(function () use ($code) {
                // قفلِ ردیفِ تنظیمات تا دو تأییدِ هم‌زمان یک کار را دو بار نکنند
                Setting::where('key', self::KEY_STATE)->lockForUpdate()->first();

                $state   = $this->state(fresh: true);
                $pending = $state['pending'] ?? null;

                if (! is_array($pending) || ! isset($pending['hash'], $pending['exp'])) {
                    return null;
                }

                if ((int) $pending['exp'] < now()->getTimestamp()) {
                    $this->putState(['pending' => null]);

                    return null;
                }

                // ⚠️ **پیش از** مقایسه نوشته می‌شود، وگرنه حدسِ نامحدود
                $pending['tries'] = (int) ($pending['tries'] ?? 0) + 1;

                if (! hash_equals((string) $pending['hash'], $this->hash($code))) {
                    if ($pending['tries'] >= self::CONFIRM_MAX_TRIES) {
                        $this->putState(['pending' => null]);

                        ErrorTracker::noteOnce('bale-admin', 'کدِ تأییدِ رباتِ بله ۳ بار اشتباه وارد شد.', 900);
                        $this->alertOwner('کدِ تأییدِ نادرست در رباتِ بله', [
                            'کار' => (string) ($pending['human'] ?? '—'),
                            'اگر کارِ شما نبود' => 'در /admin/bale اتصال را قطع کنید',
                        ]);
                    } else {
                        $this->putState(['pending' => $pending]);
                    }

                    return null;
                }

                // درست بود ⇒ همین‌جا مصرف شود، پیش از اجرای کار
                $this->putState(['pending' => null]);

                return [
                    'verb'  => (string) $pending['verb'],
                    'args'  => (array) ($pending['args'] ?? []),
                    'human' => (string) ($pending['human'] ?? ''),
                ];
            });
        } catch (\Throwable $e) {
            ErrorTracker::note('bale-admin', $e, ['step' => 'takeConfirm']);

            return null;
        }
    }

    /** کارِ مسلحِ فعلی (فقط برای نمایش «چیزی در انتظارِ تأیید هست») */
    public function pendingHuman(): ?string
    {
        $p = $this->state()['pending'] ?? null;

        return is_array($p) && (int) ($p['exp'] ?? 0) >= now()->getTimestamp()
            ? (string) ($p['human'] ?? '') : null;
    }

    // ─────────────────── جریانِ یک‌مرحله‌ای (جستجو) ───────────────────

    /**
     * «منتظرِ یک ورودیِ متنی‌ام» — سبک‌ترین حالتی که این کد پشتیبانی می‌کند.
     *
     * ⚠️ عمر دارد (۱۰ دقیقه). جریانی که تا ابد باز بماند یعنی کارفرما فردا یک
     * پیامِ بی‌ربط می‌فرستد و آن به‌عنوانِ جستجو خوانده می‌شود.
     */
    public function armFlow(string $kind): void
    {
        try {
            $this->putState(['flow' => [
                'kind' => $kind,
                'exp'  => now()->addMinutes(10)->getTimestamp(),
            ]]);
        } catch (\Throwable $e) {
            ErrorTracker::note('bale-admin', $e, ['step' => 'armFlow']);
        }
    }

    /** جریانِ باز، یا null. مصرف نمی‌کند — فقط می‌خوانَد. */
    public function flow(): ?string
    {
        try {
            $f = $this->state()['flow'] ?? null;

            if (! is_array($f) || (int) ($f['exp'] ?? 0) < now()->getTimestamp()) {
                return null;
            }

            return (string) ($f['kind'] ?? '') ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function clearFlow(): void
    {
        try {
            $this->putState(['flow' => null]);
        } catch (\Throwable) {
        }
    }

    // ─────────────────── پیش‌نویسِ هوشِ مصنوعی ───────────────────

    /**
     * پیش‌نویسِ ساخته‌شده را تا لحظهٔ تصمیمِ کارفرما نگه دار.
     *
     * ⚠️ در `callback_data` نمی‌گنجد (سقفِ ۶۴ بایت) و جدولِ تازه هم نمی‌سازیم:
     * مهاجرت‌های پروداکشن دستی اجرا می‌شوند. پس در همان blobِ رمزنگاری‌شدهٔ
     * وضعیت می‌نشیند.
     *
     * ⚠️ فقط **یک** پیش‌نویس در هر لحظه نگه داشته می‌شود. کارفرما در گوشی روی
     * یک تیکت کار می‌کند؛ انباشتنِ پیش‌نویس یعنی blob بزرگ شود و روزی کلیکِ
     * روی دکمهٔ قدیمی متنِ اشتباهی را بفرستد.
     */
    public function putDraft(int $ticketId, string $text): void
    {
        try {
            $this->putState(['draft' => [
                'ticket' => $ticketId,
                'text'   => mb_substr($text, 0, 3000),
                'exp'    => now()->addMinutes(30)->getTimestamp(),
            ]]);
        } catch (\Throwable $e) {
            ErrorTracker::note('bale-admin', $e, ['step' => 'putDraft']);
        }
    }

    /** پیش‌نویسِ همان تیکت، یا null اگر نبود/منقضی/مالِ تیکتِ دیگری بود */
    public function takeDraft(int $ticketId): ?string
    {
        try {
            $d = $this->state()['draft'] ?? null;

            if (! is_array($d) || (int) ($d['ticket'] ?? 0) !== $ticketId) {
                return null;
            }

            if ((int) ($d['exp'] ?? 0) < now()->getTimestamp()) {
                return null;
            }

            return (string) ($d['text'] ?? '') ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    // ───────────────────── ارسالِ دوبارهٔ بله (dedupe) ─────────────────────

    /**
     * آیا این `update_id` را قبلاً دیده‌ایم؟ (و اگر نه، ثبتش کن)
     *
     * بله تحویلِ ناموفق را دوباره می‌فرستد و هیچ‌جای این پروژه dedupe نداشت.
     * ⚠️ این **تنها** محافظِ تکرار نیست و نباید باشد: کدِ تأیید هم یک‌بارمصرف
     * است، پس حتی اگر بله روزی `update_id` نفرستد، کارِ نوشتنی دو بار اجرا
     * نمی‌شود. تستی هر دو نیمه را جدا می‌سنجد.
     */
    public function seenUpdate(?int $updateId): bool
    {
        if ($updateId === null) {
            return false;
        }

        try {
            $state = $this->state();
            $ring  = array_values(array_filter((array) ($state['seen'] ?? []), 'is_int'));

            if (in_array($updateId, $ring, true)) {
                return true;
            }

            $ring[] = $updateId;
            $this->putState(['seen' => array_slice($ring, -self::SEEN_RING)]);

            return false;
        } catch (\Throwable) {
            return false;              // شک ⇒ اجرا کن؛ محافظِ دومِ کدِ تأیید هست
        }
    }

    // ───────────────────────────── داخلی ─────────────────────────────

    /** @return array<string,mixed> */
    private function state(bool $fresh = false): array
    {
        try {
            if ($fresh) {
                $row = Setting::where('key', self::KEY_STATE)->first();
                $raw = $row?->value;

                if (blank($raw)) {
                    return [];
                }

                $dec = \Illuminate\Support\Facades\Crypt::decryptString((string) $raw);

                return (array) (json_decode($dec, true) ?: []);
            }

            return (array) (json_decode((string) Setting::getSecret(self::KEY_STATE), true) ?: []);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * نوشتنِ **یک خانه** از وضعیت، زیرِ قفل.
     *
     * 🔴 نسخهٔ قبلی کلِ blob را بی‌قفل بازنویسی می‌کرد و این یک باگِ واقعی بود،
     * نه احتمالِ نظری: `seenUpdate()` و `putDraft()` هر دو read-modify-write
     * می‌کردند و `state()` از کشِ ۳۰۰ ثانیه‌ایِ `settings.all` می‌خواند.
     *
     * یعنی دو آپدیتِ پشتِ سرِ هم — که در بله عادی است، مثلاً کلیکِ دکمه و پیامِ
     * بعدی — می‌توانستند همدیگر را پاک کنند: یکی `seen` را می‌نوشت و پیش‌نویس
     * را می‌بلعید، یا برعکس. نتیجه‌اش «دکمه گاهی کار نمی‌کند» بود؛ چیزی که
     * هیچ خطایی تولید نمی‌کند و فقط گاه‌به‌گاه دیده می‌شود.
     *
     * ⚠️ قفل این‌جاست نه در فراخوان‌ها: هر نویسندهٔ تازه‌ای که فردا اضافه شود
     * خودبه‌خود امن است. همان سه خطی که `takeConfirm()` از قبل داشت.
     *
     * @param  array<string,mixed>  $patch  فقط خانه‌هایی که عوض می‌شوند
     */
    private function putState(array $patch): void
    {
        DB::transaction(function () use ($patch) {
            Setting::where('key', self::KEY_STATE)->lockForUpdate()->first();

            // زیرِ قفل **تازه** بخوان، وگرنه نسخهٔ کهنهٔ کش را برمی‌گردانی
            $merged = array_merge($this->state(fresh: true), $patch);

            // مقدارِ null یعنی «این خانه را بردار»
            $merged = array_filter($merged, fn ($v) => $v !== null);

            Setting::putSecret(self::KEY_STATE, json_encode($merged, JSON_UNESCAPED_UNICODE));
        });
    }

    /** همان ساختِ `OtpService`: کدِ خام هرگز ذخیره نمی‌شود */
    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    /**
     * هشدارِ امنیتی به مدیر.
     *
     * ⚠️ عمداً `AdminNotifier` است و نه پاسخِ درونِ چت: این متد فقط وقتی صدا
     * می‌شود که احتمال می‌دهیم چت دستِ **کسِ دیگری** باشد، و آن‌وقت تنها کانالِ
     * قابلِ اعتماد ایمیل است.
     *
     * @param  array<string,string>  $rows
     */
    private function alertOwner(string $title, array $rows): void
    {
        try {
            app(AdminNotifier::class)->event($title, $rows, null, '🔐');
        } catch (\Throwable) {
            // هشدار هرگز نباید خودش جریان را بشکند
        }
    }
}
