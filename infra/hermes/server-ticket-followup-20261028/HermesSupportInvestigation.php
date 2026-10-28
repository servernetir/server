<?php

namespace App\Services\Hermes;

/** Evidence gate: a security assurance cannot be replaced by a generic customer question. */
final class HermesSupportInvestigation
{
    public static function assess(string $subject, array $thread, array $evidence): ?array
    {
        $text = $subject."\n".implode("\n", array_column($thread, 'body'));
        if (! preg_match('/php|session\.upload_progress/iu', $text)
            || ! preg_match('/PHP-SEC|session\.upload_progress|امنیت|وصله|آسیب.?پذیر|patch|vulnerab|security/iu', $text)) {
            $network = self::network($text, $thread, $evidence);
            if ($network !== null) { return $network; }
            // A checklist is not evidence of a repair. Missing runtime/DNS/mail/GPU facts stay internal.
            if (! preg_match('/خطا|خراب|قطع|کار نمی|مشکل|ارور|error|fail|broken|outage|problem/iu', $text)) { return null; }
            $plans = HermesSupportPlaybooks::build($text, $evidence['dossier'] ?? [], $evidence['diagnostics'] ?? []);
            if ($plans === []) { return null; }
            $lines = ['این درخواست به بررسی واقعی همان سرویس نیاز دارد. ابتدا مالکیت سرویس از حساب مشتری تطبیق داده شود.'];
            foreach ($plans as $plan) { $lines[] = $plan['playbook'].': '.$plan['manual_verification']; }
            $lines[] = 'شواهد فعلی فقط بررسی‌های ثبت‌شده‌اند؛ تغییر یا رفع مشکل تأیید نشده است. طرح تغییر با اثر و بازگشت برای تأیید مدیر آماده شود؛ پس از تأیید و اقدام مجاز، نتیجهٔ مؤثر دوباره بررسی و پاسخ مشتری پیشنهاد شود.';
            return ['required' => true, 'state' => 'waiting_owner_investigation', 'analysis' => implode("\n", $lines),
                'customer_reply_ready' => false, 'infrastructure_change_executed' => false];
        }
        $dossier = $evidence['dossier'] ?? [];
        $lines = ['این تیکت به نتیجهٔ بررسی واقعی PHP نیاز دارد؛ هنوز پاسخ قابل تأیید برای مشتری آماده نیست.'];
        if (empty($dossier['service']['id'])) {
            $status = $dossier['link_status'] ?? 'inventory_unavailable';
            $lines[] = match ($status) {
                'multiple_owned_hosting_requires_owner_selection' => 'فهرست هاست‌های ثبت‌شدهٔ خود مشتری بررسی شد؛ چند سرویس وجود دارد و انتخاب دقیق سرویس توسط مدیر لازم است.',
                'no_managed_hosting_in_owned_inventory' => 'در فهرست ثبت‌شدهٔ مشتری، هاست مدیریت‌شدهٔ واجد شرایط پیدا نشد؛ سوابق سفارش و انتقال باید توسط مدیر بررسی شود.',
                default => 'هویت سرویس یا کامل‌بودن فهرست متعلق به مشتری تأیید نشد؛ ابتدا بررسی داخلی تکمیل شود.',
            };
            $lines[] = 'پیش از درخواست مجدد شناسه از مشتری، سرویس‌ها و سفارش‌های همین حساب را در پنل تطبیق بده.';
        } else {
            $php = null;
            foreach ($evidence['diagnostics']['checks'] ?? [] as $check) {
                if (($check['check'] ?? '') === 'whm_php_configuration') { $php = $check; break; }
            }
            if (($php['security_patch_verified'] ?? false) === true
                && ($php['effective_web_runtime_verified'] ?? false) === true
                && ($php['session_upload_progress_enabled'] ?? null) === false) { return null; }
            $lines[] = 'سرویس متعلق به مشتری از سوابق تطبیق داده شد: #'.(int) $dossier['service']['id'];
            if (isset($php['php_package'])) { $lines[] = 'شاخهٔ تنظیم‌شدهٔ دامنه در WHM: '.$php['php_package'].'؛ این مقدار نسخهٔ دقیق وصله را ثابت نمی‌کند.'; }
            if (is_bool($php['session_upload_progress_enabled'] ?? null)) {
                $lines[] = 'مقدار ثبت‌شدهٔ session.upload_progress.enabled در php.ini شاخه: '.($php['session_upload_progress_enabled'] ? 'روشن' : 'خاموش').'؛ مقدار مؤثر وب هنوز تأیید نشده است.';
            }
            $lines[] = 'بررسی خواندنی لازم: نسخه و build بستهٔ نصب‌شده با اصلاحیهٔ سازنده تطبیق داده شود؛ سپس SAPI و تنظیم مؤثر همین دامنه و overrideهای FPM بررسی شوند.';
            $lines[] = 'اگر تغییر لازم بود، نام سرویس و دامنه، مقدار فعلی و مقدار پیشنهادی، اثر روی هاست‌های مشترک و برنامهٔ بازگشت برای تأیید مدیر آماده شود. تأیید ارسال پاسخ مجوز تغییر PHP نیست.';
        }
        $lines[] = 'پس از اقدامِ تأییدشده، نتیجه دوباره خوانده و سایت بررسی شود؛ فقط آن‌وقت متن نهایی مشتری پیشنهاد شود. هیچ تغییر، restart یا پاسخی برای مشتری اجرا نشده است.';
        return ['required' => true, 'state' => 'waiting_owner_investigation', 'analysis' => implode("\n", $lines),
            'customer_reply_ready' => false, 'infrastructure_change_executed' => false];
    }

    /** Report observed facts, rather than returning only a checklist after a customer retry. */
    private static function network(string $text, array $thread, array $evidence): ?array
    {
        $latest = '';
        foreach ($thread as $message) {
            if (($message['role'] ?? '') === 'customer') { $latest = (string) ($message['body'] ?? ''); }
        }
        if (! preg_match('/هنوز|همچنان|بازم|نمی.?رس|نمی.?آ|وصل نمی|کار نمی|مشکل|قطع|خطا|timeout|refused|still|not working|fail|broken|problem/iu', $latest)) { return null; }
        $dossier = $evidence['dossier'] ?? [];
        $diagnostics = $evidence['diagnostics'] ?? [];
        $plans = HermesSupportPlaybooks::build($text, $dossier, $diagnostics);
        if (! in_array('cloud_network', array_column($plans, 'playbook'), true)) { return null; }
        $serviceId = $dossier['service']['id'] ?? null;
        $owned = is_int($serviceId) && $serviceId > 0;
        $lines = ['پیگیری ادامهٔ مشکل شبکه؛ نتیجهٔ بررسی خواندنی:'];
        if (! $owned) {
            $lines[] = 'مالکیت سرویس دقیق از دادهٔ حساب تأیید نشد؛ بررسی شبکه به سرویس حدسی نسبت داده نمی‌شود.';
        } else {
            $lines[] = 'سرویس متعلق به مشتری از حساب تطبیق داده شد.';
            $checks = [];
            if (($diagnostics['service_id'] ?? null) === $serviceId) {
                foreach ($diagnostics['checks'] ?? [] as $check) {
                    $at = $check['at'] ?? 0;
                    if (($check['result'] ?? '') === 'verified' && ($check['source'] ?? '') === 'provider_api'
                        && is_int($at) && $at >= time() - 900 && $at <= time() + 60) {
                        $checks[$check['check'] ?? ''] = $check;
                    }
                }
            }
            $status = $checks['cloud_status']['status'] ?? null;
            $lines[] = match ($status) {
                'running' => 'وضعیت ماشین در بررسی تازهٔ API: روشن.',
                'stopped' => 'وضعیت ماشین در بررسی تازهٔ API: خاموش؛ هیچ فرمان روشن‌کردنی اجرا نشد.',
                default => 'وضعیت جاری ماشین تأیید نشد.',
            };
            $firewall = $checks['cloud_firewall'] ?? [];
            $coverage = $firewall['all_tcp_udp_ports'] ?? [];
            $all = true;
            foreach (['ingress', 'egress'] as $direction) {
                foreach (['IPv4', 'IPv6'] as $family) {
                    if (($coverage[$direction][$family] ?? null) !== true) { $all = false; }
                }
            }
            $lines[] = $all
                ? 'قواعد تازهٔ گروه متصل: همهٔ پورت‌های TCP/UDP ورودی و خروجی IPv4/IPv6 مجازند؛ بازکردن مجدد پیشنهاد نمی‌شود.'
                : ($firewall === [] ? 'قواعد جاری فایروال متصل تأیید نشد.'
                    : 'مجوز سراسری همهٔ پورت‌ها تأیید نشد؛ این به‌تنهایی مسدودبودن پورت درخواستی را ثابت نمی‌کند.');
        }
        $lines[] = 'listener، فایروال مهمان و تحویل واقعی UDP آزموده نشده‌اند. گام بعد، تطبیق آزمون مبدأ و دریافت tcpdump همان سرور با زمان مشترک است؛ دسترسی لازم باید مجاز باشد.';
        $lines[] = 'این چرخه فقط خواندنی بود؛ تغییری در شبکه یا ماشین و پاسخی به مشتری اجرا نشد.';
        return ['required' => true, 'state' => 'waiting_owner_investigation', 'analysis' => implode("\n", $lines),
            'customer_reply_ready' => false, 'infrastructure_change_executed' => false];
    }
}
