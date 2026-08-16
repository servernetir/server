<?php
/**
 * Plugin Name:       ServerNet Domains
 * Plugin URI:        https://servernet.cloud/developers
 * Description:       فروش دامنه روی وردپرس و ووکامرس از حساب نمایندگی سرورنت — جستجو، سبد خرید و ثبت خودکار پس از پرداخت.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            ServerNet
 * Author URI:        https://servernet.cloud
 * License:           GPL-2.0-or-later
 * Text Domain:       servernet-domains
 *
 * WC requires at least: 7.0
 * WC tested up to:      9.0
 *
 * ═══════════════════════════════════════════════════════════════════════
 * سه قاعده‌ای که کلِ این افزونه را شکل داده‌اند
 * ═══════════════════════════════════════════════════════════════════════
 *
 * ۱) **توکن هرگز به مرورگر نمی‌رسد.** همهٔ تماس‌ها سمتِ سرور انجام می‌شوند.
 *    توکنی که در جاوااسکریپت بنشیند، با «نمایشِ منبعِ صفحه» در دستِ هر
 *    بازدیدکننده‌ای است — و آن توکن از اعتبارِ شما خرید می‌کند.
 *
 * ۲) **ثبت فقط بعد از پرداختِ واقعی.** قلّاب روی `processing`/`completed`
 *    است، نه روی ساختِ سفارش. سفارشِ پرداخت‌نشده‌ای که دامنه بخرد، یعنی شما
 *    پولش را داده‌اید.
 *
 * ۳) **اگر قیمتِ ما بالا رفته باشد، ثبت انجام نمی‌شود و سفارش به انتظار
 *    می‌رود.** بی‌این، یک جهشِ ارز بینِ لحظهٔ افزودن به سبد و لحظهٔ پرداخت،
 *    تفاوت را از جیبِ **شما** برمی‌دارد — روی هر سفارش، بی‌هیچ خطایی.
 *
 * @package ServerNet_Domains
 */

defined('ABSPATH') || exit;

define('SERVERNET_DOMAINS_VERSION', '1.0.0');
define('SERVERNET_DOMAINS_FILE', __FILE__);
define('SERVERNET_DOMAINS_DIR', plugin_dir_path(__FILE__));
define('SERVERNET_DOMAINS_URL', plugin_dir_url(__FILE__));

require_once SERVERNET_DOMAINS_DIR.'includes/class-servernet-api.php';
require_once SERVERNET_DOMAINS_DIR.'includes/class-servernet-settings.php';
require_once SERVERNET_DOMAINS_DIR.'includes/class-servernet-search.php';

add_action('plugins_loaded', function () {
    load_plugin_textdomain('servernet-domains', false, dirname(plugin_basename(__FILE__)).'/languages');

    ServerNet_Settings::init();
    ServerNet_Search::init();

    /*
    | ووکامرس اختیاری است.
    |
    | ⚠️ بی‌این بررسی، افزونه روی وردپرسِ بی‌ووکامرس **فتال** می‌دهد و کلِ
    | سایتِ نماینده سفید می‌شود — چون کلاسِ `WC_Product` وجود ندارد. و بخشِ
    | جستجو کاملاً مستقل است و روی وردپرسِ ساده هم باید کار کند.
    */
    if (class_exists('WooCommerce')) {
        require_once SERVERNET_DOMAINS_DIR.'includes/class-servernet-woo.php';
        ServerNet_Woo::init();
    }
});

/*
| اعلامِ سازگاری با HPOS (جدولِ سفارشِ تازهٔ ووکامرس).
|
| ⚠️ بی‌این، ووکامرس افزونه را «ناسازگار» علامت می‌زند و مدیر یا HPOS را
| خاموش می‌کند یا افزونه را. ما جایی مستقیم به `postmeta` دست نمی‌زنیم و
| همه‌جا از `$order->get_meta()`/`update_meta_data()` استفاده می‌کنیم، پس
| ادعا درست است.
*/
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables', __FILE__, true
        );
    }
});

register_activation_hook(__FILE__, function () {
    if (! wp_next_scheduled('servernet_poll_pending')) {
        /*
        | پایشِ سفارش‌های «در حالِ ثبت».
        |
        | ثبت گاهی همان لحظه تمام نمی‌شود و پاسخ `pending` است. بی‌این کرون،
        | آن سفارش تا ابد در همان حالت می‌مانَد: مشتری پول داده، دامنه در پنلِ
        | سرورنت ثبت شده، و سایتِ نماینده هنوز «در حالِ بررسی» نشان می‌دهد.
        */
        wp_schedule_event(time() + 300, 'servernet_five_minutes', 'servernet_poll_pending');
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('servernet_poll_pending');
});

add_filter('cron_schedules', function ($s) {
    $s['servernet_five_minutes'] = array('interval' => 300, 'display' => __('هر ۵ دقیقه', 'servernet-domains'));

    return $s;
});
