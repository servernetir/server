<?php

namespace App\Services\IdentityApi;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Controls
{
    private ?bool $schemaReady = null;

    public function ready(): bool
    {
        return $this->schemaReady ??= Schema::hasTable('identity_requests') && Schema::hasTable('identity_controls')
            && Schema::hasTable('identity_prices') && Schema::hasTable('identity_applications')
            && Schema::hasColumn('customer_api_tokens', 'identity_application_id');
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if ($this->ready()) {
            $value = DB::table('identity_controls')->where('key', $key)->value('value');
            if ($value !== null) {
                return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            }
        }

        return config('identity_api.'.$key, $default);
    }

    public function put(string $key, mixed $value, int $actor): void
    {
        DB::transaction(function () use ($key, $value, $actor) {
            DB::table('identity_controls')->updateOrInsert(['key' => $key], ['value' => json_encode($value, JSON_THROW_ON_ERROR), 'updated_by' => $actor, 'updated_at' => now(), 'created_at' => now()]);
            $this->audit($actor, 'control', ['key' => $key, 'value' => $value]);
        });
    }

    public function audit(?int $actor, string $action, array $detail): void
    {
        DB::table('identity_audits')->insert(['actor_id' => $actor, 'action' => $action, 'detail' => json_encode($detail, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function dispatchEnabled(): bool
    {
        return $this->ready() && $this->get('enabled', false) === true && filled($this->get('resale_evidence'))
            && $this->get('egress_verified', false) === true;
    }
}
