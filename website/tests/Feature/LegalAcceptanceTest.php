<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 🔴 تا امروز هیچ مشتری‌ای قوانین را «نپذیرفته» بود — و هیچ‌کس خبر نداشت.
 *
 * `recordAcceptance()` از `legal_documents` می‌خوانْد، ولی هیچ کدی در کلِ مخزن
 * در آن جدول رکورد نمی‌نوشت. پس کوئری صفر ردیف می‌داد، حلقه روی مجموعهٔ خالی
 * می‌چرخید، **هیچ استثنایی پرتاب نمی‌شد**، و `legal_acceptances` برای همیشه
 * خالی می‌مانْد.
 *
 * یعنی سقفِ مسئولیت، جدولِ اعتبارِ SLA و بندِ قوّهٔ قاهره همه بر پذیرشی
 * ایستاده بودند که **هیچ مدرکی نداشت**.
 */
class LegalAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private function publishLegal(): void
    {
        (new \Database\Seeders\LegalDocumentSeeder())->run();
    }

    public function test_the_seeder_publishes_terms_and_privacy_in_every_language(): void
    {
        $this->publishLegal();

        foreach (['terms', 'privacy'] as $kind) {
            foreach (['fa', 'en', 'tr'] as $locale) {
                $this->assertTrue(
                    DB::table('legal_documents')
                        ->where('kind', $kind)->where('locale', $locale)
                        ->whereNotNull('published_at')->exists(),
                    "سندِ {$kind} برای زبانِ {$locale} منتشر نشد"
                );
            }
        }
    }

    /** متن نباید خالی باشد — رکوردِ بی‌متن در دعوا بدتر از نبودنش است */
    public function test_every_published_document_has_real_text(): void
    {
        $this->publishLegal();

        $empty = DB::table('legal_documents')
            ->whereRaw('LENGTH(body) < 80')->pluck('kind')->all();

        $this->assertSame([], $empty, 'سندِ بی‌متن منتشر شد: '.implode('، ', $empty));
    }

    /** اثرِ انگشت باید با خودِ متن بخواند، وگرنه اثباتی در کار نیست */
    public function test_the_fingerprint_matches_the_stored_text(): void
    {
        $this->publishLegal();

        foreach (DB::table('legal_documents')->get() as $d) {
            $this->assertSame(hash('sha256', $d->body), $d->sha256,
                "هشِ سندِ {$d->kind}/{$d->locale} با متنش نمی‌خواند");
        }
    }

    /**
     * ⚠️ اجرای دوباره نباید نسخهٔ تکراری بسازد.
     *
     * این سیدر روی `/system/migrate` هم می‌دود، یعنی در هر دیپلوی. بی‌این
     * محافظ، هر دیپلوی یک نسخهٔ تازه می‌ساخت و تاریخچهٔ پذیرش بی‌معنا می‌شد.
     */
    public function test_running_twice_creates_no_duplicates(): void
    {
        $this->publishLegal();
        $first = DB::table('legal_documents')->count();

        $this->publishLegal();

        $this->assertSame($first, DB::table('legal_documents')->count());
    }

    /**
     * 🔴 نسخه از **هشِ متن** می‌آید، نه از تاریخ.
     *
     * با نسخهٔ تاریخ‌محور، ویرایشِ متن بی‌آنکه تاریخ عوض شود، رکوردهای پذیرشِ
     * قبلی را **دروغ‌گو** می‌کرد: می‌گفتند کاربر نسخهٔ X را پذیرفته، در حالی
     * که متنِ X عوض شده بود.
     */
    public function test_the_version_is_derived_from_the_text(): void
    {
        $this->publishLegal();

        $d = DB::table('legal_documents')->where('kind', 'terms')->where('locale', 'fa')->first();

        $this->assertNotNull($d);
        $this->assertSame(substr(hash('sha256', $d->body), 0, 12), $d->version);
    }
}
