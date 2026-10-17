<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use ServerNet\Registrar\PriceImportTransport;
use Tests\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class WhmcsMinimumPeriodPricingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require base_path('tests/Fixtures/whmcs-price-import.php');
        define('WHMCS', true);
        require resource_path('whmcs/servernet/servernet.php');
    }

    public function test_importer_keeps_minimum_term_totals_and_real_free_transfer(): void
    {
        PriceImportTransport::$rows = [['tld' => 'ai', 'min_years' => 2, 'max_years' => 10,
            'register' => 3000000, 'renew' => 4000000, 'transfer' => 0]];
        $items = \servernet_GetTldPricing(['ApiToken' => 'fixture', 'currency' => 'IRT']);

        $this->assertCount(1, $items);
        $this->assertSame(2, $items[0]->values['setMinYears']);
        $this->assertSame(10, $items[0]->values['setMaxYears']);
        $this->assertSame(3000000, $items[0]->values['setRegisterPrice']);
        $this->assertSame(4000000, $items[0]->values['setRenewPrice']);
        $this->assertSame(0, $items[0]->values['setTransferPrice']);
        $this->assertSame(1, PriceImportTransport::$calls);
    }

    public function test_importer_never_invents_missing_actions_or_periods(): void
    {
        $complete = ['tld' => 'test', 'min_years' => 1, 'max_years' => 10,
            'register' => 3000000, 'renew' => 4000000, 'transfer' => 2000000];
        PriceImportTransport::$rows = [
            ['renew' => null] + $complete,
            ['transfer' => null] + $complete,
            ['min_years' => null] + $complete,
            ['min_years' => 2.5] + $complete,
            ['min_years' => 2, 'max_years' => 1] + $complete,
        ];
        $items = \servernet_GetTldPricing(['ApiToken' => 'fixture']);
        $this->assertCount(2, $items);
        $this->assertSame(3000000, $items[0]->values['setRegisterPrice']);
        $this->assertNull($items[0]->values['setRenewPrice']);
        $this->assertNull($items[1]->values['setTransferPrice']);
        $this->assertSame(1, PriceImportTransport::$calls);
    }
}
