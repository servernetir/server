<?php

namespace App\Services\Hermes;

use Illuminate\Support\Facades\Crypt;

/** Owner-scoped, encrypted durable state. No customer content is stored in public logs. */
final class HermesOperationsStore
{
    public function __construct(private ?string $directory = null) {}

    public function change(array $owner, callable $operation): mixed
    {
        if (! ctype_digit((string) ($owner['user_id'] ?? '')) || ! ctype_digit((string) ($owner['chat_id'] ?? ''))) {
            throw new \InvalidArgumentException('Invalid owner');
        }
        $directory = $this->directory ?? storage_path('app/private/hermes-operations');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Operations unavailable');
        }
        chmod($directory, 0700);
        $path = $directory.'/'.hash('sha256', $owner['user_id'].':'.$owner['chat_id']);
        if (is_link($path) || is_link($path.'.lock')) { throw new \RuntimeException('Invalid operations path'); }
        $lock = fopen($path.'.lock', 'c+b');
        if (! $lock) { throw new \RuntimeException('Operations lock unavailable'); }
        chmod($path.'.lock', 0600);
        $tmp = null;
        try {
            if (! flock($lock, LOCK_EX)) { throw new \RuntimeException('Operations lock unavailable'); }
            $state = is_file($path)
                ? json_decode(Crypt::decryptString(file_get_contents($path)), true, 64, JSON_THROW_ON_ERROR)
                : ['version' => 1, 'tasks' => [], 'rules' => [], 'alerts' => [], 'scans' => [], 'receipts' => [], 'digests' => []];
            if (($state['version'] ?? null) !== 1) { throw new \RuntimeException('Invalid operations state'); }
            $before = $state;
            $result = $operation($state);
            if ($state !== $before || ! is_file($path)) {
                if (count($state['tasks']) > 2000 || count($state['rules']) > 200 || count($state['alerts']) > 5000) {
                    throw new \LengthException('Operations retention limit; archive before adding more');
                }
                $state['receipts'] = array_slice($state['receipts'], -1000, null, true);
                $state['digests'] = array_slice($state['digests'], -40, null, true);
                $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                if (strlen($json) > 12 * 1024 * 1024) { throw new \LengthException('Operations state limit'); }
                $encoded = Crypt::encryptString($json);
                $tmp = tempnam($directory, '.write-');
                if (! $tmp) { throw new \RuntimeException('Operations write unavailable'); }
                chmod($tmp, 0600);
                if (file_put_contents($tmp, $encoded) !== strlen($encoded) || ! rename($tmp, $path)) {
                    throw new \RuntimeException('Operations commit failed');
                }
            }
            return $result;
        } finally {
            if ($tmp && is_file($tmp)) { unlink($tmp); }
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    public function read(array $owner): array
    {
        return $this->change($owner, static fn (array &$state): array => $state);
    }
}
