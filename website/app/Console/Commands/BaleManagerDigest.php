<?php

namespace App\Console\Commands;

use App\Services\Bale\Management\BaleManagerDailyDigest;
use App\Services\Bale\Management\BaleManagerDigestDelivery;
use Illuminate\Console\Command;

class BaleManagerDigest extends Command
{
    protected $signature = 'bale:manager-digest {--preview : فقط ساخت گزارش؛ بدون claim یا ارسال} {--topic= : جزئیات فقط همراه preview}';

    protected $description = 'گزارش ادغام‌شده مدیریت بله، روزانه ساعت ۹ تهران';

    public function handle(BaleManagerDailyDigest $digest, BaleManagerDigestDelivery $delivery): int
    {
        if ($this->option('preview')) {
            $topic = (string) $this->option('topic');
            if ($topic !== '' && ! in_array($topic, BaleManagerDailyDigest::TOPICS, true)) {
                $this->error('موضوع گزارش معتبر نیست.');
                return self::FAILURE;
            }
            $this->line($topic !== '' ? $digest->detail($topic) : $digest->text());
            return self::SUCCESS;
        }
        if ($this->option('topic')) { $this->error('topic فقط در preview مجاز است.'); return self::FAILURE; }
        $result = $delivery->send();
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));
        $successful = in_array($result['status'], ['sent', 'disabled', 'busy'], true)
            || ($result['status'] === 'already_claimed' && ($result['prior_status'] ?? null) === 'sent');
        return $successful ? self::SUCCESS : self::FAILURE;
    }
}
