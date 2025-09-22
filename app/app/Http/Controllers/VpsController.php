<?php

namespace App\Http\Controllers;

class VpsController extends Controller
{
    public function index()
    {
        $locations = [
            ['id' => 'clt', 'name' => 'Charlotte', 'flag' => '🇺🇸'],
            ['id' => 'fra', 'name' => 'Frankfurt', 'flag' => '🇩🇪'],
            ['id' => 'par', 'name' => 'Paris', 'flag' => '🇫🇷'],
            ['id' => 'ams', 'name' => 'Amsterdam', 'flag' => '🇳🇱'],
            ['id' => 'vie', 'name' => 'Vienna', 'flag' => '🇦🇹'],
            ['id' => 'sto', 'name' => 'Stockholm', 'flag' => '🇸🇪'],
            ['id' => 'lon', 'name' => 'London', 'flag' => '🇬🇧'],
            ['id' => 'hel', 'name' => 'Helsinki', 'flag' => '🇫🇮'],
        ];

        $segments = [
            ['id' => 'shared', 'title' => 'Shared', 'desc' => 'Value cloud servers with basic DDoS protection and NVMe on shared vCPU. Great for websites, VPN, or development.'],
            ['id' => 'dedicated', 'title' => 'Dedicated', 'desc' => 'Guaranteed CPU resources with RAM cache for heavy projects, 1C/Bitrix, high-load services. Includes DDoS.'],
        ];

        $plans = [
            'shared' => [
                ['id' => 'CLTs-1', 'cpu' => 1, 'ram' => 2, 'nvme' => 30, 'net' => 25, 'price_m' => 4.94, 'price_h' => 0.02],
                ['id' => 'CLTs-2', 'cpu' => 2, 'ram' => 4, 'nvme' => 60, 'net' => 25, 'price_m' => 9.89, 'price_h' => 0.03],
                ['id' => 'CLTs-3', 'cpu' => 4, 'ram' => 8, 'nvme' => 120, 'net' => 25, 'price_m' => 19.77, 'price_h' => 0.05],
                ['id' => 'CLTs-4', 'cpu' => 8, 'ram' => 16, 'nvme' => 240, 'net' => 25, 'price_m' => 39.54, 'price_h' => 0.10],
                ['id' => 'CLTs-5', 'cpu' => 16, 'ram' => 32, 'nvme' => 480, 'net' => 25, 'price_m' => 79.09, 'price_h' => 0.19],
            ],
            'dedicated' => [
                ['id' => 'DED-1', 'cpu' => 2, 'ram' => 8, 'nvme' => 80, 'net' => 25, 'price_m' => 14.90, 'price_h' => 0.04],
                ['id' => 'DED-2', 'cpu' => 4, 'ram' => 16, 'nvme' => 160, 'net' => 25, 'price_m' => 28.90, 'price_h' => 0.08],
                ['id' => 'DED-3', 'cpu' => 8, 'ram' => 32, 'nvme' => 320, 'net' => 25, 'price_m' => 56.90, 'price_h' => 0.16],
            ]
        ];

        $os = [
            ['id' => 'ubuntu-24', 'label' => 'Ubuntu 24.04 LTS'],
            ['id' => 'ubuntu-22', 'label' => 'Ubuntu 22.04 LTS'],
            ['id' => 'debian-12', 'label' => 'Debian 12 Bookworm'],
            ['id' => 'centos-9', 'label' => 'CentOS Stream 9'],
        ];

        $apps = [
            ['id' => 'docker', 'label' => 'Docker', 'tag' => 'Ubuntu 20.04'],
            ['id' => 'wireguard', 'label' => 'WireGuard', 'tag' => 'Ubuntu 20.04'],
            ['id' => 'openvpn', 'label' => 'OpenVPN Server', 'tag' => 'Ubuntu 20.04'],
            ['id' => 'nodejs', 'label' => 'NodeJS (Yarn/PM2)', 'tag' => 'Ubuntu 20.04'],
            ['id' => 'lamp', 'label' => 'LAMP', 'tag' => 'Ubuntu 20.04'],
            ['id' => 'lemp', 'label' => 'LEMP', 'tag' => 'Ubuntu 20.04'],
            ['id' => 'mailcow', 'label' => 'Mailcow', 'tag' => 'Ubuntu 20.04'],
            ['id' => 'pterodactyl', 'label' => 'Pterodactyl', 'tag' => 'Ubuntu 22.04'],
            ['id' => 'nextcloud', 'label' => 'Nextcloud', 'tag' => 'Ubuntu 22.04'],
            ['id' => 'isp-lite', 'label' => 'ISPmanager Lite', 'tag' => 'Ubuntu 20.04'],
            ['id' => 'hestiacp', 'label' => 'HestiaCP', 'tag' => 'Ubuntu 22.04'],
        ];

        $cycles = [
            ['id' => 'hour', 'label' => 'Hour', 'discount' => 0],
            ['id' => 'month', 'label' => 'Month', 'discount' => 0],
            ['id' => '3m', 'label' => '3 months', 'discount' => 5],
            ['id' => '6m', 'label' => '6 months', 'discount' => 9],
            ['id' => 'year', 'label' => 'Year', 'discount' => 12],
        ];

        return view('vps', compact('locations', 'segments', 'plans', 'os', 'apps', 'cycles'));
    }
}