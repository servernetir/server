<?php

namespace App\Services\Hermes;

/** Fixed investigation plans, never executable model-generated commands. */
final class HermesSupportPlaybooks
{
    public static function build(string $text, array $dossier, array $diagnostics, ?int $now = null): array
    {
        $now ??= time(); $plans = [];
        $definitions = [
            'cloud_network' => ['/\b(?:udp|tcp)\b|firewall|فایروال|پورت/iu', ['cloud_status', 'cloud_firewall'],
                'ابتدا مالکیت سرویس ابری و قواعد گروه واقعاً متصل را زنده بررسی کن. خالی بودن فهرست هاست به معنی نبود VPS نیست؛ شناسهٔ موجود را دوباره نپرس. دادهٔ نسخهٔ جدید پنل بر پاسخ قدیمیِ ناقص مقدم است. بازبودن قاعدهٔ زیرساخت، listener برنامه، فایروال مهمان یا تحویل واقعی UDP را ثابت نمی‌کند؛ refused را از timeout جدا کن. پاسخ کوتاه، هم‌زبان مشتری و فاقد نام زیرساخت باشد.'],
            'php' => ['/php|session\.upload_progress/iu', ['whm_account', 'whm_php_configuration', 'website_dns_tls_http'],
                'نسخه و build واقعی، SAPI دامنه، تنظیم مؤثر و overrideهای FPM؛ برای ادعای رفع امنیتی، اصلاحیهٔ سازنده نیز تطبیق شود.'],
            'dotnet' => ['/\.net|asp\.?net|دات.?نت|iis/iu', ['plesk_subscription', 'website_dns_tls_http'],
                'نسخهٔ Runtime و Hosting Bundle، app pool، لاگ خطای همان برنامه و پاسخ مسیر واقعی؛ پذیرش پرس‌وجوی Plesk سلامت برنامه را ثابت نمی‌کند.'],
            'dns' => ['/\bdns\b|nameserver|نیم.?سرور|دی.?ان.?اس/iu', ['website_dns_tls_http'],
                'پاسخ authoritative برای نام و نوع رکورد دقیق، delegation، TTL و propagation؛ resolve شدن A به‌تنهایی همهٔ DNS را تأیید نمی‌کند.'],
            'mail' => ['/\bmail\b|smtp|imap|spf|dkim|dmarc|ایمیل|ای.?میل/iu', ['website_dns_tls_http'],
                'MX، SPF، DKIM، DMARC و رخداد تحویل همان پیام؛ تست ارسال فقط با دامنه و گیرندهٔ مجاز، بدون افشای رمز.'],
            'gpu' => ['/\bgpu\b|cuda|گرافیک|nvidia/iu', ['cloud_status'],
                'منبع تحویل‌شده، مدل و تعداد GPU واقعی، درایور، CUDA و نمونهٔ workload؛ موجودی کاتالوگ اثبات تحویل GPU نیست.'],
        ];
        foreach ($definitions as $key => [$pattern, $checks, $manual]) {
            if (! preg_match($pattern, $text)) { continue; }
            $observed = []; $missing = [];
            foreach ($checks as $name) {
                $match = null;
                foreach ($diagnostics['checks'] ?? [] as $check) {
                    if (! empty($dossier['service']['id']) && ($diagnostics['service_id'] ?? null) === $dossier['service']['id']
                        && ($check['check'] ?? '') === $name && ($check['at'] ?? 0) >= $now - 900 && ($check['at'] ?? 0) <= $now + 60
                        && ($check['result'] ?? '') === 'verified') { $match = $check; break; }
                }
                if ($match) { $observed[] = ['check' => $name, 'at' => $match['at'], 'source' => $match['source'] ?? 'unknown']; }
                else { $missing[] = $name; }
            }
            $plan = ['playbook' => $key, 'service_ownership_verified' => ! empty($dossier['service']['id']),
                'observed' => $observed, 'missing_checks' => $missing, 'manual_verification' => $manual,
                'stages' => ['verify_customer_service_ownership', 'read_only_diagnosis', 'owner_approved_exact_change_with_rollback', 'verify_effective_result', 'draft_evidence_based_reply'],
                'repair_verified' => false, 'authorized_action' => 'read_only_diagnostics_only',
                'change_plan_required' => ['exact_service', 'current_value', 'proposed_value', 'shared_host_impact', 'rollback', 'post_change_check']];
            if ($key === 'cloud_network' && ($dossier['service']['cloud']['provider'] ?? null) === 'arvan') {
                $plan['documented_network_policy'] = [
                    'source_ref' => 'docs/project/ARVAN-FIREWALL-SUPPORT-KNOWLEDGE-2026-10-28.md',
                    'source_date' => '2026-10-28', 'reference_only' => true, 'current_state_verified_by_reference' => false,
                    'scope' => 'six_existing_account_regions_infrastructure_firewall',
                    'documented_policy' => 'servernet_group_all_tcp_udp_ports_and_icmp_ingress_egress_ipv4_ipv6',
                    'required_current_evidence' => 'owned_service_attached_group_fresh_provider_rules',
                    'provider_smtp_25_465_restriction' => 'separate_policy_not_proven_unblocked',
                    'customer_guidance' => 'در صورت تأیید زندهٔ قواعد، بازبودن پورت‌های زیرساخت را اعلام کن؛ رفع اتصال برنامه را بدون آزمون ادعا نکن. رضایت صریح مشتری برای پورت‌ها را دوباره نخواه. محدودیت مستقل ارسال ایمیل را با فایروال حساب یکی ندان. در پیگیری، بازهٔ قبلی پورت‌ها را حفظ کن.',
                    'grants_execution_rights' => false,
                ];
            }
            $plans[] = $plan;
        }
        return $plans;
    }
}
