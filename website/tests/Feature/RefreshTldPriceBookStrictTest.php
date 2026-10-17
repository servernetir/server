<?php

namespace Tests\Feature;

use App\Services\Domain\TldPriceBook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefreshTldPriceBookStrictTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_prices_succeed_in_strict_mode(): void
    {
        $this->mock(TldPriceBook::class)->shouldReceive('refreshAll')->once()
            ->andReturn(['count' => 1024, 'total' => 1025, 'excluded' => 1]);

        $this->artisan('domains:price-book --strict')->assertSuccessful();
    }

    public function test_missing_prices_fail_in_strict_mode(): void
    {
        $this->mock(TldPriceBook::class)->shouldReceive('refreshAll')->once()
            ->andThrow(new \RuntimeException('Openprovider catalogue pagination is incomplete.'));

        $this->artisan('domains:price-book --strict')->assertFailed();
    }

    public function test_exception_fails_in_strict_mode(): void
    {
        $this->mock(TldPriceBook::class)->shouldReceive('refreshAll')->once()
            ->andThrow(new \RuntimeException('Test quote unavailable'));

        $this->artisan('domains:price-book --strict')->assertFailed();
    }

    public function test_legacy_command_without_strict_option_also_rejects_incomplete_refresh(): void
    {
        $this->mock(TldPriceBook::class)->shouldReceive('refreshAll')->once()
            ->andThrow(new \RuntimeException('Openprovider catalogue pagination is incomplete.'));

        $this->artisan('domains:price-book')->assertFailed();
    }
}
