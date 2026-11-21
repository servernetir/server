<?php

namespace App\Mcp\Tools;

use App\Services\Mcp\SshGateway;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class SshDiagnostic extends AdminTool
{
    protected string $name = 'ssh_diagnostic';
    protected string $description = 'Run one fixed read-only Linux diagnostic on a privately registered SSH target. Keys and verified host fingerprints must already be installed. Output is bounded and screened for common secrets.';
    public function schema(JsonSchema $schema): array
    {
        return ['target' => $schema->string()->max(80)->required(),
            'diagnostic' => $schema->string()->enum(array_keys(SshGateway::DIAGNOSTICS))->required()];
    }
    public function handle(Request $request, SshGateway $gateway): Response
    {
        $data = $request->validate(['target' => ['required', 'string', 'max:80'],
            'diagnostic' => ['required', 'in:'.implode(',', array_keys(SshGateway::DIAGNOSTICS))]]);
        return $this->perform(fn () => $gateway->run($data['target'], SshGateway::DIAGNOSTICS[$data['diagnostic']]));
    }
}
