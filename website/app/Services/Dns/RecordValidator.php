<?php

namespace App\Services\Dns;

use App\Models\CdnRecord;

/**
 * اعتبارسنجی و نرمال‌سازیِ یک رکوردِ DNS، به ازای هر نوع.
 *
 * ═══ چرا یک کلاسِ جدا و نه قاعده‌های `validate()` در کنترلر ═══
 *
 * سه مسیر رکورد می‌سازند: فرمِ پنل، واردکردنِ رکوردهای فعلی، و (بعداً) API.
 * اگر قاعده‌ها در کنترلر باشند، واردکننده بی‌اعتبارسنجی می‌نویسد — و PowerDNS
 * کلِ PATCHِ zone را با ۴۲۲ رد می‌کند، یعنی **یک** رکوردِ خراب همهٔ تغییراتِ
 * بعدیِ همان zone را هم متوقف می‌کند. خطا باید پیش از دیتابیس گرفته شود.
 *
 * ═══ قالبِ ذخیره ═══
 *
 *   MX   → content = میزبانِ مقصد          · prio = اولویت
 *   SRV  → content = "weight port target"  · prio = اولویت
 *   CAA  → content = 'flags tag "value"'
 *   TXT  → content = متنِ خام، بی‌گیومه (تکه‌کردن کارِ PowerDnsClient است)
 *   بقیه → content = مقدار، بی‌نقطهٔ پایانی
 *
 * خروجیِ `validate()` همیشه `['ok'=>bool, 'errors'=>[field=>پیام], 'data'=>[...]]`.
 */
class RecordValidator
{
    /** @return array{ok:bool, errors:array<string,string>, data:array<string,mixed>} */
    public function validate(string $zone, array $in): array
    {
        $errors = [];
        $type = strtoupper(trim((string) ($in['type'] ?? '')));

        if (! in_array($type, (array) config('edge.record_types', []), true)) {
            return $this->fail(['type' => 'نوعِ رکورد پشتیبانی نمی‌شود.']);
        }

        $name = CdnRecord::fqdn((string) ($in['name'] ?? '@'), $zone);

        if (($e = $this->nameError($name, $zone, $type)) !== null) {
            $errors['name'] = $e;
        }

        $ttl = (int) ($in['ttl'] ?? 0);
        if ($ttl === 0) {
            $ttl = (int) config('edge.default_ttl', 3600);
        }
        if ($ttl < 60 || $ttl > 86400) {
            $errors['ttl'] = 'TTL باید بین ۶۰ ثانیه و ۲۴ ساعت باشد.';
        }

        $prio = null;
        $content = '';

        switch ($type) {
            case 'A':
                $content = trim((string) ($in['content'] ?? ''));
                if (! filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $errors['content'] = 'یک آدرسِ IPv4 معتبر وارد کنید (مثل 185.10.20.30).';
                } elseif ($this->unroutable($content)) {
                    $errors['content'] = 'این آدرس در اینترنت قابلِ دسترسی نیست (آدرسِ خصوصی یا رزرو).';
                }
                break;

            case 'AAAA':
                $content = strtolower(trim((string) ($in['content'] ?? '')));
                if (! filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $errors['content'] = 'یک آدرسِ IPv6 معتبر وارد کنید.';
                }
                break;

            case 'CNAME':
                $content = $this->host((string) ($in['content'] ?? ''));
                if ($name === $zone) {
                    /*
                     * 🔴 CNAME روی apex ممنوعِ پروتکل است (RFC 1034): apex همیشه
                     * SOA و NS دارد و CNAME نمی‌تواند کنارِ هیچ رکوردِ دیگری
                     * بنشیند. PowerDNS قبولش نمی‌کند و اگر هم می‌کرد، ایمیل و
                     * تفویضِ کلِ دامنه می‌شکست.
                     */
                    $errors['name'] = 'رکوردِ CNAME روی خودِ دامنه (@) مجاز نیست. برای دامنهٔ اصلی از رکوردِ A استفاده کنید.';
                } elseif (! $this->validHost($content)) {
                    $errors['content'] = 'مقصد باید یک نامِ میزبانِ معتبر باشد (مثل example.net).';
                } elseif ($content === $name) {
                    $errors['content'] = 'رکورد نمی‌تواند به خودش اشاره کند.';
                }
                break;

            case 'MX':
                $content = $this->host((string) ($in['content'] ?? ''));
                $prio = $this->int($in['prio'] ?? 10, 0, 65535);
                if (filter_var($content, FILTER_VALIDATE_IP)) {
                    // رایج‌ترین خطای واقعی: IP به‌جای نامِ میزبان. RFC 5321 اجازه
                    // نمی‌دهد و سرورهای ایمیلِ فرستنده بی‌صدا ردش می‌کنند.
                    $errors['content'] = 'مقصدِ MX باید نامِ میزبان باشد، نه IP. اول یک رکوردِ A (مثلاً mail) بسازید.';
                } elseif (! $this->validHost($content)) {
                    $errors['content'] = 'نامِ میزبانِ سرورِ ایمیل معتبر نیست.';
                }
                if ($prio === null) {
                    $errors['prio'] = 'اولویت باید عددی بین ۰ و ۶۵۵۳۵ باشد.';
                }
                break;

            case 'TXT':
                $content = $this->txt((string) ($in['content'] ?? ''));
                $max = (int) config('edge.txt_max', 2048);
                if ($content === '') {
                    $errors['content'] = 'مقدارِ TXT خالی است.';
                } elseif (mb_strlen($content) > $max) {
                    $errors['content'] = 'مقدارِ TXT بیش از '.fa_num($max).' نویسه است.';
                } elseif (! mb_check_encoding($content, 'ASCII')) {
                    // DNS بایت است نه متن؛ فارسی در TXT به‌شکلِ escapeِ عددی
                    // منتشر می‌شود و هیچ سرویسی (SPF، DKIM، تأییدِ گوگل) نمی‌خواندش.
                    $errors['content'] = 'مقدارِ TXT فقط می‌تواند نویسه‌های لاتین داشته باشد.';
                }
                break;

            case 'NS':
                $content = $this->host((string) ($in['content'] ?? ''));
                if ($name === $zone) {
                    /*
                     * 🔴 NSِ apex مالِ ماست. اگر مشتری بتواند عوضش کند، zone به
                     * نیم‌سرورِ دیگری اشاره می‌کند در حالی که رجیستری هنوز به ما
                     * — حلقه‌ای که resolverها را سردرگم می‌کند و عیب‌یابی‌اش ساعت‌ها.
                     * NS فقط برای **واگذاریِ یک زیردامنه** مجاز است.
                     */
                    $errors['name'] = 'نیم‌سرورهای خودِ دامنه را سرورنت مدیریت می‌کند. رکوردِ NS فقط برای واگذاریِ یک زیردامنه است.';
                } elseif (! $this->validHost($content)) {
                    $errors['content'] = 'نامِ نیم‌سرور معتبر نیست.';
                }
                break;

            case 'SRV':
                $prio = $this->int($in['prio'] ?? null, 0, 65535);
                $weight = $this->int($in['weight'] ?? null, 0, 65535);
                $port = $this->int($in['port'] ?? null, 0, 65535);
                $target = $this->host((string) ($in['target'] ?? $in['content'] ?? ''));

                // «_sip._tcp» — بدونِ این دو برچسب، هیچ کلاینتی رکورد را نمی‌پرسد
                $first = explode('.', $name);
                if (count($first) < 3 || ! str_starts_with($first[0], '_') || ! in_array($first[1], ['_tcp', '_udp', '_tls'], true)) {
                    $errors['name'] = 'نامِ SRV باید به شکلِ ‎_service._tcp‎ یا ‎_service._udp‎ باشد (مثل ‎_sip._tcp‎).';
                }
                if ($prio === null) {
                    $errors['prio'] = 'اولویت باید عددی بین ۰ و ۶۵۵۳۵ باشد.';
                }
                if ($weight === null) {
                    $errors['weight'] = 'وزن باید عددی بین ۰ و ۶۵۵۳۵ باشد.';
                }
                if ($port === null) {
                    $errors['port'] = 'پورت باید عددی بین ۰ و ۶۵۵۳۵ باشد.';
                }
                if ($target !== '.' && ! $this->validHost($target)) {
                    $errors['target'] = 'مقصدِ SRV باید نامِ میزبان باشد.';
                }
                $content = ($weight ?? 0).' '.($port ?? 0).' '.$target;
                break;

            case 'CAA':
                $flags = $this->int($in['flags'] ?? 0, 0, 255);
                $tag = strtolower(trim((string) ($in['tag'] ?? 'issue')));
                $value = trim((string) ($in['value'] ?? $in['content'] ?? ''), " \t\"");

                if (! in_array($flags, [0, 128], true)) {
                    $errors['flags'] = 'پرچمِ CAA فقط ۰ یا ۱۲۸ است.';
                }
                if (! in_array($tag, ['issue', 'issuewild', 'iodef'], true)) {
                    $errors['tag'] = 'برچسبِ CAA باید issue، issuewild یا iodef باشد.';
                }
                if ($tag === 'iodef' && ! preg_match('~^(mailto:|https?://)\S+$~i', $value)) {
                    $errors['value'] = 'مقدارِ iodef باید mailto: یا یک آدرسِ http(s) باشد.';
                } elseif ($tag !== 'iodef' && $value !== ';' && ! preg_match('~^[a-z0-9.-]+(;.*)?$~i', $value)) {
                    $errors['value'] = 'نامِ صادرکنندهٔ گواهی معتبر نیست (مثل letsencrypt.org).';
                }
                $content = ($flags ?? 0).' '.$tag.' "'.$value.'"';
                break;
        }

        $comment = trim((string) ($in['comment'] ?? ''));

        if ($errors !== []) {
            return $this->fail($errors);
        }

        return [
            'ok'     => true,
            'errors' => [],
            'data'   => [
                'type'    => $type,
                'name'    => $name,
                'content' => $content,
                'ttl'     => $ttl,
                'prio'    => in_array($type, ['MX', 'SRV'], true) ? $prio : null,
                'comment' => $comment !== '' ? mb_substr($comment, 0, 120) : null,
            ],
        ];
    }

    /**
     * قاعده‌هایی که فقط با دیدنِ **بقیهٔ رکوردها** معنا دارند.
     *
     * @param  iterable<CdnRecord>  $existing  رکوردهای فعلیِ zone (به‌جز خودِ رکوردِ در حالِ ویرایش)
     */
    public function conflict(array $data, iterable $existing): ?string
    {
        foreach ($existing as $r) {
            if ($r->name !== $data['name']) {
                continue;
            }

            if ($r->type === $data['type'] && $this->same($r, $data)) {
                return 'همین رکورد از قبل وجود دارد.';
            }

            /*
             * 🔴 CNAME تنهاست. کنارِ هر نوعِ دیگری با همان نام، رفتارِ
             * resolverها تعریف‌نشده است و PowerDNS کلِ PATCH را رد می‌کند —
             * یعنی رکوردِ خراب جلوی **همهٔ** تغییراتِ بعدیِ zone را می‌گیرد.
             */
            if ($data['type'] === 'CNAME' || $r->type === 'CNAME') {
                return 'نامِ «'.$this->label($data['name']).'» '
                    .($data['type'] === 'CNAME' ? 'رکوردِ دیگری دارد' : 'یک CNAME دارد')
                    .'. CNAME نمی‌تواند با رکوردِ دیگری هم‌نام باشد.';
            }

            // PowerDNS هر (نام، نوع) را یک rrset با **یک** TTL می‌داند
            if ($r->type === $data['type'] && (int) $r->ttl !== (int) $data['ttl']) {
                return 'رکوردهای هم‌نام و هم‌نوع باید TTL یکسان داشته باشند (فعلی: '.fa_num($r->ttl).' ثانیه).';
            }
        }

        return null;
    }

    /* ───────────────────────── داخلی ───────────────────────── */

    private function nameError(string $name, string $zone, string $type): ?string
    {
        if ($name !== $zone && ! str_ends_with($name, '.'.$zone)) {
            return 'نام باید زیرِ همین دامنه باشد.';
        }

        if (strlen($name) > 253) {
            return 'نام بیش از حدِ مجاز طولانی است.';
        }

        $rel = $name === $zone ? '' : substr($name, 0, -strlen($zone) - 1);

        foreach ($rel === '' ? [] : explode('.', $rel) as $i => $label) {
            if ($label === '*' && $i === 0) {
                continue;   // wildcard فقط چپ‌ترین برچسب
            }
            if ($label === '' || strlen($label) > 63 || ! preg_match('~^[a-z0-9_]([a-z0-9_-]*[a-z0-9_])?$~', $label)) {
                return 'نامِ «'.$label.'» معتبر نیست: فقط حروفِ لاتین، عدد، خط تیره و زیرخط؛ حداکثر ۶۳ نویسه.';
            }
        }

        if ($type !== 'SRV' && $type !== 'TXT' && $rel !== '' && str_contains($rel, '_') && ! in_array($type, ['CNAME'], true)) {
            // زیرخط در نامِ میزبان مجاز نیست؛ فقط در برچسب‌های سرویسی (TXT/SRV و CNAMEِ DKIM)
            return 'زیرخط (_) فقط در نامِ رکوردهای TXT، SRV و CNAME مجاز است.';
        }

        return null;
    }

    private function same(CdnRecord $r, array $d): bool
    {
        return mb_strtolower(trim($r->content)) === mb_strtolower(trim($d['content']))
            && (int) $r->prio === (int) ($d['prio'] ?? 0);
    }

    private function label(string $fqdn): string
    {
        return $fqdn;
    }

    private function host(string $raw): string
    {
        $h = mb_strtolower(trim($raw));
        $h = preg_replace('~^https?://~', '', $h) ?? $h;

        return rtrim(explode('/', $h)[0], '.');
    }

    private function validHost(string $h): bool
    {
        return strlen($h) <= 253
            && (bool) preg_match('~^([a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$~', $h)
            && ! filter_var($h, FILTER_VALIDATE_IP);
    }

    /** گیومه‌های دورِ کلِ مقدار و بینِ تکه‌های چسبانده‌شده (کپی از پنلِ دیگر) برداشته می‌شود */
    private function txt(string $raw): string
    {
        $v = trim($raw);

        // «"v=DKIM1; k=rsa; " "p=MIIB..."» — قالبِ تکه‌تکه‌ای که پنل‌های دیگر نشان می‌دهند
        if (preg_match('~^"(.*)"$~s', $v)) {
            $v = preg_replace('~"\s*"~', '', $v) ?? $v;
            $v = trim($v, '"');
        }

        return str_replace(["\r", "\n"], '', $v);
    }

    private function int(mixed $v, int $min, int $max): ?int
    {
        if ($v === null || $v === '' || ! is_numeric($v) || (int) $v != $v) {
            return null;
        }

        $v = (int) $v;

        return $v >= $min && $v <= $max ? $v : null;
    }

    /**
     * آدرس‌هایی که از اینترنت نمی‌رسند. رکوردِ A به 192.168.x.x همیشه
     * اشتباهِ کاربر است (کپی از پنلِ مودم) و سایتِ منتشرشده هرگز باز نمی‌شود.
     */
    private function unroutable(string $ip): bool
    {
        return ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    private function fail(array $errors): array
    {
        return ['ok' => false, 'errors' => $errors, 'data' => []];
    }
}
