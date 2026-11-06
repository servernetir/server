<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Read-only BSC USDT watcher. Database amounts remain integer micro-USDT (6 decimals). */
class BscWatcher
{
    public const USDT_CONTRACT = '0x55d398326f99059ff775485246999027b3197955';

    public const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    private float $deadline = 0;

    public const RPC = 'https://bsc-rpc.publicnode.com';

    public function deposits(string $address, string $asset, int $since): array
    {
        $this->deadline = microtime(true) + 40;
        if ($asset !== 'USDT' || ! preg_match('/^0x[0-9a-fA-F]{40}$/D', $address)) {
            return [];
        }

        if ($this->rpc('eth_chainId', []) !== '0x38') {
            throw new \RuntimeException('BSC RPC chain mismatch');
        }

        $decimals = $this->rpc('eth_call', [['to' => self::USDT_CONTRACT, 'data' => '0x313ce567'], 'latest']);
        if (! is_string($decimals) || ! preg_match('/^0x0*12$/iD', $decimals)) {
            throw new \RuntimeException('BSC USDT decimal mismatch');
        }

        // Require economic finality, never silently substitute the latest head.
        $head = $this->rpc('eth_getBlockByNumber', ['finalized', false]);
        $last = $this->quantity($head['number'] ?? null);
        $headTime = $this->quantity($head['timestamp'] ?? null);
        if ($headTime < time() - 300 || $headTime > time() + 120) {
            throw new \RuntimeException('BSC finalized head is stale');
        }

        $first = Cache::remember('crypto:bsc:start:'.$since, 14400, function () use ($since, $last) {
            $low = 0;
            $high = $last;
            while ($low < $high) {
                $mid = intdiv($low + $high, 2);
                $block = $this->rpc('eth_getBlockByNumber', ['0x'.dechex($mid), false]);
                if ($this->quantity($block['timestamp'] ?? null) < $since) {
                    $low = $mid + 1;
                } else {
                    $high = $mid;
                }
            }

            return $low;
        });

        if ($last - $first > 160000) {
            throw new \RuntimeException('BSC scan window exceeds safe limit');
        }

        $to = '0x'.str_pad(strtolower(substr($address, 2)), 64, '0', STR_PAD_LEFT);
        $transactions = [];
        for ($start = $first; $start <= $last; $start += 4000) {
            $logs = $this->rpc('eth_getLogs', [[
                'fromBlock' => '0x'.dechex($start), 'toBlock' => '0x'.dechex(min($last, $start + 3999)),
                'address' => self::USDT_CONTRACT, 'topics' => [self::TRANSFER_TOPIC, null, $to],
            ]]);
            if (! is_array($logs) || ! array_is_list($logs)) {
                throw new \RuntimeException('Invalid BSC log response');
            }
            foreach ($logs as $log) {
                if ($this->matches($log, $to) && $this->quantity($log['blockNumber'] ?? null) <= $last
                    && preg_match('/^0x[0-9a-fA-F]{64}$/D', $log['transactionHash'] ?? '')) {
                    $transactions[strtolower($log['transactionHash'])] = true;
                }
            }
        }

        $out = [];
        foreach (array_keys($transactions) as $txid) {
            $receipt = $this->rpc('eth_getTransactionReceipt', [$txid]);
            if (! is_array($receipt) || ($receipt['status'] ?? '') !== '0x1'
                || strtolower($receipt['transactionHash'] ?? '') !== $txid) {
                continue;
            }
            $number = $this->quantity($receipt['blockNumber'] ?? null);
            if ($number > $last || $number < $first) {
                continue;
            }
            $block = $this->rpc('eth_getBlockByNumber', ['0x'.dechex($number), false]);
            if (! isset($receipt['blockHash'], $block['hash'])
                || strtolower($receipt['blockHash']) !== strtolower($block['hash'])) {
                continue;
            }
            $timestamp = $this->quantity($block['timestamp'] ?? null);
            if ($timestamp < $since) {
                continue;
            }
            $amount = 0;
            $seen = [];
            foreach ($receipt['logs'] ?? [] as $log) {
                if (! $this->matches($log, $to) || strtolower($log['transactionHash'] ?? '') !== $txid
                    || strtolower($log['blockHash'] ?? '') !== strtolower($block['hash'])) {
                    continue;
                }
                $index = $this->quantity($log['logIndex'] ?? null);
                if (isset($seen[$index])) {
                    continue;
                }
                $seen[$index] = true;
                $micro = self::microAmount($log['data'] ?? '');
                if ($micro === null || $amount > PHP_INT_MAX - $micro) {
                    throw new \RuntimeException('BSC token amount exceeds accounting range');
                }
                $amount += $micro;
            }
            if ($amount > 0) {
                $out[] = ['txid' => $txid, 'amount' => $amount, 'decimals' => 6, 'asset' => 'USDT',
                    'confirmed' => true, 'confirmations' => $last - $number + 1, 'timestamp' => $timestamp];
            }
        }

        return $out;
    }

    private function matches(array $log, string $to): bool
    {
        return ($log['removed'] ?? true) === false
            && strtolower($log['address'] ?? '') === self::USDT_CONTRACT
            && count($log['topics'] ?? []) === 3
            && strtolower($log['topics'][0] ?? '') === self::TRANSFER_TOPIC
            && strtolower($log['topics'][2] ?? '') === $to;
    }

    /** uint256 hexadecimal -> micro-USDT. No float, BCMath, or schema changes. Submicro dust is rounded down. */
    public static function microAmount(string $hex): ?int
    {
        if (! preg_match('/^0x[0-9a-fA-F]{64}$/D', $hex)) {
            return null;
        }
        $decimal = '0';
        foreach (str_split(substr($hex, 2)) as $digit) {
            $carry = hexdec($digit);
            $next = '';
            for ($i = strlen($decimal) - 1; $i >= 0; $i--) {
                $value = ((int) $decimal[$i]) * 16 + $carry;
                $next = ($value % 10).$next;
                $carry = intdiv($value, 10);
            }
            $decimal = ($carry ? (string) $carry : '').$next;
        }
        $micro = strlen($decimal) > 12 ? substr($decimal, 0, -12) : '0';
        $max = (string) PHP_INT_MAX;
        if (strlen($micro) > strlen($max) || (strlen($micro) === strlen($max) && strcmp($micro, $max) > 0)) {
            return null;
        }

        return (int) $micro;
    }

    private function quantity(mixed $hex): int
    {
        if (! is_string($hex) || ! preg_match('/^0x[0-9a-fA-F]{1,12}$/D', $hex)) {
            throw new \RuntimeException('Invalid BSC quantity');
        }

        return (int) hexdec(substr($hex, 2));
    }

    private function rpc(string $method, array $params): mixed
    {
        $left = $this->deadline - microtime(true);
        if ($left <= 0) {
            throw new \RuntimeException('BSC scan deadline exceeded');
        }
        $response = Http::connectTimeout(min(4, $left))->timeout(min(10, $left))->post(self::RPC, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params,
        ])->throw()->json();
        if (! is_array($response) || isset($response['error']) || ! array_key_exists('result', $response)) {
            throw new \RuntimeException('BSC RPC request failed: '.$method);
        }

        return $response['result'];
    }
}
