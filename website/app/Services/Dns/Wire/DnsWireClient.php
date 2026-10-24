<?php

namespace App\Services\Dns\Wire;

/**
 * کلاینتِ خامِ DNS: یک پرس‌وجو به یک سرور، UDP و در صورتِ TC=1 روی TCP.
 *
 * ═══ قرارداد ═══
 *
 *  • هرگز استثنا نمی‌دهد؛ نتیجه DnsResponse یا DnsFailure است.
 *  • مهلت **کل** است، نه به ازای هر مرحله: UDP + تلاشِ دوباره روی TCP + تلاشِ
 *    بی‌EDNS همه از یک ساعت می‌خورند. مسیرِ درخواستِ کاربر نباید با یک سرورِ
 *    کند چند برابرِ مهلت معطل شود.
 *  • فقط آدرسِ IP (v4 یا v6)؛ نامِ میزبان پذیرفته نیست — این کلاینت هرگز خودش
 *    resolve نمی‌کند، وگرنه «مستقیم پرسیدیم» دروغ می‌شود.
 *  • روی UDP، دیتاگرامِ ناهم‌خوان (شناسه یا سؤالِ دیگر) دور ریخته می‌شود و تا
 *    پایانِ مهلت منتظرِ پاسخِ درست می‌مانیم — همان رفتارِ resolverها در برابرِ
 *    جعل؛ اگر فقط ناهم‌خوان رسید، نتیجه `id_mismatch` است نه `timeout`.
 *
 * از stream_* استفاده می‌شود نه ext-sockets، چون روی هاستِ اشتراکی همیشه هست.
 * کلاس final نیست تا تست‌ها بتوانند سلسله‌مراتبِ DNSِ جعلی بسازند.
 */
class DnsWireClient
{
    public const DEFAULT_TIMEOUT = 2.5;

    private const MIN_TIMEOUT = 0.05;

    private const MAX_TIMEOUT = 30.0;

    /** errnoهای «اتصال رد شد» در لینوکس/مک/ویندوز */
    private const ERR_REFUSED = [111, 61, 10061];

    /** errnoهای «اتصال مهلت تمام» در لینوکس/مک/ویندوز */
    private const ERR_TIMEDOUT = [110, 60, 10060];

    /**
     * @param  bool  $tcpOnly  مستقیم روی TCP (برای بررسیِ دسترس‌پذیریِ TCPِ نیم‌سرور)
     */
    public function query(
        string $server,
        int $port,
        string $name,
        string $type,
        bool $rd = false,
        float $timeout = self::DEFAULT_TIMEOUT,
        bool $tcpOnly = false,
    ): DnsResponse|DnsFailure {
        try {
            return $this->exchange($server, $port, $name, $type, $rd, $timeout, $tcpOnly);
        } catch (\Throwable) {
            return new DnsFailure(DnsFailure::NETWORK, 'internal');
        }
    }

    private function exchange(string $server, int $port, string $name, string $type, bool $rd, float $timeout, bool $tcpOnly): DnsResponse|DnsFailure
    {
        $ip = trim($server, " []\t");
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return new DnsFailure(DnsFailure::NETWORK, 'server_not_ip');
        }
        if ($port < 1 || $port > 65535) {
            return new DnsFailure(DnsFailure::NETWORK, 'bad_port');
        }

        try {
            $msg = DnsMessage::query($name, $type, $rd);
        } catch (\InvalidArgumentException $e) {
            return new DnsFailure(DnsFailure::MALFORMED, $e->getMessage());
        }

        $deadline = $this->now() + max(self::MIN_TIMEOUT, min(self::MAX_TIMEOUT, $timeout));
        $address = str_contains($ip, ':') ? '['.$ip.']:'.$port : $ip.':'.$port;

        $res = $this->roundTrip($address, $msg, $deadline, $tcpOnly);

        // سرورهای قدیمی به OPT با FORMERR (و بی OPT در پاسخ) جواب می‌دهند؛ یک بار بی‌EDNS
        if ($res instanceof DnsResponse && $res->rcode === 1 && $res->edns === null && $this->now() < $deadline) {
            $plain = DnsMessage::query($name, $type, $rd, false);
            $retry = $this->roundTrip($address, $plain, $deadline, $tcpOnly);
            if ($retry instanceof DnsResponse) {
                $res = $retry;
            }
        }

        return $res;
    }

    private function roundTrip(string $address, DnsMessage $msg, float $deadline, bool $tcpOnly): DnsResponse|DnsFailure
    {
        if ($tcpOnly) {
            return $this->overTcp($address, $msg, $deadline);
        }

        $res = $this->overUdp($address, $msg, $deadline);

        // 🔴 پاسخِ بریده هرگز به‌عنوانِ پاسخِ کامل برنمی‌گردد. اگر TCP شکست خورد،
        // همان شکست گزارش می‌شود — «بخشی از رکوردها» بدتر از «نتوانستیم» است.
        if ($res instanceof DnsResponse && $res->tc) {
            return $this->overTcp($address, $msg, $deadline);
        }

        return $res;
    }

    private function overUdp(string $address, DnsMessage $msg, float $deadline): DnsResponse|DnsFailure
    {
        $left = $deadline - $this->now();
        if ($left <= 0) {
            return new DnsFailure(DnsFailure::TIMEOUT, 'udp');
        }

        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client('udp://'.$address, $errno, $errstr, $left);
        if ($sock === false) {
            return new DnsFailure(DnsFailure::NETWORK, 'udp_socket:'.$errno);
        }

        try {
            stream_set_blocking($sock, false);
            $wire = $msg->toWire();
            $sent = @stream_socket_sendto($sock, $wire);
            if ($sent !== strlen($wire)) {
                return new DnsFailure(DnsFailure::NETWORK, 'udp_send');
            }

            $seen = null;
            while (true) {
                $left = $deadline - $this->now();
                if ($left <= 0) {
                    return $seen ?? new DnsFailure(DnsFailure::TIMEOUT, 'udp');
                }

                $r = [$sock];
                $w = null;
                $e = null;
                [$sec, $usec] = $this->split($left);
                $ready = @stream_select($r, $w, $e, $sec, $usec);
                if ($ready === false) {
                    return new DnsFailure(DnsFailure::NETWORK, 'udp_select');
                }
                if ($ready === 0) {
                    continue;
                }

                $data = @stream_socket_recvfrom($sock, 65535);
                if ($data === false || $data === '') {
                    // روی سوکتِ UDPِ متصل، ICMP port-unreachable به شکلِ خواندنِ ناموفق ظاهر می‌شود
                    return new DnsFailure(DnsFailure::REFUSED_SOCKET, 'udp_unreachable');
                }

                if (strlen($data) < 2 || unpack('n', $data)[1] !== $msg->id) {
                    $seen = new DnsFailure(DnsFailure::ID_MISMATCH, 'id');

                    continue;
                }

                $resp = DnsMessage::parse($data, 'udp');
                if ($resp instanceof DnsFailure) {
                    return $resp;   // شناسهٔ درست ولی بستهٔ خراب: سرورِ خودش خراب است
                }

                $bad = $msg->check($resp);
                if ($bad !== null) {
                    $seen = $bad;

                    continue;
                }

                return $resp;
            }
        } finally {
            fclose($sock);
        }
    }

    private function overTcp(string $address, DnsMessage $msg, float $deadline): DnsResponse|DnsFailure
    {
        $left = $deadline - $this->now();
        if ($left <= 0) {
            return new DnsFailure(DnsFailure::TIMEOUT, 'tcp');
        }

        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client('tcp://'.$address, $errno, $errstr, $left, STREAM_CLIENT_CONNECT);
        if ($sock === false) {
            if (in_array($errno, self::ERR_REFUSED, true)) {
                return new DnsFailure(DnsFailure::REFUSED_SOCKET, 'tcp_refused');
            }
            if (in_array($errno, self::ERR_TIMEDOUT, true) || $this->now() >= $deadline - 0.01) {
                return new DnsFailure(DnsFailure::TIMEOUT, 'tcp_connect');
            }

            return new DnsFailure(DnsFailure::NETWORK, 'tcp_connect:'.$errno);
        }

        try {
            stream_set_blocking($sock, false);
            $wire = $msg->toWire();
            $out = pack('n', strlen($wire)).$wire;

            while ($out !== '') {
                $left = $deadline - $this->now();
                if ($left <= 0) {
                    return new DnsFailure(DnsFailure::TIMEOUT, 'tcp_write');
                }
                $r = null;
                $w = [$sock];
                $e = null;
                [$sec, $usec] = $this->split($left);
                $ready = @stream_select($r, $w, $e, $sec, $usec);
                if ($ready === false) {
                    return new DnsFailure(DnsFailure::NETWORK, 'tcp_select');
                }
                if ($ready === 0) {
                    continue;
                }
                $n = @fwrite($sock, $out);
                if ($n === false) {
                    return new DnsFailure(DnsFailure::NETWORK, 'tcp_write');
                }
                $out = (string) substr($out, $n);
            }

            $head = $this->readExact($sock, 2, $deadline);
            if ($head instanceof DnsFailure) {
                return $head;
            }
            $size = unpack('n', $head)[1];
            if ($size < 12) {
                return new DnsFailure(DnsFailure::MALFORMED, 'tcp_length');
            }

            $body = $this->readExact($sock, $size, $deadline);
            if ($body instanceof DnsFailure) {
                return $body;
            }

            $resp = DnsMessage::parse($body, 'tcp');
            if ($resp instanceof DnsFailure) {
                return $resp;
            }

            // روی TCP جعلِ کور عملاً ممکن نیست؛ ناهم‌خوانی یعنی سرورِ خراب — فوراً گزارش
            return $msg->check($resp) ?? $resp;
        } finally {
            fclose($sock);
        }
    }

    /** @param resource $sock */
    private function readExact($sock, int $need, float $deadline): string|DnsFailure
    {
        $buf = '';
        while (strlen($buf) < $need) {
            $left = $deadline - $this->now();
            if ($left <= 0) {
                return new DnsFailure(DnsFailure::TIMEOUT, 'tcp_read');
            }
            $r = [$sock];
            $w = null;
            $e = null;
            [$sec, $usec] = $this->split($left);
            $ready = @stream_select($r, $w, $e, $sec, $usec);
            if ($ready === false) {
                return new DnsFailure(DnsFailure::NETWORK, 'tcp_select');
            }
            if ($ready === 0) {
                continue;
            }
            $chunk = @fread($sock, $need - strlen($buf));
            if ($chunk === false) {
                return new DnsFailure(DnsFailure::NETWORK, 'tcp_read');
            }
            if ($chunk === '') {
                if (feof($sock)) {
                    return new DnsFailure(DnsFailure::NETWORK, 'tcp_closed');
                }

                continue;
            }
            $buf .= $chunk;
        }

        return $buf;
    }

    /** @return array{0:int,1:int} */
    private function split(float $seconds): array
    {
        $sec = (int) floor($seconds);

        return [$sec, max(0, (int) (($seconds - $sec) * 1_000_000))];
    }

    private function now(): float
    {
        return hrtime(true) / 1e9;
    }
}
