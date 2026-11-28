<?php

namespace App\Services\Mcp;

use App\Models\Setting;
use App\Models\User;

final class AdminAccess
{
    public const READ = 'infrastructure:read';
    public const EXECUTE = 'infrastructure:execute';
    public const RELEASE_REVIEW = 'infrastructure:release:review';
    public const MANAGEMENT_READ = 'management:read';
    public const TICKET_REPLY = 'management:tickets:reply';

    public static function enabled(): bool
    {
        return Setting::get('mcp_admin_enabled', '0') === '1';
    }

    public static function allowed(?User $user): bool
    {
        $ids = json_decode(Setting::get('mcp_admin_user_ids', '[]'), true);
        return self::enabled() && self::owner($user) && is_array($ids)
            && in_array((int) $user->id, array_map('intval', $ids), true);
    }

    public static function owner(?User $user): bool
    {
        return $user?->isAdmin() && (int) Setting::get('mcp_admin_owner_user_id', '0') === (int) $user->id;
    }

    public static function autoApproveCommands(?User $user): bool
    {
        return self::allowed($user) && Setting::get('mcp_admin_ssh_auto_approve', '1') === '1';
    }

    public static function scopes(): array
    {
        return [self::READ => 'mcp_admin.scope_read', self::EXECUTE => 'mcp_admin.scope_execute',
            self::RELEASE_REVIEW => 'mcp_admin.scope_release_review',
            self::MANAGEMENT_READ => 'mcp_admin.scope_management_read', self::TICKET_REPLY => 'mcp_admin.scope_ticket_reply'];
    }
}
