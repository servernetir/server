<?php

declare(strict_types=1);

namespace App\Services\Cloud;

use RuntimeException;

/** One hash-reviewed account-private JSON file; it cannot choose a transport path. */
final class ArvanObservationApprovalFile
{
    public static function read(string $root, string $path, string $sha256): array
    {
        $directory = $root.'/storage/app/private/arvan-observation';
        if (!ArvanObservationState::sha($sha256) || !str_starts_with($path, $directory.'/')
            || dirname($path) !== $directory || realpath($root) !== $root
            || realpath($directory) !== $directory || realpath($path) !== $path
            || !function_exists('posix_geteuid') || !function_exists('posix_getuid') || posix_getuid() !== posix_geteuid()) {
            throw new RuntimeException('approval_file_path_invalid');
        }
        clearstatcache(true, $directory); $parent = lstat($directory);
        clearstatcache(true, $path); $before = lstat($path);
        if (!$parent || ($parent['mode'] & 0177777) !== 0040700 || $parent['uid'] !== posix_geteuid()
            || !$before || ($before['mode'] & 0177777) !== 0100600 || $before['uid'] !== posix_geteuid()
            || $before['nlink'] !== 1 || $before['size'] > ArvanObservationState::MAX_RECORD_BYTES) {
            throw new RuntimeException('approval_file_metadata_invalid');
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) { throw new RuntimeException('approval_file_unavailable'); }
        try {
            $opened = fstat($stream);
            $raw = stream_get_contents($stream, ArvanObservationState::MAX_RECORD_BYTES + 1);
            $after = fstat($stream);
        } finally { fclose($stream); }
        clearstatcache(true, $path); $named = lstat($path);
        foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
            if (!$opened || !$after || !$named || $before[$field] !== $opened[$field] || $before[$field] !== $after[$field]
                || $before[$field] !== $named[$field]) { throw new RuntimeException('approval_file_changed'); }
        }
        if (!is_string($raw) || strlen($raw) !== $before['size'] || !hash_equals($sha256, hash('sha256', $raw))) {
            throw new RuntimeException('approval_file_hash_changed');
        }
        return ArvanObservationState::decode($raw) ?? throw new RuntimeException('approval_file_invalid');
    }
}
