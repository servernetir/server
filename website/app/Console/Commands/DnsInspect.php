<?php

namespace App\Console\Commands;

use App\Services\Dns\DelegationInspector;
use App\Services\Dns\DnsConfig;
use App\Services\Dns\PowerDnsClient;
use Illuminate\Console\Command;

/**
 * تشخیصِ فقط‌خواندنی — روی سرورِ تولید اجرا شود (نه روی ایستگاهی که پورتِ ۵۳ را
 * رهگیری می‌کند). هیچ چیزی را تغییر نمی‌دهد و هیچ رازی چاپ نمی‌کند.
 *
 *   php artisan dns:inspect example.com     ← تفویض از والد، DS، TXTِ چالش
 *   php artisan dns:inspect --nodes         ← SOAِ catalog روی هر نیم‌سرور + سلامتِ API
 */
class DnsInspect extends Command
{
    protected $signature = 'dns:inspect {domain?} {--nodes}';

    protected $description = 'Managed DNS: read-only diagnostics (parent delegation, DS, nameserver reachability)';

    public function handle(DelegationInspector $inspector, PowerDnsClient $pdns): int
    {
        if ($d = $this->argument('domain')) {
            $t = microtime(true);
            $r = $inspector->parentDelegation($d);
            $this->line(sprintf('parent delegation (%.2fs): ok=%s status=%s parent=%s error=%s',
                microtime(true) - $t, $r['ok'] ? 'yes' : 'no', $r['status'] ?? '-', $r['parent'] ?? '-', $r['error'] ?? '-'));
            $this->line('  NS: '.implode(', ', $r['ns']));
            $this->line('  DS records at parent: '.count($r['ds']));
            $this->line('  classification vs ours: '.DelegationInspector::classify($r['ns'], DnsConfig::nameserverHosts()));
            $txt = $inspector->authoritativeTxt($d, '_servernet-challenge.'.$d, $r);
            $this->line('  challenge TXT: ok='.($txt['ok'] ? 'yes' : 'no').' answered='.$txt['answered'].' values='.count($txt['values']));
        }

        if ($this->option('nodes') || ! $this->argument('domain')) {
            $info = $pdns->serverInfo();
            $this->line('primary API: '.($info['ok'] ? 'ok (PowerDNS '.($info['data']['version'] ?? '?').')' : 'FAIL '.$info['error']));
            foreach (DnsConfig::nameservers() as $ns) {
                foreach (array_filter([$ns['ipv4'], $ns['ipv6']]) as $ip) {
                    foreach ([false, true] as $tcp) {
                        $s = $inspector->soaAt($ip, (int) $ns['port'], DnsConfig::catalog(), $tcp);
                        $this->line(sprintf('  %-28s %-39s %s  state=%s aa=%s serial=%s',
                            $ns['host'], $ip, $tcp ? 'tcp' : 'udp', $s['state'], ($s['aa'] ?? false) ? 'y' : 'n', $s['serial'] ?? '-'));
                    }
                }
            }
        }

        return self::SUCCESS;
    }
}
