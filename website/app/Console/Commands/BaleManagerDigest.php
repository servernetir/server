<?php

namespace App\Console\Commands;

use App\Services\Bale\Management\BaleManagerDailyDigest;
use App\Services\Bale\Management\BaleManagerDigestDelivery;
use Illuminate\Console\Command;

class BaleManagerDigest extends Command
{
    protected $signature = 'bale:manager-digest
        {--preview : فقط ساخت گزارش؛ بدون claim یا ارسال}
        {--topic= : جزئیات فقط همراه preview}
        {--sample-request= : شناسهٔ یکتای درخواست صریح مدیر برای یک نمونه}
        {--operator-user-id= : شناسهٔ مدیر متصل؛ فقط همراه درخواست نمونه}';

    protected $description = 'گزارش ادغام‌شده مدیریت بله، روزانه ساعت ۹ تهران';

    public function handle(BaleManagerDailyDigest $digest, BaleManagerDigestDelivery $delivery): int
    {
        $sampleKey = $this->option('sample-request');
        $operator = $this->option('operator-user-id');
        if ($sampleKey !== null || $operator !== null) {
            if ($this->option('preview') || $this->option('topic') !== null
                || ! is_string($sampleKey) || ! preg_match('/\A[a-z0-9][a-z0-9._:-]{0,79}\z/iD', $sampleKey)
                || ! is_string($operator) || ! preg_match('/\A[1-9][0-9]{0,17}\z/D', $operator)
                || (string) (int) $operator !== $operator) {
                $this->error('نمونه به شناسهٔ درخواست و مدیر معتبر نیاز دارد و با preview یا topic ترکیب نمی‌شود.');
                return self::FAILURE;
            }
            $result = $delivery->sendSample($sampleKey, (int) $operator);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
            return $result['status'] === 'sent'
                || ($result['status'] === 'already_claimed' && ($result['prior_status'] ?? null) === 'sent')
                ? self::SUCCESS : self::FAILURE;
        }
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
