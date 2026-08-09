<?php
namespace App\Console\Commands;
use App\Services\Provisioner;
use Illuminate\Console\Command;
class ProvisionVm extends Command
{
    protected $signature = 'vm:provision {name} {--cores=2} {--memory=2048} {--os=ubuntu2204} {--country=}';
    protected $description = 'Provision a customer VM (auto IP + public port). --os is an os.catalog key; --country is an optional exit country (de/nl/fi...) that routes the VM outbound via that country.';
    public function handle(Provisioner $prov): int
    {
        try {
            $out = $prov->sell(
                $this->argument('name'),
                (int) $this->option('cores'),
                (int) $this->option('memory'),
                $this->option('os'),
                null,
                $this->option('country') ?: null,
            );
        } catch (\Throwable $e) {
            $this->error('FAILED: ' . $e->getMessage());
            return self::FAILURE;
        }
        $this->info('VM ' . $out['vmid'] . ' ready (' . $out['os_label'] . ($out['exit_country'] ? ', exit=' . $out['exit_country'] : '') . ')');
        $this->line('access:   ' . $out['access']);
        $this->line('internal: ' . $out['ip']);
        $this->line('user:     ' . $out['ciuser']);
        $this->line('password: ' . $out['password']);
        if ($out['exit_country']) {
            $this->line('exit:     ' . $out['exit_country'] . ' (applied by the country-routing agent within ~1 min)');
        }
        return self::SUCCESS;
    }
}
