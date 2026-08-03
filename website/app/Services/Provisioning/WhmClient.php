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

    /** @return array{ok:bool,reason:string,data:array,raw:array} */
    public function call(string $function, array $params = []): array
    {
        $base = 'https://'.$this->server->hostname.':'.$this->server->effectivePort().'/json-api/'.$function;

        try {
            $req = Http::acceptJson()
                ->timeout(30)
                ->retry(1, 500, throw: false)
                ->withHeaders([
                    'Authorization' => 'whm '.$this->server->username.':'.(string) $this->server->api_token,
                ]);

            if (! $this->server->verify_tls) {
                $req = $req->withoutVerifying();
            }

            $resp = $req->get($base, array_merge(['api.version' => 1], $params));
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => 'ارتباط با سرور برقرار نشد: '.mb_substr($e->getMessage(), 0, 160), 'data' => [], 'raw' => []];
        }

        $json = $resp->json();
        if (! is_array($json)) {
            return ['ok' => false, 'reason' => 'پاسخِ نامعتبر از سرور (HTTP '.$resp->status().')', 'data' => [], 'raw' => []];
        }

        // WHM API 1: metadata.result = 1 موفق / 0 ناموفق
        $result = (int) ($json['metadata']['result'] ?? ($json['result']['status'] ?? 0));
        $reason = (string) ($json['metadata']['reason'] ?? ($json['result']['statusmsg'] ?? 'unknown'));

        return [
            'ok'     => $result === 1,
            'reason' => $reason,
            'data'   => $json['data'] ?? [],
            'raw'    => $json,
        ];
    }

    public function createAccount(array $params): array
    {
        return $this->call('createacct', $params);
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
        $r = $this->accountSummary($user);

        // اگر حساب نباشد WHM result=0 با reason حاویِ «does not exist» می‌دهد
        return $r['ok'] === true;
    }
}
