<?php

namespace App\Services\Payment;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;

/**
 * خواندنِ واریزی‌ها از شبکهٔ ترون — **فقط خواندن**.
 *
 * این کلاس هیچ توانِ خرج‌کردن ندارد و نباید پیدا کند: تنها ورودی‌اش آدرس است و
 * تنها خروجی‌اش فهرستِ واریزی‌ها. کلیدِ خصوصی جایی در این پروژه نیست.
 *
 * ⚠️ USDT روی ترون یک **قراردادِ TRC20** است، پس با انتقالِ خودِ TRX فرق دارد
 * و از دو نقطهٔ متفاوتِ API خوانده می‌شود.
 */
class TronWatcher
{
    /** قراردادِ رسمیِ USDT روی ترون */
    public const USDT_CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

    /**
     * 🔴 ترون «تأییدِ» شمارشی به معنای بیت‌کوین ندارد؛ بلاکِ نهایی‌شده دارد.
     *
     * ۱۹ بلاک (~۵۷ ثانیه) همان چیزی است که صرافی‌های بزرگ برای نهایی‌شدن
     * می‌گیرند. کمترش یعنی ریسکِ بازگشتِ زنجیره — و در آن حالت سرویسی فعال
     * کرده‌ایم که پولش برنگشته.
     */
    public const MIN_CONFIRMATIONS = 19;

    private const BASE = 'https://api.trongrid.io';

    public function enabled(): bool
    {
        return true;   // TronGrid بدونِ کلید هم سهمیهٔ عمومی دارد؛ کلید فقط سقف را بالا می‌برد
    }

    /**
     * واریزی‌های یک آدرس.
     *
     * @return array<int,array{txid:string,amount:int,decimals:int,asset:string,confirmed:bool,timestamp:int}>
     */
    public function deposits(string $address, string $asset): array
    {
        return $asset === 'TRX'
            ? $this->nativeDeposits($address)
            : $this->trc20Deposits($address);
    }

    /** انتقالِ توکنِ TRC20 (USDT) */
    private function trc20Deposits(string $address): array
    {
        $res = $this->get("/v1/accounts/{$address}/transactions/trc20", [
            'only_confirmed' => 'true',      // ⚠️ فقط تراکنشِ نهایی‌شده
            'only_to' => 'true',             // فقط واریز، نه برداشت
            'contract_address' => self::USDT_CONTRACT,
            'limit' => 50,
        ]);

        $out = [];

        foreach ($res['data'] ?? [] as $t) {
            // ⚠️ `to` را دوباره می‌سنجیم: `only_to` پارامترِ سرور است و اگر
            //    روزی معنایش عوض شود، نباید واریزیِ کسِ دیگری را پرداختِ ما
            //    حساب کنیم.
            if (! hash_equals($address, (string) ($t['to'] ?? ''))) {
                continue;
            }

            $out[] = [
                'txid' => (string) ($t['transaction_id'] ?? ''),
                'amount' => (int) ($t['value'] ?? 0),
                'decimals' => (int) ($t['token_info']['decimals'] ?? 6),
                'asset' => 'USDT',
                'confirmed' => true,          // only_confirmed=true تضمینش می‌کند
                'timestamp' => (int) (($t['block_timestamp'] ?? 0) / 1000),
            ];
        }

        return array_values(array_filter($out, fn ($d) => $d['txid'] !== '' && $d['amount'] > 0));
    }

    /** انتقالِ خودِ TRX */
    private function nativeDeposits(string $address): array
    {
        $res = $this->get("/v1/accounts/{$address}/transactions", [
            'only_confirmed' => 'true',
            'only_to' => 'true',
            'limit' => 50,
        ]);

        $out = [];

        foreach ($res['data'] ?? [] as $t) {
            $c = $t['raw_data']['contract'][0] ?? [];

            // فقط انتقالِ ساده؛ قراردادهای دیگر واریزِ ما نیستند
            if (($c['type'] ?? '') !== 'TransferContract') {
                continue;
            }

            $v = $c['parameter']['value'] ?? [];

            // ⚠️ آدرسِ ترون در این نقطه **hex** است نه Base58، پس مستقیم با
            //    آدرسِ ما مقایسه نمی‌شود. TronGrid فیلترِ only_to را خودش
            //    اعمال کرده؛ ما فقط موفق‌بودنِ تراکنش را می‌سنجیم.
            if (($t['ret'][0]['contractRet'] ?? '') !== 'SUCCESS') {
                continue;
            }

            $out[] = [
                'txid' => (string) ($t['txID'] ?? ''),
                'amount' => (int) ($v['amount'] ?? 0),
                'decimals' => 6,
                'asset' => 'TRX',
                'confirmed' => true,
                'timestamp' => (int) (($t['block_timestamp'] ?? 0) / 1000),
            ];
        }

        return array_values(array_filter($out, fn ($d) => $d['txid'] !== '' && $d['amount'] > 0));
    }

    /**
     * ⚠️ خطا **استثنا پرتاب نمی‌کند** و آرایهٔ خالی برمی‌گرداند.
     *
     * قطعیِ TronGrid نباید کرون را بکشد یا پرداختی را «منقضی» کند. آرایهٔ خالی
     * یعنی «الان چیزی ندیدم»، نه «پولی نیامده» — و چون واچر هر دقیقه دوباره
     * می‌پرسد، تأخیر جبران می‌شود. برای همین انقضا هم بر اساسِ **زمان** است نه
     * بر اساسِ نتیجهٔ این تماس.
     */
    private function get(string $path, array $query): array
    {
        try {
            $req = Http::timeout(12)->acceptJson();

            if (filled($key = Setting::getSecret('trongrid_api_key'))) {
                $req = $req->withHeaders(['TRON-PRO-API-KEY' => $key]);
            }

            $res = $req->get(self::BASE.$path, $query);

            return $res->successful() ? (array) $res->json() : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
