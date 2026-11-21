<?php

namespace App\Mcp\Tools;

use App\Services\Mcp\AdminAccess;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

abstract class AdminTool extends Tool
{
    protected string $scope = AdminAccess::READ;

    public function shouldRegister(): bool
    {
        $user = request()->user('mcp_admin');
        return AdminAccess::allowed($user) && $user->tokenCan($this->scope);
    }

    public function toArray(): array
    {
        $result = parent::toArray();
        $result['inputSchema']['additionalProperties'] = false;
        $result['securitySchemes'] = [['type' => 'oauth2', 'scopes' => [$this->scope]]];
        return $result;
    }

    protected function perform(callable $call): Response
    {
        $user = request()->user('mcp_admin');
        abort_unless(AdminAccess::allowed($user) && $user->tokenCan($this->scope), 403);
        try {
            $data = $call();
            Log::info('mcp.admin.tool', ['user_id' => $user->id, 'tool' => $this->name(), 'outcome' => 'returned']);
            return Response::json($data);
        } catch (\Throwable $e) {
            Log::notice('mcp.admin.tool', ['user_id' => $user->id, 'tool' => $this->name(), 'outcome' => 'failed']);
            return Response::error('Operation unavailable or not permitted. Check the private ServerNet console.');
        }
    }
}
