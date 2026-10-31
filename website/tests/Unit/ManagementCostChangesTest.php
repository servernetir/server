<?php

namespace Tests\Unit;

use App\Services\Bale\Management\ManagementCostChanges;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class ManagementCostChangesTest extends TestCase
{
    private string $directory;
    private string $path;
    private CatalogCostFixture $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/bale-catalog-cost-test-'.bin2hex(random_bytes(8));
        $this->path = $this->directory.'/private/costs.json';
        $this->service = new CatalogCostFixture($this->path);
        $this->service->at = '2026-10-29T05:00:00+00:00';
        $this->service->rows = [$this->row(1, 'hetzner', 100)];
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->directory.'/private/*') as $path) { @unlink($path); }
        @rmdir($this->directory.'/private');
        @rmdir($this->directory);
        parent::tearDown();
    }

    public function test_preview_never_creates_reporting_state_and_initial_baseline_is_unknown(): void
    {
        $report = $this->service->snapshot();
        self::assertTrue($report['available']);
        self::assertSame('baseline_missing', $report['status']);
        self::assertNull($report['increase_count']);
        self::assertSame(0, $report['comparison_count']);
        self::assertSame(1, $report['unknown_count']);
        self::assertFalse($report['state_persisted']);
        self::assertDirectoryDoesNotExist($this->directory);

        self::assertTrue($this->service->snapshot(true)['state_persisted']);
        self::assertFileExists($this->path);
        self::assertSame('baseline_missing', $this->service->snapshot()['status']);
        self::assertSame(0600, fileperms($this->path) & 0777);
    }

    public function test_compares_dated_catalog_units_separately_and_groups_actual_providers(): void
    {
        $this->service->rows = [$this->row(1, 'hetzner', 100, 3000), $this->row(2, 'aeza', 400)];
        $this->service->snapshot(true);
        $this->nextDay();
        $this->service->rows = [$this->row(1, 'hetzner', 120, 3500), $this->row(2, 'aeza', 450)];
        $report = $this->service->snapshot();
        self::assertSame('compared', $report['status']);
        self::assertSame(3, $report['increase_count']);
        self::assertSame(3, $report['comparison_count']);
        self::assertSame(0, $report['unknown_count']);
        self::assertSame(['aeza', 'hetzner'], array_column($report['provider_increases'], 'provider'));
        $items = $report['provider_increases'][1]['items'];
        self::assertSame(['month', 'hour'], array_column($items, 'period'));
        self::assertSame([100, 1000000], array_column($items, 'scale'));
        self::assertSame([100, 3000], array_column($items, 'before'));
        self::assertSame([120, 3500], array_column($items, 'after'));
        self::assertSame('2026-10-29T05:00:00+00:00', $items[0]['before_source_at']);
        self::assertSame($this->service->at, $items[0]['source_at']);
        self::assertSame(ManagementCostChanges::SOURCE, $report['source']);
        self::assertStringContainsString('unverified', $report['provider_increases'][0]['cause']);
    }

    public function test_daily_commit_is_idempotent_and_keeps_same_day_detail_comparison(): void
    {
        $this->service->snapshot(true);
        $this->nextDay();
        $this->service->rows = [$this->row(1, 'hetzner', 150)];
        self::assertSame(1, $this->service->snapshot(true)['increase_count']);
        $saved = file_get_contents($this->path);
        $this->service->rows = [$this->row(1, 'hetzner', 160)];
        $detail = $this->service->snapshot(true);
        self::assertSame($saved, file_get_contents($this->path));
        self::assertSame(100, $detail['provider_increases'][0]['items'][0]['before']);
        self::assertSame(160, $detail['provider_increases'][0]['items'][0]['after']);
        $this->nextDay();
        $this->service->rows = [$this->row(1, 'hetzner', 170)];
        self::assertSame(150, $this->service->snapshot()['provider_increases'][0]['items'][0]['before']);
    }

    public function test_missing_stale_and_zero_costs_are_unknown_and_last_known_source_is_retained(): void
    {
        $this->service->rows = [$this->row(1, 'hetzner', 100), $this->row(2, 'aeza', 200)];
        $this->service->snapshot(true);
        $this->nextDay();
        $stale = $this->row(2, 'aeza', 500);
        $stale['synced_at'] = '2026-10-20T05:00:00+00:00';
        $this->service->rows = [$this->row(1, 'hetzner', 0), $stale];
        $report = $this->service->snapshot(true);
        self::assertSame('comparison_unknown', $report['status']);
        self::assertNull($report['increase_count']);
        self::assertSame(2, $report['unknown_count']);
        $this->nextDay();
        $this->service->rows = [$this->row(1, 'hetzner', 130), $this->row(2, 'aeza', 250)];
        $report = $this->service->snapshot();
        self::assertSame(2, $report['increase_count']);
        self::assertSame(200, $report['provider_increases'][0]['items'][0]['before']);
        self::assertSame('2026-10-29T05:00:00+00:00', $report['provider_increases'][0]['items'][0]['before_source_at']);
    }

    public function test_replaced_product_or_location_and_new_rate_are_not_price_increases(): void
    {
        $this->service->rows = [$this->row(1, 'hetzner', 100), $this->row(2, 'aeza', 200)];
        $this->service->snapshot(true);
        $this->nextDay();
        $replacement = $this->row(1, 'hetzner', 300);
        $replacement['provider_ref'] = 'new-product';
        $this->service->rows = [$replacement, $this->row(2, 'aeza', 200, 5000)];
        $report = $this->service->snapshot();
        self::assertSame(0, $report['increase_count']);
        self::assertSame(1, $report['new_plan_count']);
        self::assertSame(2, $report['unknown_count']);
        self::assertSame(1, $report['comparison_count']);
    }

    public function test_currency_or_scale_change_cannot_be_reported_as_numeric_increase(): void
    {
        $this->service->rows = [$this->row(1, 'hetzner', 100), $this->row(2, 'aeza', 200)];
        $this->service->snapshot(true);
        $state = json_decode(file_get_contents($this->path), true, 64, JSON_THROW_ON_ERROR);
        foreach ($state['latest'] as &$row) {
            if ($row['provider'] === 'hetzner') { $row['rates']['month']['currency'] = 'USD'; }
            else { $row['rates']['month']['scale'] = 1000000; }
        }
        unset($row);
        file_put_contents($this->path, json_encode($state, JSON_THROW_ON_ERROR));
        $this->nextDay();
        $this->service->rows = [$this->row(1, 'hetzner', 150), $this->row(2, 'aeza', 300)];
        $report = $this->service->snapshot();
        self::assertNull($report['increase_count']);
        self::assertSame(0, $report['comparison_count']);
        self::assertSame(2, $report['unknown_count']);
        self::assertSame([], $report['provider_increases']);
    }

    public function test_provider_and_item_details_are_bounded_while_total_count_is_preserved(): void
    {
        $rows = [];
        for ($provider = 1; $provider <= 14; $provider++) {
            for ($plan = 1; $plan <= 5; $plan++) { $rows[] = $this->row($provider * 10 + $plan, 'provider'.$provider, 100); }
        }
        $this->service->rows = $rows;
        $this->service->snapshot(true);
        $this->nextDay();
        foreach ($rows as &$row) { $row['cost_eur_cents'] = 150; $row['synced_at'] = $this->service->at; }
        unset($row);
        $this->service->rows = $rows;
        $report = $this->service->snapshot();
        self::assertSame(70, $report['increase_count']);
        self::assertSame(14, $report['provider_count']);
        self::assertCount(ManagementCostChanges::MAX_PROVIDERS, $report['provider_increases']);
        self::assertTrue($report['truncated']);
        foreach ($report['provider_increases'] as $provider) {
            self::assertSame(5, $provider['increase_count']);
            self::assertCount(ManagementCostChanges::ITEMS_PER_PROVIDER, $provider['items']);
        }
    }

    public function test_corrupt_state_is_unknown_and_is_not_overwritten_by_a_preview_or_commit(): void
    {
        mkdir(dirname($this->path), 0700, true);
        file_put_contents($this->path, 'invalid private baseline');
        $before = file_get_contents($this->path);
        foreach ([false, true] as $persist) {
            $report = $this->service->snapshot($persist);
            self::assertFalse($report['available']);
            self::assertNull($report['increase_count']);
            self::assertSame($before, file_get_contents($this->path));
            self::assertStringNotContainsString($this->directory, json_encode($report));
        }
    }

    private function nextDay(): void
    {
        $this->service->at = (new DateTimeImmutable($this->service->at))->modify('+1 day')->format(DATE_ATOM);
    }

    private function row(int $id, string $provider, ?int $monthly, ?int $hourly = null): array
    {
        return ['id' => $id, 'provider' => $provider, 'provider_ref' => 'type'.$id,
            'provider_location' => 'zone-1', 'public_name' => 'Synthetic plan '.$id,
            'cost_eur_cents' => $monthly, 'cost_hour_eur_micro' => $hourly,
            'synced_at' => $this->service->at];
    }
}

class CatalogCostFixture extends ManagementCostChanges
{
    public string $at;
    public array $rows = [];

    protected function clock(): DateTimeImmutable { return new DateTimeImmutable($this->at); }
    protected function catalogRows(): array { return $this->rows; }
}
