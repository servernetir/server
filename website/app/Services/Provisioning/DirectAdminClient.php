<?php

namespace App\Services\Provisioning;

use App\Models\Server;
use Illuminate\Support\Facades\Http;

/**
 * کلاینتِ DirectAdmin API — لایهٔ HTTP.
 *
 * احراز: HTTP Basic (کاربرِ ادمین : رمز/کلیدِ ورود) روی پورت ۲۲۲۲. پاسخ
 * به‌صورتِ query-stringِ URL-encoded است؛ error=0 موفق، error=1 ناموفق
 * (text/details پیام). گواهی اغلب self-signed است → با verify_tls=false خاموش.
 */
class DirectAdminClient
{
    public function __construct(private Server $server) {}

    /** @return array{ok:bool,reason:string,data:array,raw:string} */
    public function call(string $command, array $params = [], string $method = 'GET'): array
    {
        $base = 'https://'.$this->server->hostname.':'.$this->server->effectivePort().'/'.$command;

        try {
            $req = Http::asForm()
                ->timeout(30)
                ->retry(1, 500, throw: false)
                ->withBasicAuth($this->server->username, (string) $this->server->api_token);

            if (! $this->server->verify_tls) {
                $req = $req->withoutVerifying();
            }

            $resp = $method === 'POST'
                ? $req->post($base, $params)
                : $req->get($base.(empty($params) ? '' : '?'.http_build_query($params)));
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => 'ارتباط با سرور برقرار نشد: '.mb_substr($e->getMessage(), 0, 160), 'data' => [], 'raw' => ''];
        }

        $body = $resp->body();
        $out = [];
        parse_str($body, $out);

        $error = $out['error'] ?? null;
        $low = strtolower($body);
        $authFail = ! $resp->successful() || str_contains($low, 'not logged in') || str_contains($low, 'unauthorized');

        // موفق = error==0 و شکستِ احراز نباشد (بعضی دستورها روی موفقیت error نمی‌دهند)
        $ok = ! $authFail && ($error === '0' || $error === 0 || $error === null);
        if ($authFail) {
            $ok = false;
        }

        $reason = trim(($out['text'] ?? '').' '.($out['details'] ?? ''));
        if ($authFail && $reason === '') {
            $reason = 'احراز هویت با DirectAdmin ناموفق بود (کاربر/رمز API).';
        }

        return ['ok' => $ok, 'reason' => $reason ?: ($ok ? 'ok' : 'unknown'), 'data' => $out, 'raw' => $body];
    }

    public function createAccount(array $params): array
    {
        return $this->call('CMD_API_ACCOUNT_USER', array_merge(['action' => 'create', 'add' => 'Submit', 'notify' => 'no'], $params), 'POST');
    }

    /**
     * ساختِ **نماینده** — دستورِ جدا، نه پرچم روی همان دستور.
     *
     * 🔴 برخلافِ WHM (که `reseller=1` روی `createacct` است), در DirectAdmin
     * نماینده و کاربرِ عادی دو endpointِ متفاوت‌اند. اگر
     * `CMD_API_ACCOUNT_USER` صدا زده شود، DirectAdmin **موفق** برمی‌گرداند و
     * یک کاربرِ کاملاً معمولی می‌سازد — یعنی مشتری پولِ نمایندگی می‌دهد و
     * حسابِ ساده می‌گیرد، بی‌هیچ خطایی در هیچ لاگی.
     *
     * ⚠️ `package` این‌جا باید نامِ یک **Reseller Package** باشد، نه
     * User Package. نامِ پکیجِ کاربری روی این دستور خطای «package not found»
     * می‌دهد.
     */
    public function createReseller(array $params): array
    {
        return $this->call('CMD_API_ACCOUNT_RESELLER', array_merge([
            'action' => 'create', 'add' => 'Submit', 'notify' => 'no',
            // نماینده باید بتواند برای مشتریانش حساب بسازد؛ بی‌این، پنلی
            // تحویل می‌دهیم که کارِ اصلی‌اش را نمی‌کند.
            'ip'     => 'shared',
        ], $params), 'POST');
    }

    /** آیا این کاربر در فهرستِ نمایندگان است؟ null = نتوانستیم بپرسیم */
    public function isReseller(string $user): ?bool
    {
        $r = $this->call('CMD_API_SHOW_RESELLERS');

        if (! $r['ok']) {
            return null;
        }

        // پاسخ به شکلِ list[]=name&list[]=name برمی‌گردد
        $list = $r['data']['list'] ?? [];

        return in_array($user, array_map('strval', (array) $list), true);
    }

    public function userExists(string $user): bool
    {
        $r = $this->call('CMD_API_SHOW_USER_CONFIG', ['user' => $user]);

        // اگر کاربر نباشد error=1 می‌دهد؛ اگر باشد پیکربندی برمی‌گردد
        return $r['ok'] === true && ($r['data']['error'] ?? '0') !== '1' && trim($r['raw']) !== '';
    }

    public function suspend(string $user): array
    {
        return $this->call('CMD_API_SELECT_USERS', ['suspend' => 'Suspend', 'select0' => $user, 'dosuspend' => 'yes'], 'POST');
    }

    public function unsuspend(string $user): array
    {
        return $this->call('CMD_API_SELECT_USERS', ['suspend' => 'Unsuspend', 'select0' => $user, 'dosuspend' => 'yes'], 'POST');
    }

    public function terminate(string $user): array
    {
        return $this->call('CMD_API_SELECT_USERS', ['confirmed' => 'Confirm', 'delete' => 'yes', 'select0' => $user], 'POST');
    }
}
