<?php
/**
 * یکپارچگی با ووکامرس — سبد، پرداخت، و ثبتِ خودکارِ دامنه.
 *
 * @package ServerNet_Domains
 */

defined('ABSPATH') || exit;

class ServerNet_Woo
{
    /** شناسهٔ محصولِ مجازیِ «دامنه» در تنظیمات */
    const PRODUCT_OPTION = 'servernet_product_id';

    public static function init()
    {
        add_action('wp_ajax_servernet_add_to_cart', array(__CLASS__, 'ajax_add'));
        add_action('wp_ajax_nopriv_servernet_add_to_cart', array(__CLASS__, 'ajax_add'));

        add_filter('woocommerce_add_cart_item_data', array(__CLASS__, 'keep_item_data'), 10, 3);
        add_action('woocommerce_before_calculate_totals', array(__CLASS__, 'apply_price'), 20);
        add_filter('woocommerce_get_item_data', array(__CLASS__, 'show_in_cart'), 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'save_to_order'), 10, 4);

        // 🔴 ثبت فقط پس از پرداختِ واقعی — نه روی ساختِ سفارش.
        add_action('woocommerce_order_status_processing', array(__CLASS__, 'fulfil'), 20);
        add_action('woocommerce_order_status_completed', array(__CLASS__, 'fulfil'), 20);

        add_action('servernet_poll_pending', array(__CLASS__, 'poll_pending'));

        add_action('woocommerce_order_item_meta_end', array(__CLASS__, 'order_item_status'), 10, 3);
    }

    // ═══════════════════════ سبد ═══════════════════════

    /** محصولِ مجازیِ حاملِ دامنه — یک بار ساخته و بازاستفاده می‌شود */
    private static function product_id()
    {
        $id = (int) get_option(self::PRODUCT_OPTION, 0);

        if ($id && get_post_type($id) === 'product') {
            return $id;
        }

        $p = new WC_Product_Simple;
        $p->set_name(__('ثبت دامنه', 'servernet-domains'));
        $p->set_status('private');            // در فروشگاه دیده نشود
        $p->set_catalog_visibility('hidden');
        $p->set_virtual(true);
        $p->set_sold_individually(false);
        $p->set_price(0);
        $p->set_regular_price(0);
        $id = $p->save();

        update_option(self::PRODUCT_OPTION, $id);

        return $id;
    }

    public static function ajax_add()
    {
        check_ajax_referer('servernet_search', 'nonce');

        $domain = isset($_POST['domain']) ? sanitize_text_field(wp_unslash($_POST['domain'])) : '';
        $domain = strtolower(trim($domain, ". \t"));

        if ($domain === '' || ! preg_match('/^[a-z0-9-]+(\.[a-z0-9-]{2,63})+$/i', $domain)) {
            wp_send_json_error(array('message' => __('نام دامنه معتبر نیست.', 'servernet-domains')), 400);
        }

        /*
        |----------------------------------------------------------------------
        | 🔴 قیمت از فرم نمی‌آید — دوباره از سرورنت پرسیده می‌شود
        |----------------------------------------------------------------------
        |
        | اگر قیمت را از بدنهٔ درخواست بگیریم، هر کسی با یک `curl` می‌تواند
        | دامنهٔ ده‌میلیونی را به هزار تومان در سبد بگذارد و بخرد — و تفاوت را
        | **نماینده** می‌دهد، چون سرورنت قیمتِ واقعیِ خودش را از اعتبار کسر
        | می‌کند. هیچ خطایی هم تولید نمی‌شود.
        */
        $api = new ServerNet_API;
        [$sld, $tld] = array_pad(explode('.', $domain, 2), 2, '');

        $res = $api->check($sld, array($tld));

        if (empty($res['ok'])) {
            wp_send_json_error(array('message' => __('استعلام ممکن نشد. کمی بعد دوباره تلاش کنید.', 'servernet-domains')), 503);
        }

        $row = null;
        foreach ((array) $res['data'] as $r) {
            if (isset($r['domain']) && strtolower($r['domain']) === $domain) {
                $row = $r;
                break;
            }
        }

        $state = $row ? (string) ($row['state'] ?? '') : '';
        $cost = $row ? (int) ($row['price']['register'] ?? 0) : 0;

        if (! in_array($state, array('free', 'premium'), true) || $cost <= 0) {
            wp_send_json_error(array('message' => __('این دامنه قابل سفارش نیست.', 'servernet-domains')), 409);
        }

        $added = WC()->cart->add_to_cart(self::product_id(), 1, 0, array(), array(
            'servernet' => array(
                'domain' => $domain,
                'years'  => 1,
                // بهایِ خریدِ نماینده در لحظهٔ افزودن — پایهٔ سنجشِ نوسان
                'cost'   => $cost,
                'price'  => ServerNet_Settings::retail($cost),
                // ⚠️ زمان لازم است: «قیمت از کِی است» بدونِ آن قابلِ قضاوت نیست
                'quoted' => time(),
            ),
        ));

        if (! $added) {
            wp_send_json_error(array('message' => __('افزودن به سبد انجام نشد.', 'servernet-domains')), 500);
        }

        wp_send_json_success(array('cart' => wc_get_cart_url()));
    }

    /** بی‌این، ووکامرس دو دامنهٔ متفاوت را «همان محصول» می‌بیند و یکی می‌کند */
    public static function keep_item_data($data, $product_id, $variation_id)
    {
        if (isset($data['servernet']['domain'])) {
            $data['servernet_key'] = md5($data['servernet']['domain']);
        }

        return $data;
    }

    public static function apply_price($cart)
    {
        if (is_admin() && ! defined('DOING_AJAX')) {
            return;
        }

        // ⚠️ ووکامرس این قلّاب را در یک درخواست چند بار می‌زند؛ بی‌این محافظ،
        //    قیمت چند بار اعمال و در بعضی افزونه‌ها انباشته می‌شود.
        if (did_action('woocommerce_before_calculate_totals') > 1) {
            return;
        }

        foreach ($cart->get_cart() as $item) {
            if (isset($item['servernet']['price'])) {
                $item['data']->set_price((float) $item['servernet']['price']);
                $item['data']->set_name(sprintf(
                    /* translators: %s: domain name */
                    __('ثبت دامنه %s', 'servernet-domains'),
                    $item['servernet']['domain']
                ));
            }
        }
    }

    public static function show_in_cart($items, $item)
    {
        if (isset($item['servernet']['domain'])) {
            $items[] = array(
                'key'   => __('دامنه', 'servernet-domains'),
                'value' => esc_html($item['servernet']['domain']),
            );
        }

        return $items;
    }

    public static function save_to_order($line, $key, $values, $order)
    {
        if (! isset($values['servernet']['domain'])) {
            return;
        }

        $sn = $values['servernet'];

        $line->add_meta_data('_servernet_domain', $sn['domain'], true);
        $line->add_meta_data('_servernet_years', (int) $sn['years'], true);
        $line->add_meta_data('_servernet_cost', (int) $sn['cost'], true);
        $line->add_meta_data('_servernet_state', 'new', true);
        $line->add_meta_data(__('دامنه', 'servernet-domains'), $sn['domain'], true);
    }

    // ═══════════════════════ تحویل ═══════════════════════

    /**
     * ثبتِ دامنه‌های یک سفارشِ پرداخت‌شده.
     *
     * ⚠️ این متد ممکن است برای یک سفارش **چند بار** صدا زده شود: وب‌هوکِ
     * درگاه، بازگشتِ کاربر، تغییرِ دستیِ وضعیت توسطِ مدیر، و گذارِ
     * `processing → completed`. محافظ دو لایه است — علامتِ `_servernet_state`
     * روی خطِ سفارش، و کلیدِ قطعیِ idempotency سمتِ سرورنت.
     */
    public static function fulfil($order_id)
    {
        $order = wc_get_order($order_id);

        if (! $order) {
            return;
        }

        $api = new ServerNet_API;

        if (! $api->is_configured()) {
            $order->add_order_note(__('ثبت دامنه انجام نشد: توکن API سرورنت وارد نشده است.', 'servernet-domains'));

            return;
        }

        $ns = ServerNet_Settings::nameservers();
        $tolerance = (float) get_option('servernet_drift_tolerance_pct', 0);

        foreach ($order->get_items() as $item_id => $item) {
            $domain = (string) $item->get_meta('_servernet_domain');

            if ($domain === '') {
                continue;
            }

            $state = (string) $item->get_meta('_servernet_state');

            // از قبل ثبت شده یا در جریان است ⇒ دست نزن
            if (in_array($state, array('registered', 'pending', 'held'), true)) {
                continue;
            }

            $years = max(1, (int) $item->get_meta('_servernet_years'));
            $quoted_cost = (int) $item->get_meta('_servernet_cost');

            /*
            |------------------------------------------------------------------
            | 🔴 محافظِ نوسانِ قیمت — «یهو ضرر نکنم»
            |------------------------------------------------------------------
            |
            | بینِ لحظه‌ای که مشتری دامنه را در سبد گذاشت و لحظه‌ای که پرداخت
            | کرد، ممکن است ساعت‌ها یا روزها گذشته باشد. قیمتِ خریدِ ما به نرخِ
            | ارز بند است. اگر نرخ جهش کرده باشد، مشتری قیمتِ دیروز را پرداخت
            | کرده و ما قیمتِ امروز را می‌دهیم — تفاوت مستقیم از جیبِ نماینده.
            |
            | بی‌این بررسی، آن ضرر **خاموش** است: سفارش موفق، دامنه ثبت‌شده،
            | مشتری راضی، و کسی تا پایانِ ماه نمی‌فهمد.
            |
            | ⚠️ ردکردن هم راه‌حل نیست — سفارش به `on-hold` می‌رود تا **آدم**
            | تصمیم بگیرد: یا تفاوت را بپذیرد، یا از مشتری بگیرد، یا لغو کند.
            */
            $now = $api->check(...self::split($domain));
            $current = self::cost_of($now, $domain);

            if ($current > 0 && $quoted_cost > 0) {
                $rise = (($current - $quoted_cost) / $quoted_cost) * 100;

                if ($rise > $tolerance) {
                    $item->update_meta_data('_servernet_state', 'held');
                    $item->save();

                    $order->update_status('on-hold', sprintf(
                        /* translators: 1: domain, 2: percentage */
                        __('ثبت «%1$s» متوقف شد: قیمت خرید شما %2$s٪ بالا رفته است. پیش از ثبت تصمیم بگیرید.', 'servernet-domains'),
                        $domain,
                        number_format_i18n($rise, 1)
                    ));

                    continue;
                }
            }

            $res = $api->register($domain, $years, $ns, ServerNet_API::key_for('register', $domain, array(
                // ⚠️ شناسهٔ **آیتم** نه سفارش: یک سفارش می‌تواند چند دامنه
                //    داشته باشد و هرکدام باید کلیدِ جدا بگیرد.
                $item_id, $years,
            )));

            self::record($order, $item, $domain, $res);
        }

        $order->save();
    }

    /** ثبتِ نتیجه روی خطِ سفارش + یادداشت برای مدیر */
    private static function record($order, $item, $domain, $res)
    {
        if (! empty($res['ok'])) {
            $data = (array) $res['data'];
            $state = isset($data['order_state']) ? (string) $data['order_state'] : 'pending';

            /*
            | ⚠️ «pending» شکست نیست و نباید خطا نوشته شود. ثبت گاهی چند دقیقه
            | طول می‌کشد؛ کرونِ `servernet_poll_pending` دنبالش را می‌گیرد.
            */
            $item->update_meta_data('_servernet_state', $state === 'registered' ? 'registered' : 'pending');
            $item->save();

            $order->add_order_note($state === 'registered'
                ? sprintf(__('دامنهٔ %s با موفقیت ثبت شد.', 'servernet-domains'), $domain)
                : sprintf(__('دامنهٔ %s در صف ثبت است؛ وضعیت خودکار پی‌گیری می‌شود.', 'servernet-domains'), $domain));

            return;
        }

        if (! empty($res['transport'])) {
            /*
            | 🔴 «نشنیدیم» ≠ «نشد».
            |
            | تایم‌اوت یعنی دامنه **ممکن است ثبت شده باشد**. اگر «ناموفق»
            | بنویسیم، مدیر دوباره سفارش می‌دهد یا لغو می‌کند — در حالی که پول
            | رفته و دامنه ثبت شده. `pending` می‌ماند تا کرون واقعیت را بپرسد.
            */
            $item->update_meta_data('_servernet_state', 'pending');
            $item->save();

            $order->add_order_note(sprintf(
                __('ارتباط با سرورنت هنگام ثبت %s قطع شد و نتیجه نامعلوم است. وضعیت خودکار بررسی می‌شود؛ دوباره سفارش ندهید.', 'servernet-domains'),
                $domain
            ));

            return;
        }

        $item->update_meta_data('_servernet_state', 'failed');
        $item->update_meta_data('_servernet_error', (string) ($res['error'] ?? 'unknown'));
        $item->save();

        $hints = array(
            'insufficient_credit'   => __('اعتبار حساب نمایندگی شما کافی نیست. از پنل سرورنت شارژ کنید.', 'servernet-domains'),
            'daily_cap_reached'     => __('سقف خرج روزانهٔ API پر شده است.', 'servernet-domains'),
            'insufficient_scope'    => __('توکن دسترسی domains:write ندارد.', 'servernet-domains'),
            'ip_not_allowed'        => __('IP این سرور در فهرست مجاز توکن نیست.', 'servernet-domains'),
            'registrant_incomplete' => __('مشخصات مالک در حساب نمایندگی شما ناقص است.', 'servernet-domains'),
            'tld_blocked'           => __('ثبت این پسوند موقتاً مقدور نیست؛ مبلغی کسر نشد.', 'servernet-domains'),
            'already_registered'    => __('این دامنه از قبل ثبت شده است.', 'servernet-domains'),
        );

        $code = (string) ($res['error'] ?? '');

        $order->add_order_note(sprintf(
            /* translators: 1: domain, 2: reason */
            __('ثبت دامنهٔ %1$s ناموفق بود: %2$s', 'servernet-domains'),
            $domain,
            isset($hints[$code]) ? $hints[$code] : ($res['message'] ?: $code)
        ));
    }

    /**
     * پی‌گیریِ سفارش‌هایی که ثبتشان تمام نشده.
     *
     * ⚠️ بی‌این، یک ثبتِ `pending` تا ابد در همان حالت می‌مانَد: دامنه در پنلِ
     * سرورنت ثبت شده، مشتری پول داده، و سایتِ نماینده هنوز «در حالِ بررسی»
     * نشان می‌دهد — تا روزی که مشتری تماس بگیرد.
     */
    public static function poll_pending()
    {
        $orders = wc_get_orders(array(
            'limit'      => 30,
            'status'     => array('processing', 'completed', 'on-hold'),
            'date_after' => gmdate('Y-m-d', time() - 30 * DAY_IN_SECONDS),
        ));

        if (! $orders) {
            return;
        }

        $api = new ServerNet_API;

        if (! $api->is_configured()) {
            return;
        }

        foreach ($orders as $order) {
            $touched = false;

            foreach ($order->get_items() as $item) {
                if ((string) $item->get_meta('_servernet_state') !== 'pending') {
                    continue;
                }

                $domain = (string) $item->get_meta('_servernet_domain');

                if ($domain === '') {
                    continue;
                }

                $res = $api->domain($domain);

                if (empty($res['ok'])) {
                    /*
                    | 🔴 شکستِ **خواندن** هرگز وضعیت نمی‌نویسد.
                    |
                    | توکنِ منقضی یا یک قطعیِ شبکه نباید دامنه‌ای را «ناموفق»
                    | علامت بزند؛ آن‌وقت یک قطعیِ گذرا همهٔ سفارش‌های در جریان
                    | را خراب اعلام می‌کند و مدیر بر اساسش سفارشِ سالم را لغو
                    | می‌کند.
                    */
                    continue;
                }

                $status = (string) ($res['data']['status'] ?? '');

                if ($status === 'active') {
                    $item->update_meta_data('_servernet_state', 'registered');
                    $item->save();
                    $order->add_order_note(sprintf(__('دامنهٔ %s ثبت شد.', 'servernet-domains'), $domain));
                    $touched = true;
                } elseif (in_array($status, array('cancelled', 'expired'), true)) {
                    $item->update_meta_data('_servernet_state', 'failed');
                    $item->save();
                    $order->add_order_note(sprintf(
                        __('ثبت دامنهٔ %s در سرورنت لغو شد و مبلغش به اعتبار شما بازگشت. تکلیف سفارش مشتری را روشن کنید.', 'servernet-domains'),
                        $domain
                    ));
                    $touched = true;
                }
            }

            if ($touched) {
                $order->save();
            }
        }
    }

    /** وضعیتِ ثبت، زیرِ هر خطِ سفارش در پنلِ مدیر و صفحهٔ مشتری */
    public static function order_item_status($item_id, $item, $order)
    {
        $domain = is_object($item) && method_exists($item, 'get_meta') ? (string) $item->get_meta('_servernet_domain') : '';

        if ($domain === '') {
            return;
        }

        $labels = array(
            'registered' => __('ثبت شد', 'servernet-domains'),
            'pending'    => __('در حال ثبت', 'servernet-domains'),
            'failed'     => __('ثبت نشد', 'servernet-domains'),
            'held'       => __('متوقف — تغییر قیمت', 'servernet-domains'),
            'new'        => __('در انتظار پرداخت', 'servernet-domains'),
        );

        $state = (string) $item->get_meta('_servernet_state');

        if (isset($labels[$state])) {
            echo '<p class="sn-item-state"><small>'.esc_html($labels[$state]).'</small></p>';
        }
    }

    // ═══════════════════════ کمکی ═══════════════════════

    /** «example.com» → ['example', ['com']] برای `check()` */
    private static function split($domain)
    {
        [$sld, $tld] = array_pad(explode('.', $domain, 2), 2, '');

        return array($sld, array($tld));
    }

    private static function cost_of($res, $domain)
    {
        if (empty($res['ok'])) {
            return 0;
        }

        foreach ((array) $res['data'] as $r) {
            if (isset($r['domain']) && strtolower($r['domain']) === strtolower($domain)) {
                return (int) ($r['price']['register'] ?? 0);
            }
        }

        return 0;
    }
}
