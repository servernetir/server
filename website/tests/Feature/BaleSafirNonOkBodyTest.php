<?php

namespace Tests\Feature;

use App\Services\Bale\BaleSafirSender;
use App\Support\ErrorTracker;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🔴 وضعیتِ غیرِ ۲۰۰ سفیر باید از **بدنه** خوانده شود، نه از عددِ وضعیت.
 *
 * ═══ از کجا آمد ═══
 *
 * در ردیابِ خطای پروداکشن یک ردیف بود با متنِ «status code 400 … processed
 * request id»، بی‌کلاس و بی‌آدرس، از یک کرون. ردیابیِ آن سه نقصِ به‌هم‌چسبیده
 * را بیرون آورد و هر سه از یک تصمیم می‌آمدند: `retry(2, 400)` با پرتابِ
 * پیش‌فرضِ لاراول.
 *
 * ۱) هر ۴xx یک استثنا می‌شد، پس شاخهٔ `! successful()` عملاً **مرده** بود و
 *    آنچه ثبت می‌شد متنِ خامِ لاراول بود، نه پیامِ ما.
 * ۲) `handleErrors()` برای ۴xx **هرگز** اجرا نمی‌شد. یعنی کدِ ۲۰ («اعتبار تمام
 *    شد») اگر با وضعیتِ غیرِ ۲۰۰ می‌آمد، بی‌صدا رد می‌شد — کلِ کانالِ بله
 *    می‌خوابید و تنها هشدارِ ساخته‌شده برای همین، شلیک نمی‌کرد.
 * ۳) تلاشِ دوباره همان `request_id` را می‌فرستد (عمدی، برای دو بار نفرستادن)،
 *    پس سفیر «قبلاً پردازش شده» می‌دهد — برای پیامی که **رفته بود**.
 *
 * ⚠️ همان قاعدهٔ ثبت‌شدهٔ CLAUDE.md، وارونه: «زحل روی خطا هم ۲۰۰ می‌دهد، هرگز
 * به کدِ HTTP تکیه نکن.» این‌جا سفیر روی وضعیتِ خطا هم بدنهٔ معنادار می‌دهد.
 *
 * ⚠️ در هر تست فقط **یک بار** `Http::fake()` — اولین تطبیق برنده است و یک
 * استابِ همه‌گیرِ زودتر هر fakeِ بعدی را بی‌اثر می‌کند.
 */
class BaleSafirNonOkBodyTest extends TestCase
{
    private function sender(): BaleSafirSender
    {
        return new BaleSafirSender('key-test', 42, 'https://safir.example.test');
    }

    private function trackerSays(string $needle): bool
    {
        foreach (ErrorTracker::recent(200) as $e) {
            if (str_contains((string) ($e['message'] ?? ''), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 🔴 «قبلاً پردازش شده» یعنی پیام رفته — گزارشِ شکست برایش دروغ است.
     */
    public function test_a_duplicate_request_id_counts_as_delivered(): void
    {
        Http::fake(['*' => Http::response(
            ['error_data' => [['code' => 0, 'description' => 'processed request id 9f1c']]], 400)]);

        $this->assertTrue($this->sender()->text('09121234567', 'سلام'),
            'پاسخِ «تکراری» همان چیزی است که request_id برایش فرستاده شده');
        $this->assertFalse($this->trackerSays('سفیر کدِ 400'),
            'برای پیامی که رفته نباید خطا ثبت شود');
    }

    /**
     * 🔴 مهم‌ترین ادعا: خوابیدنِ کلِ کانال نباید بی‌صدا باشد.
     */
    public function test_out_of_credit_still_raises_the_alarm_when_it_arrives_with_a_4xx(): void
    {
        Http::fake(['*' => Http::response(
            ['error_data' => [['code' => 20, 'description' => 'insufficient balance']]], 402)]);

        $this->assertFalse($this->sender()->text('09121234567', 'سلام'));

        $this->assertNotNull(\Illuminate\Support\Facades\Cache::get('bale:safir_error'),
            'اعتبارِ تمام‌شده باید در کش بنشیند حتی وقتی با وضعیتِ غیرِ ۲۰۰ می‌آید');
    }

    /** «کاربر بله ندارد» عادی است و نباید ردیابِ خطا را پر کند. */
    public function test_a_missing_bale_account_stays_quiet_even_with_a_4xx(): void
    {
        Http::fake(['*' => Http::response(
            ['error_data' => [['code' => 17, 'description' => 'user not found']]], 400)]);

        $this->assertFalse($this->sender()->text('09121234567', 'سلام'));
        $this->assertFalse($this->trackerSays('سفیر کدِ'),
            'نبودِ حسابِ بله خطا نیست؛ ثبتش ردیاب را پر از نویز می‌کند');
    }

    /** ۴۰۰ِ بی‌بدنهٔ معنادار همچنان شکست است — و متنش باید **گویا** باشد. */
    public function test_an_opaque_failure_is_reported_with_the_body_not_just_the_code(): void
    {
        Http::fake(['*' => Http::response('bot_id is invalid', 400)]);

        $this->assertFalse($this->sender()->text('09121234567', 'سلام'));
        $this->assertTrue($this->trackerSays('bot_id is invalid'),
            'بی‌متنِ بدنه، مدیر فقط عددِ ۴۰۰ می‌بیند و علتش را هرگز نمی‌فهمد');
    }

    /**
     * 🔴 ۴۰۰ هرگز با تکرار ۲۰۰ نمی‌شود.
     *
     * تلاشِ دوباره روی آن هم بی‌فایده است و هم خودش سازندهٔ همان پاسخِ
     * «تکراری» — چون `request_id` عمداً ثابت می‌مانَد.
     */
    public function test_a_rejected_request_is_not_sent_again(): void
    {
        Http::fake(['*' => Http::response(['error_data' => [['code' => 5, 'description' => 'nope']]], 400)]);

        $this->sender()->text('09121234567', 'سلام');

        $sent = 0;
        Http::assertSent(function () use (&$sent) {
            $sent++;

            return true;
        });

        $this->assertSame(1, $sent, 'پاسخِ صریحِ «نه» نباید دوباره فرستاده شود');
    }

    /** مسیرِ موفق دست‌نخورده: هم `message_id` لازم است هم `error_data`ی خالی. */
    public function test_a_clean_success_is_still_a_success(): void
    {
        Http::fake(['*' => Http::response(['message_id' => 'm-1', 'error_data' => []], 200)]);

        $this->assertTrue($this->sender()->text('09121234567', 'سلام'));
    }
}
