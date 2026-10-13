<?php

namespace App\Http\Controllers\Ai;

use App\Services\Ai\AiMediaRelay;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AiMediaRelayController
{
    public function __invoke(Request $request, string $id, AiMediaRelay $relay): Response
    {
        $headers = ['Cache-Control' => 'private, no-store, max-age=0', 'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Content-Type' => 'video/mp4', 'X-Content-Type-Options' => 'nosniff', 'Accept-Ranges' => 'bytes'];
        $expires = $request->query('expires');
        if (! is_string($expires) || ! preg_match('/^[0-9]{10}$/D', $expires) || ! $request->hasValidSignature(false)) {
            return response('', 403, $headers);
        }
        try { $opened = $relay->open($id, (int) $expires); }
        catch (\RuntimeException) { return response('', 403, $headers); }
        $handle = $opened['handle']; $size = $opened['size']; $start = 0; $end = $size - 1; $status = 200;
        if ($request->isMethod('GET') && ($range = $request->header('Range')) !== null) {
            // A single bounded byte range supports MP4 readers without arbitrary range concatenation.
            if (! preg_match('/^bytes=([0-9]{0,12})-([0-9]{0,12})$/D', $range, $match) || ($match[1] === '' && $match[2] === '')) {
                fclose($handle);
                return response('', 416, $headers + ['Content-Range' => 'bytes */'.$size]);
            }
            if ($match[1] === '') $start = max(0, $size - (int) $match[2]);
            else $start = (int) $match[1];
            if ($match[1] !== '' && $match[2] !== '') $end = min($end, (int) $match[2]);
            if ($start >= $size || $start > $end || ($match[1] === '' && (int) $match[2] === 0)) {
                fclose($handle);
                return response('', 416, $headers + ['Content-Range' => 'bytes */'.$size]);
            }
            $status = 206;
            $headers['Content-Range'] = 'bytes '.$start.'-'.$end.'/'.$size;
        }
        $headers['Content-Length'] = (string) ($end - $start + 1);
        $headers['Content-Disposition'] = 'inline; filename="media.mp4"';
        if ($request->isMethod('HEAD')) { fclose($handle); return response('', 200, $headers); }
        return response()->stream(function () use ($handle, $start, $end) {
            try {
                if (fseek($handle, $start) !== 0) return;
                $remaining = $end - $start + 1;
                while ($remaining > 0 && ! feof($handle)) {
                    $bytes = fread($handle, min(65536, $remaining));
                    if ($bytes === false || $bytes === '') break;
                    echo $bytes;
                    $remaining -= strlen($bytes);
                }
            } finally { fclose($handle); }
        }, $status, $headers);
    }
}
