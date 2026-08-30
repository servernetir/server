<?php
/**
 * کلاینتِ APIِ نمایندگیِ دامنهٔ سرورنت.
 *
 * ⚠️ هیچ‌جای این کلاس به وردپرسِ خاصی وابسته نیست جز `wp_remote_*` و
 * transient — تا بشود منطقش را جدا خواند و فهمید. هر چیزی که به قلّاب‌های
 * ووکامرس وصل است در `class-servernet-woo.php` می‌مانَد.
 *
 * @package ServerNet_Domains
 */

defined('ABSPATH') || exit;

class ServerNet_API
{
    const VERSION = '1.0.0';

    const DEFAULT_BASE = 'https://servernet.cloud/api/v1';

    /** @var string */
    private $token;

    /** @var string */
    private $base;

    public function __construct($token = null, $base = null)
    {
        $this->token = $token !== null ? $token : (string) get_option('servernet_token', '');
        $this->base = rtrim($base !== null ? $base : (string) get_option('servernet_api_url', self::DEFAULT_BASE), '/');

        if ($this->base === '') {
            $this->base = self::DEFAULT_BASE;
        }
    }

    public function is_configured()
    {
        return $this->token !== '';
    }

    // ═══════════════════════ عملیات ═══════════════════════

    public function ping()
    {
        return $this->call('GET', '/ping');
    }

    /**
     * استعلامِ دسترس‌بودن و قیمت — **کش‌شده**.
     *
     * ═══ 🔴 چرا کش این‌جا یک قابلیت نیست، یک محافظ است ═══
     *
     * این تماس از یک جعبهٔ جستجوی **عمومی** روی سایتِ نماینده می‌آید. هر
     * بازدیدکننده — و هر رباتی — می‌تواند صدایش بزند، و هر تماس چند استعلامِ
     * واقعی نزدِ رجیسترار می‌سازد. بی‌کش، یک ربات روی سایتِ نماینده مستقیم
     * سهمیهٔ رجیسترارِ **ما** را می‌سوزاند — همان حسابی که یک بار به‌خاطرِ
     * تماسِ زیاد علامت خورد. هزینه‌اش را نماینده نمی‌دهد، ما می‌دهیم.
     *
     * ⚠️ فقط پاسخِ **موفق** کش می‌شود. کش‌کردنِ خطا یعنی یک قطعیِ ده‌ثانیه‌ای
     * ده دقیقه روی سایت می‌مانَد.
     */
    public function check($domain, $tlds = array(), $ttl = 300)
    {
        $domain = strtolower(trim($domain));
        $key = 'sn_chk_'.md5($this->base.'|'.$domain.'|'.implode(',', $tlds));

        $cached = get_transient($key);

        if ($cached !== false) {
            return $cached;
        }

        $res = $this->call('POST', '/domains/check', array(
            'domain' => $domain,
            'tlds'   => array_values($tlds),
        ));

        if (! empty($res['ok'])) {
            set_transient($key, $res, max(60, (int) $ttl));
        }

        return $res;
    }

    /** فهرستِ قیمتِ پسوندها — کشِ بلندتر، چون روزی چند بار بیشتر عوض نمی‌شود */
    public function tlds($tlds = array(), $ttl = 3600)
    {
        $key = 'sn_tlds_'.md5($this->base.'|'.implode(',', $tlds));
        $cached = get_transient($key);

        if ($cached !== false) {
            return $cached;
        }

        $q = $tlds ? '?'.http_build_query(array('tlds' => array_values($tlds))) : '';
        $res = $this->call('GET', '/tlds'.$q);

        if (! empty($res['ok'])) {
            set_transient($key, $res, max(300, (int) $ttl));
        }

        return $res;
    }

    public function register($fqdn, $years, $nameservers, $idempotency_key)
    {
        return $this->call('POST', '/domains', array(
            'domain'      => $fqdn,
            'years'       => (int) $years,
            'nameservers' => array_values((array) $nameservers),
        ), $idempotency_key);
    }

    public function renew($fqdn, $years, $idempotency_key)
    {
        return $this->call('POST', '/domains/'.rawurlencode($fqdn).'/renew', array(
            'years' => (int) $years,
        ), $idempotency_key);
    }

    /** ⚠️ بی‌کش: وضعیتِ سفارش را می‌پرسد و کشِ کهنه یعنی «هنوز pending» تا ابد */
    public function domain($fqdn)
    {
        return $this->call('GET', '/domains/'.rawurlencode($fqdn));
    }

    // ═══════════════════════ کلیدِ یکتاسازی ═══════════════════════

    /**
     * کلیدِ `Idempotency-Key` — قطعی، نه تصادفی.
     *
     * 🔴 ووکامرس یک سفارش را چند بار به حالتِ «پرداخت‌شده» می‌بَرد: وب‌هوکِ
     * درگاه، بازگشتِ کاربر، تغییرِ دستیِ وضعیت توسطِ مدیر، و `wc_maybe_*`.
     * هر کدام همین قلّاب را دوباره صدا می‌زند. بی‌کلیدِ قطعی، یک سفارش
     * می‌تواند **سه بار** دامنه بخرد و سه بار از اعتبارِ نماینده کسر کند —
     * بی‌هیچ خطایی، چون هر سه تماس واقعاً موفق‌اند.
     *
     * ⚠️ شناسهٔ آیتمِ سفارش (نه شناسهٔ سفارش) پایه است: یک سفارش می‌تواند چند
     * دامنهٔ متفاوت داشته باشد و همه باید کلیدِ جدا بگیرند.
     */
    public static function key_for($action, $fqdn, $parts = array())
    {
        $seed = implode('|', array_merge(array($action, strtolower($fqdn)), array_map('strval', (array) $parts)));

        return substr(hash('sha256', $seed), 0, 48);
    }

    // ═══════════════════════ انتقال ═══════════════════════

    /**
     * @return array{ok:bool, error:string|null, message:string, data:mixed, status:int, transport:bool}
     */
    public function call($method, $path, $body = null, $idempotency_key = null)
    {
        if (! $this->is_configured()) {
            return array(
                'ok' => false, 'error' => 'not_configured', 'status' => 0, 'transport' => false,
                'message' => __('توکن API سرورنت وارد نشده است.', 'servernet-domains'), 'data' => null,
            );
        }

        $args = array(
            'method'  => $method,
            'timeout' => 60,
            'headers' => array(
                'Authorization' => 'Bearer '.$this->token,
                'Accept'        => 'application/json',
                'User-Agent'    => 'ServerNet-WP/'.self::VERSION.'; '.home_url('/'),
            ),
        );

        if ($idempotency_key) {
            $args['headers']['Idempotency-Key'] = $idempotency_key;
        }

        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }

        $res = wp_remote_request($this->base.$path, $args);

        if (is_wp_error($res)) {
            /*
            | 🔴 «نشنیدیم» با «نه گفت» یکی نیست.
            |
            | تایم‌اوتِ وسطِ ثبت یعنی ممکن است دامنه **ثبت شده باشد**. اگر
            | لایهٔ بالا این را «ناموفق» بخواند، یا سفارش را لغو می‌کند یا
            | دوباره می‌فرستد — در حالی که پول رفته و دامنه ثبت شده. پرچمِ
            | `transport` تنها راهِ تفکیک است.
            */
            return array(
                'ok' => false, 'error' => 'transport_error', 'status' => 0, 'transport' => true,
                'message' => $res->get_error_message(), 'data' => null,
            );
        }

        $status = (int) wp_remote_retrieve_response_code($res);
        $json = json_decode((string) wp_remote_retrieve_body($res), true);

        if (! is_array($json)) {
            return array(
                'ok' => false, 'error' => 'bad_response', 'status' => $status, 'transport' => false,
                'message' => sprintf(__('پاسخ نامعتبر از سرورنت (HTTP %d).', 'servernet-domains'), $status),
                'data' => null,
            );
        }

        // ⚠️ به کدِ HTTP تنها تکیه نکن — به `ok` در بدنه.
        return array(
            'ok'        => ! empty($json['ok']),
            'error'     => isset($json['error']) ? $json['error'] : null,
            'message'   => isset($json['message']) ? (string) $json['message'] : '',
            'data'      => isset($json['data']) ? $json['data'] : null,
            'status'    => $status,
            'transport' => false,
        );
    }
}
