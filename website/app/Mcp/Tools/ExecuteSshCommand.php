<?php

namespace App\Mcp\Tools;

use App\Services\Mcp\AdminAccess;
use App\Services\Mcp\CommandApprovals;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

final class ExecuteSshCommand extends AdminTool
{
    protected string $name = 'execute_ssh_command';
    protected string $scope = AdminAccess::EXECUTE;
    protected string $description = 'Execute one approved SSH command by its action ID, including commands approved automatically by the owner policy. The same OAuth token, owner, exact target, host keys and expiry are checked. Completed results may be replayed; interrupted or unknown operations are never automatically rerun.';
    public function schema(JsonSchema $schema): array
    {
        return ['action_id' => $schema->string()->format('uuid')->required()];
    }
    public function handle(Request $request, CommandApprovals $approvals): Response
    {
        $data = $request->validate(['action_id' => ['required', 'uuid']]);
        return $this->perform(fn () => $approvals->execute(request()->user('mcp_admin'), $data['action_id']));
    }
}
