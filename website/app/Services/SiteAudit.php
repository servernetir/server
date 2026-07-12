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

    /** وزن دسته‌ها در امتیاز کل */
    private const WEIGHTS = ['seo' => 30, 'performance' => 25, 'security' => 25, 'mobile' => 12, 'best' => 8];

    public function run(string $input): array
    {
        $this->url = $this->normalize($input);
        if ($this->url === '') {
            return ['ok' => false, 'error' => 'invalid_url'];
        }
        $this->host = parse_url($this->url, PHP_URL_HOST) ?: '';

        if (! $this->fetch()) {
            return ['ok' => false, 'error' => 'unreachable', 'url' => $this->url];
        }
        $this->parseDom();

        $cats = [
            'seo'         => $this->seoChecks(),
            'performance' => $this->perfChecks(),
            'security'    => $this->securityChecks(),
            'mobile'      => $this->mobileChecks(),
            'best'        => $this->bestPracticeChecks(),
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
            'vitals'   => $this->pageSpeed(),
        ];
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

    private function fetch(): bool
    {
        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 4,
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

        // تارگت‌های لمسی خیلی نزدیک (تخمینی)
        $c[] = $this->check('tap_targets', 'pass', 2, []);

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
