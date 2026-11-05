<?php

namespace App\Services\Hermes;

/**
 * Executes exactly one read-only Growth HMAC observation cycle.
 *
 * The returned receipt never includes the response data or an HMAC key. It
 * records only action names, response-data digests and a bounded error code.
 */
final class GrowthReadCycle
{
    private \Closure $clock;

    public function __construct(
        private readonly HermesGrowthReadClient $client,
        ?callable $clock = null,
    ) {
        $this->clock = \Closure::fromCallable($clock ?? static fn (): int => time());
    }

    /** @return array<string, mixed> */
    public function run(string $runId): array
    {
        if (preg_match('/^hg-[0-9]{8}T[0-9]{6}Z-[a-zA-Z0-9_-]{4,40}$/D', $runId) !== 1) {
            throw new \InvalidArgumentException('growth_run_id_invalid');
        }

        $startedAt = $this->timestamp();
        $completed = [];

        foreach (['curriculum', 'oversight'] as $action) {
            try {
                $data = $this->client->{$action}();
                $completed[] = [
                    'action' => $action,
                    'data_sha256' => hash('sha256', $this->encodeData($data)),
                ];
            } catch (\Throwable $exception) {
                return [
                    'schema' => 'servernet-growth-read-receipt-v1',
                    'run_id' => $runId,
                    'status' => 'unknown',
                    'started_at' => $startedAt,
                    'completed_at' => $this->timestamp(),
                    'completed_actions' => $completed,
                    'failed_action' => $action,
                    'error' => self::safeError($exception),
                    'retry_allowed' => false,
                ];
            }
        }

        return [
            'schema' => 'servernet-growth-read-receipt-v1',
            'run_id' => $runId,
            'status' => 'accepted',
            'started_at' => $startedAt,
            'completed_at' => $this->timestamp(),
            'completed_actions' => $completed,
            'retry_allowed' => false,
        ];
    }

    /** @param array<string, mixed> $data */
    private function encodeData(array $data): string
    {
        return json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }

    private function timestamp(): string
    {
        $epoch = ($this->clock)();
        if (! is_int($epoch) || $epoch < 1) {
            throw new \RuntimeException('growth_clock_invalid');
        }

        return gmdate('Y-m-d\TH:i:s\Z', $epoch);
    }

    private static function safeError(\Throwable $exception): string
    {
        $message = $exception->getMessage();

        return preg_match('/^growth_[a-z0-9_]+$/D', $message) === 1
            ? $message
            : 'growth_cycle_failed';
    }
}
