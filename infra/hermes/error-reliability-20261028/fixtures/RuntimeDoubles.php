<?php

// Process-isolated command dependency doubles. These files are NEVER deployed.
namespace App\Services\Hermes {
    final class HermesOperations
    {
        public bool $active = true;
        public array $scans = [];
        public array $health = [];
        public array $sample = ['status' => 'sampled', 'services_checked' => 2];
        public ?\Throwable $sampleError = null;
        public int $digestCalls = 0;
        public function enabled(): bool { return $this->active; }
        public function owner(): array { return ['user_id' => '100', 'chat_id' => '200']; }
        public function scan(string $provider): array
        {
            $result = $this->scans[$provider] ?? ['status' => 'complete'];
            if ($result instanceof \Throwable) { throw $result; }
            return $result;
        }
        public function refresh(): array
        {
            if ($this->sampleError) { throw $this->sampleError; }
            return $this->sample;
        }
        public function recordHealth(bool $healthy): void { $this->health[] = $healthy; }
        public function digest(bool $daily = true): string { $this->digestCalls++; return 'test_delivery_disabled'; }
    }
    final class HermesConversationMemory
    {
        public static function observe(array &$state): void {}
        public static function sensitive(string $value): bool { return false; }
    }
    final class HermesOperationsPolicy
    {
        public static function approvedRules(array $state): array { return []; }
    }
}

namespace App\Services\Bale\Admin {
    final class HermesMiniAppStore
    {
        public bool $fail = false;
        public function transact(array $owner, callable $callback): void
        {
            if ($this->fail) { throw new \RuntimeException('private-diagnostic-marker'); }
            $state = [];
            $callback($state);
        }
    }
}
