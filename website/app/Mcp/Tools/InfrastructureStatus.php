<?php

namespace App\Mcp\Tools;

use App\Services\Mcp\InfrastructureRead;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class InfrastructureStatus extends AdminTool
{
    protected string $name = 'infrastructure_status';
    protected string $description = 'Read live Proxmox guest inventory or test an existing WHM/Plesk panel using its inventory target ID. TLS verification is mandatory.';
    public function schema(JsonSchema $schema): array
    {
        return ['target' => $schema->string()->max(80)->required()];
    }
    public function handle(Request $request, InfrastructureRead $reader): Response
    {
        $data = $request->validate(['target' => ['required', 'string', 'max:80']]);
        return $this->perform(fn () => $reader->status($data['target']));
    }
}
