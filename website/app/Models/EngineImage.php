<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EngineImage extends Model
{
    protected $table = 'cloud_engine_images';
    protected $guarded = [];
    protected $attributes = ['arch' => 'x86', 'min_ram_mb' => 0, 'min_disk_gb' => 0];
    protected $casts = [
        'accepted_at' => 'datetime', 'initialization_verified_at' => 'datetime',
        'license_verified_at' => 'datetime', 'metadata' => 'array',
    ];
    public function node() { return $this->belongsTo(EngineNode::class, 'node_id'); }

    public function accepted(): bool
    {
        return $this->accepted_at !== null && $this->accepted_at->lte(now())
            && $this->initialization_verified_at !== null && $this->initialization_verified_at->lte(now())
            && preg_match('/\A[a-f0-9]{64}\z/i', (string) $this->checksum_sha256) === 1
            && filter_var((string) $this->source_url, FILTER_VALIDATE_URL) !== false
            && parse_url((string) $this->source_url, PHP_URL_SCHEME) === 'https'
            && (int) $this->template_vmid > 0
            && ($this->metadata['guest_agent_verified'] ?? false) === true
            && (! str_starts_with(strtolower((string) $this->family), 'windows')
                || ($this->license_verified_at !== null && $this->license_verified_at->lte(now())
                    && in_array((string) $this->version, ['2019', '2022', '2025'], true)
                    && ($this->metadata['guest_agent_verified'] ?? false) === true
                    && ($this->metadata['windows_ready_receipt_verified'] ?? false) === true));
    }
}
