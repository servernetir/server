<?php

namespace App\Services\Ai;

use App\Models\AiModel;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/** Short-lived supplier access to admitted MP4 bytes; never fetches customer URLs. */
final class AiMediaRelay
{
    public const TTL_SECONDS = 900;
    private const MODELS = ['Bria/video_increase_resolution', 'Bria/video_remove_background',
        'Bria/video_eraser', 'Bria/video_foreground_mask', 'Bria/video_mask_by_key_points', 'Bria/video_mask_by_prompt'];

    public static function requiresRelay(AiModel $model): bool
    {
        return $model->provider?->slug === 'deepinfra' && in_array($model->upstream_model, self::MODELS, true);
    }

    /** Only the driver calls this after financial reservation, never admission or replay. */
    public function preparePayload(array $payload): array
    {
        $inputs = [];
        foreach (['video', 'mask'] as $field) {
            if (! isset($payload[$field])) continue;
            $value = $payload[$field];
            if (! is_string($value) || strlen($value) > 8_000_000
                || ! preg_match('~^data:video/mp4;base64,([A-Za-z0-9+/=]+)$~D', $value, $match)
                || ($bytes = base64_decode($match[1], true)) === false) {
                throw new RuntimeException('Supplier media relay requires validated inline MP4 input.');
            }
            app(AiVideoInput::class)->validate($bytes);
            $inputs[$field] = $bytes;
        }
        if (! isset($inputs['video'])) throw new RuntimeException('Supplier media relay requires video input.');
        // Validate every input before creating any file. A mutex coordinates creation and expiry cleanup.
        return $this->locked(function (string $directory) use ($payload, $inputs) {
            $this->prune($directory);
            $expires = now()->getTimestamp() + self::TTL_SECONDS;
            $created = [];
            try {
                foreach ($inputs as $field => $bytes) {
                    $id = bin2hex(random_bytes(32));
                    $path = $directory.'/'.$expires.'-'.$id.'.mp4';
                    $handle = @fopen($path, 'xb');
                    if ($handle === false) throw new RuntimeException('Supplier media relay is unavailable.');
                    $created[] = $path;
                    try {
                        if (! chmod($path, 0600) || fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle)) {
                            throw new RuntimeException('Supplier media relay is unavailable.');
                        }
                    } finally { fclose($handle); }
                    // Relative signatures work on the public site and console host; no request Host is trusted.
                    $relative = URL::temporarySignedRoute('ai.media.relay', now()->setTimestamp($expires), ['id' => $id], absolute: false);
                    $payload[$field] = 'https://servernet.cloud'.$relative;
                }
                return $payload;
            } catch (\Throwable $e) {
                foreach ($created as $path) @unlink($path);
                throw $e;
            }
        });
    }

    /** An open descriptor remains readable if later expiry cleanup unlinks the file. */
    public function open(string $id, int $expires): array
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $id) || $expires <= now()->getTimestamp()
            || $expires > now()->getTimestamp() + self::TTL_SECONDS) {
            throw new RuntimeException('Supplier media relay has expired.');
        }
        return $this->locked(function (string $directory) use ($id, $expires) {
            $path = $directory.'/'.$expires.'-'.$id.'.mp4';
            if (is_link($path) || ! is_file($path) || realpath($path) !== $path) {
                throw new RuntimeException('Supplier media relay is unavailable.');
            }
            $handle = @fopen($path, 'rb');
            if ($handle === false) throw new RuntimeException('Supplier media relay is unavailable.');
            $stat = fstat($handle);
            if (! $stat || $stat['size'] < 40 || $stat['size'] > 6_000_000) {
                fclose($handle);
                throw new RuntimeException('Supplier media relay is unavailable.');
            }
            return ['handle' => $handle, 'size' => $stat['size']];
        });
    }

    public function cleanupExpired(): int
    {
        if (! is_dir(storage_path('app/private/ai-media/relay'))) return 0;
        return $this->locked(fn (string $directory) => $this->prune($directory));
    }

    private function prune(string $directory): int
    {
        $count = 0;
        foreach (new \DirectoryIterator($directory) as $file) {
            if (! $file->isFile() || $file->isLink()
                || ! preg_match('/^([0-9]{10})-[a-f0-9]{64}\.mp4$/D', $file->getFilename(), $match)
                || (int) $match[1] > now()->getTimestamp()) continue;
            if (@unlink($file->getPathname())) $count++;
        }
        return $count;
    }

    private function locked(callable $operation): mixed
    {
        $private = storage_path('app/private');
        if (is_link($private) || (! is_dir($private) && ! @mkdir($private, 0700, true))) {
            throw new RuntimeException('Supplier media relay is unavailable.');
        }
        $directory = realpath($private);
        if ($directory === false) throw new RuntimeException('Supplier media relay is unavailable.');
        foreach (['ai-media', 'relay'] as $segment) {
            $directory .= '/'.$segment;
            if (is_link($directory) || (! is_dir($directory) && ! @mkdir($directory, 0700))) {
                throw new RuntimeException('Supplier media relay is unavailable.');
            }
        }
        if (realpath($directory) !== $directory || ! chmod($directory, 0700) || is_link($directory.'/.lock')) {
            throw new RuntimeException('Supplier media relay is unavailable.');
        }
        $lock = @fopen($directory.'/.lock', 'c+b');
        if ($lock === false) throw new RuntimeException('Supplier media relay is unavailable.');
        try {
            if (! chmod($directory.'/.lock', 0600) || ! flock($lock, LOCK_EX)) {
                throw new RuntimeException('Supplier media relay is unavailable.');
            }
            return $operation($directory);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
