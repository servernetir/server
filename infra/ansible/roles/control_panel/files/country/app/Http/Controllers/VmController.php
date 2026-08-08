<?php
namespace App\Http\Controllers;
use App\Models\VmRecord;
use App\Models\PortForward;
use App\Services\Provisioner;
use App\Services\ProxmoxService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
class VmController extends Controller
{
    public function index(ProxmoxService $pve)
    {
        $records = VmRecord::orderByDesc('vmid')->get();
        $live = collect();
        try {
            $live = collect($pve->listCustomerVMs())->keyBy('vmid');
        } catch (\Throwable $e) {
            // panel must never go down because Proxmox is briefly unreachable
        }
        return view('vms', [
            'records'   => $records,
            'live'      => $live,
            'created'   => session('created'),
            'catalog'   => config('os.catalog'),
            'countries' => config('countries.catalog'),
        ]);
    }
    public function store(Request $r, Provisioner $prov)
    {
        $data = $r->validate([
            'name'    => ['required', 'string', 'max:32', 'regex:/^[a-zA-Z0-9-]+$/'],
            'cores'   => ['required', 'integer', 'min:1', 'max:16'],
            'memory'  => ['required', 'integer', 'min:512', 'max:65536'],
            'os'      => ['required', Rule::in(array_keys(config('os.catalog')))],
            'country' => ['nullable', Rule::in(array_keys((array) config('countries.catalog')))],
        ]);
        try {
            $out = $prov->sell($data['name'], (int) $data['cores'], (int) $data['memory'], $data['os'], null, $data['country'] ?? null);
            return redirect()->route('vms.index')->with('created', $out);
        } catch (\Throwable $e) {
            return redirect()->route('vms.index')->withErrors(['provision' => $e->getMessage()]);
        }
    }
    public function action(Request $r, ProxmoxService $pve, int $vmid)
    {
        $act = (string) $r->input('action');
        abort_unless(in_array($act, ['start', 'stop'], true), 400);
        abort_unless(VmRecord::where('vmid', $vmid)->exists(), 403);
        try {
            $act === 'start' ? $pve->start($vmid) : $pve->stop($vmid);
        } catch (\Throwable $e) {
            return redirect()->route('vms.index')->withErrors(['vm' => $e->getMessage()]);
        }
        return redirect()->route('vms.index');
    }
    public function destroy(Request $r, ProxmoxService $pve, int $vmid)
    {
        // audit H4: the guard must NOT depend on config being present. Hardcode the
        // full red-line set here so a missing/empty config/proxmox.php can never
        // expose 108, the infra containers, the templates, the reserved 100-114
        // range, or anything below the customer floor. Config adds to it, never
        // replaces it. (The Ansible destroy.yml guard is separately fail-closed.)
        $hardProtected = [108, 113, 115, 9000, 9001, 9002, 9003, 9004, 9005, 9012];
        $cfgProtected  = array_map('intval', (array) config('proxmox.protected_vmids', []));
        $protected     = array_values(array_unique(array_merge($hardProtected, $cfgProtected)));
        $customerFloor = (int) config('proxmox.customer_vmid_min', 116);
        if (in_array($vmid, $protected, true)          // explicit red lines
            || ($vmid >= 100 && $vmid <= 114)          // pre-existing hand-managed range
            || $vmid < $customerFloor) {               // platform-sold guests start at the floor
            return redirect()->route('vms.index')->withErrors(['vm' => "VMID {$vmid} protected"]);
        }
        $rec = VmRecord::where('vmid', $vmid)->first();
        abort_unless($rec, 403);
        try {
            $pve->destroy($vmid);
        } catch (\Throwable $e) {
            return redirect()->route('vms.index')->withErrors(['vm' => $e->getMessage()]);
        }
        PortForward::where('vmid', $vmid)->delete();
        $rec->delete();
        return redirect()->route('vms.index');
    }
}
