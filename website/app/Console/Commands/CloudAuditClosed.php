<?php

namespace App\Console\Commands;

use App\Services\Cloud\ClosedInfrastructureAudit;
use Illuminate\Console\Command;

class CloudAuditClosed extends Command
{
    protected $signature = 'cloud:audit-closed {--limit=50 : Maximum closed resources to read}';
    protected $description = 'تطبیق منابع بسته‌شده با استعلام فقط‌خواندنی؛ بدون حذف، خرید یا تغییر مالی';

    public function handle(ClosedInfrastructureAudit $audit): int
    {
        $result = $audit->run((int) $this->option('limit'));
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $result['unknown'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
