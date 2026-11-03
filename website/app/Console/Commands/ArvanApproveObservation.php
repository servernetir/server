<?php

namespace App\Console\Commands;

use App\Services\Cloud\ArvanObservationApprovalFile;
use App\Services\Cloud\ArvanObservationRegistry;
use App\Services\Cloud\UnixPrometheusTrafficTransport;
use Illuminate\Console\Command;
use Throwable;

/** No customer route or automatic enrollment/transport enablement. */
final class ArvanApproveObservation extends Command
{
    protected $signature = 'cloud:approve-arvan-observation
        {--inspect-pin : Print only the fixed local Unix socket metadata; changes no approval}
        {--descriptor= : Exact account-private JSON descriptor path}
        {--sha256= : Reviewed descriptor SHA-256}';
    protected $description = 'Approve explicitly reviewed Arvan observation transport or enrollment';

    public function handle(): int
    {
        try {
            if ($this->option('inspect-pin')) {
                if ($this->option('descriptor') || $this->option('sha256')) {
                    throw new \RuntimeException('approval_arguments_invalid');
                }
                $result = ['status' => 'local_pin_only_no_coverage', 'pin' => UnixPrometheusTrafficTransport::capture(base_path())];
            } else {
                $path = (string) $this->option('descriptor');
                $sha = (string) $this->option('sha256');
                $descriptor = ArvanObservationApprovalFile::read(base_path(), $path, $sha);
                $result = (new ArvanObservationRegistry(base_path()))->approve($descriptor);
            }
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('observation_approval_refused');
            return self::FAILURE;
        }
    }
}
