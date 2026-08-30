<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;

/**
 * موتور بررسی سایت — سئو، پرفورمنس، امنیت، موبایل و بهترین‌روش‌ها.
 * همه‌چیز سرور-ساید و بدون وابستگی خارجی (curl + DOMDocument).
 * اگر PAGESPEED_API_KEY تنظیم باشد، Core Web Vitals واقعی هم افزوده می‌شود.
 */
class SiteAudit
{
    private string $url;
    private string $host;
    private array $info = [];
    private array $headers = [];
    private string $body = '';
    private ?DOMXPath $xp = null;

    /**
     * وزن دسته‌ها در امتیاز کل (جمع = ۱۰۰).
     *
     * ⚠️ عوض‌کردن این اعداد **امتیازِ همهٔ سایت‌ها** را جابه‌جا می‌کند. با افزودنِ
     * دو بُعدِ تازه (دسترس‌پذیری و شبکه) سهم بقیه کم شد؛ این عمدی است: گزارشی که
     * فقط سئو و سرعت را می‌سنجد به طراح و مدیرِ شبکه چیزی نمی‌گوید.
     */
    private const WEIGHTS = [
        'seo'           => 24,
        'performance'   => 18,
        'security'      => 18,
        'accessibility' => 14,
        'network'       => 10,
        'mobile'        => 10,
        'best'          => 6,
    ];

    /** وزنِ شدت در اولویت‌بندیِ برنامهٔ اقدام — خطا دو برابرِ هشدار می‌ارزد. */
    private const SEVERITY = ['fail' => 2.0, 'warn' => 1.0, 'pass' => 0.0];

    /** چند کار در «برنامهٔ اقدام» بیاید. بیشتر از این، فهرست به یک دیوار متن بدل می‌شود. */
    private const PLAN_SIZE = 6;

    public function __construct(private ?NetworkTools $net = null)
    {
        $this->net = $net ?? new NetworkTools();
    }

    public function run(string $input): array
    {
        $this->url = $this->normalize($input);
        if ($this->url === '') {
            return ['ok' => false, 'error' => 'invalid_url'];
        }
        $this->host = parse_url($this->url, PHP_URL_HOST) ?: '';

        // نگهبان SSRF: مقصد باید عمومی باشد، وگرنه سرور ما به شبکه‌ی داخلی درخواست می‌زند
        if (! SafeUrl::allowed($this->url)) {
            return ['ok' => false, 'error' => 'invalid_url'];
        }

        if (! $this->fetch()) {
            return ['ok' => false, 'error' => 'unreachable', 'url' => $this->url];
        }
        $this->parseDom();

        $cats = [
            'seo'           => $this->seoChecks(),
            'performance'   => $this->perfChecks(),
            'security'      => $this->securityChecks(),
            'accessibility' => $this->accessibilityChecks(),
            'network'       => $this->networkChecks(),
            'mobile'        => $this->mobileChecks(),
            'best'          => $this->bestPracticeChecks(),
        ];

        $scores = [];
        $overall = 0;
        foreach ($cats as $key => $checks) {
            $scores[$key] = $this->score($checks);
            $overall += $scores[$key] * self::WEIGHTS[$key];
        }
        $overall = (int) round($overall / array_sum(self::WEIGHTS));

        return [
            'ok'       => true,
            'url'      => $this->url,
            'host'     => $this->host,
            'overall'  => $overall,
            'grade'    => $this->grade($overall),
            'scores'   => $scores,
            'weights'  => self::WEIGHTS,
            'meta'     => $this->pageMeta(),
            'checks'   => $cats,
            'plan'     => $this->actionPlan($cats),
            'counts'   => $this->counts($cats),
            'vitals'   => $this->pageSpeed(),
        ];
    }

    /**
     * برنامهٔ اقدام — «اول کدام را درست کنم؟»
     *
     * 🔴 چرا سمتِ سرور و نه در جاوااسکریپت: ترتیب از **وزنِ همان چک** ساخته
     * می‌شود که فقط این‌جا تعریف شده. اگر مرورگر خودش مرتب کند، دو تعریف از
     * «مهم» پیدا می‌شود و روزی بی‌صدا از هم فاصله می‌گیرند — همان تلهٔ ثبت‌شدهٔ
     * فیلترِ کاتالوگِ ابری.
     *
     * ⚠️ ردیفِ `pass` هرگز وارد نمی‌شود: فهرستِ کارها باید فقط کار داشته باشد.
     */
    private function actionPlan(array $cats): array
    {
        $items = [];
        foreach ($cats as $cat => $checks) {
            foreach ($checks as $c) {
                $sev = self::SEVERITY[$c['status']] ?? 0.0;
                if ($sev <= 0) {
                    continue;
                }
                $items[] = [
                    'key'      => $c['key'],
                    'cat'      => $cat,
                    'status'   => $c['status'],
                    'weight'   => $c['weight'],
                    'priority' => $sev * $c['weight'],
                ];
            }
        }

        // مرتب‌سازیِ پایدار: اولویتِ برابر ⇒ ترتیبِ کشف (سئو پیش از بقیه)
        $i = 0;
        foreach ($items as &$it) {
            $it['_i'] = $i++;
        }
        unset($it);

        usort($items, fn ($a, $b) => ($b['priority'] <=> $a['priority']) ?: ($a['_i'] <=> $b['_i']));

        return array_map(
            fn ($x) => array_diff_key($x, ['_i' => 1]),
            array_slice($items, 0, self::PLAN_SIZE)
        );
    }

    /** شمارشِ کلیِ قبول/هشدار/خطا — برای نوارِ خلاصه، بی‌آنکه مرورگر دوباره بشمارد. */
    private function counts(array $cats): array
    {
        $n = ['pass' => 0, 'warn' => 0, 'fail' => 0];
        foreach ($cats as $checks) {
            foreach ($checks as $c) {
                if (isset($n[$c['status']])) {
                    $n[$c['status']]++;
                }
            }
        }

        return $n;
    }

    /* ---------------------------------------------------------------- */

    private function normalize(string $in): string
    {
        $in = trim($in);
        $in = preg_replace('~\s+~', '', $in);
        if ($in === '') {
            return '';
        }
        if (! preg_match('~^https?://~i', $in)) {
            $in = 'https://'.$in;
        }
        $host = parse_url($in, PHP_URL_HOST);
        if (! $host || ! preg_match('~^([a-z0-9\x{0600}-\x{06FF}-]+\.)+[a-z\x{0600}-\x{06FF}]{2,}$~iu', $host)) {
            return '';
        }

        return $in;
    }

    /**
     * فقط هدر را می‌گیرد و در صورت ریدایرکت، مقصد بعدی را برمی‌گرداند.
     *
     * ⚠️ `$timeout` برای **کاوشِ جانبی** است، نه مسیرِ اصلی. کاوشِ `www` روی
     * دامنه‌ای که اصلاً رکوردِ www ندارد، با مهلتِ پیش‌فرض تا ۸ ثانیه بی‌کار
     * منتظر می‌مانْد — و آن ۸ ثانیه مستقیم به زمانِ انتظارِ کاربر اضافه می‌شد،
     * برای یک چکِ کم‌اهمیت. مسیرِ اصلی عمداً مهلتِ سخاوتمندش را نگه می‌دارد.
     */
    private function peekRedirect(string $url, int $timeout = 12): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, SafeUrl::curlOptions() + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY         => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; ServerNetBot/1.0; +https://servernet.cloud/tools)',
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $code < 300 || $code > 399) {
            return null;
        }
        if (preg_match('~^\s*location:\s*(\S+)~im', (string) $raw, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function fetch(): bool
    {
        // ریدایرکت را خودمان دنبال می‌کنیم تا هر پرش دوباره اعتبارسنجی شود؛
        // با FOLLOWLOCATION یک دامنه‌ی عمومی می‌توانست به آدرس داخلی بپرد.
        $final = SafeUrl::resolveRedirects($this->url, fn (string $u) => $this->peekRedirect($u));
        if ($final === null) {
            return false;
        }
        $this->url = $final;
        $this->host = parse_url($final, PHP_URL_HOST) ?: $this->host;

        $ch = curl_init($this->url);
        curl_setopt_array($ch, SafeUrl::curlOptions() + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_ENCODING       => '',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; ServerNetBot/1.0; +https://servernet.cloud/tools)',
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            curl_close($ch);

            return false;
        }
        $this->info = curl_getinfo($ch);
        curl_close($ch);

        $headerSize = $this->info['header_size'] ?? 0;
        $rawHeaders = substr($raw, 0, $headerSize);
        $this->body = substr($raw, $headerSize);
        $this->url = $this->info['url'] ?? $this->url;
        $this->host = parse_url($this->url, PHP_URL_HOST) ?: $this->host;

        // آخرین بلوک هدر (بعد از ریدایرکت‌ها)
        $blocks = preg_split('/\r?\n\r?\n/', trim($rawHeaders));
        $last = end($blocks);
        foreach (explode("\n", $last) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $this->headers[strtolower(trim($k))] = trim($v);
            }
        }

        return ($this->info['http_code'] ?? 0) > 0 && $this->body !== '';
    }

    private function parseDom(): void
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $html = $this->body;
        if (! preg_match('~<meta[^>]+charset~i', $html)) {
            $html = '<?xml encoding="UTF-8">'.$html;
        }
        $dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        $this->xp = new DOMXPath($dom);
    }

    /* ---------------------------------------------------------------- */

    private function q(string $expr): array
    {
        $out = [];
        $nodes = $this->xp?->query($expr);
        if ($nodes) {
            foreach ($nodes as $n) {
                $out[] = $n;
            }
        }

        return $out;
    }

    private function text(string $expr): ?string
    {
        $n = $this->q($expr);

        return $n ? trim($n[0]->textContent) : null;
    }

    private function attr(string $expr): ?string
    {
        $n = $this->q($expr);

        return $n ? trim($n[0]->nodeValue) : null;
    }

    private function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /* ============ SEO ============ */

    private function seoChecks(): array
    {
        $c = [];
        $title = $this->text('//title');
        $tl = $title ? mb_strlen($title) : 0;
        $c[] = $this->check('title', $title ? ($tl >= 30 && $tl <= 65 ? 'pass' : 'warn') : 'fail', 5,
            ['value' => $title, 'len' => $tl]);

        $desc = $this->attr('//meta[@name="description"]/@content');
        $dl = $desc ? mb_strlen($desc) : 0;
        $c[] = $this->check('description', $desc ? ($dl >= 70 && $dl <= 160 ? 'pass' : 'warn') : 'fail', 5,
            ['value' => $desc, 'len' => $dl]);

        $h1 = $this->q('//h1');
        $c[] = $this->check('h1', count($h1) === 1 ? 'pass' : (count($h1) === 0 ? 'fail' : 'warn'), 4,
            ['count' => count($h1), 'value' => count($h1) ? trim($h1[0]->textContent) : null]);

        $headings = count($this->q('//h1|//h2|//h3|//h4|//h5|//h6'));
        $c[] = $this->check('headings', $headings >= 3 ? 'pass' : 'warn', 2, ['count' => $headings]);

        $imgs = $this->q('//img');
        $noAlt = 0;
        foreach ($imgs as $img) {
            if (trim($img->getAttribute('alt')) === '') {
                $noAlt++;
            }
        }
        $c[] = $this->check('img_alt', count($imgs) === 0 ? 'warn' : ($noAlt === 0 ? 'pass' : ($noAlt / max(1, count($imgs)) < .2 ? 'warn' : 'fail')), 3,
            ['total' => count($imgs), 'missing' => $noAlt]);

        $c[] = $this->check('canonical', $this->attr('//link[@rel="canonical"]/@href') ? 'pass' : 'warn', 2,
            ['value' => $this->attr('//link[@rel="canonical"]/@href')]);

        $og = count($this->q('//meta[starts-with(@property,"og:")]'));
        $c[] = $this->check('open_graph', $og >= 3 ? 'pass' : ($og > 0 ? 'warn' : 'fail'), 3, ['count' => $og]);

        $tw = count($this->q('//meta[starts-with(@name,"twitter:")]'));
        $c[] = $this->check('twitter_card', $tw >= 2 ? 'pass' : 'warn', 1, ['count' => $tw]);

        $ld = count($this->q('//script[@type="application/ld+json"]'));
        $c[] = $this->check('structured_data', $ld > 0 ? 'pass' : 'warn', 3, ['count' => $ld]);

        $robotsMeta = $this->attr('//meta[@name="robots"]/@content');
        $blocked = $robotsMeta && stripos($robotsMeta, 'noindex') !== false;
        $c[] = $this->check('robots_meta', $blocked ? 'fail' : 'pass', 3, ['value' => $robotsMeta, 'indexable' => ! $blocked]);

        $c[] = $this->check('robots_txt', $this->remoteExists('/robots.txt') ? 'pass' : 'warn', 2, []);
        $c[] = $this->check('sitemap', $this->remoteExists('/sitemap.xml') ? 'pass' : 'warn', 3, []);

        $lang = $this->attr('//html/@lang');
        $c[] = $this->check('lang', $lang ? 'pass' : 'warn', 2, ['value' => $lang]);

        $links = count($this->q('//a[@href]'));
        $c[] = $this->check('links', $links >= 5 ? 'pass' : 'warn', 1, ['count' => $links]);

        return $c;
    }

    /* ============ Performance ============ */

    private function perfChecks(): array
    {
        $c = [];
        $ttfb = ($this->info['starttransfer_time'] ?? 0) * 1000;
        $c[] = $this->check('ttfb', $ttfb < 600 ? 'pass' : ($ttfb < 1500 ? 'warn' : 'fail'), 5,
            ['ms' => (int) round($ttfb)]);

        $total = ($this->info['total_time'] ?? 0) * 1000;
        $c[] = $this->check('load_time', $total < 2000 ? 'pass' : ($total < 4000 ? 'warn' : 'fail'), 4,
            ['ms' => (int) round($total)]);

        $size = strlen($this->body);
        $c[] = $this->check('page_size', $size < 500000 ? 'pass' : ($size < 1500000 ? 'warn' : 'fail'), 3,
            ['kb' => (int) round($size / 1024)]);

        $enc = $this->header('content-encoding');
        $c[] = $this->check('compression', $enc && preg_match('~gzip|br|deflate~i', $enc) ? 'pass' : 'fail', 4,
            ['value' => $enc ?: '—']);

        $scripts = count($this->q('//script[@src]'));
        $c[] = $this->check('js_requests', $scripts <= 15 ? 'pass' : ($scripts <= 30 ? 'warn' : 'fail'), 2, ['count' => $scripts]);

        $styles = count($this->q('//link[@rel="stylesheet"]'));
        $c[] = $this->check('css_requests', $styles <= 6 ? 'pass' : ($styles <= 12 ? 'warn' : 'fail'), 2, ['count' => $styles]);

        $imgs = count($this->q('//img'));
        $c[] = $this->check('image_count', $imgs <= 40 ? 'pass' : 'warn', 1, ['count' => $imgs]);

        $cache = $this->header('cache-control');
        $c[] = $this->check('caching', $cache && preg_match('~max-age=[1-9]~', $cache) ? 'pass' : 'warn', 3,
            ['value' => $cache ?: '—']);

        $http = $this->info['http_version'] ?? 0;
        $httpLabel = ['1.0', '1.1', '2', '3'][max(0, min(3, (int) $http - 1))] ?? '1.1';
        $c[] = $this->check('http_version', $http >= 3 ? 'pass' : 'warn', 2, ['value' => 'HTTP/'.$httpLabel]);

        $inlineStyle = substr_count($this->body, 'style=');
        $c[] = $this->check('inline_styles', $inlineStyle <= 20 ? 'pass' : 'warn', 1, ['count' => $inlineStyle]);

        return $c;
    }

    /* ============ Security ============ */

    private function securityChecks(): array
    {
        $c = [];
        $https = str_starts_with($this->url, 'https://');
        $c[] = $this->check('https', $https ? 'pass' : 'fail', 6, ['value' => $https]);

        $c[] = $this->check('hsts', $this->header('strict-transport-security') ? 'pass' : 'fail', 4,
            ['value' => $this->header('strict-transport-security') ?: '—']);
        $c[] = $this->check('x_content_type', strcasecmp($this->header('x-content-type-options') ?? '', 'nosniff') === 0 ? 'pass' : 'warn', 2,
            ['value' => $this->header('x-content-type-options') ?: '—']);
        $c[] = $this->check('x_frame', $this->header('x-frame-options') || $this->hasCspDirective('frame-ancestors') ? 'pass' : 'warn', 3,
            ['value' => $this->header('x-frame-options') ?: ($this->hasCspDirective('frame-ancestors') ? 'CSP frame-ancestors' : '—')]);
        $c[] = $this->check('csp', $this->header('content-security-policy') ? 'pass' : 'warn', 4,
            ['value' => $this->header('content-security-policy') ? '✓' : '—']);
        $c[] = $this->check('referrer_policy', $this->header('referrer-policy') ? 'pass' : 'warn', 1,
            ['value' => $this->header('referrer-policy') ?: '—']);
        $c[] = $this->check('permissions_policy', $this->header('permissions-policy') ? 'pass' : 'warn', 1,
            ['value' => $this->header('permissions-policy') ? '✓' : '—']);

        $server = $this->header('server');
        $leaks = $server && preg_match('~\d~', $server);
        $c[] = $this->check('server_disclosure', $leaks ? 'warn' : 'pass', 2, ['value' => $server ?: '—']);
        $poweredBy = $this->header('x-powered-by');
        $c[] = $this->check('powered_by', $poweredBy ? 'warn' : 'pass', 2, ['value' => $poweredBy ?: '—']);

        // مخلوط بودن محتوا: منابع http روی صفحه https
        $mixed = 0;
        if ($https) {
            $mixed = preg_match_all('~(?:src|href)=["\']http://~i', $this->body);
        }
        $c[] = $this->check('mixed_content', $mixed === 0 ? 'pass' : 'fail', 4, ['count' => $mixed]);

        return $c;
    }

    /* ============ Accessibility ============ */

    /**
     * دسترس‌پذیری — بُعدی که تا امروز اصلاً سنجیده نمی‌شد.
     *
     * برای طراحِ سایت این مهم‌ترین بخشِ گزارش است، و برای صاحبِ کسب‌وکار یک
     * ریسکِ حقوقی و یک بخشِ از‌دست‌رفتهٔ بازار. همه‌چیز از **خودِ HTML** خوانده
     * می‌شود؛ چیزی که فقط با رندرِ واقعی معلوم می‌شود (کنتراستِ محاسبه‌شده،
     * فوکوسِ صفحه‌کلید) عمداً ادعا نمی‌شود — گزارشِ حدسی از نبودِ گزارش بدتر است.
     */
    private function accessibilityChecks(): array
    {
        $c = [];

        $lang = $this->attr('//html/@lang');
        $c[] = $this->check('a11y_lang', $lang ? 'pass' : 'fail', 5, ['value' => $lang ?: '—']);

        // ورودی‌های بی‌برچسب — صفحه‌خوان اسمی برای گفتن ندارد
        $inputs = $this->q('//input[not(@type="hidden")]|//select|//textarea');
        $unlabelled = 0;
        foreach ($inputs as $el) {
            $id = trim($el->getAttribute('id'));
            $has = trim($el->getAttribute('aria-label')) !== ''
                || trim($el->getAttribute('aria-labelledby')) !== ''
                || trim($el->getAttribute('title')) !== ''
                || ($id !== '' && count($this->q('//label[@for="'.addslashes($id).'"]')) > 0)
                // <label><input …></label> — برچسبِ دربرگیرنده
                || ($el->parentNode && strtolower($el->parentNode->nodeName) === 'label');
            // دکمهٔ submit متنِ خودش را دارد
            if (! $has && in_array(strtolower($el->getAttribute('type')), ['submit', 'button', 'image'], true)) {
                $has = trim($el->getAttribute('value')) !== '' || trim($el->getAttribute('alt')) !== '';
            }
            if (! $has) {
                $unlabelled++;
            }
        }
        $c[] = $this->check('a11y_labels',
            count($inputs) === 0 ? 'pass' : ($unlabelled === 0 ? 'pass' : ($unlabelled <= 2 ? 'warn' : 'fail')),
            5, ['total' => count($inputs), 'missing' => $unlabelled]);

        // دکمه/لینکِ بی‌نام — «دکمه» تنها چیزی است که صفحه‌خوان می‌گوید
        $nameless = 0;
        foreach ($this->q('//a[@href]|//button') as $el) {
            $txt = trim(preg_replace('~\s+~u', ' ', $el->textContent));
            if ($txt !== '') {
                continue;
            }
            $ok = trim($el->getAttribute('aria-label')) !== ''
                || trim($el->getAttribute('title')) !== ''
                || trim($el->getAttribute('aria-labelledby')) !== '';
            if (! $ok) {
                // آیکنِ داخلش ممکن است alt یا title داشته باشد
                foreach ($this->qIn($el, './/img[@alt]|.//*[@aria-label]|.//title') as $inner) {
                    if (trim($inner->textContent) !== '' || trim($inner->nodeValue ?? '') !== '') {
                        $ok = true;
                        break;
                    }
                }
            }
            if (! $ok) {
                $nameless++;
            }
        }
        $c[] = $this->check('a11y_names', $nameless === 0 ? 'pass' : ($nameless <= 3 ? 'warn' : 'fail'), 4,
            ['count' => $nameless]);

        // ترتیبِ تیترها — پرش از H2 به H4 ساختار را برای صفحه‌خوان می‌شکند
        $levels = [];
        foreach ($this->q('//h1|//h2|//h3|//h4|//h5|//h6') as $h) {
            $levels[] = (int) substr(strtolower($h->nodeName), 1);
        }
        $skips = 0;
        for ($i = 1; $i < count($levels); $i++) {
            if ($levels[$i] - $levels[$i - 1] > 1) {
                $skips++;
            }
        }
        $c[] = $this->check('a11y_heading_order', $skips === 0 ? 'pass' : ($skips <= 2 ? 'warn' : 'fail'), 3,
            ['count' => $skips]);

        // نشانه‌های ساختاری — بی‌اینها صفحه‌خوان راهی برای پرش ندارد
        $landmarks = count($this->q('//main|//nav|//header|//footer|//*[@role="main"]|//*[@role="navigation"]'));
        $c[] = $this->check('a11y_landmarks', $landmarks >= 3 ? 'pass' : ($landmarks > 0 ? 'warn' : 'fail'), 3,
            ['count' => $landmarks]);

        // tabindex مثبت ترتیبِ طبیعیِ فوکوس را به‌هم می‌ریزد
        $positive = 0;
        foreach ($this->q('//*[@tabindex]') as $el) {
            if ((int) $el->getAttribute('tabindex') > 0) {
                $positive++;
            }
        }
        $c[] = $this->check('a11y_tabindex', $positive === 0 ? 'pass' : 'warn', 2, ['count' => $positive]);

        // user-scalable=no بزرگ‌نمایی را روی موبایل قفل می‌کند
        $vp = (string) $this->attr('//meta[@name="viewport"]/@content');
        $locked = preg_match('~user-scalable\s*=\s*(no|0)~i', $vp)
            || preg_match('~maximum-scale\s*=\s*1(\.0)?\b~i', $vp);
        $c[] = $this->check('a11y_zoom', $locked ? 'fail' : 'pass', 3, ['value' => $vp ?: '—']);

        return $c;
    }

    /* ============ Network / infrastructure ============ */

    /**
     * شبکه و زیرساخت — برای مدیرِ شبکه و انفورماتیک، نه برای سئوکار.
     *
     * ⚠️ همه‌چیز از `NetworkTools` می‌آید و **دوباره پیاده نشده**: گواهی و DNS
     * منطقِ ظریفی دارند (SNI، زنجیره، DoH) که دو پیاده‌سازی‌شان روزی از هم
     * فاصله می‌گیرد و آن‌وقت `/lookup` و این گزارش دو حرفِ متفاوت می‌زنند.
     *
     * ⚠️ هر تماسِ بیرونی در `try` است: قطعیِ یک رزولور نباید کلِ گزارش را
     * بخواباند. چکِ نادانسته `warn` می‌گیرد، نه `fail` — «نمی‌دانیم» خبرِ بد نیست.
     */
    private function networkChecks(): array
    {
        $c = [];

        // ── گواهی TLS ─────────────────────────────────────────────────
        $ssl = $this->safely(fn () => $this->net->ssl($this->host));
        if (is_array($ssl) && ($ssl['ok'] ?? false)) {
            $days = $ssl['days_left'];
            $c[] = $this->check('cert_expiry',
                $days === null ? 'warn' : ($days > 21 ? 'pass' : ($days > 0 ? 'warn' : 'fail')),
                6, ['days' => $days, 'value' => $ssl['valid_to'] ?? null]);
            $c[] = $this->check('cert_issuer', 'pass', 1, ['value' => $ssl['issuer'] ?? '—']);

            // نامِ روی گواهی باید با میزبان بخواند، وگرنه مرورگر اخطار می‌دهد
            $names = array_merge([$ssl['subject'] ?? ''], $ssl['san'] ?? []);
            $c[] = $this->check('cert_hostname', $this->certCovers($names, $this->host) ? 'pass' : 'fail', 5,
                ['value' => $ssl['subject'] ?? '—']);
        } else {
            $c[] = $this->check('cert_expiry', 'fail', 6, ['days' => null, 'value' => null]);
        }

        /*
         * ── IPv6 و SPF: **یک** تماس، نه دو تا ────────────────────────
         *
         * ⚠️ `allDns()` هر هشت نوعِ رکورد را با `curl_multi` **موازی** می‌گیرد،
         * پس هزینه‌اش تقریباً یک رفت‌وبرگشت است. دو فراخوانِ جداگانهٔ `dns()`
         * دو رفت‌وبرگشتِ پشتِ‌سرِ‌هم بود و روی گزارشی که کاربر منتظرش نشسته،
         * همان چند ثانیه دیده می‌شود.
         */
        $all = $this->safely(fn () => $this->net->allDns($this->host));
        $byType = [];
        foreach (($all['groups'] ?? []) as $g) {
            $byType[$g['type']] = $g;
        }

        $v6 = (int) ($byType['AAAA']['count'] ?? 0);
        $c[] = $this->check('ipv6', $v6 > 0 ? 'pass' : 'warn', 3, ['count' => $v6]);

        // ── ایمیل: SPF و DMARC ────────────────────────────────────────
        $spf = false;
        foreach (($byType['TXT']['records'] ?? []) as $r) {
            if (stripos(is_array($r) ? ($r['value'] ?? '') : (string) $r, 'v=spf1') !== false) {
                $spf = true;
                break;
            }
        }
        $c[] = $this->check('spf', $spf ? 'pass' : 'warn', 3, ['value' => $spf ? '✓' : '—']);

        $dmarcRes = $this->safely(fn () => $this->net->dns('_dmarc.'.$this->host, 'TXT'));
        $dmarc = false;
        foreach (($dmarcRes['records'] ?? []) as $r) {
            if (stripos(is_array($r) ? ($r['value'] ?? '') : (string) $r, 'v=dmarc1') !== false) {
                $dmarc = true;
                break;
            }
        }
        $c[] = $this->check('dmarc', $dmarc ? 'pass' : 'warn', 3, ['value' => $dmarc ? '✓' : '—']);

        // ── زنجیرهٔ ریدایرکت ──────────────────────────────────────────
        // هر پرش یک رفت‌وبرگشتِ کامل است؛ روی موبایلِ ایران هر کدام صدها میلی‌ثانیه.
        $hops = max(0, (int) ($this->info['redirect_count'] ?? 0));
        $c[] = $this->check('redirects', $hops <= 1 ? 'pass' : ($hops <= 2 ? 'warn' : 'fail'), 3,
            ['count' => $hops]);

        // ── www و بدونِ www به یک نسخه برسند ──────────────────────────
        $c[] = $this->check('www_canonical', $this->wwwUnified(), 3, []);

        return $c;
    }

    /** نامِ روی گواهی میزبان را پوشش می‌دهد؟ (با پشتیبانی از wildcard) */
    private function certCovers(array $names, string $host): bool
    {
        $host = strtolower($host);
        foreach ($names as $n) {
            $n = strtolower(trim((string) $n));
            if ($n === '') {
                continue;
            }
            if ($n === $host) {
                return true;
            }
            if (str_starts_with($n, '*.') && str_ends_with($host, substr($n, 1))
                && substr_count($host, '.') === substr_count($n, '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * `www` و apex باید به **یک** نسخه برسند.
     *
     * اگر هر دو مستقل ۲۰۰ بدهند، گوگل دو سایتِ کامل می‌بیند و اعتبارِ لینک‌ها
     * بینشان نصف می‌شود — و همان چیزی است که در همین پروژه با `ConsoleHost`
     * حل شد.
     */
    private function wwwUnified(): string
    {
        $bare = preg_replace('~^www\.~i', '', $this->host);
        $other = str_starts_with(strtolower($this->host), 'www.') ? $bare : 'www.'.$bare;
        $probe = 'https://'.$other.'/';

        if (! SafeUrl::allowed($probe)) {
            return 'warn';
        }

        $redirect = $this->safely(fn () => $this->peekRedirect($probe, 5));
        if ($redirect === null) {
            // یا ۲۰۰ داد (دو نسخهٔ مستقل) یا اصلاً بالا نیامد. تفکیکشان یک
            // درخواستِ دیگر می‌خواهد و ارزشش را ندارد؛ هشدار کافی است.
            return 'warn';
        }

        return str_contains(strtolower($redirect), strtolower($bare)) ? 'pass' : 'warn';
    }

    /** تماسِ بیرونی که حق ندارد کلِ گزارش را بخواباند. */
    private function safely(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable) {
            return null;
        }
    }

    /** XPath نسبت به یک گره — برای گشتن **داخلِ** یک دکمه یا لینک. */
    private function qIn(\DOMNode $node, string $expr): array
    {
        $out = [];
        $nodes = $this->xp?->query($expr, $node);
        if ($nodes) {
            foreach ($nodes as $n) {
                $out[] = $n;
            }
        }

        return $out;
    }

    /* ============ Mobile / UX ============ */

    private function mobileChecks(): array
    {
        $c = [];
        $vp = $this->attr('//meta[@name="viewport"]/@content');
        $c[] = $this->check('viewport', $vp && stripos($vp, 'width=device-width') !== false ? 'pass' : 'fail', 6,
            ['value' => $vp]);

        $charset = $this->q('//meta[@charset]') || $this->q('//meta[contains(@content,"charset")]');
        $c[] = $this->check('charset', $charset ? 'pass' : 'warn', 2, []);

        // فونت پایه readable
        $tiny = preg_match('~font-size:\s*(?:[0-9]|1[0-1])px~i', $this->body);
        $c[] = $this->check('font_size', $tiny ? 'warn' : 'pass', 2, []);

        /*
         * 🔴 چکِ `tap_targets` حذف شد چون **همیشه `pass` برمی‌گرداند**.
         *
         * اندازهٔ واقعیِ ناحیهٔ لمسی فقط بعد از رندر و با CSS معلوم می‌شود و ما
         * صفحه را رندر نمی‌کنیم. یک `pass`ِ ثابت بدتر از نبودِ چک است: هم به
         * کاربر می‌گوید «این را بررسی کردیم و مشکلی نیست» در حالی که هیچ
         * بررسی‌ای نشده، هم امتیاز را به‌طورِ تصنعی بالا می‌بَرد.
         *
         * جایش دو چکِ **واقعی** آمد که از خودِ HTML خواندنی‌اند: عرضِ ثابتی که
         * روی موبایل سرریز می‌کند، و lazy-loadingِ تصاویر.
         */
        $wide = preg_match_all('~width:\s*(\d{4,})px~i', $this->body);
        $c[] = $this->check('fixed_width', $wide === 0 ? 'pass' : 'warn', 2, ['count' => $wide]);

        $imgs = $this->q('//img');
        $lazy = 0;
        foreach ($imgs as $img) {
            if (strtolower(trim($img->getAttribute('loading'))) === 'lazy') {
                $lazy++;
            }
        }
        $c[] = $this->check('lazy_images',
            count($imgs) <= 3 ? 'pass' : ($lazy >= count($imgs) * 0.5 ? 'pass' : 'warn'),
            2, ['total' => count($imgs), 'count' => $lazy]);

        $appleTouch = $this->attr('//link[@rel="apple-touch-icon"]/@href');
        $c[] = $this->check('apple_touch', $appleTouch ? 'pass' : 'warn', 1, []);

        $themeColor = $this->attr('//meta[@name="theme-color"]/@content');
        $c[] = $this->check('theme_color', $themeColor ? 'pass' : 'warn', 1, ['value' => $themeColor]);

        return $c;
    }

    /* ============ Best practices ============ */

    private function bestPracticeChecks(): array
    {
        $c = [];
        $c[] = $this->check('doctype', preg_match('~^\s*<!doctype html>~i', $this->body) ? 'pass' : 'warn', 2, []);
        $c[] = $this->check('favicon', $this->attr('//link[contains(@rel,"icon")]/@href') ? 'pass' : 'warn', 2,
            ['value' => $this->attr('//link[contains(@rel,"icon")]/@href')]);

        $deprecated = count($this->q('//center|//font|//marquee|//blink'));
        $c[] = $this->check('deprecated_tags', $deprecated === 0 ? 'pass' : 'warn', 1, ['count' => $deprecated]);

        $console = substr_count(strtolower($this->body), 'console.log');
        $c[] = $this->check('console_logs', $console === 0 ? 'pass' : 'warn', 1, ['count' => $console]);

        $c[] = $this->check('hreflang', count($this->q('//link[@rel="alternate"][@hreflang]')) > 0 ? 'pass' : 'warn', 1,
            ['count' => count($this->q('//link[@rel="alternate"][@hreflang]'))]);

        return $c;
    }

    /* ---------------------------------------------------------------- */

    private function check(string $key, string $status, int $weight, array $data): array
    {
        return ['key' => $key, 'status' => $status, 'weight' => $weight] + $data;
    }

    private function score(array $checks): int
    {
        $max = 0;
        $got = 0;
        foreach ($checks as $ch) {
            $max += $ch['weight'];
            $got += $ch['weight'] * ($ch['status'] === 'pass' ? 1 : ($ch['status'] === 'warn' ? 0.5 : 0));
        }

        return $max ? (int) round($got / $max * 100) : 0;
    }

    private function grade(int $s): string
    {
        return $s >= 90 ? 'A' : ($s >= 75 ? 'B' : ($s >= 60 ? 'C' : ($s >= 40 ? 'D' : 'F')));
    }

    private function pageMeta(): array
    {
        return [
            'title'    => $this->text('//title'),
            'desc'     => $this->attr('//meta[@name="description"]/@content'),
            'code'     => $this->info['http_code'] ?? 0,
            'ip'       => $this->info['primary_ip'] ?? null,
            'size_kb'  => (int) round(strlen($this->body) / 1024),
            'load_ms'  => (int) round(($this->info['total_time'] ?? 0) * 1000),
            'server'   => $this->header('server'),
            'og_image' => $this->attr('//meta[@property="og:image"]/@content'),
        ];
    }

    private function hasCspDirective(string $d): bool
    {
        $csp = $this->header('content-security-policy');

        return $csp && stripos($csp, $d) !== false;
    }

    private function remoteExists(string $path): bool
    {
        $u = 'https://'.$this->host.$path;
        $ch = curl_init($u);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'ServerNetBot/1.0',
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $code >= 200 && $code < 400;
    }

    /** Core Web Vitals واقعی از Google PageSpeed اگر کلید موجود باشد */
    private function pageSpeed(): ?array
    {
        $key = config('services.pagespeed.key');
        if (! $key) {
            return null;
        }
        $api = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?strategy=mobile&category=performance'
            .'&url='.urlencode($this->url).'&key='.$key;
        $ch = curl_init($api);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => false]);
        $raw = curl_exec($ch);
        curl_close($ch);
        if ($raw === false) {
            return null;
        }
        $d = json_decode($raw, true);
        $metrics = $d['lighthouseResult']['audits'] ?? null;
        if (! $metrics) {
            return null;
        }

        return [
            'perf' => (int) round(($d['lighthouseResult']['categories']['performance']['score'] ?? 0) * 100),
            'lcp'  => $metrics['largest-contentful-paint']['displayValue'] ?? null,
            'cls'  => $metrics['cumulative-layout-shift']['displayValue'] ?? null,
            'fcp'  => $metrics['first-contentful-paint']['displayValue'] ?? null,
            'tbt'  => $metrics['total-blocking-time']['displayValue'] ?? null,
            'si'   => $metrics['speed-index']['displayValue'] ?? null,
        ];
    }
}
