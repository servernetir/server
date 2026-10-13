<?php

namespace App\Services\Ai;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Symfony\Component\Process\Process;

/** Admission checks local, bounded MP4 input; customer URLs are never fetched. */
final class AiVideoInput
{
    public static function binary(): string
    {
        return (string) config('ai.media_ffmpeg_binary', storage_path('app/private/ai-media/bin/ffmpeg'));
    }

    public static function available(): bool
    {
        return is_file(self::binary()) && is_executable(self::binary()) && function_exists('proc_open');
    }

    public function inspect(string $bytes): array
    {
        if (strlen($bytes) < 40 || strlen($bytes) > 6_000_000) $this->invalid();
        $top = $this->boxes($bytes);
        if (count($top['ftyp'] ?? []) !== 1 || count($top['moov'] ?? []) !== 1 || empty($top['mdat'])
            || isset($top['moof']) || isset($top['sidx'])) $this->invalid();
        $movie = $this->boxes($top['moov'][0]);
        if (isset($movie['mvex']) || count($movie['trak'] ?? []) < 1 || count($movie['trak']) > 2) $this->invalid();
        $movieDuration = $this->duration($this->one($movie, 'mvhd'));
        $video = null; $audio = 0;
        foreach ($movie['trak'] as $trackBytes) {
            $track = $this->boxes($trackBytes);
            $mdia = $this->boxes($this->one($track, 'mdia'));
            $handler = $this->one($mdia, 'hdlr');
            $type = substr($handler, 8, 4);
            if (! in_array($type, ['vide', 'soun'], true)) $this->invalid();
            $seconds = $this->duration($this->one($mdia, 'mdhd'));
            if (BigDecimal::of($seconds)->isGreaterThan('10.1')) $this->invalid();
            $minf = $this->boxes($this->one($mdia, 'minf'));
            $table = $this->boxes($this->one($minf, 'stbl'));
            $sizes = $this->one($table, 'stsz');
            if (strlen($sizes) < 12) $this->invalid();
            $samples = $this->integer($sizes, 8);
            $sampleSize = $this->integer($sizes, 4);
            if ($samples < 1 || $samples > ($type === 'vide' ? 600 : 3000)
                || strlen($sizes) !== 12 + ($sampleSize === 0 ? $samples * 4 : 0)) $this->invalid();
            $timing = $this->one($table, 'stts');
            if (strlen($timing) < 8) $this->invalid();
            $entries = $this->integer($timing, 4);
            if ($entries < 1 || $entries > $samples || strlen($timing) !== 8 + $entries * 8) $this->invalid();
            $timedSamples = 0; $ticks = BigDecimal::zero();
            for ($i = 0; $i < $entries; $i++) {
                $count = $this->integer($timing, 8 + $i * 8);
                $delta = $this->integer($timing, 12 + $i * 8);
                if ($count < 1 || $delta < 1) $this->invalid();
                $timedSamples += $count;
                $ticks = $ticks->plus(BigDecimal::of($count)->multipliedBy($delta));
            }
            if ($timedSamples !== $samples) $this->invalid();
            $scale = $this->integer($this->one($mdia, 'mdhd'), 12);
            if ($ticks->dividedBy($scale, 6, RoundingMode::Up)->minus($seconds)->abs()->isGreaterThan('0.1')) $this->invalid();
            $description = $this->one($table, 'stsd');
            if (strlen($description) < 16 || $this->integer($description, 4) !== 1) $this->invalid();
            $codec = substr($description, 12, 4);
            if ($type === 'soun') {
                if (++$audio > 1 || $codec !== 'mp4a') $this->invalid();
                continue;
            }
            $header = $this->one($track, 'tkhd');
            if (strlen($header) < 8 || $video !== null || ! in_array($codec, ['avc1', 'avc3'], true)) $this->invalid();
            $width = intdiv($this->integer($header, strlen($header) - 8), 65536);
            $height = intdiv($this->integer($header, strlen($header) - 4), 65536);
            if ($width < 1 || $height < 1 || $width > 1920 || $height > 1920 || $width * $height > 2_097_152
                || BigDecimal::of($seconds)->isLessThan('0.1')
                || BigDecimal::of($samples)->isGreaterThan(BigDecimal::of($seconds)->multipliedBy(60)->plus(1))) $this->invalid();
            $video = ['seconds' => $seconds, 'width' => $width, 'height' => $height, 'frames' => $samples];
        }
        if (! $video || BigDecimal::of($movieDuration)->isGreaterThan('10.1')
            || BigDecimal::of($movieDuration)->isLessThan(BigDecimal::of($video['seconds'])->minus('0.1'))) $this->invalid();
        return $video;
    }

    public function validate(string $bytes): array
    {
        $info = $this->inspect($bytes);
        if (! self::available()) throw new AiRequestException('native_decoder_unavailable', 'Video input validation is unavailable.', 503);
        $directory = storage_path('app/private/ai-media/tmp');
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new AiRequestException('native_decoder_unavailable', 'Video input validation is unavailable.', 503);
        }
        $path = tempnam($directory, 'input-');
        if ($path === false) throw new AiRequestException('native_decoder_unavailable', 'Video input validation is unavailable.', 503);
        try {
            chmod($path, 0600);
            if (file_put_contents($path, $bytes) !== strlen($bytes)) $this->invalid();
            $process = new Process([self::binary(), '-nostdin', '-hide_banner', '-v', 'info', '-nostats', '-xerror', '-max_alloc', '16777216',
                '-max_pixels', '2097152', '-codec_whitelist', 'h264,aac',
                '-threads', '1', '-protocol_whitelist', 'file,pipe', '-enable_drefs', '0', '-use_absolute_path', '0', '-f', 'mov', '-i', $path,
                '-map', '0:v:0', '-map', '0:a:0?', '-threads', '1', '-fps_mode', 'passthrough',
                '-filter_threads', '1', '-vf', 'showinfo', '-progress', 'pipe:1', '-f', 'null', '-'], timeout: 20);
            try { $process->run(); } catch (\Throwable) { $this->invalid(); }
            $progress = $process->getOutput();
            $diagnostic = $process->getErrorOutput();
            if (! $process->isSuccessful() || strlen($progress) > 200_000
                || strlen($diagnostic) > 1_000_000
                || ! str_contains($progress, 'progress=end')
                || ! preg_match_all('/^frame=(\d+)$/m', $progress, $frames)
                || ! preg_match_all('/^out_time_us=(\d+)$/m', $progress, $times)) $this->invalid();
            $decodedFrames = (int) end($frames[1]);
            if (! preg_match_all('/^\[[^\]\r\n]*showinfo[^\]\r\n]*\][^\r\n]*\bn:\s*(\d+)[^\r\n]*\bs:(\d+)x(\d+)/m', $diagnostic, $dimensions)
                || count($dimensions[0]) !== $decodedFrames) $this->invalid();
            foreach ($dimensions[2] as $index => $width) {
                if ((int) $width !== $info['width'] || (int) $dimensions[3][$index] !== $info['height']) $this->invalid();
            }
            $decodedSeconds = BigDecimal::of(end($times[1]))->dividedBy(1_000_000, 6, RoundingMode::Up);
            if ($decodedFrames !== $info['frames'] || $decodedSeconds->isGreaterThan('10.1')
                || $decodedSeconds->isGreaterThan(BigDecimal::of($info['seconds'])->plus('0.15'))) $this->invalid();
            // Reserve the greater of full decoding and the declared duration, never just the header.
            $info['seconds'] = (string) BigDecimal::max($info['seconds'], $decodedSeconds);
            return $info;
        } finally {
            @unlink($path);
        }
    }

    private function duration(string $header): string
    {
        if (strlen($header) < 20 || ord($header[0]) !== 0) $this->invalid();
        $scale = $this->integer($header, 12); $duration = $this->integer($header, 16);
        if ($scale < 1 || $duration < 1) $this->invalid();
        return (string) BigDecimal::of($duration)->dividedBy($scale, 6, RoundingMode::Up);
    }

    private function boxes(string $bytes): array
    {
        $result = []; $pos = 0; $count = 0; $length = strlen($bytes);
        while ($pos < $length) {
            if ($length - $pos < 8 || ++$count > 2048) $this->invalid();
            $size = $this->integer($bytes, $pos); $type = substr($bytes, $pos + 4, 4);
            if ($size < 8 || $size > $length - $pos || ! preg_match('/^[\x20-\x7e]{4}$/D', $type)) $this->invalid();
            $result[$type][] = substr($bytes, $pos + 8, $size - 8); $pos += $size;
        }
        return $result;
    }

    private function one(array $boxes, string $type): string
    {
        if (count($boxes[$type] ?? []) !== 1) $this->invalid();
        return $boxes[$type][0];
    }

    private function integer(string $bytes, int $offset): int
    {
        if ($offset < 0 || strlen($bytes) - $offset < 4) $this->invalid();
        return unpack('N', substr($bytes, $offset, 4))[1];
    }

    private function invalid(): never
    {
        throw new AiRequestException('invalid_payload', 'Video must be a complete H.264 MP4 of at most 10 seconds and 1080p, with matching frame timing.', 422);
    }
}
