<?php

namespace App\Services\Dns;

use App\Models\CdnZone;
use App\Services\NetworkTools;

/**
 * «آیا مشتری واقعاً کار را انجام داده؟»
 *
 * دو حالت، دو سنجه:
 *  • `ns`    — نیم‌سرورهای دامنه باید مالِ ما باشند.
 *  • `cname` — رکوردِ TXT تأیید باید سرِ جایش باشد (مالکیت)، و در صورتِ
 *              وجود، CNAME هم به میزبانِ لبهٔ همین zone اشاره کند.
 *
 * ⚠️ همیشه از resolverِ عمومی (DoH) پرسیده می‌شود نه از PowerDNSِ خودمان.
 * پرسیدن از خودمان یعنی همیشه «بله» — سروری که ما پرش کرده‌ایم نمی‌تواند
 * ثابت کند دنیا هم آن را می‌بیند. همان الگوی «گاردی که همان فایلی را
 * می‌سنجد که خودش ساخته».
 */
class DelegationCheck
{
    public function __construct(private readonly NetworkTools $net = new NetworkTools) {}

    /**
     * @return array{ok:bool, reason:?string, found:array<int,string>}
     */
    public function check(CdnZone $zone): array
    {
        return $zone->isNsMode() ? $this->checkNs($zone) : $this->checkCname($zone);
    }

    private function checkNs(CdnZone $zone): array
    {
        $res = $this->net->dns($zone->domain, 'NS');

        if (($res['ok'] ?? false) !== true) {
            // 🔴 «نپرسیدیم» هرگز «رد شد» نیست. اگر قطعیِ resolver را شکستِ
            // تأیید بخوانیم، zoneِ کاملاً درست را «تأییدنشده» علامت می‌زنیم و
            // مشتری را دنبالِ نخودِ سیاه می‌فرستیم.
            return ['ok' => false, 'reason' => 'lookup_failed', 'found' => []];
        }

        $found = array_map(
            fn (array $r) => mb_strtolower(rtrim((string) ($r['data'] ?? ''), '.')),
            $res['records'] ?? []
        );

        $ours = array_map(fn ($n) => mb_strtolower(rtrim($n, '.')), $zone->nameservers());

        /*
         * ⚠️ سنجه «همه مالِ ماست» است، نه «یکی مالِ ماست».
         *
         * تفویضِ نصفه (دو نیم‌سرورِ ما + دو نیم‌سرورِ قدیمی) بدترین حالتِ ممکن
         * است: نیمی از پرس‌وجوها پاسخِ کهنه می‌گیرند و مشکل **متناوب** است.
         * پذیرفتنِ آن یعنی گفتنِ «همه‌چیز درست است» به مشتری‌ای که سایتش
         * یک‌درمیان بالا می‌آید.
         */
        $foreign = array_values(array_diff($found, $ours));

        if ($found === []) {
            return ['ok' => false, 'reason' => 'no_ns', 'found' => []];
        }

        if ($foreign !== []) {
            return ['ok' => false, 'reason' => 'partial', 'found' => $found];
        }

        return ['ok' => true, 'reason' => null, 'found' => $found];
    }

    private function checkCname(CdnZone $zone): array
    {
        $rec = $zone->verifyRecord();
        $res = $this->net->dns($rec['name'], 'TXT');

        if (($res['ok'] ?? false) !== true) {
            return ['ok' => false, 'reason' => 'lookup_failed', 'found' => []];
        }

        $found = array_map(fn (array $r) => trim((string) ($r['data'] ?? ''), '"'), $res['records'] ?? []);

        foreach ($found as $value) {
            if (hash_equals($rec['content'], trim($value))) {
                return ['ok' => true, 'reason' => null, 'found' => $found];
            }
        }

        return ['ok' => false, 'reason' => $found === [] ? 'no_txt' : 'mismatch', 'found' => $found];
    }

    /** پیامِ فارسیِ هر علت — یک جا، تا پنل و کرون یک حرف بزنند */
    public static function explain(?string $reason): string
    {
        return match ($reason) {
            null            => 'تأیید شد.',
            'lookup_failed' => 'استعلام DNS در این لحظه پاسخ نداد. این یعنی ما نتوانستیم بپرسیم، نه اینکه تنظیمات شما غلط است.',
            'no_ns'         => 'هنوز هیچ نیم‌سروری برای این دامنه دیده نمی‌شود.',
            'partial'       => 'تفویض ناقص است: بخشی از نیم‌سرورها هنوز قبلی‌اند. تا کامل نشود، سایت یک‌درمیان بالا می‌آید.',
            'no_txt'        => 'رکورد TXT تأیید پیدا نشد. ممکن است هنوز منتشر نشده باشد.',
            'mismatch'      => 'رکورد TXT هست ولی مقدارش با کدِ این دامنه نمی‌خوانَد.',
            default         => 'وضعیت تأیید مشخص نشد.',
        };
    }
}
