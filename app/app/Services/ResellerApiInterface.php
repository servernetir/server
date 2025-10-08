<?php

namespace App\Services;

interface ResellerApiInterface
{
    public function createServer(array $params);
    public function getServerStatus($serverId);
    public function deleteServer($serverId);
}