<?php

namespace Tests\Feature;

use App\Services\Hermes\HermesSupportInvestigation;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3).'/infra/hermes/support-firewall-20261028/HermesSupportPlaybooks.php';
require_once dirname(__DIR__, 3).'/infra/hermes/server-ticket-followup-20261028/HermesSupportInvestigation.php';

class HermesNetworkFollowupEvidenceTest extends TestCase
{
    private function evidence(): array
    {
        return ['dossier' => ['service' => ['id' => 10, 'cloud' => ['provider' => 'arvan']]],
            'diagnostics' => ['service_id' => 10, 'checks' => [
                ['check' => 'cloud_status', 'source' => 'provider_api', 'at' => time(), 'result' => 'verified', 'status' => 'running'],
                ['check' => 'cloud_firewall', 'source' => 'provider_api', 'at' => time(), 'result' => 'verified',
                    'all_tcp_udp_ports' => ['ingress' => ['IPv4' => true, 'IPv6' => true], 'egress' => ['IPv4' => true, 'IPv6' => true]]],
            ]]];
    }

    private function assess(array $evidence): array
    {
        return HermesSupportInvestigation::assess('udp', [
            ['role' => 'support', 'body' => 'پورت‌ها مجاز شدند، دوباره آزمایش کنید.'],
            ['role' => 'customer', 'body' => 'هنوز بسته‌ها نمی‌رسند.'],
        ], $evidence);
    }

    public function test_fresh_owned_evidence_reports_findings_and_limits_without_customer_send(): void
    {
        $r = $this->assess($this->evidence());
        $this->assertStringContainsString('API: روشن', $r['analysis']);
        $this->assertStringContainsString('همهٔ پورت‌های TCP/UDP', $r['analysis']);
        $this->assertStringContainsString('تحویل واقعی UDP آزموده نشده', $r['analysis']);
        $this->assertFalse($r['customer_reply_ready']);
        $this->assertFalse($r['infrastructure_change_executed']);
        $this->assertLessThan(1300, mb_strlen($r['analysis']));
    }

    public function test_evidence_from_another_service_is_not_reported_as_observed(): void
    {
        $e = $this->evidence(); $e['diagnostics']['service_id'] = 11;
        $r = $this->assess($e);
        $this->assertStringContainsString('وضعیت جاری ماشین تأیید نشد', $r['analysis']);
        $this->assertStringContainsString('قواعد جاری فایروال متصل تأیید نشد', $r['analysis']);
    }

    public function test_stale_or_future_checks_do_not_prove_current_state(): void
    {
        $e = $this->evidence(); $e['diagnostics']['checks'][0]['at'] = time() - 1000;
        $e['diagnostics']['checks'][1]['at'] = time() + 120;
        $r = $this->assess($e);
        $this->assertStringContainsString('وضعیت جاری ماشین تأیید نشد', $r['analysis']);
        $this->assertStringNotContainsString('قواعد تازهٔ گروه متصل', $r['analysis']);
    }

    public function test_unavailable_or_untrusted_sources_do_not_prove_current_state(): void
    {
        $e = $this->evidence(); $e['diagnostics']['checks'][0]['result'] = 'unavailable';
        $e['diagnostics']['checks'][1]['source'] = 'ticket_prose';
        $r = $this->assess($e);
        $this->assertStringContainsString('قواعد جاری فایروال متصل تأیید نشد', $r['analysis']);
        $this->assertStringNotContainsString('API: روشن', $r['analysis']);
    }

    public function test_missing_ownership_does_not_attribute_another_servers_evidence(): void
    {
        $e = $this->evidence(); $e['dossier']['service'] = null;
        $r = $this->assess($e);
        $this->assertStringContainsString('مالکیت سرویس دقیق', $r['analysis']);
        $this->assertStringNotContainsString('همهٔ پورت‌های TCP/UDP', $r['analysis']);
    }

    public function test_partial_coverage_is_not_claimed_as_blocked_requested_ports(): void
    {
        $e = $this->evidence(); $e['diagnostics']['checks'][1]['all_tcp_udp_ports']['ingress']['IPv4'] = false;
        $r = $this->assess($e);
        $this->assertStringContainsString('مسدودبودن پورت درخواستی را ثابت نمی‌کند', $r['analysis']);
        $this->assertStringNotContainsString('بازکردن مجدد پیشنهاد نمی‌شود', $r['analysis']);
    }

    public function test_stopped_machine_is_reported_without_claiming_a_power_change(): void
    {
        $e = $this->evidence(); $e['diagnostics']['checks'][0]['status'] = 'stopped';
        $this->assertStringContainsString('خاموش؛ هیچ فرمان روشن‌کردنی اجرا نشد', $this->assess($e)['analysis']);
    }

    public function test_old_customer_complaint_does_not_override_latest_support_message(): void
    {
        $r = HermesSupportInvestigation::assess('udp', [
            ['role' => 'customer', 'body' => 'مشکل داریم'],
            ['role' => 'support', 'body' => 'هنوز قطع است'],
        ], $this->evidence());
        // The support workflow dispatches only on a fresh customer message; this assessment cannot dispatch.
        $this->assertFalse($r['customer_reply_ready']);
    }

    public function test_unrelated_or_php_investigations_keep_their_existing_scope(): void
    {
        $this->assertNull(HermesSupportInvestigation::assess('سلام', [['role' => 'customer', 'body' => 'ممنون']], []));
        $r = HermesSupportInvestigation::assess('PHP-SEC session.upload_progress', [['role' => 'customer', 'body' => 'هنوز مشکل دارد']], []);
        $this->assertStringContainsString('بررسی واقعی PHP', $r['analysis']);
        $this->assertStringNotContainsString('پیگیری ادامهٔ مشکل شبکه', $r['analysis']);
    }
}
