<?php
namespace App\Http\Controllers;
use App\Models\VmRecord;
use Illuminate\Http\Request;
class CountryRouteApiController extends Controller
{
    // Agent-facing desired-state: which VMs must egress via which country.
    public function index(Request $request)
    {
        $token = (string) config('proxmox.agent_token');
        $given = (string) $request->header('X-PF-Token');
        if ($token === '' || !hash_equals($token, $given)) {
            abort(403, 'forbidden');
        }
        return response()->json(
            VmRecord::whereNotNull('exit_country')->where('exit_country', '!=', '')
                ->get(['ip', 'exit_country'])
                ->map(fn ($v) => ['ip' => $v->ip, 'cc' => $v->exit_country])
                ->values()
        );
    }
}
