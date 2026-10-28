<?php

namespace App\Services\Hermes;

/** Fixed read-only reporting. Ticket prose is neither an execution plan nor an address source. */
final class HermesTechnicalFollowup
{
    public static function continues(string $department, array $thread, ?bool $priorSupport = null): bool
    {
        if ($department !== 'technical' || $thread === []) { return false; }
        $latest = $thread[array_key_last($thread)];
        if (($latest['role'] ?? '') !== 'customer') { return false; }
        $prior = array_slice($thread, 0, -1);
        if (! ($priorSupport ?? in_array('support', array_column($prior, 'role'), true))) { return false; }
        $text = mb_strtolower(str_replace(['ي', 'ك', '‌'], ['ی', 'ک', ' '], (string) ($latest['body'] ?? '')));
        $text = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', ' ', $text) ?? '';
        $pendingTest = preg_match('/هنوز.{0,30}(?:تست|آزمایش).{0,15}(?:نکرد|نداد)|haven.t tested|not yet tested|henüz test etmed/iu', $text) === 1;
        $failure = preg_match('/حل نشد|حل نشده|رفع نشد|رفع نشده|برطرف نشد|برطرف نشده|درست نشد|درست نشده|کار نمی|وصل نمی|نمی رس|نمی آ|not (?:fixed|resolved|working)|did(?:n.t| not) (?:work|help)|çözülmedi|düzelmedi|çalışmıyor/iu', $text) === 1;
        $resolved = preg_match('/حل شد|رفع شد|برطرف شد|درست شد|وصل شد|کار می کند|working now|(?:now )?resolved|(?:now )?fixed|çözüldü|düzeldi/iu', $text) === 1;
        if ($pendingTest && ! $failure) { return false; }
        if ($resolved && ! $failure) { return false; }
        return $failure || preg_match('/هنوز|همچنان|کماکان|دوباره|بازم|باز هم|تغییری نکرد|فرقی نکرد|همونه|همان مشکل|پابرجا|ادامه دارد|مثل قبل|\bstill\b|\bagain\b|same (?:error|problem|issue|as before)|\bpersists?\b|hala|hâlâ|devam|aynı (?:hata|sorun)|^(?:نه|خیر|نخیر|no|hayır)[.!؟?\s]*$/iu', trim($text)) === 1;
    }

    public static function assess(string $department, string $subject, array $thread, array $evidence, ?int $now = null, ?bool $priorSupport = null): ?array
    {
        if (! self::continues($department, $thread, $priorSupport)) { return null; }
        $now ??= time();
        $dossier = $evidence['dossier'] ?? [];
        $diagnostics = $evidence['diagnostics'] ?? [];
        $id = $dossier['service']['id'] ?? null;
        $owned = is_int($id) && $id > 0 && ($dossier['source'] ?? '') === 'servernet_database'
            && self::fresh($dossier['checked_at'] ?? null, $now);
        $lines = ['مشتری ادامهٔ مشکل را گزارش کرده؛ نتیجهٔ بررسی خواندنی تیکت فنی:'];
        $checks = [];
        if (! $owned) {
            $lines[] = 'سرویس دقیق از حساب مشتری تأیید نشد؛ فهرست حساب باید تطبیق داده شود و هیچ سرویس حدسی بررسی‌شده اعلام نمی‌شود.';
        } else {
            $lines[] = 'مالکیت سرویس از دادهٔ تازهٔ حساب تطبیق داده شد.';
            if (($diagnostics['service_id'] ?? null) === $id) {
                foreach (array_slice($diagnostics['checks'] ?? [], 0, 20) as $check) {
                    if (! is_array($check) || ! self::fresh($check['at'] ?? null, $now)) { continue; }
                    $name = $check['check'] ?? '';
                    if (! is_string($name)) { continue; }
                    if (($check['at'] ?? 0) >= ($checks[$name]['at'] ?? 0)) { $checks[$name] = $check; }
                }
            }
            $found = false;
            foreach (['cloud_status', 'cloud_firewall', 'whm_account', 'plesk_subscription', 'website_dns_tls_http', 'whm_php_configuration'] as $name) {
                if (! isset($checks[$name])) { continue; }
                $found = true;
                $lines[] = self::describe($name, $checks[$name]);
            }
            if (! $found) { $lines[] = 'بررسی تازه و قابل انتساب به همین سرویس در دسترس نیست؛ سلامت سرویس تأیید نمی‌شود.'; }
        }
        $text = $subject."\n".implode("\n", array_column($thread, 'body'));
        $gaps = [];
        if (preg_match('/udp|tcp|فایروال|پورت|شبکه|firewall/iu', $text)) { $gaps[] = 'listener، فایروال مهمان و تحویل واقعی UDP'; }
        if (preg_match('/dns|nameserver|نیم.?سرور/iu', $text)) { $gaps[] = 'delegation و پاسخ authoritative رکورد دقیق'; }
        if (preg_match('/smtp|imap|mail|ایمیل|ای.?میل/iu', $text)) { $gaps[] = 'رخداد تحویل واقعی پیام و تنظیم‌های ایمیل'; }
        if (preg_match('/\.net|iis|دات.?نت/iu', $text)) { $gaps[] = 'Runtime و لاگ برنامهٔ همان سرویس'; }
        if (preg_match('/gpu|cuda|گرافیک/iu', $text)) { $gaps[] = 'مدل GPU، درایور و اجرای workload'; }
        if (preg_match('/php|session\.upload_progress/iu', $text)) { $gaps[] = 'نسخهٔ وصله و تنظیم مؤثر SAPI وب'; }
        $lines[] = 'محدودیت: '.($gaps ? implode('؛ ', array_slice($gaps, 0, 3)) : 'عملکرد واقعی برنامه و علت دقیق ادامهٔ مشکل').' هنوز قطعی آزموده نشده است.';
        $lines[] = $owned ? 'گام بعد: بررسی خواندنیِ بخش باقی‌مانده با دسترسی مجاز و تطبیق زمان/نتیجهٔ آزمون مشتری.' : 'گام بعد: تکمیل انتخاب سرویس از سوابق خود حساب، پیش از پرسیدن دوبارهٔ اطلاعات از مشتری.';
        $lines[] = 'هیچ restart، تغییر تنظیم یا پاسخ عمومی اجرا نشد؛ این گزارش برای مدیر است.';
        return ['required' => true, 'kind' => 'technical_continuation', 'state' => 'waiting_owner_investigation',
            'analysis' => implode("\n", $lines), 'customer_reply_ready' => false, 'infrastructure_change_executed' => false];
    }

    private static function fresh(mixed $at, int $now): bool
    {
        return is_int($at) && $at >= $now - 900 && $at <= $now + 60;
    }

    private static function describe(string $name, array $check): string
    {
        $source = $check['source'] ?? '';
        $verified = ($check['result'] ?? '') === 'verified';
        if ($name === 'cloud_status' && $source === 'provider_api' && $verified) {
            return 'وضعیت جاری ماشین: '.match ($check['status'] ?? '') {
                'running' => 'روشن.', 'stopped', 'off' => 'خاموش.', 'building' => 'در حال ساخت.',
                'suspended' => 'تعلیق‌شده.', 'deleted' => 'حذف‌شده نزد API.', 'error' => 'خطا نزد API.', default => 'نامعلوم.',
            };
        }
        if ($name === 'cloud_firewall' && $source === 'provider_api' && $verified) {
            $all = true;
            foreach (['ingress', 'egress'] as $direction) {
                foreach (['IPv4', 'IPv6'] as $family) {
                    if (($check['all_tcp_udp_ports'][$direction][$family] ?? null) !== true) { $all = false; }
                }
            }
            return $all ? 'فایروال گروه متصل: همهٔ TCP/UDP ورودی/خروجی IPv4/IPv6 مجازند؛ تحویل بسته اثبات نشده است.'
                : 'قواعد گروه متصل خوانده شد؛ مجوز سراسری همهٔ پورت‌ها ثابت نشد و مسدودبودن پورت درخواستی هم از این مقدار نتیجه نمی‌شود.';
        }
        if ($name === 'whm_account' && $source === 'whm_api' && $verified) {
            return match ($check['suspended'] ?? null) {
                true => 'حساب هاست در API: تعلیق‌شده.', false => 'حساب هاست در API: موجود و تعلیق‌نشده؛ سلامت برنامه اثبات نشده است.',
                default => 'حساب هاست پیدا شد؛ وضعیت تعلیق نامعلوم است.',
            };
        }
        if ($name === 'plesk_subscription' && $source === 'plesk_xml_api' && ($check['result'] ?? '') === 'query_accepted') {
            return 'API هاست پرس‌وجوی اشتراک را پذیرفت؛ وجود/سلامت برنامه با این نتیجه ثابت نمی‌شود.';
        }
        if ($name === 'website_dns_tls_http' && $source === 'public_dns_pinned_tls' && $verified) {
            $code = $check['http_status'] ?? null;
            $http = is_int($code) && $code >= 100 && $code <= 599 ? (string) $code : 'نامعلوم';
            $dns = ($check['dns'] ?? '') === 'resolved' ? 'پاسخ دارد' : 'نامعلوم';
            $tls = ($check['tls'] ?? '') === 'verified' ? 'معتبر' : 'نامعلوم';
            return 'بررسی دامنه: DNS '.$dns.'، TLS '.$tls.'، HTTP '.$http.'؛ مسیرهای برنامه و redirect بررسی کامل نشده‌اند.';
        }
        // Unknown fields, raw errors, domain strings and package values are never projected.
        return match ($name) {
            'cloud_status' => 'وضعیت جاری ماشین تأیید نشد.', 'cloud_firewall' => 'قواعد جاری فایروال تأیید نشد.',
            'whm_account', 'plesk_subscription' => 'وضعیت جاری حساب هاست تأیید نشد.',
            'website_dns_tls_http' => 'بررسی جاری DNS/TLS/HTTP موفق یا کامل نبود.',
            'whm_php_configuration' => 'نتیجهٔ قابل اتکای PHP در دسترس نیست؛ وصله و تنظیم مؤثر وب تأیید نشده است.',
            default => 'نتیجهٔ قابل اتکایی در دسترس نیست.',
        };
    }
}
