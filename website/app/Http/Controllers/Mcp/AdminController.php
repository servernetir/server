<?php

namespace App\Http\Controllers\Mcp;

use App\Models\McpAdminAction;
use App\Models\Setting;
use App\Services\Mcp\AdminAccess;
use App\Services\Mcp\CommandApprovals;
use App\Services\Mcp\InfrastructureRead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Token;

final class AdminController
{
    public function index(Request $request, InfrastructureRead $reader)
    {
        return response()->view('mcp-admin.index', [
            'enabled' => AdminAccess::allowed($request->user('web')),
            'owner' => AdminAccess::owner($request->user('web')),
            'tokens' => Token::where('user_id', $request->user('web')->id)->with('client')
                ->where('revoked', false)->where('expires_at', '>', now())->get(),
            'inventory' => $reader->inventory(),
        ], 200, ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function enable(Request $request)
    {
        abort_unless(AdminAccess::owner($request->user('web')), 403);
        $request->validate(['accept' => ['accepted']]);
        DB::transaction(function () use ($request) {
            // Serialize policy edits on the existing enabled setting row.
            Setting::firstOrCreate(['key' => 'mcp_admin_enabled'], ['value' => '0']);
            Setting::where('key', 'mcp_admin_enabled')->lockForUpdate()->firstOrFail();
            $ids = json_decode(Setting::where('key', 'mcp_admin_user_ids')->value('value') ?? '[]', true) ?: [];
            $ids[] = (int) $request->user('web')->id;
            Setting::put('mcp_admin_user_ids', json_encode(array_values(array_unique($ids))));
            Setting::put('mcp_admin_enabled', '1');
        });
        return redirect('/admin/mcp');
    }

    public function disable(Request $request)
    {
        abort_unless(AdminAccess::owner($request->user('web')), 403);
        DB::transaction(function () use ($request) {
            Setting::where('key', 'mcp_admin_enabled')->lockForUpdate()->firstOrFail();
            $ids = json_decode(Setting::where('key', 'mcp_admin_user_ids')->value('value') ?? '[]', true) ?: [];
            $ids = array_values(array_filter($ids, fn ($id) => (int) $id !== (int) $request->user('web')->id));
            Setting::put('mcp_admin_user_ids', json_encode($ids));
            if ($ids === []) {
                Setting::put('mcp_admin_enabled', '0');
            }
            $this->revokeTokens($request->user('web')->id);
        });
        return redirect('/admin/mcp');
    }

    public function revoke(Request $request, string $client)
    {
        DB::transaction(fn () => $this->revokeTokens($request->user('web')->id, $client));
        return redirect('/admin/mcp');
    }

    private function revokeTokens(int $user, ?string $client = null): void
    {
        $query = Token::where('user_id', $user)->when($client !== null, fn ($q) => $q->where('client_id', $client));
        $ids = (clone $query)->pluck('id');
        DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $ids)->update(['revoked' => true]);
        $query->update(['revoked' => true]);
        McpAdminAction::where('user_id', $user)->whereIn('token_id', $ids)
            ->whereIn('status', ['pending', 'approved'])->update(['status' => 'revoked']);
    }

    public function action(Request $request, string $action)
    {
        $record = McpAdminAction::where('user_id', $request->user('web')->id)->findOrFail($action);
        return response()->view('mcp-admin.action', ['action' => $record], 200,
            ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function approve(Request $request, string $action, CommandApprovals $approvals)
    {
        $request->validate(['accept' => ['accepted']]);
        $approvals->approve($request->user('web'), $action);
        return redirect('/admin/mcp/actions/'.$action);
    }
}
