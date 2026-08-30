<?php
namespace App\Services;
use App\Models\PortForward;
use App\Models\VmRecord;
class Provisioner
{
    public function __construct(
        private ProxmoxService $pve,
        private Ipam $ipam,
    ) {}
    public function sell(string $name, int $cores, int $memory, string $os = 'ubuntu2204', ?string $ciuser = null, ?string $country = null): array
    {
        $catalog = config('os.catalog');
        $osDef   = $catalog[$os] ?? null;
        if (!$osDef) {
            throw new \RuntimeException('Unknown OS: ' . $os);
        }
        // Optional exit country (must be a configured, sellable country).
        if ($country !== null && $country !== '') {
            if (!array_key_exists($country, (array) config('countries.catalog'))) {
                throw new \RuntimeException('Unknown exit country: ' . $country);
            }
        } else {
            $country = null;
        }
        $isWin    = (bool) $osDef['win'];
        $ciuser   = $ciuser ?: ($isWin ? 'Administrator' : 'root');
        $svcPort  = (int) $osDef['port'];
        $password = $this->genPassword(16);
        // 1) internal IP
        $ip = $this->ipam->nextIp();
        // 2) clone the OS-specific template. cloneTemplate() reads config('proxmox.template')
        //    at call time, so a runtime override selects the right template without touching
        //    the proven ProxmoxService. Gateway is UNCHANGED (10.10.10.1): inbound stays via
        //    the Iran IP:port; the country-routing agent applies split-routing for outbound.
        config(['proxmox.template' => (int) $osDef['template']]);
        $res  = $this->pve->provision($name, $cores, $memory, $ip, $ciuser, $password);
        $vmid = (int) $res['vmid'];
        // 3) public port + port-forward (host agent applies DNAT)
        $pubPort = $this->ipam->nextPort('tcp');
        PortForward::updateOrCreate(
            ['public_ip' => config('proxmox.public_ip'), 'port' => $pubPort, 'proto' => 'tcp'],
            ['vmid' => $vmid, 'label' => $name, 'dest_ip' => $ip, 'dest_port' => $svcPort, 'active' => 1]
        );
        // 4) local record (exit_country drives the country-routing agent)
        $rec = VmRecord::create([
            'vmid'         => $vmid,
            'name'         => $name,
            'host_label'   => 's' . $vmid,
            'ip'           => $ip,
            'os'           => $os,
            'exit_country' => $country,
            'cores'        => $cores,
            'memory'       => $memory,
            'ciuser'       => $ciuser,
            'svc_port'     => $svcPort,
            'pub_port'     => $pubPort,
        ]);
        return [
            'vmid'         => $vmid,
            'ip'           => $ip,
            'access'       => $rec->access(),
            'ciuser'       => $ciuser,
            'password'     => $password,
            'os'           => $os,
            'os_label'     => $osDef['label'],
            'is_win'       => $isWin,
            'exit_country' => $country,
        ];
    }
    private function genPassword(int $len = 16): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $max = strlen($alphabet) - 1;
        $s = '';
        for ($i = 0; $i < $len; $i++) {
            $s .= $alphabet[random_int(0, $max)];
        }
        return $s;
    }
}
