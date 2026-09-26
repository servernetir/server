<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * عنوانِ /solutions/bpmn-designer.
 *
 * ═══ چرا ═══
 *
 * `bpmn.servernet.cloud` (خودِ ادیتور، روی Cloudflare) با ۱۲۰۹ نمایش و **صفر
 * کلیک** در رتبهٔ ۷۰ ایستاده و روی همان عبارت‌ها با این صفحه رقابت می‌کند.
 * وقتی زیردامنه noindex شود، این صفحه باید برنده شود — پس عنوانش باید عبارتِ
 * جست‌وجوشده را اول بیاورد.
 *
 * 🔴 عنوانِ قبلی با «سرورنت» تمام می‌شد و لایوت هم «— سرورنت کلاود» می‌چسباند،
 * یعنی برند **دو بار** در یک عنوان و عبارتِ اصلی عقب‌تر.
 *
 * ⚠️ این ادعا عمداً به کلِ `solutions.php` تعمیم داده نشد: ۹ عنوانِ دیگر هم
 * برند دارند («سرورنت ریموت»، «تلفن ابری سرورنت») ولی آن‌جا برند بخشی از
 * **نامِ محصول** است. تستی که همه را یک‌کاسه کند، نامِ محصول را می‌شکند.
 */
class BpmnDesignerTitleTest extends TestCase
{
    /** @return array<string, string> locale => عنوانِ خام */
    private function metaTitles(): array
    {
        $row = (array) config('solutions.bpmn-designer');

        return [
            'fa' => (string) ($row['fa']['meta_t'] ?? ''),
            'en' => (string) ($row['en']['meta_t'] ?? ''),
            'tr' => (string) ($row['tr']['meta_t'] ?? ''),
        ];
    }

    public function test_no_locale_tacks_the_brand_onto_the_title(): void
    {
        foreach ($this->metaTitles() as $loc => $t) {
            $this->assertNotSame('', $t, "$loc: عنوان خالی است");
            $this->assertStringNotContainsStringIgnoringCase('ServerNet', $t, "$loc: برند را لایوت می‌چسباند");
            $this->assertStringNotContainsString('سرورنت', $t, "$loc: برند را لایوت می‌چسباند");
        }
    }

    public function test_every_locale_leads_with_the_searched_term(): void
    {
        foreach ($this->metaTitles() as $loc => $t) {
            $pos = mb_stripos($t, 'BPMN');
            $this->assertNotFalse($pos, "$loc: واژهٔ BPMN در عنوان نیست");
            // در نیمهٔ اولِ عنوان باشد، نه ته آن
            $this->assertLessThan(mb_strlen($t) / 2, $pos, "$loc: BPMN خیلی دیر می‌آید");
        }
    }

    /** عنوانِ بلند در نتایج جست‌وجو بریده می‌شود و برند هم بعداً اضافه می‌شود */
    public function test_titles_leave_room_for_the_appended_brand(): void
    {
        foreach ($this->metaTitles() as $loc => $t) {
            $this->assertLessThanOrEqual(60, mb_strlen($t), "$loc: با برندِ چسبیده از حدِ SERP رد می‌شود");
        }
    }

    /**
     * 🔴 صفحه باید واقعاً رندر شود و همان عنوان را بدهد.
     *
     * ⚠️ ادعا روی **عنوانِ رندرشده** است نه روی مقدارِ config: برند را لایوت
     * می‌چسباند، پس تنها جایی که «دو بار آمدنِ برند» دیده می‌شود همین‌جاست.
     */
    public function test_the_rendered_title_names_the_brand_exactly_once(): void
    {
        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $loc => $prefix) {
            $r = $this->get($prefix.'/solutions/bpmn-designer');
            $r->assertOk();

            preg_match('~<title>(.*?)</title>~s', (string) $r->getContent(), $m);
            $title = trim($m[1] ?? '');

            $this->assertNotSame('', $title, "$loc: عنوان خالی");
            $this->assertStringContainsString('BPMN', $title, "$loc: BPMN در عنوان نیست");

            $brand = $loc === 'fa' ? 'سرورنت' : 'ServerNet';
            $this->assertSame(
                1,
                mb_substr_count($title, $brand),
                "$loc: برند «{$brand}» باید دقیقاً یک بار بیاید — عنوان: $title"
            );

            fwrite(STDERR, "\n  [$loc] len=".mb_strlen($title).'  '.$title);
        }
    }
}
