<?php

/**
 * ماژولِ رجیسترارِ سرورنت برای WHMCS
 * ==================================
 *
 * نمایندگانِ دامنهٔ سرورنت این ماژول را روی WHMCSِ خودشان نصب می‌کنند و
 * ثبت/تمدید/مدیریتِ دامنه از حسابِ نمایندگی‌شان انجام می‌شود.
 *
 * نصب:  این پوشه را در `modules/registrars/servernet/` بگذارید، سپس
 *       Configuration → System Settings → Domain Registrars → ServerNet.
 *
 * ⚠️ همهٔ منطقِ قابلِ آزمون در `lib/ServerNetApi.php` است تا بدونِ WHMCS هم
 *    تست شود. این فایل عمداً نازک است: هرچه این‌جا بماند فقط روی سرورِ
 *    نماینده کشف می‌شود، نه در تستِ ما.
 *
 * @package  ServerNet WHMCS Registrar
 * @version  1.0.0
 * @license  MIT
 * @link     https://servernet.cloud/developers
 */

if (! defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__.'/lib/ServerNetApi.php';

use ServerNet\Registrar\ServerNetApi;

// ═══════════════════════════ پیکربندی ═══════════════════════════

function servernet_MetaData()
{
    return [
        'DisplayName' => 'ServerNet — نمایندگی دامنه',
        'APIVersion'  => '1.1',
    ];
}

function servernet_getConfigArray()
{
    return [
        'FriendlyName' => [
            'Type'  => 'System',
            'Value' => 'ServerNet Domain Reseller v'.ServerNetApi::VERSION,
        ],
        'ApiToken' => [
            'FriendlyName' => 'توکن API',
            'Type'         => 'password',
            'Size'         => '60',
            'Description'  => 'از پنل کاربری سرورنت → امنیت → توکن API. '
                .'توکن باید دسترسی‌های domains:write و domains:manage داشته باشد.',
        ],
        'ApiUrl' => [
            'FriendlyName' => 'نشانی API',
            'Type'         => 'text',
            'Size'         => '60',
            'Default'      => ServerNetApi::DEFAULT_BASE,
            'Description'  => 'در حالت عادی تغییرش ندهید.',
        ],
        'DefaultNs' => [
            'FriendlyName' => 'نام‌سرورهای پیش‌فرض',
            'Type'         => 'text',
            'Size'         => '60',
            'Description'  => 'با کاما جدا کنید. خالی = نام‌سرورهای سرورنت.',
        ],
        'Notice' => [
            'FriendlyName' => 'توجه',
            'Type'         => 'System',
            'Value'        => 'قیمت‌ها به <b>تومان (IRT)</b> برمی‌گردند — ارز WHMCS شما باید تومان باشد. '
                .'ثبت از <b>اعتبار حساب نمایندگی</b> کسر می‌شود؛ پیش از فروش، حساب را شارژ کنید.',
        ],
    ];
}

/** @return ServerNetApi */
function servernet_client(array $params)
{
    return new ServerNetApi(
        (string) ($params['ApiToken'] ?? ''),
        (string) ($params['ApiUrl'] ?? '') ?: ServerNetApi::DEFAULT_BASE,
    );
}

/** «example» + «com» → «example.com» (پشتیبانی از پسوندِ چندبخشی مثل co.uk) */
function servernet_fqdn(array $params)
{
    $sld = strtolower(trim((string) ($params['sld'] ?? '')));
    $tld = strtolower(trim((string) ($params['tld'] ?? ''), " \t."));

    return $sld.'.'.$tld;
}

/**
 * ثبتِ تماس در لاگِ ماژولِ WHMCS.
 *
 * ⚠️ توکن هرگز پاس داده نمی‌شود — `ServerNetApi::$lastRequest` عمداً شاملش
 * نیست. لاگِ ماژول در دیتابیسِ WHMCS می‌نشیند و آن دیتابیس بکاپ می‌شود.
 */
function servernet_log($action, ServerNetApi $api, $result)
{
    if (function_exists('logModuleCall')) {
        logModuleCall('servernet', $action, $api->lastRequest, $api->lastResponse, $result);
    }
}

/**
 * ترجمهٔ پاسخِ ناموفق به پیامِ WHMCS.
 *
 * 🔴 «نشنیدیم» هرگز «ناموفق» ترجمه نمی‌شود.
 *
 * تایم‌اوتِ وسطِ ثبت یعنی دامنه **ممکن است ثبت شده باشد**. اگر ماژول بگوید
 * «ناموفق»، اپراتور یا دوباره سفارش می‌دهد یا لغو می‌کند — در حالی که ثبت
 * انجام شده و پولش رفته. پس متن صریح می‌گوید که وضعیت نامعلوم است و چه باید
 * کرد. همان تفکیکی که در سرورنت با پول یاد گرفته شد.
 */
function servernet_error(array $res)
{
    if (! empty($res['transport'])) {
        return ['error' => 'ارتباط با سرورنت قطع شد و نتیجه نامعلوم است. '
            .'پیش از تلاش دوباره، وضعیت دامنه را در پنل سرورنت ببینید — ممکن است ثبت شده باشد.'];
    }

    $code = (string) ($res['error'] ?? 'unknown');
    $msg = (string) ($res['message'] ?? '');

    // پیام‌هایی که اپراتور باید دقیقاً بداند چه کند
    $hints = [
        'insufficient_credit' => 'اعتبار حساب نمایندگی کافی نیست. از پنل سرورنت شارژ کنید.',
        'daily_cap_reached'   => 'سقف خرج روزانهٔ API پر شده است.',
        'insufficient_scope'  => 'این توکن دسترسی لازم را ندارد (domains:write).',
        'token_expired'       => 'توکن API منقضی شده است. توکن تازه بسازید.',
        'token_revoked'       => 'توکن API باطل شده است.',
        'ip_not_allowed'      => 'IP این سرور در فهرست مجازِ توکن نیست.',
        'registrant_incomplete' => 'مشخصات مالک در حساب نمایندگی کامل نیست؛ از پنل سرورنت تکمیلش کنید.',
        'tld_blocked'         => 'ثبت این پسوند موقتاً از سمت سرورنت مقدور نیست. مبلغی کسر نشد.',
        'request_in_progress' => 'همین درخواست در حال پردازش است؛ چند لحظه بعد Sync بزنید.',
    ];

    return ['error' => ($hints[$code] ?? ($msg ?: 'خطای ناشناخته')).' ['.$code.']'];
}

// ═══════════════════════════ اتصال ═══════════════════════════

function servernet_TestConnection(array $params)
{
    $api = servernet_client($params);
    $res = $api->ping();
    servernet_log('TestConnection', $api, $res);

    if (! $res['ok']) {
        return ['success' => false, 'error' => servernet_error($res)['error']];
    }

    $d = (array) $res['data'];
    $abilities = (array) ($d['token']['abilities'] ?? []);

    /*
    | ⚠️ اتصالِ موفق کافی نیست — دسترسی هم بررسی می‌شود.
    |
    | توکنی که فقط `read` دارد این آزمون را پاس می‌کند و بعد **در لحظهٔ
    | سفارشِ یک مشتریِ واقعی** شکست می‌خورد. بدترین زمانِ ممکن برای کشفِ یک
    | اشتباهِ پیکربندی، وسطِ یک فروش است.
    */
    if (! in_array('domains:write', $abilities, true) && ! in_array('*', $abilities, true)) {
        return ['success' => false, 'error' => 'اتصال برقرار شد ولی این توکن دسترسی «domains:write» ندارد؛ '
            .'با آن نمی‌توان دامنه ثبت کرد. از پنل سرورنت توکن تازه با این دسترسی بسازید.'];
    }

    if (empty($d['reseller']['enabled'])) {
        return ['success' => false, 'error' => 'اتصال برقرار شد ولی حساب شما هنوز به‌عنوان نمایندهٔ دامنه فعال نشده است. '
            .'با پشتیبانی سرورنت تماس بگیرید.'];
    }

    return ['success' => true];
}

// ═══════════════════════════ خرید ═══════════════════════════

function servernet_RegisterDomain(array $params)
{
    $api = servernet_client($params);
    $fqdn = servernet_fqdn($params);
    $years = max(1, (int) ($params['regperiod'] ?? 1));

    $res = $api->register(
        $fqdn,
        $years,
        servernet_nameservers($params),
        // کلیدِ قطعی: تلاشِ دوبارهٔ WHMCS همان کلید را می‌فرستد و دامنهٔ دوم
        // خریده نمی‌شود. `domainid` در WHMCS یکتاست و یک دامنه یک بار ثبت می‌شود.
        ServerNetApi::keyFor('register', $fqdn, [(int) ($params['domainid'] ?? 0), $years])
    );

    servernet_log('RegisterDomain', $api, $res);

    if (! $res['ok']) {
        return servernet_error($res);
    }

    /*
    | 🔴 «pending» شکست **نیست** و نباید خطا برگردانَد.
    |
    | ثبت گاهی همان لحظه تمام نمی‌شود (تماس با رجیستری کُند است، یا کرونِ
    | سرورنت هم‌زمان همان دامنه را برداشته). اگر این‌جا خطا بدهیم، WHMCS سفارش
    | را ناموفق نشان می‌دهد در حالی که ثبت دارد **موفق** انجام می‌شود.
    |
    | `_Sync` وضعیت واقعی را ظرف چند دقیقه می‌آورد.
    */
    return [];
}

function servernet_RenewDomain(array $params)
{
    $api = servernet_client($params);
    $fqdn = servernet_fqdn($params);
    $years = max(1, (int) ($params['regperiod'] ?? 1));

    /*
    | 🔴 تاریخِ انقضای فعلی **باید** در کلید باشد.
    |
    | بی‌آن، تمدیدِ سالِ بعدِ همان دامنه دقیقاً همان کلید را می‌سازد و سرور
    | آن را «درخواستِ تکراری» می‌بیند و پاسخِ پارسال را دوباره پخش می‌کند:
    | WHMCS «تمدید شد» می‌گیرد، مشتری پول می‌دهد، و هیچ تمدیدی انجام نمی‌شود —
    | تا روزی که دامنه منقضی شود. کاملاً خاموش، با کدِ ۲۰۰.
    |
    | انقضا در تلاش‌های دوبارهٔ همین درخواست ثابت است و بعد از تمدیدِ موفق
    | عوض می‌شود؛ یعنی دقیقاً همان چیزی که کلید لازم دارد.
    */
    $expiry = (string) ($params['expirydate'] ?? $params['expiryDate'] ?? '');

    $res = $api->renew($fqdn, $years, ServerNetApi::keyFor('renew', $fqdn, [
        (int) ($params['domainid'] ?? 0), $years, $expiry,
    ]));

    servernet_log('RenewDomain', $api, $res);

    return $res['ok'] ? [] : servernet_error($res);
}

/**
 * انتقال — در نسخهٔ ۱ پشتیبانی نمی‌شود.
 *
 * ⚠️ صریح رد می‌شود و «ساکت موفق» اعلام نمی‌شود. ماژولی که بگوید «شد» و
 * نشده باشد، مشتریِ نماینده را ماه‌ها منتظر می‌گذارد.
 */
function servernet_TransferDomain(array $params)
{
    return ['error' => 'انتقال دامنه از این ماژول هنوز پشتیبانی نمی‌شود. '
        .'برای انتقال، با پشتیبانی سرورنت تماس بگیرید.'];
}

// ═══════════════════════════ مدیریت ═══════════════════════════

function servernet_GetNameservers(array $params)
{
    $api = servernet_client($params);
    $res = $api->domain(servernet_fqdn($params));
    servernet_log('GetNameservers', $api, $res);

    if (! $res['ok']) {
        return servernet_error($res);
    }

    $out = [];
    $ns = array_values((array) ($res['data']['nameservers'] ?? []));

    foreach ($ns as $i => $host) {
        $out['ns'.($i + 1)] = $host;
    }

    return $out;
}

function servernet_SaveNameservers(array $params)
{
    $api = servernet_client($params);

    $ns = [];
    for ($i = 1; $i <= 5; $i++) {
        $v = trim((string) ($params['ns'.$i] ?? ''));
        if ($v !== '') {
            $ns[] = $v;
        }
    }

    if (count($ns) < 2) {
        // محلی رد می‌شود: دامنه‌ای با یک نام‌سرور به هیچ‌جا اشاره نمی‌کند و
        // یک تماسِ شبکه‌ای برای گرفتنِ همین جواب لازم نیست.
        return ['error' => 'دست‌کم دو نام‌سرور لازم است.'];
    }

    $res = $api->saveNameservers(servernet_fqdn($params), $ns);
    servernet_log('SaveNameservers', $api, $res);

    return $res['ok'] ? [] : servernet_error($res);
}

function servernet_GetRegistrarLock(array $params)
{
    $api = servernet_client($params);
    $res = $api->domain(servernet_fqdn($params));
    servernet_log('GetRegistrarLock', $api, $res);

    if (! $res['ok']) {
        return servernet_error($res);
    }

    return ! empty($res['data']['locked']) ? 'locked' : 'unlocked';
}

/**
 * قفلِ انتقال — فقط **روشن‌کردن**.
 *
 * 🔴 خاموش‌کردن عمداً از دسترسِ API بیرون است. قفلِ باز پیش‌نیازِ بردنِ دامنه
 * است؛ توکنی که بتواند قفل را باز کند یعنی یک نشتِ توکن، پرتفویِ مشتریانِ
 * نماینده را در یک حلقه از دستش می‌برد. عملی که فقط محافظت **اضافه** می‌کند
 * بی‌خطر است؛ عملی که محافظت را برمی‌دارد باید انسان ببیندش.
 */
function servernet_SaveRegistrarLock(array $params)
{
    $wantLocked = (string) ($params['lockenabled'] ?? '') === 'locked';

    if (! $wantLocked) {
        return ['error' => 'باز کردن قفل انتقال از WHMCS ممکن نیست. '
            .'برای امنیت دامنه‌ها، این کار فقط از پنل کاربری سرورنت انجام می‌شود.'];
    }

    $api = servernet_client($params);
    $res = $api->lock(servernet_fqdn($params), true);
    servernet_log('SaveRegistrarLock', $api, $res);

    return $res['ok'] ? [] : servernet_error($res);
}

/**
 * کدِ انتقال (EPP) — عمداً از API برنمی‌گردد.
 *
 * 🔴 این کد **کلیدِ مالکیتِ** دامنه است. اگر از API برگردد، در لاگِ ماژولِ
 * WHMCS، در بکاپِ آن دیتابیس، و در لاگِ هر پروکسیِ میانی می‌نشیند. در پنلِ
 * سرورنت این کد نه ذخیره می‌شود و نه لاگ؛ همان‌جا در لحظه گرفته و یک‌بار
 * نشان داده می‌شود.
 */
function servernet_GetEPPCode(array $params)
{
    return ['error' => 'کد انتقال (EPP) از WHMCS قابل دریافت نیست. '
        .'برای امنیت، فقط از پنل کاربری سرورنت و یک‌بار نمایش داده می‌شود.'];
}

/**
 * مشخصاتِ مالک.
 *
 * ⚠️ در نسخهٔ ۱، مالکِ ثبت‌شدهٔ همهٔ دامنه‌ها **خودِ نماینده** است، نه مشتریِ
 * نهایی. پس ویرایشِ این فرم در WHMCS هیچ اثری نزدِ رجیستری ندارد و ماژول
 * صریح می‌گوید — به‌جای اینکه بپذیرد و بی‌صدا دور بریزد.
 */
function servernet_GetContactDetails(array $params)
{
    return ['error' => 'مالک ثبت‌شدهٔ دامنه‌ها در این نسخه، حساب نمایندگی شماست. '
        .'ویرایش مشخصات مالک از پنل سرورنت انجام می‌شود.'];
}

function servernet_SaveContactDetails(array $params)
{
    return ['error' => 'ویرایش مشخصات مالک از WHMCS پشتیبانی نمی‌شود.'];
}

// ═══════════════════════════ همگام‌سازی ═══════════════════════════

/**
 * وضعیت و انقضای واقعی.
 *
 * ═══ 🔴 شکستِ خواندن هرگز وضعیت نمی‌نویسد ═══
 *
 * اگر توکن منقضی شود یا شبکه قطع باشد، وسوسه این است که «پیدا نشد» را
 * «منتقل شده» یا «منقضی» بخوانیم. آن‌وقت یک قطعیِ گذرا، **همهٔ** دامنه‌های
 * نماینده را در WHMCS مرده علامت می‌زند و مشتریانش ایمیلِ انقضا می‌گیرند.
 * خطا برگردانده می‌شود؛ WHMCS همان را نگه می‌دارد و دفعهٔ بعد دوباره می‌پرسد.
 */
function servernet_Sync(array $params)
{
    $api = servernet_client($params);
    $res = $api->domain(servernet_fqdn($params));
    servernet_log('Sync', $api, $res);

    if (! $res['ok']) {
        return ['error' => servernet_error($res)['error']];
    }

    $d = (array) $res['data'];
    $status = (string) ($d['status'] ?? '');
    $expiry = (string) ($d['expires_at'] ?? '');

    $out = [];

    if ($expiry !== '') {
        // ISO-8601 → Y-m-d که WHMCS انتظار دارد
        $ts = strtotime($expiry);
        if ($ts) {
            $out['expirydate'] = date('Y-m-d', $ts);
        }
    }

    /*
    | ⚠️ `pending` نه فعال است نه منقضی — هیچ پرچمی نمی‌خورد.
    |
    | علامت‌زدنش به‌عنوان «فعال» یعنی WHMCS دامنه‌ای را به مشتری تحویل می‌دهد
    | که هنوز ثبت نشده؛ علامت‌زدنش به‌عنوان «منقضی» یعنی مشتری ایمیلِ هشدار
    | می‌گیرد برای دامنه‌ای که همین حالا خریده.
    */
    switch ($status) {
        case 'active':
            $out['active'] = true;
            $out['expired'] = false;
            break;

        case 'expired':
            $out['active'] = false;
            $out['expired'] = true;
            break;

        case 'transferred_away':
            $out['transferredAway'] = true;
            break;

        case 'cancelled':
            /*
            | 🔴 لغو در سمتِ ما = پولِ نماینده برگشته.
            |
            | `domains:resolve-stuck` دامنهٔ گیرکرده را بعد از مهلت لغو و
            | مبلغش را به اعتبار برمی‌گرداند. بی‌این شاخه، WHMCSِ نماینده تا
            | ابد دامنه‌ای را Active نشان می‌دهد که وجود ندارد — و او همان را
            | به مشتریِ خودش فروخته. تنها راهِ فهمیدنش شکایتِ مشتری بود.
            */
            $out['active'] = false;
            $out['expired'] = true;
            break;
    }

    return $out;
}

// ═══════════════════════════ جستجو و قیمت ═══════════════════════════

function servernet_CheckAvailability(array $params)
{
    $api = servernet_client($params);

    $term = (string) ($params['searchTerm'] ?? '');
    $tlds = array_map(
        fn ($t) => ltrim((string) $t, '.'),
        (array) ($params['tldsToInclude'] ?? [])
    );

    $res = $api->check($term, array_slice($tlds, 0, 12));
    servernet_log('CheckAvailability', $api, $res);

    $results = new \WHMCS\Domains\DomainLookup\ResultsList;

    if (! $res['ok']) {
        return $results;   // فهرستِ خالی — WHMCS خودش «نتیجه‌ای نبود» نشان می‌دهد
    }

    foreach ((array) $res['data'] as $row) {
        $fqdn = (string) ($row['domain'] ?? '');
        $tld = (string) ($row['tld'] ?? '');

        if ($fqdn === '' || $tld === '') {
            continue;
        }

        $sld = substr($fqdn, 0, strlen($fqdn) - strlen($tld) - 1);
        $r = new \WHMCS\Domains\DomainLookup\SearchResult($sld, $tld);

        /*
        | 🔴 روی `state` تصمیم می‌گیریم، نه روی `available`.
        |
        | سه رابطِ سرورنت یک بار دقیقاً به‌خاطرِ حدس‌زدنِ وضعیت از روی فیلدهای
        | خام واگرا شدند — یکی «نتوانستیم استعلام کنیم» را «ثبت‌شده» می‌خواند
        | و به کاربر می‌گفت اسمِ دلخواهش گرفته شده. `state` همان محاسبهٔ
        | یک‌بارهٔ سمتِ سرور است.
        */
        switch ((string) ($row['state'] ?? '')) {
            case 'free':
            case 'premium':
                $r->setStatus(\WHMCS\Domains\DomainLookup\SearchResult::STATUS_NOT_REGISTERED);

                if (! empty($row['is_premium'])) {
                    $r->setPremiumDomain(true);
                    $r->setPremiumCostPricing([
                        'register' => (int) ($row['price']['register'] ?? 0),
                        'renew'    => (int) ($row['price']['renew'] ?? 0),
                        'CurrencyCode' => 'IRT',
                    ]);
                }
                break;

            case 'taken':
                $r->setStatus(\WHMCS\Domains\DomainLookup\SearchResult::STATUS_REGISTERED);
                break;

            default:
                // unchecked / unsupported / no_price ⇒ «نمی‌دانیم»، نه «آزاد» و نه «گرفته»
                $r->setStatus(\WHMCS\Domains\DomainLookup\SearchResult::STATUS_TLD_NOT_SUPPORTED);
        }

        $results[] = $r;
    }

    return $results;
}

function servernet_GetDomainSuggestions(array $params)
{
    // پیشنهاد از همان مسیرِ استعلام می‌آید؛ سرورنت خودش پسوندهای جایگزین را
    // برمی‌گردانَد وقتی فهرستِ پسوند خالی باشد.
    return servernet_CheckAvailability($params);
}

/**
 * وارد کردنِ قیمت‌ها به WHMCS.
 *
 * ⚠️ قیمتِ **تمدید** جدا خوانده می‌شود و برابرِ ثبت گرفته نمی‌شود. قیمتِ سالِ
 * اولِ بیشترِ پسوندها تبلیغاتی است؛ نمونهٔ واقعیِ کاتالوگ: `.shop` با ثبتِ
 * ۱۹۰٬۰۰۰ و تمدیدِ ۱٬۴۹۰٬۰۰۰ تومان. برابر گرفتنشان یعنی هر تمدید با ضرر
 * فروخته می‌شود — و تمدید سالانه تکرار می‌شود، پس ضرر انباشته است.
 */
function servernet_GetTldPricing(array $params)
{
    $api = servernet_client($params);
    $res = $api->tlds();
    servernet_log('GetTldPricing', $api, $res);

    $results = new \WHMCS\Results\ResultsList;

    if (! $res['ok']) {
        return $results;
    }

    foreach ((array) $res['data'] as $row) {
        $tld = (string) ($row['tld'] ?? '');

        if ($tld === '' || (int) ($row['register'] ?? 0) <= 0) {
            continue;
        }

        $item = (new \WHMCS\Domain\TopLevel\ImportItem)
            ->setExtension('.'.$tld)
            ->setMinYears(1)
            ->setMaxYears(10)
            ->setRegisterPrice((int) $row['register'])
            ->setRenewPrice((int) ($row['renew'] ?? $row['register']))
            ->setTransferPrice((int) ($row['transfer'] ?? $row['register']))
            ->setEppRequired(true);

        if (isset($params['currency'])) {
            $item->setCurrency($params['currency']);
        }

        $results[] = $item;
    }

    return $results;
}

// ═══════════════════════════ کمکی ═══════════════════════════

/** @return string[] */
function servernet_nameservers(array $params)
{
    $ns = [];

    for ($i = 1; $i <= 5; $i++) {
        $v = trim((string) ($params['ns'.$i] ?? ''));
        if ($v !== '') {
            $ns[] = $v;
        }
    }

    if (count($ns) < 2) {
        foreach (explode(',', (string) ($params['DefaultNs'] ?? '')) as $host) {
            $host = trim($host);
            if ($host !== '') {
                $ns[] = $host;
            }
        }
    }

    // کمتر از دو تا ⇒ خالی می‌فرستیم و سرورنت پیش‌فرضِ خودش را می‌گذارد.
    // ⚠️ فرستادنِ یک نام‌سرور بدتر از نفرستادن است: دامنه‌ای می‌سازد که به
    //    هیچ‌جا اشاره نمی‌کند و سایتِ مشتری بالا نمی‌آید.
    return count($ns) >= 2 ? array_slice($ns, 0, 5) : [];
}
