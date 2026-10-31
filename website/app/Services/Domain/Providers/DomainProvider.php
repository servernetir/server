<?php

namespace App\Services\Domain\Providers;

/**
 * Account-bound registrar contract. Money is integer currency minor units;
 * periods are months. No provider response, contact or credential is public.
 * A submitted request is not a delivered domain: only reconciliation can
 * promote queued/unknown operations to succeeded.
 */
interface DomainProvider
{
    public function probe(): array;

    public function quote(string $fqdn, string $operation, int $months, array $options = []): array;

    public function find(string $fqdn): array;

    public function submit(string $operation, string $fqdn, int $months, array $context): array;

    public function reconcile(array $operation): array;

    public function manage(string $fqdn, string $objectId, string $action, array $payload = []): array;
}
