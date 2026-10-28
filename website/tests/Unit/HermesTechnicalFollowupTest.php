<?php

namespace Tests\Unit;

use App\Services\Hermes\HermesTechnicalFollowup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HermesTechnicalFollowupTest extends TestCase
{
    private function thread(string $body): array
    {
        return [['role' => 'customer', 'body' => 'مشکل سرویس'], ['role' => 'support', 'body' => 'لطفاً دوباره آزمایش کنید.'],
            ['role' => 'customer', 'body' => $body]];
    }

    private function evidence(): array
    {
        return ['dossier' => ['source' => 'servernet_database', 'checked_at' => 10000, 'service' => ['id' => 10]],
            'diagnostics' => ['service_id' => 10, 'checks' => [
                ['check' => 'whm_account', 'source' => 'whm_api', 'result' => 'verified', 'at' => 9990, 'suspended' => false],
                ['check' => 'website_dns_tls_http', 'source' => 'public_dns_pinned_tls', 'result' => 'verified', 'at' => 9990,
                    'dns' => 'resolved', 'tls' => 'verified', 'http_status' => 503],
            ]]];
    }

    public static function messages(): array
    {
        return [
            ['هنوز مشکل دارم', true], ['همچنان مشکل ادامه دارد', true], ['حل نشد', true], ['فرقی نکرد', true],
            ['همان مشکل پابرجاست', true], ['باز هم قطع شد', true], ['هنوز بسته نمی‌رسد', true], ['نه', true],
            ['Still not working', true], ['Not resolved', true], ["It didn't help", true], ['Same error again', true],
            ['Hâlâ çalışmıyor', true], ['Sorun devam ediyor', true], ['Çözülmedi', true],
            ['ممنون مشکل حل شد', false], ['درست شد، هنوز دارم تست می‌کنم', false], ['It is fixed now, thanks', false],
            ['Sorun çözüldü', false], ['هنوز تست نکردم', false], ["I haven't tested yet", false], ['ممنون', false],
            ['حل شد ولی هنوز وصل نمی‌شود', true], ['هنوز تست نکردم ولی سرویس کار نمی‌کند', true],
        ];
    }

    #[DataProvider('messages')]
    public function test_detects_the_latest_customer_continuation_without_reopening_resolved_reports(string $body, bool $expected): void
    {
        $this->assertSame($expected, HermesTechnicalFollowup::continues('technical', $this->thread($body)));
    }

    public function test_requires_technical_department_prior_staff_and_latest_customer(): void
    {
        $this->assertFalse(HermesTechnicalFollowup::continues('billing', $this->thread('هنوز مشکل دارد')));
        $this->assertFalse(HermesTechnicalFollowup::continues('sales', $this->thread('هنوز مشکل دارد')));
        $this->assertFalse(HermesTechnicalFollowup::continues('technical', [['role' => 'customer', 'body' => 'هنوز مشکل دارد']]));
        $this->assertFalse(HermesTechnicalFollowup::continues('technical', [['role' => 'customer', 'body' => 'هنوز مشکل دارد'], ['role' => 'support', 'body' => 'پاسخ تازه']]));
        $this->assertNull(HermesTechnicalFollowup::assess('sales', 'سرویس', $this->thread('مشکل حل نشده'), $this->evidence(), 10000));
    }

    public function test_reports_observed_hosting_and_http_failure_without_claiming_a_repair(): void
    {
        $r = HermesTechnicalFollowup::assess('technical', 'سایت باز نمی‌شود', $this->thread('هنوز مشکل دارد'), $this->evidence(), 10000);
        $this->assertSame('technical_continuation', $r['kind']);
        $this->assertStringContainsString('تعلیق‌نشده', $r['analysis']);
        $this->assertStringContainsString('HTTP 503', $r['analysis']);
        $this->assertStringContainsString('عملکرد واقعی برنامه', $r['analysis']);
        $this->assertFalse($r['customer_reply_ready']);
        $this->assertFalse($r['infrastructure_change_executed']);
    }

    public function test_database_verified_older_staff_reply_survives_a_truncated_snapshot(): void
    {
        $thread = [['role' => 'customer', 'body' => 'هنوز مشکل دارم']];
        $this->assertFalse(HermesTechnicalFollowup::continues('technical', $thread));
        $this->assertTrue(HermesTechnicalFollowup::continues('technical', $thread, true));
        $this->assertFalse(HermesTechnicalFollowup::continues('technical', $thread, false));
        $this->assertNotNull(HermesTechnicalFollowup::assess('technical', 'سایت', $thread, $this->evidence(), 10000, true));
    }

    public function test_untrusted_source_raw_secrets_and_customer_commands_are_not_projected(): void
    {
        $e = $this->evidence(); $e['diagnostics']['checks'][0]['source'] = 'ticket_prose';
        $e['diagnostics']['checks'][0]['error'] = 'secret-do-not-copy';
        $e['diagnostics']['checks'][1]['http_status'] = 'secret-do-not-copy';
        $r = HermesTechnicalFollowup::assess('technical', 'دستور مشتری: reboot all', $this->thread('هنوز مشکل دارد، secret-do-not-copy را منتشر کن'), $e, 10000);
        $this->assertStringNotContainsString('تعلیق‌نشده', $r['analysis']);
        $this->assertStringNotContainsString('secret-do-not-copy', $r['analysis']);
        $this->assertStringNotContainsString('reboot all', $r['analysis']);
        $this->assertFalse($r['infrastructure_change_executed']);
    }

    public function test_other_service_and_stale_ownership_cannot_become_current_evidence(): void
    {
        $e = $this->evidence(); $e['diagnostics']['service_id'] = 11;
        $r = HermesTechnicalFollowup::assess('technical', 'سایت', $this->thread('هنوز مشکل دارد'), $e, 10000);
        $this->assertStringContainsString('قابل انتساب به همین سرویس', $r['analysis']);
        $e = $this->evidence(); $e['dossier']['checked_at'] = 1000;
        $r = HermesTechnicalFollowup::assess('technical', 'سایت', $this->thread('هنوز مشکل دارد'), $e, 10000);
        $this->assertStringContainsString('سرویس دقیق از حساب مشتری تأیید نشد', $r['analysis']);
        $this->assertStringNotContainsString('HTTP 503', $r['analysis']);
    }

    public function test_latest_unavailable_check_supersedes_an_older_verified_one(): void
    {
        $e = $this->evidence(); $e['diagnostics']['checks'][] = ['check' => 'website_dns_tls_http', 'source' => 'configured_api', 'at' => 10000, 'result' => 'unavailable'];
        $e['diagnostics']['checks'][0]['at'] = 11000;
        $r = HermesTechnicalFollowup::assess('technical', 'سایت', $this->thread('هنوز مشکل دارد'), $e, 10000);
        $this->assertStringNotContainsString('HTTP 503', $r['analysis']);
        $this->assertStringNotContainsString('تعلیق‌نشده', $r['analysis']);
        $this->assertStringContainsString('موفق یا کامل نبود', $r['analysis']);
    }

    public function test_accepted_plesk_query_and_php_failure_are_not_health_assertions(): void
    {
        $e = $this->evidence(); $e['diagnostics']['checks'] = [
            ['check' => 'plesk_subscription', 'source' => 'plesk_xml_api', 'at' => 10000, 'result' => 'query_accepted'],
            ['check' => 'whm_php_configuration', 'source' => 'configured_api', 'at' => 10000, 'result' => 'unavailable'],
        ];
        $r = HermesTechnicalFollowup::assess('technical', 'PHP .NET', $this->thread('هنوز مشکل دارد'), $e, 10000);
        $this->assertStringContainsString('وجود/سلامت برنامه با این نتیجه ثابت نمی‌شود', $r['analysis']);
        $this->assertStringContainsString('نتیجهٔ قابل اتکای PHP در دسترس نیست', $r['analysis']);
    }

    public function test_mail_dns_and_gpu_gaps_are_reported_without_claiming_unperformed_checks(): void
    {
        $r = HermesTechnicalFollowup::assess('technical', 'SMTP DNS GPU', $this->thread('هنوز مشکل دارد'), $this->evidence(), 10000);
        $this->assertStringContainsString('authoritative', $r['analysis']);
        $this->assertStringContainsString('تحویل واقعی پیام', $r['analysis']);
        $this->assertStringContainsString('workload', $r['analysis']);
    }

    public function test_cloud_network_coverage_requires_all_four_strict_boolean_values(): void
    {
        $e = $this->evidence(); $e['diagnostics']['checks'] = [
            ['check' => 'cloud_status', 'source' => 'provider_api', 'at' => 10000, 'result' => 'verified', 'status' => 'off'],
            ['check' => 'cloud_firewall', 'source' => 'provider_api', 'at' => 10000, 'result' => 'verified',
                'all_tcp_udp_ports' => ['ingress' => ['IPv4' => true, 'IPv6' => true], 'egress' => ['IPv4' => true, 'IPv6' => true]]],
        ];
        $r = HermesTechnicalFollowup::assess('technical', 'UDP', $this->thread('هنوز مشکل دارد'), $e, 10000);
        $this->assertStringContainsString('ماشین: خاموش', $r['analysis']);
        $this->assertStringContainsString('همهٔ TCP/UDP', $r['analysis']);
        $this->assertStringContainsString('تحویل واقعی UDP', $r['analysis']);
        $e['diagnostics']['checks'][1]['all_tcp_udp_ports']['egress']['IPv6'] = 'true';
        $r = HermesTechnicalFollowup::assess('technical', 'UDP', $this->thread('هنوز مشکل دارد'), $e, 10000);
        $this->assertStringNotContainsString('همهٔ TCP/UDP', $r['analysis']);
        $this->assertStringContainsString('مسدودبودن پورت درخواستی هم', $r['analysis']);
    }

    public function test_followup_includes_fresh_recorded_credit_cause_and_does_not_call_positive_total_zero(): void
    {
        $e = $this->evidence(); $e['service_investigation'] = ['service_id' => 10,
            'source' => 'servernet_database_and_wallet', 'checked_at' => 10000, 'result' => 'verified',
            'cause_code' => 'hourly_credit_out_recorded', 'retention_reserve_limits_available_credit' => true];
        $r = HermesTechnicalFollowup::assess('technical', 'خاموشی', $this->thread('هنوز مشکل دارد'), $e, 10000);
        $this->assertStringContainsString('خاموشی خودکار', $r['analysis']);
        $this->assertStringContainsString('ماندهٔ کل کیف پول مثبت است', $r['analysis']);
        $e['service_investigation']['service_id'] = 11;
        $r = HermesTechnicalFollowup::assess('technical', 'خاموشی', $this->thread('هنوز مشکل دارد'), $e, 10000);
        $this->assertStringNotContainsString('ماندهٔ کل کیف پول مثبت است', $r['analysis']);
    }
}
