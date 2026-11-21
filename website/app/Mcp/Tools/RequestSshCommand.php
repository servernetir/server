<?php

namespace App\Mcp\Tools;

use App\Services\Mcp\AdminAccess;
use App\Services\Mcp\CommandApprovals;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

final class RequestSshCommand extends AdminTool
{
    protected string $name = 'request_ssh_command';
    protected string $scope = AdminAccess::EXECUTE;
    protected string $description = 'Propose an exact SSH command for owner approval on ServerNet. Does not execute it. Explain its effects and present the returned approval URL. Never propose reading or exporting credentials. Approval expires after ten minutes.';
    public function schema(JsonSchema $schema): array
    {
        return ['target' => $schema->string()->max(80)->required(), 'command' => $schema->string()->max(4096)->required()];
    }
    public function handle(Request $request, CommandApprovals $approvals): Response
    {
        $data = $request->validate(['target' => ['required', 'string', 'max:80'], 'command' => ['required', 'string', 'max:4096']]);
        return $this->perform(fn () => $approvals->request(request()->user('mcp_admin'), $data['target'], $data['command']));
    }
}
