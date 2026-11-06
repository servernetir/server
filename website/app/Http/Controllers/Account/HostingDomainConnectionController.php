<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\Dns\HostingDomainConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class HostingDomainConnectionController extends Controller
{
    public function __invoke(Service $service, HostingDomainConnection $connection): JsonResponse
    {
        $customer = Auth::guard('customer')->user();
        abort_unless($customer && (int) $service->customer_id === (int) $customer->id, 404);
        abort_unless($service->kind() === 'hosting' && $service->provision_status === 'done' && ! $service->isDead(), 404);
        $fingerprint = hash('sha256', json_encode([
            $service->domain, $service->server_id, $service->server_ip,
            $service->server?->server_ip, HostingDomainConnection::nameservers($service),
        ]));
        $data = Cache::remember('hosting-domain:v2:'.$service->id.':'.$fingerprint, 120,
            fn () => $connection->inspect($service));

        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }
}

