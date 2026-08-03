<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Services\Domain\DomainRegistrar;
use Illuminate\Console\Command;

/**
 * `domains:provision` — ثبتِ دامنه‌های پرداخت‌شده‌ای که هنوز ثبت نشده‌اند.
 *
 * خواهرِ `provision:run` است و همان قاعده را دارد: تماسِ شبکه‌ای **بیرونِ**
 * وب‌هوکِ درگاه انجام می‌شود. اگر ثبت را داخلِ وب‌هوک بگذاریم، یک کندیِ
 * رجیسترار باعثِ timeout وب‌هوک می‌شود، درگاه پرداخت را ناموفق فرض می‌کند و
 * پول برمی‌گردد در حالی که دامنه ثبت شده است.
 *
 * ⚠️ فقط `provision_status='pending'` را برمی‌دارد. `manual` عمداً دستِ آدم
 * می‌مانَد — مثلِ `CloudFraudGuard` که سفارشِ مشکوک را به `manual` می‌برد تا
 * هیچ پولی خرج نشود.
 */
class RunDomainProvision extends Command
{
    protected $signature = 'domains:provision {--limit=10 : بیشینهٔ دامنه در هر اجرا} {--id= : فقط همین دامنه}';

    protected $description = 'ثبتِ دامنه‌های پرداخت‌شده نزدِ رجیسترار';

    public function handle(DomainRegistrar $registrar): int
    {
        $q = Domain::query()->awaitingRegistration()->with('customer');

        if ($id = $this->option('id')) {
            $q->whereKey($id);
        }

        $domains = $q->orderBy('created_at')->limit((int) $this->option('limit'))->get();

        if ($domains->isEmpty()) {
            $this->line('چیزی در صف نیست.');

            return self::SUCCESS;
        }

        $ok = 0;
        $failed = 0;

        foreach ($domains as $domain) {
            $res = $registrar->register($domain);

            if ($res['ok']) {
                $ok++;
                $this->info("✓ {$domain->domain}");

                continue;
            }

            $failed++;
            // ⚠️ «دستِ مدیر» با «بعداً دوباره تلاش کن» فرق دارد و باید در
            // خروجی هم فرق کند، وگرنه مدیر نمی‌فهمد کدام منتظرِ اوست.
            $this->{$res['manual'] ? 'error' : 'warn'}(
                ($res['manual'] ? '✗ نیازِ بررسیِ دستی: ' : '… تلاشِ دوباره: ').$domain->domain.' — '.$res['message']
            );
        }

        $this->line("جمع: {$ok} ثبت‌شده · {$failed} ناموفق");

        return self::SUCCESS;
    }
}
