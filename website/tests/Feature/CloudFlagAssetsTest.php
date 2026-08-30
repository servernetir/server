<?php

namespace Tests\Feature;

use App\Models\CloudLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * پرچمِ کشور — فایلِ خودمیزبان به‌جای اموجی.
 *
 * ═══ چرا این تست‌ها لازم‌اند ═══
 *
 * پرچمِ اموجی روی ویندوز پرچم نمی‌شود؛ دو مربعِ حرف می‌شود. یعنی مشتری‌ای که
 * دارد دیتاسنترش را انتخاب می‌کند، دقیقاً همان‌جا که قرار بود تصویری تصمیم
 * بگیرد، «D E» می‌بیند. پس پرچم‌ها SVGِ خودمیزبان شدند — و از آن لحظه سه
 * راهِ **بی‌صدا** شکستن باز شد که هر سه این‌جا بسته می‌شوند:
 *
 *   ۱) مسیرِ نسبی به‌جای ریشه‌نسبی → روی `/en/cloud/…` می‌شود
 *      `/en/assets/flags/de.svg` و ۴۰۴ می‌گیرد. صفحه هنوز ۲۰۰ است.
 *   ۲) فایلِ نبود → آیکنِ «تصویرِ شکسته» سرِ جای پرچم. صفحه هنوز ۲۰۰ است.
 *   ۳) سینکِ تازهٔ زیرساخت کشورِ جدیدی بیاورد که فایلش را نداریم → جای پرچم
 *      خالی می‌مانَد و **هیچ‌کس خبردار نمی‌شود**. تستِ آخرِ همین کلاس عمداً
 *      همان روز قرمز می‌شود، نه ماه‌ها بعد با گزارشِ کارفرما.
 */
class CloudFlagAssetsTest extends TestCase
{
    use RefreshDatabase;

    // ═══════════════════════ ۱) خودِ کمک‌تابع ═══════════════════════

    public function test_the_path_is_root_relative_and_lowercased(): void
    {
        $loc = new CloudLocation(['code' => 'de-frankfurt', 'country' => 'DE', 'city' => 'Frankfurt']);

        $this->assertSame('/assets/flags/de.svg', $loc->flagSvg());

        // ریشه‌نسبی یعنی: نه دامنه، نه پیشوندِ زبان. صفحاتِ سایت زیرِ `/en/` و
        // `/tr/` هم زندگی می‌کنند و یک مسیرِ نسبی آن‌جا ۴۰۴ می‌گرفت.
        $this->assertStringStartsWith('/', (string) $loc->flagSvg());
        $this->assertStringNotContainsString('http', (string) $loc->flagSvg());
        $this->assertStringNotContainsString('/en/', (string) $loc->flagSvg());
    }

    public function test_the_file_the_path_points_at_really_exists(): void
    {
        $rel = ltrim((string) CloudLocation::flagSvgFor('IR'), '/');

        $this->assertNotNull(public_asset_path($rel), "فایلِ {$rel} روی دیسک نیست");
        $this->assertStringContainsString('<svg', (string) file_get_contents(public_asset_path($rel)));
    }

    /**
     * 🔴 مهم‌ترین ادعای این کلاس: «نداریم» باید **null** بدهد، نه یک مسیرِ
     * خوش‌بینانه. مسیرِ خوش‌بینانه یعنی `<img>`ِ شکسته — که از نبودِ پرچم
     * بدتر است، چون شبیهِ خرابیِ سایت به نظر می‌رسد.
     */
    public function test_a_country_without_a_file_returns_null_not_a_broken_path(): void
    {
        // ZW در جدولِ ۵۶تاییِ ما نیست
        $this->assertNull(CloudLocation::flagSvgFor('ZW'));
        $this->assertNull(CloudLocation::flagSvgFor(''));
        $this->assertNull(CloudLocation::flagSvgFor(null));
        $this->assertNull(CloudLocation::flagSvgFor('  '));
        $this->assertNull(CloudLocation::flagSvgFor('DEU'), 'کدِ سه‌حرفی کدِ ISO alpha-2 نیست');
        $this->assertNull(CloudLocation::flagSvgFor('1A'), 'ورودیِ غیرحرفی نباید به مسیرِ فایل تبدیل شود');
        $this->assertNull(CloudLocation::flagSvgFor('../../.env'), 'ورودی هرگز نباید مسیرساز شود');
    }

    public function test_the_emoji_fallback_is_still_there_for_text_only_places(): void
    {
        // اموجی برای پیامک و بله و توضیحِ فاکتور می‌مانَد؛ `<img>` آن‌جا هیچ است.
        $loc = new CloudLocation(['code' => 'de-frankfurt', 'country' => 'DE']);

        $this->assertSame('🇩🇪', $loc->flagEmoji());

        $unknown = new CloudLocation(['code' => 'zw-harare', 'country' => 'ZW']);
        $this->assertNull($unknown->flagSvg());
        $this->assertSame('🏳️', $unknown->flagEmoji(), 'بی‌پرچم هم باید چیزی برای متن بماند');
    }

    // ═══════════════ ۲) پوششِ کشورها — نگهبانِ سینکِ آینده ═══════════════

    /**
     * هر کشوری که **خودمان** نامش را می‌شناسیم باید فایلِ پرچم داشته باشد.
     *
     * COUNTRIES و CAPITALS فهرست‌هایی‌اند که سینکِ زیرساخت با آنها برچسب
     * می‌سازد؛ اگر کسی یک کشور به آنها اضافه کند و SVGاش را نیاورد، صفحهٔ
     * انتخابِ مکان یک اسلاتِ خالی می‌گیرد و هیچ خطایی جایی ثبت نمی‌شود.
     */
    public function test_every_country_we_can_label_has_a_flag_file(): void
    {
        $codes = array_unique(array_merge(
            array_keys(CloudLocation::COUNTRIES),
            array_keys(CloudLocation::CAPITALS),
            array_keys((array) config('billing.locations', [])),
        ));

        $this->assertNotEmpty($codes);

        $missing = [];
        foreach ($codes as $cc) {
            if (CloudLocation::flagSvgFor((string) $cc) === null) {
                $missing[] = strtoupper((string) $cc);
            }
        }

        $this->assertSame([], $missing,
            'پرچمِ این کشورها در public/assets/flags/ نیست: '.implode(', ', $missing));
    }

    /**
     * …و هر کشوری که **زیرساخت** به ما داده.
     *
     * ⚠️ این تست روی دیتابیسِ تست می‌دود، پس ردیف‌ها را خودش می‌سازد تا
     * پوچ‌گرا نباشد؛ ولی حلقهٔ اصلی از `cloud_locations` می‌خوانَد، پس اگر
     * روزی یک fixture یا seeder کشورِ تازه‌ای بیاورد، همین‌جا گیر می‌کند.
     */
    public function test_every_country_in_cloud_locations_has_a_flag_file(): void
    {
        foreach (['de-frankfurt' => 'DE', 'fi-helsinki' => 'FI', 'ir-tehran' => 'IR', 'nl-amsterdam' => 'NL'] as $code => $cc) {
            CloudLocation::create(['code' => $code, 'country' => $cc, 'city' => 'x', 'is_active' => true]);
        }

        $countries = CloudLocation::query()->pluck('country')->filter()->unique()->values();

        $this->assertGreaterThan(0, $countries->count());

        $missing = $countries
            ->reject(fn ($cc) => CloudLocation::flagSvgFor((string) $cc) !== null)
            ->map(fn ($cc) => strtoupper((string) $cc))
            ->values()
            ->all();

        $this->assertSame([], $missing,
            'مکان‌هایی در دیتابیس هستند که پرچمشان را نداریم: '.implode(', ', $missing));
    }

    // ═══════════════════ ۳) قطعهٔ رندر ═══════════════════

    public function test_the_partial_renders_an_image_with_reserved_size_and_no_double_reading(): void
    {
        $html = view('partials.flag', [
            'flagSrc' => '/assets/flags/de.svg',
            'flagEmoji' => '🇩🇪',
            'flagSize' => 24,
        ])->render();

        $this->assertStringContainsString('src="/assets/flags/de.svg"', $html);
        $this->assertStringContainsString('width="24"', $html);
        $this->assertStringContainsString('height="24"', $html);
        // ابعاد در استایل هم هست تا هیچ قاعدهٔ عمومیِ img{height:auto} لهش نکند
        $this->assertStringContainsString('height:24px', $html);
        // نامِ کشور همیشه کنارش نوشته شده — alt یعنی صفحه‌خوان دو بار بگوید
        $this->assertStringContainsString('alt=""', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringNotContainsString('🇩🇪', $html, 'وقتی SVG هست، اموجی نباید هم چاپ شود');
    }

    public function test_the_partial_falls_back_to_the_emoji_and_never_emits_an_empty_image(): void
    {
        $html = view('partials.flag', [
            'flagSrc' => null,
            'flagEmoji' => '🏳️',
            'flagSize' => 18,
        ])->render();

        $this->assertStringNotContainsString('<img', $html, 'بی‌فایل نباید هیچ <img>ی ساخته شود');
        $this->assertStringContainsString('🏳️', $html);
    }

    public function test_the_partial_renders_nothing_at_all_when_there_is_neither(): void
    {
        $html = trim(view('partials.flag', ['flagSrc' => null, 'flagEmoji' => null])->render());

        $this->assertSame('', $html);
    }

    public function test_an_above_the_fold_flag_can_opt_out_of_lazy_loading(): void
    {
        $html = view('partials.flag', [
            'flagSrc' => '/assets/flags/de.svg',
            'flagSize' => 34,
            'flagEager' => true,
        ])->render();

        $this->assertStringContainsString('loading="eager"', $html);
        $this->assertStringContainsString('width="34"', $html);
    }
}
