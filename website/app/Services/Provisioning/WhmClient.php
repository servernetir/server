<?php

namespace App\Services\Provisioning;

use App\Models\Server;
use Illuminate\Support\Facades\Http;

/**
 * کلاینتِ WHM API 1 — لایهٔ HTTP.
 *
 * WHM روی خطا هم می‌تواند HTTP 200 بدهد و موفقیت در بدنه است
 * (metadata.result: 1 موفق، 0 ناموفق؛ metadata.reason پیام). پس مثلِ
 * OpenProviderClient به کدِ HTTP تکیه نمی‌کنیم و بدنه را می‌خوانیم.
 *
 * احراز: هدرِ  Authorization: whm <user>:<api-token>  روی پورت ۲۰۸۷.
 * گواهیِ WHM اغلب self-signed است؛ اگر سرور verify_tls=false باشد بررسیِ
 * گواهی خاموش می‌شود (پیش‌فرض روشن و امن).
 */
class WhmClient
{
    public function __construct(private Server $server) {}

    /**
     * فراخوان‌هایی که ذاتاً کُندند — ثانیه.
     *
     * 🔴 `createacct` یک درخواستِ ساده نیست: کاربر و سهمیه، vhostِ اپاچی و
     * reload، zoneِ DNS و reloadِ named، ایمیل و FTP، و قلّاب‌های پس از ساخت.
     * روی نودِ شلوغ به‌راحتی از ۳۰ ثانیه رد می‌شود — و ۳۰ ثانیه هم انتخاب نشده
     * بود، پیش‌فرضِ خودِ لاراول بود.
     *
     * ⚠️ فقط همین یکی بودجهٔ بلند می‌گیرد. خواندنی‌ها (accountsummary و…) روی
     * ۳۰ می‌مانند وگرنه یک WHMِ هنگ‌کرده صفحهٔ پنل را هم نگه می‌دارد.
     */
    private const SLOW = ['createacct' => 180];

    /** بودجهٔ همان فراخوان‌های کُند وقتی از **وب** صدا زده می‌شوند */
    private const SLOW_WEB = 55;

    /** @return array{ok:bool,transport:bool,reason:string,data:array,raw:array} */
    public function call(string $function, array $params = []): array
    {
        $base = 'https://'.$this->server->hostname.':'.$this->server->effectivePort().'/json-api/'.$function;

        try {
            $req = Http::acceptJson()
                ->connectTimeout(10)
                ->timeout($this->budgetFor($function))
                // ⚠️ `retry(1)` یعنی **یک** تلاش و صفر تکرار (لاراول $times را
                // کلِ تلاش‌ها می‌گیرد). برای `createacct` عددِ درست همین است:
                // ساختِ حساب idempotent نیست و تلاشِ دوباره یا حسابِ دوم می‌سازد
                // یا «این نام از قبل هست» می‌گیرد. دست نزن.
                ->retry(1, 500, throw: false)
                ->withHeaders([
                    'Authorization' => 'whm '.$this->server->username.':'.(string) $this->server->api_token,
                ]);

            if (! $this->server->verify_tls) {
                $req = $req->withoutVerifying();
            }

            $resp = $req->get($base, array_merge(['api.version' => 1], $params));
        } catch (\Throwable $e) {
            /*
            | 🔴 «نشنیدیم» با «نه گفت» یکی نیست — و تا امروز یکی بود.
            |
            | هر دو `ok=false` می‌دادند و تنها فرقشان یک پیشوندِ فارسی بود که هیچ
            | کدی نمی‌خوانَد. نتیجه‌اش دقیقاً رخدادِ zhina.shop: تایم‌اوت خوردیم،
            | حساب آن‌طرف **ساخته شد**، و ما «تحویل ناموفق» به مشتری گفتیم.
            |
            | این پرچم تنها راهِ ماشین‌خوان برای تشخیصِ آن حالت است.
            */
            return [
                'ok' => false, 'transport' => true,
                'reason' => 'ارتباط با سرور برقرار نشد: '.mb_substr($e->getMessage(), 0, 160),
                'data' => [], 'raw' => [],
            ];
        }

        $json = $resp->json();
        if (! is_array($json)) {
            /*
            | ⚠️ این‌جا عمداً `transport` **نیست**.
            |
            | بدنهٔ نامعتبر یعنی سرور جواب داد ولی جوابش WHM نبود: توکنِ باطل،
            | آی‌پیِ بیرونِ allowlistِ cPHulk، صفحهٔ ۴۰۳ِ WAF، پورتِ اشتباه،
            | میزبانِ عوض‌شده. اینها **خرابیِ پایدارِ پیکربندی**‌اند نه سکسکهٔ
            | گذرا، و اگر «نمی‌دانم» بخوانیمشان، سرویس در حالتِ ساکتِ دستی
            | می‌نشیند و ماه‌ها کسی خبردار نمی‌شود. شکستِ صریح این‌جا درست است.
            */
            return [
                'ok' => false, 'transport' => false,
                'reason' => 'پاسخِ نامعتبر از سرور (HTTP '.$resp->status().')',
                'data' => [], 'raw' => [],
            ];
        }

        // WHM API 1: metadata.result = 1 موفق / 0 ناموفق
        $result = (int) ($json['metadata']['result'] ?? ($json['result']['status'] ?? 0));
        $reason = (string) ($json['metadata']['reason'] ?? ($json['result']['statusmsg'] ?? 'unknown'));

        return [
            'ok'        => $result === 1,
            'transport' => false,
            'reason'    => $reason,
            'data'      => $json['data'] ?? [],
            'raw'       => $json,
        ];
    }

    /**
     * بودجهٔ زمانیِ این فراخوان.
     *
     * ⚠️ ۱۸۰ ثانیه فقط در **کرون**. دکمهٔ «تحویلِ دستی» در پنل همین مسیر را
     * هم‌زمان صدا می‌زند و پشتِ Cloudflare نشسته؛ درخواستی که سه دقیقه طول
     * بکشد، آن‌جا قطع می‌شود و سرویس در حالتِ `running` جا می‌مانَد و تا ۱۵
     * دقیقه هیچ‌کس برش نمی‌دارد — یعنی همان خرابی از درِ دیگر.
     */
    private function budgetFor(string $function): int
    {
        $slow = self::SLOW[$function] ?? null;

        if ($slow === null) {
            return 30;
        }

        return app()->runningInConsole() ? $slow : self::SLOW_WEB;
    }

    public function createAccount(array $params): array
    {
        return $this->call('createacct', $params);
    }

    // ───────────────────────── نمایندگی (reseller) ─────────────────────────

    /*
    | ساختِ نماینده در WHM **سه** تماس است، نه یکی. `createacct` با
    | `reseller=1` فقط بیتِ «این کاربر نماینده است» را می‌گذارد؛ بی‌دو تماسِ
    | بعدی، نماینده‌ای می‌سازیم که:
    |   • هیچ ACLای ندارد ⇒ وارد WHM می‌شود و **هیچ دکمه‌ای** نمی‌بیند
    |   • هیچ سقفی ندارد ⇒ می‌تواند تا پرشدنِ کلِ نود اکانت بسازد، یعنی
    |     نمایندهٔ ۱۰ اکانتی عملاً نامحدود است و بقیهٔ مشتریانِ همان نود قربانی.
    |
    | هر دو خرابی **بی‌صدا**ست: تحویل «موفق» ثبت می‌شود و رمز ایمیل می‌شود.
    */

    /**
     * سقفِ منابعِ نماینده — دیسک/پهنای‌باند بر حسب **مگابایت**.
     *
     * ⚠️ `setresellerlimits` برای «نامحدود» پرچمِ جدا می‌خواهد
     * (`enable_account_limit=0`)، نه عددِ ۰ در فیلدِ سقف. اگر عدد ۰ بفرستی،
     * WHM آن را «سقف = صفر» می‌فهمد و نماینده **هیچ** اکانتی نمی‌تواند بسازد.
     */
    public function setResellerLimits(string $user, ?int $accounts, ?int $diskMb, ?int $bwMb): array
    {
        $p = ['user' => $user];

        $p['enable_account_limit'] = $accounts !== null ? 1 : 0;
        if ($accounts !== null) {
            $p['account_limit'] = $accounts;
        }

        // دیسک و پهنای‌باند یک پرچمِ **مشترک** دارند (`enable_resource_limits`),
        // پس اگر فقط یکی‌شان را بدهی، آن یکی هم فعال می‌شود و مقدارش پیش‌فرضِ
        // WHM می‌شود. هر دو با هم یا هیچ‌کدام.
        $hasResource = $diskMb !== null || $bwMb !== null;
        $p['enable_resource_limits'] = $hasResource ? 1 : 0;
        if ($hasResource) {
            $p['diskspace_limit'] = $diskMb ?? 0;
            $p['bandwidth_limit'] = $bwMb ?? 0;
        }

        return $this->call('setresellerlimits', $p);
    }

    /**
     * مجوزهای نماینده. `acllist` نامِ یک ACL از پیش‌ساخته در WHM است.
     *
     * ⚠️ ACLِ نبود، خطای صریح می‌دهد و همان درست است: بی‌ACL پنل خالی است، و
     * سقوطِ بی‌صدا به «همه‌چیز» یعنی دادنِ دسترسیِ روت‌گونه به مشتری.
     */
    public function setResellerAcl(string $user, string $acl): array
    {
        return $this->call('setacls', ['reseller' => $user, 'acllist' => $acl]);
    }

    /** آیا این کاربر همین حالا نماینده است؟ null = نتوانستیم بپرسیم */
    public function isReseller(string $user): ?bool
    {
        $res = $this->call('listresellers');

        if (! $res['ok']) {
            return null;
        }

        $list = $res['data']['reseller'] ?? [];

        // WHM بسته به نسخه یا فهرستِ رشته می‌دهد یا فهرستِ آبجکت
        foreach ((array) $list as $row) {
            $name = is_array($row) ? ($row['user'] ?? $row['name'] ?? null) : $row;
            if ((string) $name === $user) {
                return true;
            }
        }

        return false;
    }

    public function accountSummary(string $user): array
    {
        return $this->call('accountsummary', ['user' => $user]);
    }

    /**
     * مصرفِ پهنای‌باندِ ماهِ جاری — پرتکرارترین پرسشِ پشتیبانیِ هاست.
     *
     * ⚠️ `accountsummary` پهنای‌باند **ندارد**؛ تنها راهِ گرفتنش همین `showbw`
     * است. اگر توکنِ WHM دسترسیِ این تابع را نداشته باشد، `ok=false` برمی‌گردد
     * و فراخوان باید بی‌سروصدا از کنارش رد شود — نبودِ یک عدد نباید کلِ کارتِ
     * سرویس را خالی کند.
     */
    public function bandwidth(string $user): array
    {
        // 🔴 `search` در WHM یک **عبارتِ باقاعده** است، نه تطبیقِ دقیق، و
        // `showbw` **فهرست** برمی‌گرداند. `search=shop` حسابِ `bigshop` را هم
        // می‌گیرد — یعنی مصرفِ مشتریِ دیگری به این مشتری نشان داده می‌شد.
        // مهار می‌کنیم و کاراکترهای ویژه را هم فرار می‌دهیم.
        return $this->call('showbw', [
            'searchtype' => 'user',
            'search'     => '^'.preg_quote($user, '/').'$',
        ]);
    }
    public function suspend(string $user, string $reason = ''): array
    {
        return $this->call('suspendacct', ['user' => $user, 'reason' => $reason]);
    }

    public function unsuspend(string $user): array
    {
        return $this->call('unsuspendacct', ['user' => $user]);
    }

    public function terminate(string $user): array
    {
        return $this->call('removeacct', ['user' => $user, 'keepdns' => 0]);
    }

    public function changePassword(string $user, string $password): array
    {
        return $this->call('passwd', ['user' => $user, 'password' => $password, 'db_pass_update' => 1]);
    }

    public function listPackages(): array
    {
        return $this->call('listpkgs');
    }

    /** ساختِ package (پلن) در WHM — quota/bwlimit بر حسب MB (۰ = نامحدود) */
    public function addPackage(array $params): array
    {
        // ⚠️ WHM برای «نامحدود» رشتهٔ 'unlimited' می‌خواهد و مقدارِ 0 را برای
        // **هر دو**ِ quota و bwlimit رد می‌کند:
        //   Invalid value "0" for the "bwlimit" setting.
        //   Invalid value "0" for the "quota" setting.
        // پس هیچ‌وقت 0 نمی‌فرستیم؛ اگر از مشخصات چیزی درنیامد، unlimited.
        $p = array_merge([
            'quota'    => 'unlimited', 'bwlimit' => 'unlimited',
            'maxpop'   => 'unlimited', 'maxftp'  => 'unlimited', 'maxsql'  => 'unlimited',
            'maxsub'   => 'unlimited', 'maxpark' => 'unlimited', 'maxaddon' => 'unlimited',
            'hasshell' => 'n', 'cgi' => 'y',
        ], $params);

        return $this->call('addpkg', $p);
    }

    /**
     * اصلاحِ packageِ موجود با همان حدومرزها.
     *
     * لازم است چون addpkg روی packageِ موجود «exists» می‌دهد و اگر فقط ردش کنیم،
     * packageی که یک‌بار با حدومرزِ غلط ساخته شده تا ابد غلط می‌ماند و اجرای
     * دوبارهٔ sync اصلاحش نمی‌کند.
     */
    public function editPackage(array $params): array
    {
        return $this->call('editpkg', $params);
    }

    /**
     * ساختِ نشستِ ورودِ یک‌بارمصرف به cPanelِ کاربر — برای «ورودِ یک‌کلیکی».
     * خروجی data.url یک آدرسِ ورودِ ازپیش‌احرازشده است.
     */
    public function createUserSession(string $user, string $service = 'cpaneld'): array
    {
        return $this->call('create_user_session', ['user' => $user, 'service' => $service]);
    }

    /** آیا حساب از قبل روی سرور هست؟ (برای idempotency) */
    public function accountExists(string $user): bool
    {
        return $this->accountState($user) === true;
    }

    /**
     * وضعیتِ حساب — **سه‌حالته**، نه دو‌حالته.
     *
     *   true  = هست، و همانی است که ما فروخته‌ایم
     *   false = نیست (WHM صریح گفت)
     *   null  = **نتوانستیم بپرسیم** — نه «نیست»
     *
     * 🔴 چرا `null` لازم است: نسخهٔ قبلی `ok === true` بود، پس یک قطعیِ گذرا در
     * لحظهٔ پرسیدن «حساب وجود ندارد» خوانده می‌شد و ما می‌رفتیم `createacct`
     * بزنیم روی حسابی که زنده است. همان قاعده‌ای که `CloudInventory` سرش درس
     * گرفت: فهرستِ خالیِ ناموفق یعنی «نپرسیدیم»، نه «چیزی نیست».
     *
     * ⚠️ **صرفِ وجودِ نام‌کاربری کافی نیست.** `accountsummary` برای حسابِ
     * معلق، و برای حسابی که دامنه‌اش با آنچه فروخته‌ایم نمی‌خوانَد، هم
     * `result=1` می‌دهد. پذیرفتنِ کورِ آن یعنی: رکوردِ DNSِ زیردامنهٔ رایگان به
     * حسابِ اشتباه اشاره کند، و رمزی ایمیل شود که رمزِ آن حساب نیست. پس ردیف
     * باید **تطبیق داده شود**؛ ناهماهنگی ⇒ `false` (یعنی «این آن نیست»).
     *
     * @param  string|null  $domain  اگر بدهی، دامنهٔ حساب هم سنجیده می‌شود
     */
    public function accountState(string $user, ?string $domain = null): ?bool
    {
        $r = $this->accountSummary($user);

        if (($r['transport'] ?? false) === true) {
            return null;                       // نپرسیدیم — تصمیم را به فراخوان بسپار
        }

        if ($r['ok'] !== true) {
            return false;                      // WHM صریح گفت نیست
        }

        $rows = (array) ($r['data']['acct'] ?? []);
        $row  = is_array($rows[0] ?? null) ? $rows[0] : null;

        if ($row === null) {
            // ok=true ولی بی‌ردیف: نمی‌شود تطبیق داد، پس ادعا هم نمی‌کنیم
            return null;
        }

        if (strcasecmp((string) ($row['user'] ?? ''), $user) !== 0) {
            return false;
        }

        if ($domain !== null && $domain !== ''
            && strcasecmp((string) ($row['domain'] ?? ''), $domain) !== 0) {
            return false;                      // حسابِ دیگری با همین نام
        }

        return filter_var($row['suspended'] ?? false, FILTER_VALIDATE_BOOLEAN) ? false : true;
    }
}
