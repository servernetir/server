<?php

namespace Tests\Unit;

use App\Services\Cloud\CloudSalesIncidentPolicy;
use PHPUnit\Framework\TestCase;

class CloudSalesIncidentPolicyTest extends TestCase
{
    private function check(bool $ok = false, string $reason = 'supplier_cost_read_failed'): array
    {
        return ['ok' => $ok, 'offers' => $ok ? 2 : 0, 'reason' => $reason, 'label' => 'Test'];
    }

    public function test_first_failure_is_immediate_and_reason_changes_stay_in_the_same_incident(): void
    {
        $first = CloudSalesIncidentPolicy::next($this->check(), null, 1000);
        $this->assertSame('failure', $first['event']);
        $changed = CloudSalesIncidentPolicy::next($this->check(false, 'access_denied'), $first['state'], 1060);
        $this->assertNull($changed['event']);
        $this->assertSame('access_denied', $changed['state']['reason']);
        $this->assertSame(1000, $changed['state']['incident_started_at']);
    }

    public function test_continuing_incident_reminders_back_off_to_two_hours_six_hours_and_daily(): void
    {
        $state = CloudSalesIncidentPolicy::next($this->check(), null, 1000)['state'];
        foreach ([7200, 21600, 86400, 86400] as $interval) {
            $before = CloudSalesIncidentPolicy::next($this->check(), $state, $state['last_attempt_at'] + $interval - 1);
            $this->assertNull($before['event']);
            $next = CloudSalesIncidentPolicy::next($this->check(), $state, $state['last_attempt_at'] + $interval);
            $this->assertSame('reminder', $next['event']);
            $state = $next['state'];
        }
        $this->assertSame(4, $state['reminders_sent']);
    }

    public function test_one_healthy_observation_does_not_close_incident_and_a_flap_does_not_start_another(): void
    {
        $first = CloudSalesIncidentPolicy::next($this->check(), null, 1000);
        $healthy = CloudSalesIncidentPolicy::next($this->check(true, 'available'), $first['state'], 1060);
        $this->assertNull($healthy['event']);
        $this->assertTrue($healthy['state']['incident_open']);
        $flap = CloudSalesIncidentPolicy::next($this->check(), $healthy['state'], 1120);
        $this->assertNull($flap['event']);
        $this->assertSame(0, $flap['state']['healthy_checks']);
        $healthy = CloudSalesIncidentPolicy::next($this->check(true, 'available'), $flap['state'], 1180);
        $recovery = CloudSalesIncidentPolicy::next($this->check(true, 'available'), $healthy['state'], 1240);
        $this->assertSame('recovery', $recovery['event']);
        $this->assertFalse($recovery['state']['incident_open']);
        $again = CloudSalesIncidentPolicy::next($this->check(), $recovery['state'], 1300);
        $this->assertSame('failure', $again['event']);
        $this->assertSame(1300, $again['state']['incident_started_at']);
    }

    public function test_manual_stop_is_quiet_and_does_not_create_a_recovery_message(): void
    {
        $failure = CloudSalesIncidentPolicy::next($this->check(), null, 1000);
        $manual = CloudSalesIncidentPolicy::next($this->check(false, 'admin_disabled'), $failure['state'], 1060);
        $this->assertNull($manual['event']);
        $this->assertFalse($manual['state']['incident_open']);
        $available = CloudSalesIncidentPolicy::next($this->check(true, 'available'), $manual['state'], 1120);
        $this->assertNull($available['event']);
        $this->assertNull(CloudSalesIncidentPolicy::next($this->check(true, 'available'), null, 1000)['event']);
    }

    public function test_legacy_receipt_migrates_without_deployment_announcing_the_existing_incident_again(): void
    {
        $legacy = $this->check() + ['last_attempt_at' => 1000];
        $next = CloudSalesIncidentPolicy::next($this->check(false, 'access_denied'), $legacy, 1060);
        $this->assertNull($next['event']);
        $this->assertTrue($next['state']['incident_open']);
        $this->assertSame(1000, $next['state']['last_attempt_at']);
        $healthy = CloudSalesIncidentPolicy::next($this->check(true, 'available'), $next['state'], 1120);
        $this->assertNull($healthy['event']);
        $this->assertSame('recovery', CloudSalesIncidentPolicy::next($this->check(true, 'available'), $healthy['state'], 1180)['event']);
    }
}
