<?php

namespace App\Services\Dns;

use Illuminate\Support\Facades\Http;

/**
 * کلاینتِ APIِ primaryِ پنهانِ PowerDNS — قرارداد §1، §2، §4.
 *
 * فقط **حمل** است: هیچ تصمیمِ کسب‌وکاری این‌جا نیست (آن مالِ ZonePublisher است).
 *
 * ⚠️ نشانی و کلید فقط از DnsConfig (تنظیماتِ مدیر) می‌آید، هرگز از ورودیِ مشتری.
 * اگر نشانی «قابلِ قبول» نباشد (https، یا شبکهٔ خصوصی فقط با پرچمِ صریحِ
 * آزمایشگاه) هیچ درخواستی فرستاده نمی‌شود — کلیدِ API روی HTTPِ عمومی نمی‌رود.
 *
 * هیچ متدی استثنا نمی‌دهد. خطای برگشتی پاک‌سازی‌شده است (کلید و نشانی از آن
 * حذف می‌شوند) و فقط برای مدیر است؛ مشتری هرگز متنِ خامِ PowerDNS را نمی‌بیند.
 *
 * نتیجهٔ هر فراخوان: ['ok'=>bool, 'status'=>int, 'data'=>array, 'error'=>?string]
 */
class PowerDnsClient
{
    public function isConfigured(): bool
    {
        return DnsConfig::apiUrlIsAcceptable() && filled(DnsConfig::apiKey());
    }

    /** اطلاعاتِ سرور — «آزمایشِ اتصال» */
    public function serverInfo(): array
    {
        return $this->request('get', '');
    }

    public function getZone(string $zone): array
    {
        return $this->request('get', '/zones/'.$this->fqdn($zone));
    }

    /** @return array{ok:bool,status:int,data:array,error:?string,names?:list<string>} */
    public function listZones(): array
    {
        $r = $this->request('get', '/zones');
        if ($r['ok']) {
            $r['names'] = array_values(array_map(fn ($z) => rtrim(strtolower((string) ($z['name'] ?? '')), '.'), $r['data']));
        }

        return $r;
    }

    /**
     * ساختِ zone با نوعِ Primary، عضوِ catalog، SOAِ قرارداد و NSِ مدیریت‌شده.
     *
     * @param  list<string>  $nameservers
     * @param  array{email:string,refresh:int,retry:int,expire:int,minimum:int}  $soa
     */
    public function createZone(string $zone, array $nameservers, array $soa, string $catalog): array
    {
        $ns = array_map(fn ($n) => $this->fqdn($n), $nameservers);

        return $this->request('post', '/zones', [
            'name'         => $this->fqdn($zone),
            'kind'         => 'Primary',
            'catalog'      => $this->fqdn($catalog),
            'nameservers'  => $ns,
            'soa_edit_api' => 'DEFAULT',
            'rrsets'       => [[
                'name'       => $this->fqdn($zone),
                'type'       => 'SOA',
                'ttl'        => (int) $soa['minimum'],
                'changetype' => 'REPLACE',
                'records'    => [[
                    'content'  => sprintf('%s %s 1 %d %d %d %d',
                        $ns[0] ?? 'localhost.', $this->fqdn($soa['email']),
                        (int) $soa['refresh'], (int) $soa['retry'], (int) $soa['expire'], (int) $soa['minimum']),
                    'disabled' => false,
                ]],
            ]],
        ]);
    }

    /** ویژگی‌های zone (catalog و …) — PUT فقط کلیدهای داده‌شده را عوض می‌کند */
    public function updateZone(string $zone, array $props): array
    {
        return $this->request('put', '/zones/'.$this->fqdn($zone), $props);
    }

    /** @param list<array<string,mixed>> $rrsets */
    public function patchRrsets(string $zone, array $rrsets): array
    {
        return $this->request('patch', '/zones/'.$this->fqdn($zone), ['rrsets' => $rrsets]);
    }

    public function deleteZone(string $zone): array
    {
        $r = $this->request('delete', '/zones/'.$this->fqdn($zone));

        // zoneِ ازقبل‌نبوده = حذف‌شده
        return $r['status'] === 404 ? ['ok' => true, 'status' => 404, 'data' => [], 'error' => null] : $r;
    }

    public function notify(string $zone): array
    {
        return $this->request('put', '/zones/'.$this->fqdn($zone).'/notify');
    }

    /** @return array{ok:bool,status:int,data:array,error:?string,values?:list<string>} */
    public function getMetadata(string $zone, string $kind): array
    {
        $r = $this->request('get', '/zones/'.$this->fqdn($zone).'/metadata/'.rawurlencode($kind));
        $r['values'] = $r['ok'] ? array_values((array) ($r['data']['metadata'] ?? [])) : [];

        return $r;
    }

    /** @param list<string> $values */
    public function setMetadata(string $zone, string $kind, array $values): array
    {
        return $this->request('put', '/zones/'.$this->fqdn($zone).'/metadata/'.rawurlencode($kind), [
            'kind' => $kind, 'metadata' => array_values($values),
        ]);
    }

    /* ───────────────────────── داخلی ───────────────────────── */

    public function fqdn(string $name): string
    {
        $n = strtolower(rtrim(trim($name), '.'));

        return $n === '' ? '.' : $n.'.';
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        if (! DnsConfig::apiUrlIsAcceptable()) {
            return $this->fail(0, 'api_url_not_acceptable');
        }
        $key = DnsConfig::apiKey();
        if (blank($key)) {
            return $this->fail(0, 'api_key_missing');
        }

        $url = rtrim((string) DnsConfig::apiUrl(), '/').'/api/v1/servers/'.rawurlencode(DnsConfig::serverId()).$path;

        try {
            $req = Http::withHeaders(['X-API-Key' => $key])
                ->acceptJson()
                ->connectTimeout(DnsConfig::connectTimeout())
                ->timeout(DnsConfig::timeout())
                ->withOptions(['allow_redirects' => false]);   // کلید به جای دیگری فرستاده نشود

            $resp = match ($method) {
                'get'    => $req->get($url),
                'delete' => $req->delete($url),
                'post'   => $req->post($url, $body ?? []),
                'patch'  => $req->patch($url, $body ?? []),
                'put'    => $body === null ? $req->send('PUT', $url) : $req->put($url, $body),
            };
        } catch (\Throwable $e) {
            return $this->fail(0, 'connection: '.$this->sanitize($e->getMessage(), $key));
        }

        $status = $resp->status();
        $json = $resp->json();

        if ($resp->successful()) {
            return ['ok' => true, 'status' => $status, 'data' => is_array($json) ? $json : [], 'error' => null];
        }

        $msg = is_array($json) && filled($json['error'] ?? null) ? (string) $json['error'] : 'HTTP '.$status;

        return $this->fail($status, $this->sanitize($msg, $key));
    }

    /** کلید و نشانی هرگز در پیامِ خطا نمی‌مانند */
    private function sanitize(string $msg, ?string $key): string
    {
        if (filled($key)) {
            $msg = str_replace($key, '***', $msg);
        }
        $url = (string) DnsConfig::apiUrl();
        if ($url !== '') {
            $msg = str_replace($url, '[api]', $msg);
        }
        $msg = preg_replace('~https?://\S+~i', '[url]', $msg) ?? $msg;

        return mb_substr(trim($msg), 0, 240);
    }

    private function fail(int $status, string $error): array
    {
        return ['ok' => false, 'status' => $status, 'data' => [], 'error' => $error];
    }
}
