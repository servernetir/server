<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EngineNode extends Model
{
    protected $table = 'cloud_engine_nodes';
    protected $guarded = [];
    protected $attributes = ['arch' => 'x86', 'sales_enabled' => false, 'draining' => false,
        'health' => 'unknown', 'health_ttl_seconds' => 300, 'vmid_min' => 100, 'vmid_max' => 999999999];
    protected $hidden = ['token_secret', 'token_id', 'api_url', 'tls_ca_file', 'metadata'];
    protected $casts = [
        'token_secret' => 'encrypted', 'metadata' => 'array', 'sales_enabled' => 'bool',
        'draining' => 'bool', 'health_checked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $node) {
            $node->api_url = rtrim(trim((string) $node->api_url), '/');
            $parts = parse_url($node->api_url);
            // Normalize valid endpoint spelling for the physical-node uniqueness guard.
            // Invalid endpoints remain rejected by the verified transport layer.
            if (is_array($parts) && isset($parts['scheme'], $parts['host'])
                && ! isset($parts['user']) && ! isset($parts['pass'])
                && ! isset($parts['query']) && ! isset($parts['fragment'])) {
                $port = isset($parts['port']) && $parts['port'] !== 443 ? ':'.$parts['port'] : '';
                $node->api_url = strtolower($parts['scheme']).'://'.strtolower($parts['host']).$port.($parts['path'] ?? '');
            }
            $node->pve_node = trim((string) $node->pve_node);
        });
    }

    public function images() { return $this->hasMany(EngineImage::class, 'node_id'); }
    public function vms() { return $this->hasMany(EngineVm::class, 'node_id'); }
    public function subnets() { return $this->hasMany(EngineSubnet::class, 'node_id'); }

    public function acceptsSales(): bool
    {
        return $this->sales_enabled && ! $this->draining && $this->health === 'healthy'
            && $this->health_checked_at !== null
            && $this->health_checked_at->lte(now()->addSeconds(30))
            && $this->health_checked_at->gte(now()->subSeconds(max(1, (int) $this->health_ttl_seconds)));
    }
}
