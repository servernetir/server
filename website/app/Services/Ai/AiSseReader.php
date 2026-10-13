<?php

namespace App\Services\Ai;

use Psr\Http\Message\StreamInterface;

/** Bounded SSE parser; EOF without [DONE] is a failure, never a free success. */
final class AiSseReader
{
    public static function events(StreamInterface $stream, int $timeout): \Generator
    {
        $buffer = '';
        $started = microtime(true);
        try {
            while (! $stream->eof()) {
                if (microtime(true) - $started > $timeout) {
                    throw new \RuntimeException('Stream deadline exceeded.');
                }
                $part = $stream->read(4096);
                if ($part === '' && ! $stream->eof()) {
                    throw new \RuntimeException('Stream stalled.');
                }
                $buffer .= $part;
                while (preg_match('/\r?\n\r?\n/', $buffer, $m, PREG_OFFSET_CAPTURE)) {
                    $pos = $m[0][1];
                    $event = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + strlen($m[0][0]));
                    if (strlen($event) > 262144) {
                        throw new \RuntimeException('Oversized stream event.');
                    }
                    $data = [];
                    foreach (preg_split('/\r?\n/', $event) as $line) {
                        if (str_starts_with($line, 'data:')) {
                            $data[] = ltrim(substr($line, 5), ' ');
                        }
                    }
                    if ($data === []) {
                        continue;
                    }
                    $raw = implode("\n", $data);
                    if ($raw === '[DONE]') {
                        return;
                    }
                    $json = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
                    if (! is_array($json) || array_is_list($json) || isset($json['error'])) {
                        throw new \RuntimeException('Invalid upstream stream event.');
                    }
                    yield [$json, $raw];
                }
                if (strlen($buffer) > 262144) {
                    throw new \RuntimeException('Oversized stream event.');
                }
            }
            throw new \RuntimeException('Stream ended without completion marker.');
        } finally {
            $stream->close();
        }
    }
}
