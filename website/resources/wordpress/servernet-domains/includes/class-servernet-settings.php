<?php
/**
 * صفحهٔ تنظیمات + آزمونِ اتصال.
 *
 * @package ServerNet_Domains
 */

defined('ABSPATH') || exit;

class ServerNet_Settings
{
    const PAGE = 'servernet-domains';

    public static function init()
    {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_init', array(__CLASS__, 'register'));
        add_action('admin_post_servernet_test', array(__CLASS__, 'test_connection'));
    }

    public static function menu()
    {
        add_options_page(
            __('دامنه‌های سرورنت', 'servernet-domains'),
            __('دامنه‌های سرورنت', 'servernet-domains'),
            'manage_options',
            self::PAGE,
            array(__CLASS__, 'render')
        );
    }

    public static function register()
    {
        register_setting(self::PAGE, 'servernet_token', array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''));
        register_setting(self::PAGE, 'servernet_api_url', array('type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => ServerNet_API::DEFAULT_BASE));
        register_setting(self::PAGE, 'servernet_markup_pct', array('type' => 'number', 'sanitize_callback' => array(__CLASS__, 'sanitize_markup'), 'default' => 25));
        register_setting(self::PAGE, 'servernet_nameservers', array('type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => ''));
        register_setting(self::PAGE, 'servernet_tlds', array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'com,net,org,shop,site,online'));
        register_setting(self::PAGE, 'servernet_drift_tolerance_pct', array('type' => 'number', 'sanitize_callback' => array(__CLASS__, 'sanitize_drift'), 'default' => 0));
    }

    /**
     * 🔴 حاشیهٔ سودِ منفی پذیرفته نمی‌شود.
     *
     * درصدِ منفی یعنی نماینده دامنه را **ارزان‌تر از قیمتِ خریدِ خودش** به
     * مشتری می‌فروشد و روی هر فروش ضرر می‌کند. این تقریباً همیشه یک اشتباهِ
     * تایپی است (`-25` به‌جای `25`)، و اگر پذیرفته شود تا وقتی کسی حساب‌کتاب
     * نکند کشف نمی‌شود — یعنی ماه‌ها بعد.
     */
    public static function sanitize_markup($v)
    {
        return max(0, min(500, (float) $v));
    }

    public static function sanitize_drift($v)
    {
        return max(0, min(50, (float) $v));
    }

    /** @return string[] */
    public static function nameservers()
    {
        $raw = (string) get_option('servernet_nameservers', '');
        $out = array();

        foreach (preg_split('/[\s,]+/', $raw) as $host) {
            $host = strtolower(trim($host, " \t."));
            if ($host !== '' && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $host)) {
                $out[] = $host;
            }
        }

        // ⚠️ کمتر از دو تا = خالی. یک نام‌سرورِ تنها یعنی دامنه‌ای که به هیچ‌جا
        //    اشاره نمی‌کند؛ سرورنت پیش‌فرضِ خودش را می‌گذارد که بهتر است.
        return count($out) >= 2 ? array_slice($out, 0, 5) : array();
    }

    /** @return string[] */
    public static function tlds()
    {
        $raw = (string) get_option('servernet_tlds', 'com,net,org');
        $out = array();

        foreach (preg_split('/[\s,]+/', $raw) as $t) {
            $t = strtolower(ltrim(trim($t), '.'));
            if ($t !== '' && preg_match('/^[a-z0-9.-]{2,63}$/', $t)) {
                $out[] = $t;
            }
        }

        return array_slice(array_unique($out), 0, 12);
    }

    /** قیمتِ فروشِ نماینده = قیمتِ خریدِ او از سرورنت + حاشیهٔ خودش */
    public static function retail($cost)
    {
        $pct = (float) get_option('servernet_markup_pct', 25);

        return (int) ceil(((float) $cost) * (1 + $pct / 100));
    }

    public static function test_connection()
    {
        check_admin_referer('servernet_test');

        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('دسترسی ندارید.', 'servernet-domains'));
        }

        $api = new ServerNet_API;
        $res = $api->ping();

        if (empty($res['ok'])) {
            $msg = $res['message'] ? $res['message'] : __('اتصال برقرار نشد.', 'servernet-domains');
            set_transient('servernet_notice', array('type' => 'error', 'msg' => $msg), 60);
            wp_safe_redirect(admin_url('options-general.php?page='.self::PAGE));
            exit;
        }

        $d = (array) $res['data'];
        $abilities = isset($d['token']['abilities']) ? (array) $d['token']['abilities'] : array();

        /*
        | ⚠️ اتصالِ موفق کافی نیست.
        |
        | توکنی که فقط `read` دارد این آزمون را پاس می‌کند و بعد **در لحظهٔ
        | پرداختِ یک مشتریِ واقعی** شکست می‌خورد. بدترین زمانِ ممکن برای کشفِ
        | یک اشتباهِ پیکربندی، وسطِ یک فروش است.
        */
        if (! in_array('domains:write', $abilities, true) && ! in_array('*', $abilities, true)) {
            set_transient('servernet_notice', array(
                'type' => 'error',
                'msg'  => __('اتصال برقرار شد ولی این توکن دسترسی «domains:write» ندارد؛ با آن نمی‌توان دامنه ثبت کرد.', 'servernet-domains'),
            ), 60);
            wp_safe_redirect(admin_url('options-general.php?page='.self::PAGE));
            exit;
        }

        $level = isset($d['reseller']['level_name']) ? $d['reseller']['level_name'] : '—';
        $credit = isset($d['credit']['IRT']) ? (int) $d['credit']['IRT'] : 0;

        set_transient('servernet_notice', array(
            'type' => empty($d['reseller']['enabled']) ? 'warning' : 'success',
            'msg'  => empty($d['reseller']['enabled'])
                ? __('اتصال برقرار شد ولی حساب شما هنوز به‌عنوان نمایندهٔ دامنه فعال نشده است.', 'servernet-domains')
                : sprintf(
                    /* translators: 1: tier name, 2: credit balance */
                    __('اتصال برقرار است. سطح: %1$s · اعتبار: %2$s تومان', 'servernet-domains'),
                    $level,
                    number_format_i18n($credit)
                ),
        ), 60);

        wp_safe_redirect(admin_url('options-general.php?page='.self::PAGE));
        exit;
    }

    public static function render()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $notice = get_transient('servernet_notice');
        if ($notice) {
            delete_transient('servernet_notice');
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr($notice['type']),
                esc_html($notice['msg'])
            );
        }

        $markup = (float) get_option('servernet_markup_pct', 25);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('دامنه‌های سرورنت', 'servernet-domains'); ?></h1>

            <?php if ($markup <= 0) : ?>
                <div class="notice notice-warning">
                    <p><strong><?php esc_html_e('حاشیهٔ سود شما صفر است.', 'servernet-domains'); ?></strong>
                    <?php esc_html_e('یعنی دامنه را دقیقاً به قیمت خریدتان می‌فروشید و هزینهٔ درگاه پرداخت از جیب خودتان می‌رود.', 'servernet-domains'); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields(self::PAGE); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="sn_token"><?php esc_html_e('توکن API', 'servernet-domains'); ?></label></th>
                        <td>
                            <input type="password" id="sn_token" name="servernet_token" class="regular-text" autocomplete="off"
                                   value="<?php echo esc_attr(get_option('servernet_token', '')); ?>">
                            <p class="description">
                                <?php esc_html_e('از پنل کاربری سرورنت → امنیت → توکن API. دسترسی‌های domains:write و domains:manage لازم است. حتماً IP همین سرور را در فهرست مجاز توکن بگذارید.', 'servernet-domains'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sn_url"><?php esc_html_e('نشانی API', 'servernet-domains'); ?></label></th>
                        <td><input type="url" id="sn_url" name="servernet_api_url" class="regular-text" dir="ltr"
                                   value="<?php echo esc_attr(get_option('servernet_api_url', ServerNet_API::DEFAULT_BASE)); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sn_markup"><?php esc_html_e('حاشیهٔ سود شما (٪)', 'servernet-domains'); ?></label></th>
                        <td>
                            <input type="number" id="sn_markup" name="servernet_markup_pct" min="0" max="500" step="0.1"
                                   value="<?php echo esc_attr($markup); ?>">
                            <p class="description"><?php esc_html_e('روی قیمت خرید شما از سرورنت اعمال می‌شود. عدد منفی پذیرفته نمی‌شود.', 'servernet-domains'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sn_drift"><?php esc_html_e('تحمل نوسان قیمت (٪)', 'servernet-domains'); ?></label></th>
                        <td>
                            <input type="number" id="sn_drift" name="servernet_drift_tolerance_pct" min="0" max="50" step="0.5"
                                   value="<?php echo esc_attr(get_option('servernet_drift_tolerance_pct', 0)); ?>">
                            <p class="description">
                                <?php esc_html_e('اگر بین لحظهٔ سفارش و لحظهٔ پرداخت، قیمت خرید شما بیشتر از این درصد بالا برود، دامنه خودکار ثبت نمی‌شود و سفارش به حالت «در انتظار» می‌رود تا خودتان تصمیم بگیرید. صفر یعنی هیچ ضرری پذیرفته نمی‌شود.', 'servernet-domains'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sn_tlds"><?php esc_html_e('پسوندهای پیشنهادی', 'servernet-domains'); ?></label></th>
                        <td>
                            <input type="text" id="sn_tlds" name="servernet_tlds" class="regular-text" dir="ltr"
                                   value="<?php echo esc_attr(get_option('servernet_tlds', 'com,net,org')); ?>">
                            <p class="description"><?php esc_html_e('با کاما جدا کنید، حداکثر ۱۲ پسوند. پسوندهای ایرانی (ir.) از این مسیر فروخته نمی‌شوند.', 'servernet-domains'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sn_ns"><?php esc_html_e('نام‌سرورهای پیش‌فرض', 'servernet-domains'); ?></label></th>
                        <td>
                            <textarea id="sn_ns" name="servernet_nameservers" rows="2" class="regular-text" dir="ltr"><?php echo esc_textarea(get_option('servernet_nameservers', '')); ?></textarea>
                            <p class="description"><?php esc_html_e('حداقل دو مورد، وگرنه نام‌سرورهای سرورنت استفاده می‌شود.', 'servernet-domains'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr>
            <h2><?php esc_html_e('آزمون اتصال', 'servernet-domains'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="servernet_test">
                <?php wp_nonce_field('servernet_test'); ?>
                <?php submit_button(__('آزمون اتصال', 'servernet-domains'), 'secondary', 'submit', false); ?>
            </form>

            <hr>
            <h2><?php esc_html_e('نمایش جعبهٔ جستجو', 'servernet-domains'); ?></h2>
            <p><?php esc_html_e('این کد کوتاه را در هر برگه یا نوشته بگذارید:', 'servernet-domains'); ?></p>
            <p><code>[servernet_domain_search]</code></p>
            <p class="description"><?php esc_html_e('مستندات کامل API: ', 'servernet-domains'); ?>
                <a href="https://servernet.cloud/developers" target="_blank" rel="noopener">servernet.cloud/developers</a></p>
        </div>
        <?php
    }
}
