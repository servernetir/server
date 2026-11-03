<?php

namespace App\Services\Provisioning;

use App\Models\Server;
use Illuminate\Support\Facades\Http;

/**
 * کلاینتِ Plesk — **XML-API**، نه REST.
 *
 * ═══ چرا XML و نه `/api/v2` ═══
 *
 * RESTِ Plesk (v2) برای دامنه/اشتراک/کاربر خوب است ولی **ساختِ نماینده
 * (reseller) را پوشش نمی‌دهد**. مدیریتِ نماینده تا امروز فقط از راهِ XML-API
 * (`packet/reseller`) یا `plesk bin reseller` روی خودِ سرور ممکن است. پس این
 * کلاس عمداً XML می‌فرستد.
 *
 * احراز: هدرهای `HTTP_AUTH_LOGIN` + `HTTP_AUTH_PASSWD` (یا `KEY` برای
 * API-key). نقطهٔ پایانی: `https://host:8443/enterprise/control/agent.php`.
 *
 * ═══ 🔴 Plesk روی خطا هم HTTP 200 می‌دهد ═══
 *
 * دقیقاً مثلِ زحل و OpenProvider و Cloudflare در همین پروژه: نتیجهٔ واقعی در
 * `<status>` بدنه است و `<errcode>`/`<errtext>` پیام را دارند. هرگز به کدِ
 * HTTP تکیه نکن.
 *
 * ⚠️ **این درایور روی سرورِ Pleskِ واقعی آزمایش نشده است.** برای همین
 * `Server::isAutoProvisioned()` تا وقتی `provisioning.plesk_auto` روشن نشود
 * Plesk را خودکار نمی‌شمارد و تحویل در صفِ دستیِ مدیر می‌مانَد.
 */
class PleskClient
{
    public function __construct(private Server $server) {}

    /** @return array{ok:bool,transport:bool,reason:string,data:array,raw:string} */
    public function call(string $xml): array
    {
        $base = 'https://'.$this->server->hostname.':'.$this->server->effectivePort().'/enterprise/control/agent.php';

        try {
            // Explicit mode: do not guess from the shape of a password, and
            // never retry a mutating XML request with another authentication method.
            $auth = ($this->server->meta['plesk_auth_mode'] ?? 'password') === 'key'
                ? ['KEY' => (string) $this->server->api_token]
                : ['HTTP_AUTH_LOGIN' => (string) $this->server->username,
                    'HTTP_AUTH_PASSWD' => (string) $this->server->api_token];
            $req = Http::withHeaders(['Content-Type' => 'text/xml'] + $auth)
                ->connectTimeout(5)->timeout(app()->runningInConsole() ? 120 : 45);

            if (! $this->server->verify_tls) {
                $req = $req->withoutVerifying();
            }

            $resp = $req->withBody($xml, 'text/xml')->post($base);
        } catch (\Throwable $e) {
            // «نشنیدیم» — نه موفق، نه ناموفق. فراخوان باید این را از «نه گفت»
            // جدا کند، وگرنه همان خرابیِ zhina.shop تکرار می‌شود.
            return ['ok' => false, 'transport' => true, 'data' => [], 'raw' => '',
                'reason' => 'ارتباط با Plesk برقرار نشد: '.mb_substr($e->getMessage(), 0, 160)];
        }

        $body = $resp->body();

        $parsed = $this->parse($body);
        if ($parsed === null) {
            // بدنهٔ غیرِXML = صفحهٔ لاگین، پروکسی، یا ۴۰۳ِ فایروال. اینها
            // خرابیِ **پایدارِ پیکربندی**‌اند نه سکسکهٔ گذرا، پس عمداً
            // transport=false تا در صفِ ساکتِ دستی گم نشوند.
            return ['ok' => false, 'transport' => false, 'data' => [], 'raw' => $body,
                'reason' => 'پاسخِ Plesk قابلِ خواندن نبود (احراز هویت یا فایروال؟).'];
        }

        return $parsed + ['raw' => $body];
    }

    /** @return array{ok:bool,transport:bool,reason:string,data:array}|null */
    private function parse(string $body): ?array
    {
        if (trim($body) === '' || ! str_contains($body, '<packet')) {
            return null;
        }

        $prev = libxml_use_internal_errors(true);
        $sx = str_contains(strtoupper($body), '<!DOCTYPE') ? false
            : simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if ($sx === false) {
            return null;
        }

        $json = json_decode(json_encode($sx), true) ?: [];

        // status/errcode/errtext هر جای درخت ممکن است باشند — دنبالِ **نامِ
        // کلید** بگرد، نه مسیرِ ثابت. (همان درسِ orderIdOf در لایهٔ ابری.)
        $status = $this->deepFind($json, 'status');
        $errText = $this->deepFind($json, 'errtext');
        $errCode = $this->deepFind($json, 'errcode');

        $statuses = $sx->xpath('/*/*/*/result/status | /*/system/status') ?: [];
        $ok = count($statuses) > 0 && count(array_filter($statuses, fn ($v) => (string) $v !== 'ok')) === 0;

        $codes = $sx->xpath('/*/*/*/result/errcode | /*/system/errcode') ?: [];
        $rejected = count($statuses) > 0 && count($codes) === count($statuses)
            && count(array_filter($statuses, fn ($v) => (string) $v !== 'error')) === 0
            && count(array_filter($codes, fn ($v) => ! ctype_digit((string) $v))) === 0;

        return [
            'rejected'  => $rejected,
            'ok'        => $ok,
            'transport' => false,
            'reason'    => $ok ? 'ok' : (trim(((string) $errCode !== '' ? '['.$errCode.'] ' : '').(string) $errText) ?: 'unknown'),
            'data'      => $json,
        ];
    }

    /** اولین مقدارِ اسکالر با این نامِ کلید، در هر عمقی */
    private function deepFind(array $a, string $key): ?string
    {
        foreach ($a as $k => $v) {
            if ($k === $key && (is_string($v) || is_numeric($v))) {
                return (string) $v;
            }
            if (is_array($v)) {
                $hit = $this->deepFind($v, $key);
                if ($hit !== null) {
                    return $hit;
                }
            }
        }

        return null;
    }

    /**
     * ساختِ نماینده.
     *
     * ⚠️ `plan-name` اختیاری است: بی‌آن نماینده با سقفِ پیش‌فرضِ سرور ساخته
     * می‌شود. فراخوان باید بداند که «بی‌پلن» یعنی «بی‌سقف».
     */
    public function createReseller(array $p): array
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<packet><reseller><add><gen-info>'
            .'<cname>'.$this->esc($p['company'] ?? $p['name'] ?? '').'</cname>'
            .'<pname>'.$this->esc($p['name'] ?? '').'</pname>'
            .'<login>'.$this->esc($p['login'] ?? '').'</login>'
            .'<passwd>'.$this->esc($p['password'] ?? '').'</passwd>'
            .'<email>'.$this->esc($p['email'] ?? '').'</email>'
            .'</gen-info>'
            .(filled($p['plan'] ?? null) ? '<plan-name>'.$this->esc($p['plan']).'</plan-name>' : '')
            .'</add></reseller></packet>';

        return $this->call($xml);
    }

    /** ساختِ مشتریِ عادی (هاستِ اشتراکیِ Plesk) */
    public function createCustomer(array $p): array
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<packet><customer><add><gen_info>'
            .'<cname>'.$this->esc($p['company'] ?? $p['name'] ?? '').'</cname>'
            .'<pname>'.$this->esc($p['name'] ?? '').'</pname>'
            .'<login>'.$this->esc($p['login'] ?? '').'</login>'
            .'<passwd>'.$this->esc($p['password'] ?? '').'</passwd>'
            .'<email>'.$this->esc($p['email'] ?? '').'</email>'
            .'</gen_info></add></customer></packet>';

        return $this->call($xml);
    }

    /**
     * آیا این لاگین روی سرور هست؟ null = **نتوانستیم بپرسیم**.
     *
     * سه‌حالته بودنش عمدی است و همان چیزی است که `accountState()` در WHM دارد:
     * «نمی‌دانم» نباید به «نیست» ترجمه شود، وگرنه بعد از یک تایم‌اوت حسابِ
     * دوم ساخته می‌شود.
     */
    public function loginExists(string $login, bool $reseller): ?bool
    {
        $node = $reseller ? 'reseller' : 'customer';
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<packet><'.$node.'><get><filter><login>'.$this->esc($login).'</login></filter>'
            .'<dataset><'.($reseller ? 'gen-info' : 'gen_info').'/></dataset></get></'.$node.'></packet>';

        $res = $this->call($xml);

        if ($res['ok']) {
            return true;
        }

        if ($res['transport']) {
            return null;
        }

        // ۱۰۱۳ = object not found. هر خطای دیگری یعنی نتوانستیم قضاوت کنیم.
        return str_contains($res['reason'], '1013') ? false : null;
    }

    public function setStatus(string $login, bool $reseller, bool $enabled): array
    {
        $node = $reseller ? 'reseller' : 'customer';
        // 0 = active، 16 = suspended by admin
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<packet><'.$node.'><set><filter><login>'.$this->esc($login).'</login></filter>'
            .'<values><'.($reseller ? 'gen-info' : 'gen_info').'><status>'.($enabled ? '0' : '16').'</status></'.($reseller ? 'gen-info' : 'gen_info').'></values>'
            .'</set></'.$node.'></packet>';

        return $this->call($xml);
    }

    public function delete(string $login, bool $reseller): array
    {
        $node = $reseller ? 'reseller' : 'customer';
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<packet><'.$node.'><del><filter><login>'.$this->esc($login).'</login></filter></del></'.$node.'></packet>';

        return $this->call($xml);
    }


    /** Exact reads retain unknown separately from a confirmed 1013 absence. */
    public function customerInfo(string $login): array
    {
        return $this->objectRead('customer', '<login>'.$this->esc($login).'</login>', '<gen_info/>');
    }

    public function subscriptionInfo(string $domain): array
    {
        return $this->objectRead('webspace', '<name>'.$this->esc($domain).'</name>', '<gen_info/><hosting/><subscriptions/>');
    }

    public function planInfo(string $name): array
    {
        return $this->objectRead('service-plan', '<name>'.$this->esc($name).'</name>', null);
    }

    private function objectRead(string $node, string $filter, ?string $dataset): array
    {
        $r = $this->call('<packet><'.$node.'><get><filter>'.$filter.'</filter>'
            .($dataset === null ? '' : '<dataset>'.$dataset.'</dataset>').'</get></'.$node.'></packet>');
        $one = $r['data'][$node]['get']['result'] ?? [];
        if (! is_array($one) || isset($one[0])) return ['exists' => null, 'result' => [], 'reason' => 'Ambiguous object response'];
        $exists = ($one['status'] ?? '') === 'ok' && $r['ok'] ? true
            : (((string) ($one['errcode'] ?? '') === '1013' && ! $r['transport']) ? false : null);
        return ['exists' => $exists, 'result' => $one, 'reason' => $r['reason']];
    }

    public function createSubscription(array $p): array
    {
        return $this->call('<packet><webspace><add><gen_setup>'
            .'<name>'.$this->esc($p['domain']).'</name><owner-id>'.(int) $p['owner_id'].'</owner-id>'
            .'<htype>vrt_hst</htype><ip_address>'.$this->esc($p['ip']).'</ip_address><status>0</status></gen_setup>'
            .'<hosting><vrt_hst><property><name>ftp_login</name><value>'.$this->esc($p['login']).'</value></property>'
            .'<property><name>ftp_password</name><value>'.$this->esc($p['password']).'</value></property>'
            .'<ip_address>'.$this->esc($p['ip']).'</ip_address></vrt_hst></hosting>'
            .'<plan-name>'.$this->esc($p['plan']).'</plan-name></add></webspace></packet>');
    }

    /** Returns a fixed-origin, single-use URL; never a password or administrator login. */
    public function createUserSession(string $login, string $ip): ?string
    {
        if ($login === '' || in_array(strtolower($login), ['admin', 'root', strtolower((string) $this->server->username)], true)
            || ! filter_var($ip, FILTER_VALIDATE_IP)) return null;
        $r = $this->call('<packet version="1.6.9.1"><server><create_session><login>'.$this->esc($login)
            .'</login><data><user_ip>'.$this->esc($ip).'</user_ip></data></create_session></server></packet>');
        $token = $r['data']['server']['create_session']['result']['id'] ?? null;
        if (! $r['ok'] || ! is_string($token) || ! preg_match('/\A[a-zA-Z0-9_,-]{16,256}\z/D', $token)) return null;
        return 'https://'.$this->server->hostname.':'.$this->server->effectivePort()
            .'/enterprise/rsession_init.php?PLESKSESSID='.rawurlencode($token);
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
