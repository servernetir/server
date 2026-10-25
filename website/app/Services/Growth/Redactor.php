<?php

namespace App\Services\Growth;

final class Redactor
{
    public function clean(string $text): string
    {
        $text = preg_replace('~-----BEGIN [^-]*PRIVATE KEY-----.*?-----END [^-]*PRIVATE KEY-----~s', '[REDACTED PRIVATE KEY]', $text);
        $text = preg_replace('~\b(?:vless|vmess|trojan|ss|ssr)://\S+~iu', '[REDACTED VPN]', $text);
        $text = preg_replace('~\b(?:Bearer\s+|sk-[a-zA-Z0-9_-]{8,}|AIza[a-zA-Z0-9_-]{15,})\S*~i', '[REDACTED TOKEN]', $text);
        $text = preg_replace('~((?:password|passwd|api[_ -]?key|access[_ -]?token|refresh[_ -]?token|secret|رمز عبور|توکن)\s*[=:]\s*)[^\s,;]+~iu', '$1[REDACTED]', $text);
        $text = preg_replace('~\b[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}\b~i', '[REDACTED UUID]', $text);
        $text = preg_replace('~[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}~iu', '[REDACTED EMAIL]', $text);
        $text = preg_replace('~(?<!\d)(?:\+98|0098|0)?9\d{9}(?!\d)~u', '[REDACTED PHONE]', $text);
        $text = preg_replace('~\b(?:\d{1,3}\.){3}\d{1,3}\b~u', '[REDACTED IP]', $text);
        $text = preg_replace('~(https?://[^\s?#]+)[?#][^\s]*~iu', '$1[REDACTED QUERY]', $text);

        return trim($text);
    }
}
