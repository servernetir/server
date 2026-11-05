<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\DomainSupplierAccount;
use App\Models\DomainSupplierOperation;
use App\Models\DomainSupplierContact;
use App\Services\Domain\DomainSupplierLifecycle;
use App\Services\Domain\DomainSupplierProbe;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/** Account configuration and read-only checks; never registration, payment or renewal. */
class DomainSupplierController extends Controller
{
    public function index(): View
    {
        $installed = Schema::hasTable('domain_supplier_accounts');
        return view('admin.domain-suppliers.index', [
            'installed' => $installed,
            'accounts' => $installed ? DomainSupplierAccount::orderBy('id')->get() : collect(),
            'operations' => $installed ? DomainSupplierOperation::with(['domain.customer', 'account', 'invoice'])
                ->whereNotIn('status', ['succeeded'])->latest('id')->limit(100)->get() : collect(),
            'canApprove' => in_array((int) auth('web')->id(), (array) config('domain_suppliers.approver_user_ids', []), true),
            'enabled' => (bool) config('domain_suppliers.enabled', false),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request, new DomainSupplierAccount);
    }

    public function update(Request $request, DomainSupplierAccount $supplierAccount): RedirectResponse
    {
        return $this->save($request, $supplierAccount);
    }

    private function save(Request $request, DomainSupplierAccount $account): RedirectResponse
    {
        abort_unless(Schema::hasTable('domain_supplier_accounts'), 503);
        // Validate a local copy and never flash the submitted credential into session old-input.
        $input = $request->only(['provider', 'environment', 'label', 'username', 'password', 'api_key',
            'active', 'contract_verified', 'identity_verified', 'pricing_verified',
            'capabilities', 'supplier_vat_pct', 'supplier_fee_pct']);
        $validator = Validator::make($input, [
            'provider' => ['required', 'in:openprovider,joker'],
            'environment' => ['required', 'in:production,ote'],
            'label' => ['required', 'string', 'max:100'],
            'username' => ['nullable', 'string', 'max:500'],
            'password' => ['nullable', 'string', 'max:2000'],
            'api_key' => ['nullable', 'string', 'max:2000'],
            'active' => ['sometimes', 'boolean'], 'contract_verified' => ['sometimes', 'boolean'],
            'identity_verified' => ['sometimes', 'boolean'], 'pricing_verified' => ['sometimes', 'boolean'],
            'capabilities' => ['nullable', 'string', 'max:50000'],
            'supplier_vat_pct' => ['nullable', 'numeric', 'between:0,100'],
            'supplier_fee_pct' => ['nullable', 'numeric', 'between:0,100'],
        ]);
        if ($validator->fails()) return back()->withErrors($validator);
        $data = $validator->validated();
        $capabilities = [];
        try {
            $capabilities = trim((string) ($data['capabilities'] ?? '')) === '' ? []
                : json_decode($data['capabilities'], true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($capabilities) || array_is_list($capabilities) && $capabilities !== []) {
                throw new \InvalidArgumentException;
            }
        } catch (\Throwable) {
            return back()->withErrors(['capabilities' => __('domain-suppliers.invalid_capabilities')]);
        }
        if ($account->exists && ($account->provider !== $data['provider'] || $account->environment !== $data['environment'])) {
            return back()->withErrors(['provider' => __('domain-suppliers.identity_immutable')]);
        }
        $newCredentials = array_filter(array_intersect_key($data, array_flip(['username', 'password', 'api_key'])),
            fn ($v) => is_string($v) && trim($v) !== '');
        // Rotation for a populated account needs a separate audited principal-verification flow.
        if ($account->exists && $newCredentials !== [] && (Domain::where('supplier_account_id', $account->id)->exists()
            || \App\Models\Invoice::where('supplier_account_id', $account->id)->exists()
            || \App\Models\DomainQuote::where('supplier_account_id', $account->id)->exists()
            || DomainSupplierContact::where('supplier_account_id', $account->id)->exists()
            || DomainSupplierOperation::where('supplier_account_id', $account->id)->exists())) {
            return back()->withErrors(['credentials' => __('domain-suppliers.bound_credentials')]);
        }
        if ($account->legacy_config && $newCredentials !== []) {
            return back()->withErrors(['credentials' => __('domain-suppliers.legacy_credentials')]);
        }
        DB::transaction(function () use ($account, $data, $capabilities, $newCredentials) {
            $changed = ! $account->exists || $newCredentials !== []
                || $capabilities !== (array) $account->capabilities
                || (string) ($data['supplier_vat_pct'] ?? '') !== (string) ($account->settings['supplier_vat_pct'] ?? '')
                || (string) ($data['supplier_fee_pct'] ?? '') !== (string) ($account->settings['supplier_fee_pct'] ?? '');
            $account->fill([
                'provider' => $data['provider'], 'environment' => $data['environment'], 'label' => $data['label'],
                'active' => (bool) ($data['active'] ?? false),
                'contract_verified' => (bool) ($data['contract_verified'] ?? false),
                'identity_verified' => (bool) ($data['identity_verified'] ?? false),
                'pricing_verified' => (bool) ($data['pricing_verified'] ?? false),
                'capabilities' => $capabilities,
                'settings' => array_filter(array_merge((array) $account->settings, [
                    'supplier_vat_pct' => $data['supplier_vat_pct'] ?? null,
                    'supplier_fee_pct' => $data['supplier_fee_pct'] ?? null,
                ]), fn ($v) => $v !== null && $v !== ''),
            ]);
            if ($newCredentials !== []) $account->credentials = array_merge((array) $account->credentials, $newCredentials);
            if ($changed) $account->fill(['sales_enabled' => false, 'connection_status' => 'unknown', 'health_checked_at' => null]);
            if (! $account->active || ! $account->contract_verified || ! $account->identity_verified || ! $account->pricing_verified) {
                $account->sales_enabled = false;
            }
            $account->save();
        });
        return redirect()->route('admin.domain-suppliers')->with('ok', __('domain-suppliers.saved'));
    }

    public function probe(DomainSupplierAccount $supplierAccount, DomainSupplierProbe $probe): RedirectResponse
    {
        $healthy = $probe->check($supplierAccount);
        return back()->with($healthy ? 'ok' : 'error', __('domain-suppliers.'.($healthy ? 'probe_ok' : 'probe_failed')));
    }

    /** Record an existing handle after owner verification; never create or edit registry contacts. */
    public function contact(Request $request, DomainSupplierAccount $supplierAccount): RedirectResponse
    {
        abort_unless(in_array((int) auth('web')->id(), (array) config('domain_suppliers.approver_user_ids', []), true), 403);
        $validator = Validator::make($request->only(['customer_profile_id', 'handle', 'evidence', 'verified']), [
            'customer_profile_id' => ['required', 'integer', 'exists:customer_profiles,id'],
            'handle' => ['required', 'string', 'max:128', 'regex:/\\A[A-Za-z0-9_.:-]+\\z/D'],
            'evidence' => ['required', 'string', 'min:10', 'max:500'],
            'verified' => ['required', 'accepted'],
        ]);
        if ($validator->fails()) return back()->withErrors($validator);
        $data = $validator->validated();
        $existing = DomainSupplierContact::where('supplier_account_id', $supplierAccount->id)
            ->where(fn ($q) => $q->where('customer_profile_id', $data['customer_profile_id'])->where('role', 'registrant')
                ->orWhere('handle', $data['handle']))->first();
        if ($existing && ((int) $existing->customer_profile_id !== (int) $data['customer_profile_id'] || $existing->handle !== $data['handle'])) {
            return back()->withErrors(['handle' => __('domain-suppliers.contact_conflict')]);
        }
        $contact = $existing ?? new DomainSupplierContact;
        $contact->fill(['supplier_account_id' => $supplierAccount->id,
            'customer_profile_id' => (int) $data['customer_profile_id'], 'role' => 'registrant',
            'handle' => $data['handle'], 'status' => 'active', 'verified_at' => now(),
            'verified_by' => (int) auth('web')->id(), 'evidence' => $data['evidence']])->save();
        return back()->with('ok', __('domain-suppliers.contact_saved'));
    }

    public function refreshQuote(Request $request, DomainSupplierOperation $supplierOperation, DomainSupplierLifecycle $lifecycle): RedirectResponse
    {
        abort_unless(in_array((int) auth('web')->id(), (array) config('domain_suppliers.approver_user_ids', []), true), 403);
        $lifecycle->refreshQuote($supplierOperation, (int) auth('web')->id());
        return back()->with('ok', __('domain-suppliers.quote_refreshed'));
    }

    public function approve(Request $request, DomainSupplierOperation $supplierOperation, DomainSupplierLifecycle $lifecycle): RedirectResponse
    {
        abort_unless(in_array((int) auth('web')->id(), (array) config('domain_suppliers.approver_user_ids', []), true), 403);
        $hash = $request->input('request_hash');
        if (! is_string($hash) || ! is_string($supplierOperation->request_hash)
            || ! hash_equals($supplierOperation->request_hash, $hash)) {
            return back()->withErrors(['operation' => __('domain-suppliers.operation_invalid')]);
        }
        $lifecycle->approve($supplierOperation, (int) auth('web')->id());
        return back()->with('ok', __('domain-suppliers.operation_approved'));
    }

    public function sales(Request $request, DomainSupplierAccount $supplierAccount): RedirectResponse
    {
        $enable = $request->boolean('enabled');
        if ($enable && $supplierAccount->readinessIssues() !== []) {
            return back()->withErrors(['sales' => __('domain-suppliers.not_ready')]);
        }
        $supplierAccount->forceFill(['sales_enabled' => $enable])->save();
        return back()->with('ok', __('domain-suppliers.saved'));
    }
}
