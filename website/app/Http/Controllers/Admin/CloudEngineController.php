<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CloudImage;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\EngineAddress;
use App\Models\EngineImage;
use App\Models\EngineNode;
use App\Models\EngineOperation;
use App\Models\EnginePlanNode;
use App\Models\EngineSubnet;
use App\Models\EngineVm;
use App\Services\Cloud\Engine\EngineTrafficService;
use App\Services\Cloud\Engine\Ipam;
use App\Services\Cloud\Engine\NodeHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CloudEngineController extends Controller
{
    public function index(Request $request)
    {
        $vms = EngineVm::with(['node', 'address', 'service.customer'])->latest()->paginate(30, ['*'], 'vms_page');
        $traffic = $vms->getCollection()->mapWithKeys(fn ($vm) => [$vm->id => app(EngineTrafficService::class)->report($vm)]);

        return response()->view('admin.cloud-engine', [
            'nodes' => EngineNode::withCount('vms')->orderBy('id')->get(),
            'editing' => $request->integer('node') ? EngineNode::findOrFail($request->integer('node')) : null,
            'vms' => $vms,
            'traffic' => $traffic,
            'operations' => EngineOperation::with('vm')->latest()->paginate(20, ['*'], 'operations_page'),
            'addresses' => EngineAddress::with('subnet')->orderBy('address')->paginate(30, ['*'], 'addresses_page'),
            'images' => EngineImage::with('node')->orderBy('node_id')->get(),
            'subnets' => EngineSubnet::with('node')->orderBy('id')->get(),
            'plans' => CloudPlan::where('provider', 'cloud-engine')->orderBy('id')->get(),
            'mappings' => EnginePlanNode::orderBy('node_id')->get(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function node(Request $request, ?EngineNode $node = null)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('cloud_engine_nodes')->ignore($node?->id)],
            'location_code' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9-]+$/'],
            'api_url' => ['required', 'url:https', 'max:255'],
            'pve_node' => ['required', 'string', 'regex:/^[a-zA-Z0-9-]+$/', 'max:80'],
            'token_id' => ['required', 'string', 'regex:/^[a-zA-Z0-9_.-]+@[a-zA-Z0-9_.-]+![a-zA-Z0-9_.-]+$/', 'max:190'],
            'token_secret' => [$node ? 'nullable' : 'required', 'string', 'max:500'],
            'storage' => ['required', 'string', 'regex:/^[a-zA-Z0-9_.-]+$/', 'max:80'],
            'bridge' => ['required', 'string', 'regex:/^[a-zA-Z0-9_.-]+$/', 'max:32'],
            'gateway' => ['required', 'ipv4'],
            'vcpu_capacity' => ['required', 'integer', 'min:1', 'max:4096'],
            'ram_mb_capacity' => ['required', 'integer', 'min:1024', 'max:16777216'],
            'disk_gb_capacity' => ['required', 'integer', 'min:1', 'max:16777216'],
            'vcpu_reserve' => ['required', 'integer', 'min:0', 'lt:vcpu_capacity'],
            'ram_mb_reserve' => ['required', 'integer', 'min:512', 'lt:ram_mb_capacity'],
            'disk_gb_reserve' => ['required', 'integer', 'min:1', 'lt:disk_gb_capacity'],
            'vmid_min' => ['required', 'integer', 'min:100', 'max:999999999'],
            'vmid_max' => ['required', 'integer', 'gte:vmid_min', 'max:999999999'],
        ]);
        $parts = parse_url($data['api_url']);
        if (! is_array($parts) || ($parts['path'] ?? '') !== '/api2/json'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw ValidationException::withMessages(['api_url' => __('cloud_engine.invalid_endpoint')]);
        }
        DB::transaction(function () use ($node, $data) {
            if ($node) {
                $node = EngineNode::whereKey($node->id)->lockForUpdate()->firstOrFail();
                $live = $node->vms()->where('status', '!=', 'deleted')->exists();
                foreach (['api_url', 'pve_node', 'location_code', 'bridge', 'storage', 'gateway', 'vmid_min', 'vmid_max'] as $field) {
                    if ($live && (string) $node->$field !== (string) $data[$field]) {
                        throw ValidationException::withMessages([$field => __('cloud_engine.node_in_use')]);
                    }
                }
                if ($node->sales_enabled) {
                    throw ValidationException::withMessages(['name' => __('cloud_engine.disable_first')]);
                }
            } else {
                $node = new EngineNode;
            }
            if (empty($data['token_secret'])) {
                unset($data['token_secret']);
            }
            $node->fill($data)->forceFill(['health' => 'unknown', 'health_checked_at' => null])->save();
            ActivityLog::record(null, 'cloud_engine.node', 'Infrastructure node '.$node->id.' configuration saved', actor: 'staff');
        });

        return back()->with('ok', __('cloud_engine.saved'));
    }

    public function control(Request $request, EngineNode $node)
    {
        $action = $request->validate(['action' => ['required', Rule::in(['drain', 'undrain', 'enable', 'disable', 'probe'])]])['action'];
        if ($action === 'probe') {
            $result = app(NodeHealth::class)->check($node);

            return back()->with($result['ok'] ? 'ok' : 'warn', __('cloud_engine.health_'.$result['state']));
        }
        DB::transaction(function () use ($node, $action) {
            $node = EngineNode::whereKey($node->id)->lockForUpdate()->firstOrFail();
            if ($action === 'enable') {
                $candidate = clone $node;
                $candidate->sales_enabled = true;
                if (! $candidate->acceptsSales() || ! $node->images()->get()->contains(fn ($i) => $i->accepted())
                    || ! EngineAddress::where('node_id', $node->id)->where('state', 'free')
                        ->whereHas('subnet', fn ($q) => $q->where('enabled', true))->exists()
                    || ! EnginePlanNode::where('node_id', $node->id)->where('enabled', true)->exists()) {
                    throw ValidationException::withMessages(['action' => __('cloud_engine.admission_required')]);
                }
            }
            $node->update(match ($action) {
                'drain' => ['draining' => true, 'sales_enabled' => false],
                'undrain' => ['draining' => false],
                'enable' => ['sales_enabled' => true],
                default => ['sales_enabled' => false],
            });
            ActivityLog::record(null, 'cloud_engine.node', 'Infrastructure node '.$node->id.' control '.$action, actor: 'staff');
        });

        return back()->with('ok', __('cloud_engine.saved'));
    }

    public function subnet(Request $request, Ipam $ipam)
    {
        $data = $request->validate(['node_id' => ['required', 'exists:cloud_engine_nodes,id'],
            'cidr' => ['required', 'string', 'max:18'], 'gateway' => ['required', 'ipv4']]);
        try {
            $subnet = $ipam->import(EngineNode::findOrFail($data['node_id']), $data['cidr'], $data['gateway']);
        } catch (\DomainException) {
            throw ValidationException::withMessages(['cidr' => __('cloud_engine.invalid_subnet')]);
        }
        ActivityLog::record(null, 'cloud_engine.subnet', 'Infrastructure subnet '.$subnet->id.' imported disabled', actor: 'staff');

        return back()->with('ok', __('cloud_engine.saved'));
    }

    public function subnetControl(Request $request, EngineSubnet $subnet)
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $subnet->update(['enabled' => (bool) $data['enabled']]);
        ActivityLog::record(null, 'cloud_engine.subnet', 'Infrastructure subnet '.$subnet->id.' allocation control updated', actor: 'staff');

        return back()->with('ok', __('cloud_engine.saved'));
    }

    public function image(Request $request)
    {
        $data = $request->validate([
            'node_id' => ['required', 'exists:cloud_engine_nodes,id'],
            'key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9.-]+$/'],
            'family' => ['required', Rule::in(['ubuntu', 'debian', 'almalinux', 'rocky', 'windows'])],
            'version' => ['required', 'string', 'max:40'], 'template_vmid' => ['required', 'integer', 'min:100'],
            'checksum_sha256' => ['required', 'regex:/^[a-fA-F0-9]{64}$/'],
            'source_url' => ['required', 'url:https', 'max:1024'],
            'min_ram_mb' => ['required', 'integer', 'min:256'], 'min_disk_gb' => ['required', 'integer', 'min:1'],
            'guest_agent' => ['sometimes', 'boolean'],
        ]);
        $agent = (bool) ($data['guest_agent'] ?? false);
        unset($data['guest_agent']);
        if (EngineImage::where('node_id', $data['node_id'])->where('key', $data['key'])->exists()) {
            throw ValidationException::withMessages(['key' => __('cloud_engine.immutable_image')]);
        }
        $image = EngineImage::create($data + ['arch' => 'x86', 'metadata' => ['guest_agent' => $agent]]);
        ActivityLog::record(null, 'cloud_engine.image', 'Infrastructure image '.$image->id.' registered unaccepted', actor: 'staff');

        return back()->with('ok', __('cloud_engine.saved'));
    }

    public function acceptImage(Request $request, EngineImage $image)
    {
        $data = $request->validate(['reference' => ['required', 'string', 'min:12', 'max:200'],
            'initialization_verified' => ['required', 'accepted'],
            'guest_agent_verified' => ['required', 'accepted'],
            'windows_ready_receipt_verified' => [$image->family === 'windows' ? 'required' : 'sometimes', 'accepted'],
            'license_verified' => [$image->family === 'windows' ? 'required' : 'sometimes', 'accepted']]);
        DB::transaction(function () use ($image, $data) {
            $image = EngineImage::whereKey($image->id)->lockForUpdate()->firstOrFail();
            $metadata = $image->metadata ?? [];
            $metadata['guest_agent_verified'] = isset($data['guest_agent_verified']);
            $metadata['windows_ready_receipt_verified'] = isset($data['windows_ready_receipt_verified']);
            $metadata['acceptance'] = ['reference' => $data['reference'], 'actor_id' => auth()->id(), 'at' => now()->toIso8601String()];
            $image->update(['accepted_at' => now(), 'initialization_verified_at' => now(),
                'license_verified_at' => isset($data['license_verified']) ? now() : null, 'metadata' => $metadata]);
            // Existing catalog/image selection is the only path used by checkout.
            CloudImage::updateOrCreate(['provider' => 'cloud-engine', 'key' => $image->key], [
                'provider_ref' => $image->key, 'kind' => 'os', 'family' => $image->family,
                'version' => $image->version, 'label' => $image->family.' '.$image->version,
                'arch' => $image->arch, 'min_ram_mb' => $image->min_ram_mb, 'min_disk_gb' => $image->min_disk_gb, 'is_active' => $image->accepted(),
            ]);
            ActivityLog::record(null, 'cloud_engine.image', 'Infrastructure image '.$image->id.' acceptance recorded', actor: 'staff');
        });

        return back()->with('ok', __('cloud_engine.saved'));
    }

    public function mapping(Request $request)
    {
        $data = $request->validate(['node_id' => ['required', 'exists:cloud_engine_nodes,id'],
            'cloud_plan_id' => ['required', 'exists:cloud_plans,id'], 'enabled' => ['required', 'boolean']]);
        $plan = CloudPlan::findOrFail($data['cloud_plan_id']);
        $node = EngineNode::findOrFail($data['node_id']);
        abort_unless($plan->provider === 'cloud-engine' && $plan->location_code === $node->location_code, 422);
        EnginePlanNode::updateOrCreate(collect($data)->only(['cloud_plan_id', 'node_id'])->all(), ['enabled' => (bool) $data['enabled']]);
        ActivityLog::record(null, 'cloud_engine.mapping', 'Infrastructure node '.$node->id.' plan '.$plan->id.' mapping updated', actor: 'staff');

        return back()->with('ok', __('cloud_engine.saved'));
    }

    public function plan(Request $request)
    {
        $data = $request->validate([
            'provider_ref' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9.-]+$/'],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/'],
            'public_name' => ['required', 'string', 'max:100'],
            'location_code' => ['required', 'string', 'max:32', 'regex:/^[a-z]{2}-[a-z0-9-]+$/'],
            'country' => ['required', 'string', 'regex:/^[A-Z]{2}$/'],
            'city' => ['required', 'string', 'max:100'],
            'vcpu' => ['required', 'integer', 'min:1', 'max:256'],
            'ram_mb' => ['required', 'integer', 'min:512', 'max:1048576'],
            'disk_gb' => ['required', 'integer', 'min:5', 'max:65536'],
            'traffic_gb' => ['required', 'integer', 'min:1', 'max:1000000'],
            'cost_eur_cents' => ['required', 'integer', 'min:1', 'max:100000000'],
            'price_irt' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'price_eur_cents' => ['required', 'integer', 'min:1', 'max:100000000'],
        ]);
        DB::transaction(function () use ($data) {
            if (CloudPlan::where('provider', 'cloud-engine')->where('provider_ref', $data['provider_ref'])
                ->where('location_code', $data['location_code'])->exists()) {
                throw ValidationException::withMessages(['provider_ref' => __('cloud_engine.immutable_plan')]);
            }
            CloudLocation::firstOrCreate(['code' => $data['location_code']], [
                'country' => $data['country'], 'city' => $data['city'], 'is_active' => true,
            ]);
            $plan = CloudPlan::create(collect($data)->except(['country', 'city'])->all() + [
                'provider' => 'cloud-engine', 'provider_location' => $data['location_code'],
                'arch' => 'x86', 'disk_type' => 'ssd', 'cpu_kind' => 'shared', 'is_active' => true,
                'in_stock' => false, 'admin_disabled' => true,
                'admin_note' => 'Dedicated infrastructure acceptance and commercial review pending',
            ]);
            ActivityLog::record(null, 'cloud_engine.plan', 'Infrastructure plan '.$plan->id.' registered with sales disabled', actor: 'staff');
        });

        return back()->with('ok', __('cloud_engine.saved'));
    }

    public function retry(Request $request, EngineOperation $operation)
    {
        $request->validate(['confirm' => ['required', 'accepted']]);
        DB::transaction(function () use ($operation) {
            $operation = EngineOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($operation->status, ['blocked', 'failed', 'uncertain'], true)
                && ($operation->lease_until === null || $operation->lease_until->isPast()), 409);
            // Preserve phase, remote receipt and identity: retry means reconcile,
            // never discard an uncertain mutation and start another clone.
            $operation->update(['status' => 'pending', 'deadline_at' => now()->addMinutes(30)]);
            DB::table('cloud_engine_operation_events')->insert(['operation_id' => $operation->id,
                'phase' => $operation->phase, 'status' => 'pending', 'error_code' => 'admin_reconcile', 'created_at' => now()]);
            ActivityLog::record(null, 'cloud_engine.retry', 'Infrastructure operation '.$operation->id.' reconciliation requested', actor: 'staff', serviceId: $operation->vm->service_id);
        });

        return back()->with('ok', __('cloud_engine.saved'));
    }
}
