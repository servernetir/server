<?php

namespace App\Services\Hermes;

use App\Models\CloudInstance;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\Server;

/** Exact ownership and database relationships only. Never match a service from ticket prose. */
final class HermesServiceDossier
{
    public function serviceForTicket(Ticket $ticket): ?Service
    {
        return $this->resolve($ticket)['service'];
    }

    /** Discover database-owned hosting and cloud services; prose never proves ownership. */
    public function resolve(Ticket $ticket): array
    {
        if ($ticket->subject_ref_type === 'service') {
            $service = ctype_digit((string) $ticket->subject_ref_id)
                ? Service::query()->whereKey($ticket->subject_ref_id)->where('customer_id', $ticket->customer_id)->first() : null;
            return ['service' => $service, 'status' => $service ? 'verified_database_ownership' : 'explicit_service_not_owned_or_missing', 'candidates' => []];
        }
        if ((int) $ticket->customer_id < 1) { return ['service' => null, 'status' => 'customer_identity_missing', 'candidates' => []]; }
        $rows = Service::query()->where('customer_id', $ticket->customer_id)->orderByDesc('id')->limit(51)->get()->all();
        $candidates = [];
        foreach (array_slice($rows, 0, 50) as $service) {
            if (! in_array((string) $service->status, ['active', 'suspended'], true)
                || $service->provision_status !== 'done') { continue; }
            $instance = CloudInstance::query()->where('service_id', $service->id)->orderByDesc('id')->first();
            $server = $service->server_id ? Server::query()->find($service->server_id) : null;
            if (($instance && filled($instance->provider_ref) && $instance->status !== 'deleted')
                || ($server && in_array($server->type, ['whm', 'plesk'], true))) { $candidates[] = $service; }
        }
        // A truncated inventory can never establish uniqueness or absence.
        if (count($rows) > 50) { return ['service' => null, 'status' => 'owned_inventory_incomplete', 'candidates' => $candidates]; }
        return ['service' => count($candidates) === 1 ? $candidates[0] : null,
            'status' => count($candidates) === 1 ? 'unique_owned_supported_service' : (count($candidates) ? 'multiple_owned_services_requires_owner_selection' : 'no_supported_active_service_in_owned_inventory'),
            'candidates' => $candidates];
    }

    public function ticket(Ticket $ticket, ?array $selection = null): array
    {
        $selection ??= $this->resolve($ticket);
        $service = $selection['service'];
        $history = Ticket::query()->where('customer_id', $ticket->customer_id)->where('id', '!=', $ticket->id)
            ->when($service, fn ($q) => $q->where('subject_ref_type', 'service')->where('subject_ref_id', $service->id))
            ->orderByDesc('id')->limit(8)->get(['id', 'status', 'department', 'last_reply_at'])->map(fn ($t) => [
                'id' => (int) $t->id, 'status' => $t->status, 'department' => $t->department,
                'last_reply_at' => $t->last_reply_at?->timestamp,
            ])->all();
        return ['ticket_id' => (int) $ticket->id, 'customer_id' => (int) $ticket->customer_id,
            'service' => $service ? $this->project($service) : null,
            'link_status' => $selection['status'], 'owned_service_inventory_scope' => 'active_or_suspended_delivered_cloud_and_managed_hosting',
            'owned_hosting_inventory_checked' => $ticket->subject_ref_type !== 'service',
            'owned_service_candidates' => array_map(fn ($s) => ['service_id' => (int) $s->id,
                'status' => (string) $s->status], array_slice($selection['candidates'], 0, 8)),
            'owned_hosting_candidates' => array_map(fn ($s) => ['service_id' => (int) $s->id, 'domain' => $s->domain,
                'status' => $s->status], array_slice(array_values(array_filter($selection['candidates'], fn ($s) => (bool) $s->server_id)), 0, 8)),
            'related_tickets' => $history, 'checked_at' => time(), 'source' => 'servernet_database'];
    }

    public function project(Service $service): array
    {
        $instance = CloudInstance::query()->where('service_id', $service->id)->orderByDesc('id')->first();
        return ['id' => (int) $service->id, 'customer_id' => (int) $service->customer_id,
            'status' => (string) $service->status, 'provision_status' => (string) $service->provision_status,
            'domain' => $service->domain, 'billing_mode' => $service->billing_mode,
            'server_id' => $service->server_id, 'cloud' => $instance ? [
                'instance_id' => (int) $instance->id, 'provider' => $instance->provider,
                'recorded_ipv4' => filter_var($instance->ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $instance->ipv4 : null,
                'provider_ref' => $instance->provider_ref, 'location' => $instance->location_code,
                'recorded_status' => $instance->status, 'last_sync_at' => $instance->synced_at?->timestamp,
            ] : null];
    }
}
