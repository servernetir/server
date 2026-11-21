<?php

namespace App\Mcp\Tools;

use App\Services\Mcp\InfrastructureRead;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class InfrastructureInventory extends AdminTool
{
    protected string $name = 'infrastructure_inventory';
    protected string $description = 'List configured Proxmox, WHM/Plesk panels and private SSH target IDs. Configuration is not proof of connectivity. No credentials are returned.';
    public function handle(InfrastructureRead $reader): Response
    {
        return $this->perform(fn () => $reader->inventory());
    }
}
