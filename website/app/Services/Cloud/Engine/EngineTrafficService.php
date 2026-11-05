<?php

namespace App\Services\Cloud\Engine;

use App\Models\EngineNode;
use App\Models\EngineVm;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Host-interface observations only. No invoices, wallet postings or automatic shutdown. */
class EngineTrafficService
{
    public function __construct(private ProxmoxTransport $transport) {}
    private function require(bool $condition, string $code): void
    {
        if (! $condition) {
            throw new InvalidArgumentException($code);
        }
    }

    private function add(int $left, int $right): int
    {
        $this->require($left >= 0 && $right >= 0 && $left <= PHP_INT_MAX - $right, 'traffic_counter_overflow');

        return $left + $right;
    }

    public function ingest(EngineNode $node, array $sample): array
    {
        foreach (['sequence', 'in_bytes', 'out_bytes'] as $key) {
            $this->require(is_int($sample[$key] ?? null) && $sample[$key] >= 0, 'traffic_integer_required');
        }
        $this->require($sample['sequence'] > 0, 'traffic_sequence_invalid');
        $this->require(is_string($sample['epoch'] ?? null)
            && preg_match('/\A[a-f0-9]{32,64}\z/D', $sample['epoch']) === 1, 'traffic_epoch_invalid');
        $this->require(is_string($sample['vm_uuid'] ?? null) && strlen($sample['vm_uuid']) <= 64, 'traffic_vm_invalid');
        $this->require(is_string($sample['sampled_at'] ?? null)
            && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $sample['sampled_at']) === 1, 'traffic_timestamp_invalid');
        try {
            $at = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $sample['sampled_at'], 'UTC');
        } catch (\Throwable) {
            throw new InvalidArgumentException('traffic_timestamp_invalid');
        }
        $this->require($at && $at->format('Y-m-d\TH:i:s\Z') === $sample['sampled_at'], 'traffic_timestamp_invalid');
        $this->require($at->timestamp <= now()->timestamp + (int) config('cloud-engine.traffic.max_clock_skew_seconds', 30)
            && $at->timestamp >= now()->timestamp - 120, 'traffic_timestamp_stale_or_future');
        $canonical = array_intersect_key($sample, array_flip(['vm_uuid', 'interface', 'epoch', 'sequence', 'in_bytes', 'out_bytes', 'sampled_at']));
        ksort($canonical);
        $hash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($node, $sample, $at, $hash) {
            $vm = EngineVm::where('uuid', $sample['vm_uuid'])->lockForUpdate()->first();
            $this->require($vm && (int) $vm->node_id === (int) $node->id && ! in_array($vm->status, ['deleted', 'quarantined'], true), 'traffic_vm_not_bound');
            $this->require(($sample['interface'] ?? null) === 'tap'.$vm->vmid.'i0', 'traffic_interface_not_bound');
            $samples = DB::table('cloud_engine_traffic_samples');
            $existing = (clone $samples)->where('vm_id', $vm->id)->where('epoch', $sample['epoch'])->where('sequence', $sample['sequence'])->first();
            if ($existing) {
                $this->require(hash_equals($existing->payload_hash, $hash), 'traffic_duplicate_conflict');

                return ['status' => 'duplicate', 'quality' => $existing->quality, 'reason' => $existing->reason];
            }
            $cursor = DB::table('cloud_engine_traffic_cursors')->where('vm_id', $vm->id)->lockForUpdate()->first();
            if (! $cursor || $cursor->epoch !== $sample['epoch']) {
                $config = $this->transport->request($node, 'GET', '/nodes/'.rawurlencode($node->pve_node).'/qemu/'.$vm->vmid.'/config');
                $this->require(($config['ok'] ?? false) && is_array($config['data'])
                    && ($config['data']['name'] ?? null) === $vm->name
                    && ($config['data']['description'] ?? null) === EngineWorker::marker($vm)
                    && (string) ($config['data']['template'] ?? '0') === '0', 'traffic_remote_identity_not_verified');
            }
            $quality = 'baseline';
            $reason = 'initial_baseline_no_history';
            $in = $out = 0;
            if ($cursor) {
                $previous = CarbonImmutable::parse($cursor->sampled_at, 'UTC');
                $this->require($at->gt($previous), 'traffic_out_of_order');
                $quality = 'gap';
                if ($cursor->epoch !== $sample['epoch']) {
                    $this->require($sample['sequence'] === 1 && ! (clone $samples)->where('vm_id', $vm->id)->where('epoch', $sample['epoch'])->exists(), 'traffic_epoch_replay');
                    $reason = 'counter_epoch_changed';
                } else {
                    $this->require($sample['sequence'] > (int) $cursor->sequence, 'traffic_out_of_order');
                    if ($cursor->interface !== $sample['interface']) {
                        $reason = 'interface_changed';
                    } elseif ($sample['in_bytes'] < (int) $cursor->in_bytes || $sample['out_bytes'] < (int) $cursor->out_bytes) {
                        $reason = 'counter_decreased';
                    } elseif ($sample['sequence'] !== (int) $cursor->sequence + 1) {
                        $reason = 'sequence_gap';
                    } elseif ($at->timestamp - $previous->timestamp > (int) config('cloud-engine.traffic.max_interval_seconds', 120)) {
                        $reason = 'sampling_gap';
                    } elseif ($at->format('Y-m') !== $previous->format('Y-m')) {
                        $reason = 'month_boundary_unattributed';
                    } else {
                        $quality = 'observed';
                        $reason = 'contiguous_host_counter_delta';
                        $in = $sample['in_bytes'] - (int) $cursor->in_bytes;
                        $out = $sample['out_bytes'] - (int) $cursor->out_bytes;
                    }
                }
            } else {
                $this->require($sample['sequence'] === 1, 'traffic_initial_sequence_required');
            }
            $now = now();
            $state = ['interface' => $sample['interface'], 'epoch' => $sample['epoch'], 'sequence' => $sample['sequence'],
                'in_bytes' => $sample['in_bytes'], 'out_bytes' => $sample['out_bytes'], 'sampled_at' => $at];
            DB::table('cloud_engine_traffic_cursors')->updateOrInsert(['vm_id' => $vm->id], $state + ['created_at' => $cursor?->created_at ?? $now, 'updated_at' => $now]);
            $samples->insert(['vm_id' => $vm->id] + $state + ['payload_hash' => $hash, 'month' => $at->format('Y-m'),
                'in_delta' => $in, 'out_delta' => $out, 'quality' => $quality, 'reason' => $reason, 'created_at' => $now, 'updated_at' => $now]);
            $month = DB::table('cloud_engine_traffic_months')->where('vm_id', $vm->id)->where('month', $at->format('Y-m'))->lockForUpdate()->first();
            $reasons = $month ? json_decode($month->gap_reasons, true, 32, JSON_THROW_ON_ERROR) : [];
            if ($quality !== 'observed' && ! in_array($reason, $reasons, true)) {
                $reasons[] = $reason;
            }
            $monthIn = $this->add((int) ($month?->in_bytes ?? 0), $in);
            $monthOut = $this->add((int) ($month?->out_bytes ?? 0), $out);
            DB::table('cloud_engine_traffic_months')->updateOrInsert(['vm_id' => $vm->id, 'month' => $at->format('Y-m')], [
                'in_bytes' => $monthIn, 'out_bytes' => $monthOut, 'samples' => (int) ($month?->samples ?? 0) + 1,
                'gaps' => (int) ($month?->gaps ?? 0) + ($quality === 'observed' ? 0 : 1),
                'gap_reasons' => json_encode($reasons, JSON_THROW_ON_ERROR), 'first_sample_at' => $month?->first_sample_at ?? $at,
                'last_sample_at' => $at, 'created_at' => $month?->created_at ?? $now, 'updated_at' => $now,
            ]);
            $quota = data_get($vm->metadata, 'traffic_quota_bytes');
            if (is_int($quota) && $quota > 0) {
                $total = $this->add($monthIn, $monthOut);
                foreach (array_unique((array) config('cloud-engine.traffic.alert_thresholds', [80, 90, 100])) as $threshold) {
                    $this->require(is_int($threshold) && $threshold > 0 && $threshold <= 100, 'traffic_threshold_invalid');
                    $limit = intdiv($quota, 100) * $threshold + intdiv(($quota % 100) * $threshold + 99, 100);
                    if ($total >= $limit) {
                        DB::table('cloud_engine_traffic_alerts')->insertOrIgnore(['vm_id' => $vm->id, 'month' => $at->format('Y-m'),
                            'threshold' => $threshold, 'observed_bytes' => $total, 'quota_bytes' => $quota, 'created_at' => $now, 'updated_at' => $now]);
                    }
                }
            }

            return ['status' => 'accepted', 'quality' => $quality, 'reason' => $reason];
        }, 3);
    }

    public function report(EngineVm $vm, ?string $month = null): array
    {
        $month ??= now('UTC')->format('Y-m');
        $this->require(preg_match('/\A\d{4}-(?:0[1-9]|1[0-2])\z/D', $month) === 1, 'traffic_month_invalid');
        $row = DB::table('cloud_engine_traffic_months')->where('vm_id', $vm->id)->where('month', $month)->first();
        $in = (int) ($row?->in_bytes ?? 0);
        $out = (int) ($row?->out_bytes ?? 0);
        $gaps = (int) ($row?->gaps ?? 0);
        $count = (int) ($row?->samples ?? 0);
        $reasons = $row ? json_decode($row->gap_reasons, true, 32, JSON_THROW_ON_ERROR) : [];
        return ['month' => $month, 'timezone' => 'UTC', 'in_bytes' => $in, 'out_bytes' => $out,
            'unit_bytes' => ['GB' => 1000000000, 'GiB' => 1073741824], 'samples' => $count,
            'coverage' => $count === 0 ? 'unknown' : ($gaps > 0 ? 'incomplete' : 'observed_intervals_only'),
            'gaps' => $gaps, 'gap_reasons' => $reasons, 'billable' => false,
            'last_sample_at' => $row?->last_sample_at,
            'stale' => $month === now('UTC')->format('Y-m') && (! $row || CarbonImmutable::parse($row->last_sample_at, 'UTC')->timestamp < now()->timestamp - 120),
            'alerts' => DB::table('cloud_engine_traffic_alerts')->where('vm_id', $vm->id)->where('month', $month)
                ->orderBy('threshold')->pluck('threshold')->map(fn ($n) => (int) $n)->all()];
    }
}
