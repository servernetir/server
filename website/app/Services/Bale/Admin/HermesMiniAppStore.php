<?php

namespace App\Services\Bale\Admin;

use Illuminate\Support\Facades\Crypt;

/** Bounded owner data, encrypted at rest, outside webroot; atomic writes under flock. */
final class HermesMiniAppStore
{
    public function __construct(private ?string $directory = null) {}

    public function transact(array $owner, callable $operation): mixed
    {
        $dir = $this->directory ?? storage_path('app/private/hermes-miniapp');
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Hermes storage unavailable');
        }
        $path = $dir.'/'.hash('sha256', (string) $owner['chat_id'].':'.(string) $owner['user_id']);
        $lock = fopen($path.'.lock', 'c');
        if (! $lock) { throw new \RuntimeException('Hermes lock unavailable'); }
        chmod($path.'.lock', 0600);
        $tmp = null;
        try {
            if (! flock($lock, LOCK_EX)) { throw new \RuntimeException('Hermes lock unavailable'); }
            $data = is_file($path)
                ? json_decode(Crypt::decryptString(file_get_contents($path)), true, 64, JSON_THROW_ON_ERROR)
                : ['version' => 1, 'messages' => [], 'memories' => [], 'requests' => []];
            if (($data['version'] ?? null) !== 1 || ! is_array($data['messages'] ?? null)
                || ! is_array($data['memories'] ?? null)) { throw new \RuntimeException('Hermes storage invalid'); }
            $before = $data;
            $data['requests'] ??= [];
            $result = $operation($data);
            $data['messages'] = array_slice($data['messages'], -100);
            if ($data === $before && is_file($path)) { return $result; }
            $encoded = Crypt::encryptString(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $tmp = tempnam($dir, '.write-');
            if (! $tmp || file_put_contents($tmp, $encoded) !== strlen($encoded)) {
                throw new \RuntimeException('Hermes storage write failed');
            }
            chmod($tmp, 0600);
            if (! rename($tmp, $path)) { throw new \RuntimeException('Hermes storage commit failed'); }
            return $result;
        } finally {
            if ($tmp && is_file($tmp)) { unlink($tmp); }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function read(array $owner): array
    {
        return $this->transact($owner, static fn (array &$data) => $data);
    }
}
