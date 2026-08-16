<?php
/**
 * جعبهٔ جستجوی دامنه — کدِ کوتاه + نقطهٔ AJAX.
 *
 * @package ServerNet_Domains
 */

defined('ABSPATH') || exit;

class ServerNet_Search
{
    public static function init()
    {
        add_shortcode('servernet_domain_search', array(__CLASS__, 'shortcode'));
        add_action('wp_ajax_servernet_search', array(__CLASS__, 'ajax'));
        add_action('wp_ajax_nopriv_servernet_search', array(__CLASS__, 'ajax'));
    }

    public static function shortcode($atts)
    {
        $atts = shortcode_atts(array('placeholder' => __('نام دامنهٔ دلخواهتان', 'servernet-domains')), $atts, 'servernet_domain_search');

        wp_enqueue_style('servernet-search', SERVERNET_DOMAINS_URL.'assets/search.css', array(), SERVERNET_DOMAINS_VERSION);
        wp_enqueue_script('servernet-search', SERVERNET_DOMAINS_URL.'assets/search.js', array(), SERVERNET_DOMAINS_VERSION, true);

        wp_localize_script('servernet-search', 'ServerNetSearch', array(
            'ajax'  => admin_url('admin-ajax.php'),
            // ⚠️ nonce فقط ضدِ CSRF است، نه ضدِ سوءاستفاده. محافظِ واقعی
            //    محدودیتِ نرخ در `ajax()` است — nonce برای کاربرِ مهمان
            //    قابلِ گرفتن است و هیچ ربات‌شکنی نیست.
            'nonce' => wp_create_nonce('servernet_search'),
            'cart'  => class_exists('WooCommerce') ? wc_get_cart_url() : '',
            'i18n'  => array(
                'searching' => __('در حال بررسی…', 'servernet-domains'),
                'free'      => __('آزاد است', 'servernet-domains'),
                'taken'     => __('ثبت شده', 'servernet-domains'),
                'unknown'   => __('استعلام نشد', 'servernet-domains'),
                'unsold'    => __('این پسوند را نمی‌فروشیم', 'servernet-domains'),
                'noprice'   => __('قیمت در دسترس نیست', 'servernet-domains'),
                'add'       => __('افزودن به سبد', 'servernet-domains'),
                'added'     => __('افزوده شد', 'servernet-domains'),
                'error'     => __('خطا در ارتباط. کمی بعد دوباره تلاش کنید.', 'servernet-domains'),
                'empty'     => __('یک نام وارد کنید.', 'servernet-domains'),
                'currency'  => __('تومان', 'servernet-domains'),
            ),
        ));

        ob_start();
        ?>
        <div class="sn-search" data-sn-search>
            <form class="sn-search-form" onsubmit="return false">
                <input type="text" class="sn-input" dir="ltr" autocomplete="off"
                       placeholder="<?php echo esc_attr($atts['placeholder']); ?>">
                <button type="button" class="sn-btn" data-sn-go><?php esc_html_e('بررسی', 'servernet-domains'); ?></button>
            </form>
            <div class="sn-status" data-sn-status role="status" aria-live="polite"></div>
            <div class="sn-results" data-sn-results></div>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * محدودیتِ نرخ — به‌ازای هر IP.
     *
     * ═══ 🔴 چرا این‌جا لازم است و نبودش هزینهٔ **سرورنت** است ═══
     *
     * این نقطه عمومی است (`nopriv`) و هر بازدیدکننده یا رباتی می‌تواند
     * صدایش بزند. هر تماس چند استعلامِ واقعی نزدِ رجیسترار می‌سازد. بی‌محدودیت،
     * یک ربات روی سایتِ نماینده مستقیم سهمیهٔ رجیسترار را می‌سوزاند — و آن
     * حساب یک بار به‌خاطرِ تماسِ زیاد علامت خورده. هزینه‌اش را نه نماینده
     * می‌دهد و نه بازدیدکننده؛ ما می‌دهیم.
     *
     * ⚠️ کش در `ServerNet_API::check()` لایهٔ دوم است: جستجوی تکراری اصلاً به
     * شبکه نمی‌رسد. این‌جا جلوی جستجوهای **متفاوت** و پشتِ‌سرِ‌هم را می‌گیرد.
     */
    private static function rate_limited()
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        $key = 'sn_rl_'.md5($ip);

        $hits = (int) get_transient($key);

        if ($hits >= 20) {
            return true;
        }

        set_transient($key, $hits + 1, MINUTE_IN_SECONDS);

        return false;
    }

    public static function ajax()
    {
        check_ajax_referer('servernet_search', 'nonce');

        if (self::rate_limited()) {
            wp_send_json_error(array('message' => __('درخواست‌های زیاد. یک دقیقه صبر کنید.', 'servernet-domains')), 429);
        }

        $q = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
        $q = trim(strtolower($q));

        if ($q === '' || strlen($q) > 120) {
            wp_send_json_error(array('message' => __('نام دامنه معتبر نیست.', 'servernet-domains')), 400);
        }

        $api = new ServerNet_API;
        $res = $api->check($q, ServerNet_Settings::tlds());

        if (empty($res['ok'])) {
            /*
            | ⚠️ پیامِ خامِ خطا به بازدیدکننده نمی‌رود.
            |
            | پیامِ سرورنت ممکن است بگوید «اعتبار کافی نیست» یا «توکن منقضی
            | شده» — یعنی وضعیتِ مالی و پیکربندیِ نماینده روی صفحهٔ عمومیِ
            | سایتش. مدیر آن را در لاگ می‌بیند، مشتری یک پیامِ عمومی.
            */
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[ServerNet] search failed: '.($res['error'] ?? '?').' — '.($res['message'] ?? ''));
            }

            wp_send_json_error(array('message' => __('استعلام ممکن نشد. کمی بعد دوباره تلاش کنید.', 'servernet-domains')), 503);
        }

        $out = array();

        foreach ((array) $res['data'] as $row) {
            $state = isset($row['state']) ? (string) $row['state'] : 'unchecked';
            $cost = isset($row['price']['register']) ? (int) $row['price']['register'] : 0;

            /*
            | 🔴 روی `state` تصمیم می‌گیریم، نه روی `available`.
            |
            | «نتوانستیم استعلام کنیم» هرگز نباید «ثبت شده» خوانده شود: مشتری
            | نتیجه می‌گیرد اسمِ دلخواهش رفته و می‌رود، و هیچ شکایتی هم
            | نمی‌شنویم تا بفهمیم چیزی خراب است.
            */
            $out[] = array(
                'domain'    => isset($row['domain']) ? (string) $row['domain'] : '',
                'state'     => $state,
                'orderable' => ($state === 'free' || $state === 'premium') && $cost > 0,
                'premium'   => ! empty($row['is_premium']),
                // ⚠️ فقط قیمتِ **فروش** بیرون می‌رود. قیمتِ خریدِ نماینده از
                //    سرورنت دادهٔ داخلیِ اوست و روی صفحهٔ عمومی جایی ندارد.
                'price'     => $cost > 0 ? ServerNet_Settings::retail($cost) : 0,
            );
        }

        wp_send_json_success(array('results' => $out));
    }
}
