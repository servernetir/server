<?php

namespace App\Services\Mcp;

use App\Models\McpAdminAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CommandApprovals
{
    public function request(User $user, string $target, string $command): array
    {
        abort_unless(AdminAccess::allowed($user) && $user->tokenCan(AdminAccess::EXECUTE), 403);
        abort_unless(strlen($command) >= 1 && strlen($command) <= 4096 && ! str_contains($command, "\0"), 422);
        abort_if(McpAdminAction::where('user_id', $user->id)->where('status', 'pending')
            ->where('expires_at', '>', now())->count() >= 20, 429);
        $automatic = AdminAccess::autoApproveCommands($user);
        $action = McpAdminAction::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id,
            'token_id' => $user->currentAccessToken()->id,
            'target' => $target, 'target_hash' => app(SshGateway::class)->targetHash($target),
            'command' => $command, 'status' => $automatic ? 'approved' : 'pending',
            'approved_at' => $automatic ? now() : null, 'expires_at' => now()->addMinutes(10),
        ]);
        return ['id' => $action->id, 'status' => $automatic ? 'approved' : 'awaiting_owner_approval',
            'approval_mode' => $automatic ? 'automatic' : 'manual',
            'approval_url' => config('mcp_admin.origin').'/admin/mcp/actions/'.$action->id,
            'expires_at' => $action->expires_at->toIso8601String()];
    }

    public function approve(User $user, string $id): void
    {
        DB::transaction(function () use ($user, $id) {
            $action = McpAdminAction::where('user_id', $user->id)->lockForUpdate()->findOrFail($id);
            abort_unless(AdminAccess::allowed($user) && $action->status === 'pending'
                && $action->expires_at->isFuture(), 409);
            abort_unless(hash_equals($action->target_hash, app(SshGateway::class)->targetHash($action->target)), 409);
            $action->update(['status' => 'approved', 'approved_at' => now()]);
        });
    }

    public function execute(User $user, string $id): array
    {
        abort_unless(AdminAccess::allowed($user) && $user->tokenCan(AdminAccess::EXECUTE), 403);
        $action = DB::transaction(function () use ($user, $id) {
            $a = McpAdminAction::where('user_id', $user->id)
                ->where('token_id', $user->currentAccessToken()->id)->lockForUpdate()->findOrFail($id);
            if (in_array($a->status, ['completed', 'unknown'], true)) {
                return $a;
            }
            abort_unless($a->status === 'approved' && $a->expires_at->isFuture(), 409);
            abort_unless(hash_equals($a->target_hash, app(SshGateway::class)->targetHash($a->target)), 409);
            $a->update(['status' => 'executing', 'executed_at' => now()]);
            return $a;
        });
        if ($action->result !== null) {
            return $action->result + ['replayed' => true];
        }
        // The durable claim precedes the network call. An interrupted process is
        // left 'executing' and must be reconciled manually, never retried blindly.
        try {
            $result = app(SshGateway::class)->run($action->target, $action->command);
        } catch (\Throwable) {
            $result = ['ok' => false, 'outcome' => 'unknown'];
        }
        $action->update(['status' => ($result['outcome'] ?? '') === 'unknown' ? 'unknown' : 'completed',
            'result' => $result]);
        return $result;
    }
}
