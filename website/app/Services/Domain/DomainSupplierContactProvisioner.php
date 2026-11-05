<?php
namespace App\Services\Domain;

use App\Models\{Customer, CustomerProfile, DomainSupplierAccount, DomainSupplierContactRequest};
use App\Services\Domain\Providers\{DomainProviderException, DomainProviderFactory};
use Illuminate\Support\Facades\{Cache, DB, Schema};

/** New contacts only, before customer payment. An unknown creation is reconciled, never resent. */
class DomainSupplierContactProvisioner
{
    public function __construct(private DomainProviderFactory $providers) {}

    private function payload(CustomerProfile $profile): ?array
    {
        // Never inherit the legacy default ZIP/country or use a company contact as this customer's owner.
        foreach (['first_name', 'last_name', 'email', 'address', 'city', 'postal_code', 'country', 'mobile'] as $field) {
            if (trim((string) $profile->$field) === '') return null;
        }
        if (! preg_match('/^[A-Z]{2}$/D', strtoupper($profile->country)) || ! filter_var($profile->email, FILTER_VALIDATE_EMAIL)
            || ($profile->isCompany() && blank($profile->company_name))) return null;
        return app(DomainRegistrar::class)->profileToCustomer($profile);
    }

    private function hash(CustomerProfile $profile, DomainSupplierAccount $account, string $tld, array $payload): string
    {
        return hash_hmac('sha256', json_encode([$profile->id, $account->id, $tld, $payload], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function current(Customer $customer, DomainSupplierAccount $account, string $tld): ?DomainSupplierContactRequest
    {
        if (! Schema::hasTable('domain_supplier_contact_requests')) return null;
        $profile = $customer->defaultProfile(); $payload = $profile ? $this->payload($profile) : null;
        if (! $profile || ! $payload) return null;
        return DomainSupplierContactRequest::where('request_hash', $this->hash($profile, $account, $tld, $payload))
            ->where('supplier_identity', DomainSupplierCatalogue::contactIdentity($account))->where('status', 'succeeded')
            ->whereNotNull('handle')->first();
    }

    public function ensure(Customer $customer, DomainSupplierAccount $account, string $tld): bool
    {
        if ($this->current($customer, $account, $tld)) return true;
        if (! config('domain_suppliers.contact_provisioning_enabled', false) || ! $account->saleEligible()
            || ($account->capabilities['tlds'][$tld]['contact_creation'] ?? null) !== 'standard'
            || ! Schema::hasTable('domain_supplier_contact_requests') || $customer->status !== 'active') return false;
        $profile = $customer->defaultProfile(); $payload = $profile ? $this->payload($profile) : null;
        if (! $profile || ! $payload) return false;
        // Registry declarations (US nexus, FI identity, SE/NU orgid, UK legal type)
        // are not present in a standard profile. Stop before the durable send claim.
        if ($account->provider === 'joker' && ! \App\Services\Domain\Providers\JokerAdapter::supportsStandardContact($tld, $payload)) return false;
        $key = hash('sha256', $account->id.'|'.$profile->id.'|'.$tld);
        $lock = Cache::lock('domain-supplier-contact:'.$key, 180);
        if (! $lock->get()) return false;
        try {
            $hash = $this->hash($profile, $account, $tld, $payload);
            $request = DomainSupplierContactRequest::where('request_hash', $hash)->first();
            if ($request && in_array($request->status, ['submitting', 'queued', 'unknown'], true)) {
                $this->reconcile($request);
                return $this->current($customer, $account, $tld) !== null;
            }
            if ($request && $request->status !== 'prepared') return $this->current($customer, $account, $tld) !== null;
            if ($request && ! hash_equals($request->supplier_identity, DomainSupplierCatalogue::contactIdentity($account))) return false;
            if (! $request) {
                if (DomainSupplierContactRequest::where('active_key', $key)->exists()) return false;
                $request = DB::transaction(fn () => DomainSupplierContactRequest::create([
                    'supplier_account_id' => $account->id, 'customer_profile_id' => $profile->id, 'tld' => $tld,
                    'request_hash' => $hash, 'active_key' => $key, 'supplier_identity' => DomainSupplierCatalogue::contactIdentity($account),
                    'payload' => $payload, 'status' => 'prepared', 'attempts' => 0]));
            }
            $claimed = DomainSupplierContactRequest::whereKey($request->id)->where('status', 'prepared')->whereNull('sent_at')
                ->update(['status' => 'submitting', 'sent_at' => now(), 'attempts' => 1]);
            if ($claimed !== 1) return false;
            try {
                $result = $this->providers->forAccount($account)->createContact($tld, $payload, 'sn-contact-'.$request->id);
            } catch (DomainProviderException $e) { $result = ['status' => $e->uncertain ? 'unknown' : 'failed']; }
            catch (\Throwable) { $result = ['status' => 'unknown']; }
            $this->apply($request, $result);
            return $this->current($customer, $account->fresh(), $tld) !== null;
        } catch (\Throwable) { return false; }
        finally { $lock->release(); }
    }

    /** Read-only; works while creation/sales are disabled. */
    public function reconcile(DomainSupplierContactRequest $request): void
    {
        $request->refresh();
        if (! in_array($request->status, ['submitting', 'queued', 'unknown'], true)) return;
        $account = DomainSupplierAccount::find($request->supplier_account_id);
        if (! $account || ! hash_equals($request->supplier_identity, DomainSupplierCatalogue::contactIdentity($account))) {
            $request->update(['status' => 'manual_review', 'reconciled_at' => now()]); return;
        }
        try {
            $result = $this->providers->forAccount($account)->reconcileContact(['process_id' => $request->process_id,
                'tracking_id' => $request->tracking_id, 'tld' => $request->tld,
                'handle' => $request->handle, 'payload' => $request->payload]);
        } catch (\Throwable) { $result = ['status' => 'unknown']; }
        $this->apply($request, $result);
    }

    private function apply(DomainSupplierContactRequest $request, array $result): void
    {
        DB::transaction(function () use ($request, $result) {
            $fresh = DomainSupplierContactRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if (in_array($fresh->status, ['succeeded', 'failed', 'manual_review'], true)) return;
            $state = in_array($result['status'] ?? '', ['queued', 'succeeded', 'failed', 'unknown'], true) ? $result['status'] : 'unknown';
            if ($state === 'succeeded' && (! is_string($result['handle'] ?? null) || ! preg_match('/^[A-Za-z0-9-]{1,128}$/D', $result['handle']))) $state = 'unknown';
            $values = ['status' => $state, 'active_key' => in_array($state, ['succeeded', 'failed'], true) ? null : $fresh->active_key,
                'reconciled_at' => now()];
            foreach (['process_id', 'tracking_id', 'handle'] as $field) {
                if (is_string($result[$field] ?? null) && preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $result[$field])) $values[$field] = $result[$field];
            }
            $fresh->update($values);
        });
    }
}
