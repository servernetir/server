<?php

namespace App\Services\Growth;

final class GrowthClaims
{
    /** Business assertions requiring verification, not a semantic fact checker. */
    public function findings(string $body): array
    {
        $text = preg_replace('/[*_`]/u', '', strip_tags($body));
        $rules = [
            'price' => '/(?:[\p{N}][\p{N}.,٬٫ ]{0,22}\s*(?:تومان|ریال|دلار|یورو|لیر|IRT|IRR|USD|EUR|TRY)(?![\p{L}\p{N}_])|[$€₺]\s*\p{N})/iu',
            'traffic' => '/(?:ترافیک[^.\n]{0,60}نامحدود(?!\s*(?:نیست|ندارد))|unlimited\s+(?:traffic|bandwidth)|sınırsız\s+trafik)/iu',
            'sla' => '/(?:[9۹٩]{2}[.,٫][9۹٩]{1,4}\s*[%٪]|(?:uptime|SLA)[^.\n]{0,25}\p{N})/iu',
            'delivery' => '/(?:(?:کمتر از|در کمتر از|حداکثر)\s*\p{N}+\s*دقیقه|(?:within|under)\s+\p{N}+\s+minutes?|\p{N}+\s*dakika\s+içinde)/iu',
            'coverage' => '/(?:بیش از\s*\p{N}+\s*کشور|\p{N}+\s*(?:countries|ülke))/iu',
        ];
        $found = [];
        foreach ($rules as $code => $pattern) {
            if (preg_match($pattern, $text)) {
                $found[] = $code;
            }
        }

        return $found;
    }
}
