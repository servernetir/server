<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * اسنادِ حقوقی — بدونِ این، **هیچ مشتری‌ای قوانین را نپذیرفته**.
 *
 * ═══ خرابیِ خاموشی که این سیدر برای رفعش نوشته شد ═══
 *
 * `RegisterController::recordAcceptance()` تیکِ پذیرش را اجباری می‌کند و بعد
 * از جدولِ `legal_documents` می‌خوانَد تا ثبت کند کاربر **کدام نسخه** را
 * پذیرفته. ولی هیچ سیدری، هیچ کامندی و هیچ صفحهٔ ادمینی در کلِ مخزن در آن
 * جدول رکورد نمی‌نوشت.
 *
 * پس آن کوئری صفر ردیف می‌داد، `foreach` روی مجموعهٔ خالی می‌چرخید، **هیچ
 * استثنایی پرتاب نمی‌شد**، و `legal_acceptances` برای همیشه خالی می‌مانْد.
 *
 * نتیجه: سقفِ مسئولیت، جدولِ اعتبارِ SLA، بندِ قوّهٔ قاهره و سیاستِ استفادهٔ
 * مجاز — همه بر این فرض ایستاده بودند که مشتری قوانین را پذیرفته، و **هیچ
 * مدرکی وجود نداشت**: نه نسخه، نه اثرِ انگشتِ متن، نه IP، نه زمان. این از
 * نداشتنِ جدول بدتر است، چون تیم باور دارد محافظت برقرار است.
 *
 * ═══ چرا متن از config می‌آید و این‌جا سخت‌کد نیست ═══
 *
 * متنِ زندهٔ `/terms` و `/privacy` در `config/pages.php` است. اگر این‌جا
 * نسخهٔ دوم می‌نوشتیم، دو متن می‌داشتیم که دیر یا زود از هم جدا می‌شدند — و
 * آن‌وقت متنی که مشتری **دید** با متنی که **ثبت کردیم** فرق می‌کرد، که دقیقاً
 * همان چیزی است که این جدول برای اثباتش ساخته شده.
 *
 * ⚠️ نسخه از **هشِ خودِ متن** ساخته می‌شود، نه از تاریخ. یعنی هر بار که متنِ
 * قوانین عوض شود، خودبه‌خود یک نسخهٔ تازه ثبت می‌شود و پذیرشِ قدیمی‌ها
 * دست‌نخورده می‌مانَد. با نسخهٔ تاریخ‌محور، ویرایشِ متن بی‌آنکه تاریخ عوض شود،
 * رکوردهای قبلی را **دروغ‌گو** می‌کرد.
 *
 * ⚠️ `updateOrInsert` روی کلیدِ طبیعی (kind+version+locale): اجرای دوباره
 * چیزی تکراری نمی‌سازد، پس روی `/system/migrate` هم بی‌خطر می‌دود.
 */
class LegalDocumentSeeder extends Seeder
{
    /** kindهایی که در ثبت‌نام پذیرفته می‌شوند */
    private const KINDS = ['terms', 'privacy'];

    public function run(): void
    {
        if (! Schema::hasTable('legal_documents')) {
            return;
        }

        $pages = (array) config('pages', []);
        $made = 0;

        foreach (self::KINDS as $kind) {
            foreach (['fa', 'en', 'tr'] as $locale) {
                $body = $this->bodyFor($pages, $kind, $locale);

                if ($body === '') {
                    // ⚠️ سند بی‌متن ثبت نمی‌شود: رکوردی که متنش خالی است، در
                    //    دعوا بدتر از نبودنش است.
                    continue;
                }

                $sha = hash('sha256', $body);

                DB::table('legal_documents')->updateOrInsert(
                    ['kind' => $kind, 'version' => substr($sha, 0, 12), 'locale' => $locale],
                    [
                        'body'         => $body,
                        'sha256'       => $sha,
                        'published_at' => now(),
                        'updated_at'   => now(),
                        'created_at'   => now(),
                    ],
                );

                $made++;
            }
        }

        $this->command?->info("اسنادِ حقوقی: {$made} نسخه ثبت/به‌روز شد.");
    }

    /**
     * متنِ کاملِ یک سند در یک زبان.
     *
     * ساختارِ `config/pages.php` سه‌زبانه است و هر سند از چند بند تشکیل شده.
     * این‌جا همه را به یک متنِ پیوسته تبدیل می‌کنیم — چون چیزی که باید ثبت
     * شود، **همان چیزی است که کاربر دید**، نه ساختارِ داخلیِ config.
     */
    private function bodyFor(array $pages, string $kind, string $locale): string
    {
        $page = $pages[$kind] ?? null;

        if (! is_array($page)) {
            return '';
        }

        /*
        | ساختارِ واقعیِ `config/pages.php`:
        |
        |   pages.terms.{fa|en|tr}         → tag, h1a, h1b, lead   (سربرگ)
        |   pages.terms.sections[].{fa|…}  → t (عنوانِ بند), b (متنِ بند)
        |
        | ⚠️ زبان کلیدِ **بیرونی** است، نه درونی. نسخهٔ اول این متد ساختار را
        | حدس می‌زد و دنبالِ `title`/`body` می‌گشت — هیچ‌کدام وجود ندارند، پس
        | متنِ خالی برمی‌گرداند و سیدر بی‌صدا هیچ سندی نمی‌ساخت. یعنی همان
        | خرابیِ خاموشی که این کلاس برای رفعش نوشته شده، در خودش بود.
        */
        $head = (array) ($page[$locale] ?? []);
        $parts = [];

        foreach (['h1a', 'h1b', 'lead'] as $k) {
            if (filled($head[$k] ?? null)) {
                $parts[] = trim((string) $head[$k]);
            }
        }

        foreach ((array) ($page['sections'] ?? []) as $section) {
            $s = (array) ($section[$locale] ?? []);

            if (filled($s['t'] ?? null)) {
                $parts[] = trim((string) $s['t']);
            }

            if (filled($s['b'] ?? null)) {
                $parts[] = trim((string) $s['b']);
            }
        }

        return trim(implode("\n\n", array_filter($parts)));
    }
}
