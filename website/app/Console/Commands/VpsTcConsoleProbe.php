<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Service;
use App\Services\Cloud\VpsTcConsole;
use Illuminate\Console\Command;

/** Creates an unused, expiring console grant; never changes guest/billing state. */
class VpsTcConsoleProbe extends Command
{
    protected $signature = 'cloud:vpstc-console-probe {service : Local owner service ID}';
    protected $description = 'Verify the owner-only console handshake without printing credentials or enabling customer access';

    public function handle(VpsTcConsole $console): int
    {
        $service = Service::find($this->argument('service'));
        if (! $service || (int) $service->customer_id !== 1
            || ! Customer::whereKey(1)->where('code', 'SN-457136')->where('status', 'active')->exists()) {
            $this->error('Owner service required.');
            return self::FAILURE;
        }
        try {
            $context = $console->context($service);
            if ($context === null) { throw new \RuntimeException; }
            $console->prepare($service, $context);
            $this->info('Console API handshake verified; browser framebuffer and input acceptance are still required.');
            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Console handshake not verified. No service or billing operation was performed.');
            return self::FAILURE;
        }
    }
}
