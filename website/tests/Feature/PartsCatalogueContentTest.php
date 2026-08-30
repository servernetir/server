<?php

namespace Tests\Feature;

use App\Models\ServerPart;
use Database\Seeders\ServerPartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * کاتالوگِ کاشته‌شدهٔ قطعات — سلامتِ خودِ محتوا، نه رندرِ صفحه.
 *
 * ═══ نقصی که این را لازم کرد ═══
 *
 * 🔴 مولدِ سیدر خطِ تازه را `\n` می‌نوشت **داخلِ رشتهٔ تک‌کوتیشنیِ PHP**. در
 * تک‌کوتیشن `\n` خطِ تازه نیست؛ دو کاراکترِ بک‌اسلش و n است. نتیجه: متنِ هر ۹۷
 * محصول در هر سه زبان وسطِ جمله «\n\n» چاپ می‌کرد — با کدِ ۲۰۰، بی‌هیچ خطایی،
 * بی‌هیچ لاگی. فقط چشم گرفتش، آن هم روی یک صفحه از ۹۷ تا.
 *
 * تستِ «صفحه ۲۰۰ می‌دهد؟» چنین چیزی را هرگز نمی‌گیرد. ادعا باید دربارهٔ
 * **خودِ متن** باشد.
 */
class PartsCatalogueContentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        (new ServerPartSeeder())->run();
    }

    /** @return iterable<ServerPart> */
    private function parts(): iterable
    {
        return ServerPart::all();
    }

    public function test_the_seeder_produces_a_catalogue_in_every_category(): void
    {
        $counts = ServerPart::selectRaw('category, count(*) c')->groupBy('category')->pluck('c', 'category');

        foreach (array_keys(ServerPart::CATEGORIES) as $category) {
            $this->assertGreaterThan(0, (int) ($counts[$category] ?? 0), "دستهٔ «{$category}» هیچ قطعه‌ای ندارد");
        }
    }

    /**
     * 🔴 هیچ متنی نباید دنبالهٔ فرارِ خام داشته باشد.
     *
     * `\n` و `\t` و `\r` که به‌جای کاراکترِ واقعی، دو کاراکترِ ادبی چاپ شوند.
     */
    public function test_no_text_field_contains_a_raw_escape_sequence(): void
    {
        $bad = [];

        foreach ($this->parts() as $part) {
            foreach (['name', 'tagline', 'summary', 'body'] as $field) {
                foreach ((array) $part->{$field} as $locale => $text) {
                    if (! is_string($text)) {
                        continue;
                    }
                    if (preg_match('/\\\\[nrt]/', $text)) {
                        $bad[] = $part->slug.'.'.$field.'.'.$locale;
                    }
                }
            }
        }

        $this->assertSame([], array_slice($bad, 0, 10), 'متنِ این فیلدها دنبالهٔ فرارِ خام دارد');
    }

    /**
     * 🔴 نیم‌فاصله بینِ عدد و کلمه، جای فاصلهٔ واقعی را نمی‌گیرد.
     *
     * قالبِ tagline پردازنده‌ها نیم‌فاصله می‌گذاشت و روی صفحه «10هسته» چاپ
     * می‌شد — چسبیده، در هر ۳۳ پردازنده. نیم‌فاصله در فارسی کاراکترِ درستی
     * است (داخلِ «می‌شود»)، ولی بینِ رقم و اسم **فاصلهٔ واقعی** لازم است.
     * این ادعا دقیقاً همان حالت را می‌گیرد و نیم‌فاصله‌های مشروع را دست
     * نمی‌زند.
     *
     * ⚠️ این باگ دو بار برگشت: بار اول در دیتابیس درستش کردم و مولدِ سیدر
     * همان را دوباره ساخت. ادعا باید روی خروجیِ نهایی باشد، نه روی یک اجرا.
     */
    public function test_no_number_is_glued_to_a_word_with_a_zero_width_joiner(): void
    {
        $bad = [];

        foreach ($this->parts() as $part) {
            foreach (['name', 'tagline', 'summary', 'body'] as $field) {
                foreach ((array) $part->{$field} as $locale => $text) {
                    if (is_string($text) && preg_match('/[0-9\x{06F0}-\x{06F9}]\x{200C}/u', $text)) {
                        $bad[] = $part->slug.'.'.$field.'.'.$locale;
                    }
                }
            }
        }

        $this->assertSame([], array_slice($bad, 0, 10), 'بینِ عدد و کلمه نیم‌فاصله آمده، نه فاصله');
    }

    /** متنِ بلندِ سئو باید واقعاً پاراگراف داشته باشد، نه یک بلوکِ چسبیده. */
    public function test_every_body_has_real_paragraph_breaks(): void
    {
        foreach ($this->parts() as $part) {
            foreach (['fa', 'en', 'tr'] as $locale) {
                $body = (string) ($part->body[$locale] ?? '');

                $this->assertGreaterThan(300, mb_strlen($body), $part->slug.' ('.$locale.') متنِ کافی ندارد');

                /*
                | ⚠️ ادعا «خطِ خالی دارد؟» است، نه «دقیقاً دو کاراکترِ LF دارد؟».
                |
                | نسخهٔ اول رشتهٔ خامِ دو خطِ تازه را می‌خواست و وقتی فایلِ سیدر
                | یک بار با پایانِ خطِ ویندوزی (CRLF) بازنویسی شد، هر ۹۷ محصول
                | قرمز شدند — در حالی که متن هیچ ایرادی نداشت. تست باید دربارهٔ
                | پاراگراف باشد، نه دربارهٔ بایتِ پایانِ خط.
                */
                $this->assertMatchesRegularExpression(
                    '/\R[ \t]*\R/u',
                    $body,
                    $part->slug.' ('.$locale.') پاراگراف ندارد'
                );
            }
        }
    }

    /**
     * ⚠️ هیچ فیلدِ سه‌زبانه‌ای نباید یک زبانش جا افتاده باشد.
     *
     * زبانِ جاافتاده روی صفحه به فارسی برمی‌گردد و «کار می‌کند» — پس تنها راهِ
     * دیدنش، همین ادعا است.
     */
    public function test_every_part_is_complete_in_all_three_languages(): void
    {
        foreach ($this->parts() as $part) {
            foreach (['name', 'tagline', 'summary', 'body'] as $field) {
                foreach (['fa', 'en', 'tr'] as $locale) {
                    $this->assertNotEmpty(
                        $part->{$field}[$locale] ?? null,
                        $part->slug.' → '.$field.'.'.$locale.' خالی است'
                    );
                }
            }
        }
    }

    /**
     * 🔴 فارسی نباید در متنِ انگلیسی/ترکی نشت کند.
     *
     * یک بار در `config/hp_generations.php` دقیقاً همین شد: `ram_speed`
     * مقدارِ «تا 2400 MT/s» داشت و بازدیدکنندهٔ انگلیسی همان فارسی را می‌دید،
     * وسطِ یک جدولِ کاملاً انگلیسی.
     */
    public function test_persian_never_leaks_into_the_english_or_turkish_text(): void
    {
        $leaks = [];

        foreach ($this->parts() as $part) {
            foreach (['tagline', 'summary', 'body'] as $field) {
                foreach (['en', 'tr'] as $locale) {
                    if (preg_match('/[\x{0600}-\x{06FF}]/u', (string) ($part->{$field}[$locale] ?? ''))) {
                        $leaks[] = $part->slug.'.'.$field.'.'.$locale;
                    }
                }
            }
        }

        $this->assertSame([], array_slice($leaks, 0, 10), 'این فیلدها در زبانِ غیرفارسی، متنِ فارسی دارند');

        // و همان قاعده برای دادهٔ نسل‌ها، که یک بار واقعاً شکسته بود
        foreach (config('hp_generations') as $gen => $data) {
            foreach (['ram_speed', 'ram_type', 'cpu_family', 'ilo', 'years'] as $field) {
                $this->assertDoesNotMatchRegularExpression(
                    '/[\x{0600}-\x{06FF}]/u',
                    (string) $data[$field],
                    "hp_generations.{$gen}.{$field} روی صفحهٔ en/tr هم چاپ می‌شود و نباید فارسی باشد"
                );
            }
        }
    }

    /**
     * ⚠️ قیمت به **سنت** ذخیره می‌شود.
     *
     * اگر جایی یورو خام (۳۴ به‌جای ۳۴۰۰) وارد شود، قیمت صد برابر ارزان‌تر
     * می‌شود و هیچ‌چیز خطا نمی‌دهد — فقط فروشگاه زیرِ قیمتِ خرید می‌فروشد.
     * کف را محافظه‌کارانه می‌گیریم: هیچ قطعهٔ سروری زیرِ ۵ یورو نیست.
     */
    public function test_prices_are_stored_in_cents_not_whole_euros(): void
    {
        foreach ($this->parts() as $part) {
            if ($part->price_contact || $part->price_eur === null) {
                continue;
            }

            $this->assertGreaterThanOrEqual(
                500,
                $part->price_eur,
                $part->slug.' قیمتش '.$part->price_eur.' است — احتمالاً یورو وارد شده نه سنت'
            );
        }
    }

    /**
     * ⚠️ سیدر باید **insert-missing** باشد.
     *
     * با `updateOrCreate`، اجرای دوبارهٔ سیدر — که در هر دیپلوی ممکن است —
     * قیمتی را که مدیر در پنل ویرایش کرده به عددِ کد برمی‌گرداند، بی‌هیچ
     * خطایی و بی‌آنکه کسی بفهمد.
     */
    public function test_re_running_the_seeder_never_overwrites_an_admin_edit(): void
    {
        $part = ServerPart::where('slug', 'xeon-e5-2680-v4')->firstOrFail();
        $part->update(['price_eur' => 111_11, 'in_stock' => false]);

        (new ServerPartSeeder())->run();

        $this->assertSame(111_11, $part->fresh()->price_eur, 'سیدر قیمتِ ویرایش‌شده را برگرداند');
        $this->assertFalse($part->fresh()->in_stock);
        $this->assertSame(97, ServerPart::count(), 'اجرای دوباره نباید ردیفِ تکراری بسازد');
    }

    /** هر قطعه که فهرستِ نسل دارد، نسل‌هایش باید واقعاً وجود داشته باشند. */
    public function test_every_declared_generation_actually_exists(): void
    {
        $known = array_keys(config('hp_generations'));

        foreach ($this->parts() as $part) {
            foreach ((array) ($part->compat_gens ?? []) as $gen) {
                $this->assertContains($gen, $known, $part->slug.' به نسلِ ناشناختهٔ «'.$gen.'» ادعای سازگاری دارد');
            }
        }
    }

    /** ویژگی‌های ماشین‌خوان باید در `ATTR_LABELS` تعریف شده باشند. */
    public function test_every_machine_attribute_has_a_label(): void
    {
        $known = array_keys(ServerPart::ATTR_LABELS);

        foreach ($this->parts() as $part) {
            foreach (array_keys((array) ($part->attrs ?? [])) as $key) {
                $this->assertContains($key, $known, $part->slug.' ویژگیِ بی‌برچسبِ «'.$key.'» دارد');
            }
        }
    }
}
