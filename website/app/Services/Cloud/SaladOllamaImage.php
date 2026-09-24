<?php

namespace App\Services\Cloud;

use App\Models\Setting;
use App\Support\ErrorTracker;
use Illuminate\Support\Facades\Http;

/**
 * کدام نسخهٔ Ollama را تحویل می‌دهیم — همیشه تازه‌ترینی که زیرساخت دارد.
 *
 * ═══ رخداد (۱ مهر ۱۴۰۵، تیکت TK-260923-0856) ═══
 * ایمیجِ «برنامهٔ آمادهٔ Ollama» در کد سفت شده بود روی یک recipeِ لاما‌۳٫۱ که
 * آخرین‌بار **۲۰۲۴-۰۹-۱۹** ساخته شده بود. مشتری RTX 3090 ساعتی خرید تا
 * `qwen3:8b` اجرا کند و Ollama جواب داد «به نسخهٔ جدیدترِ Ollama نیاز است» —
 * چون qwen3 اردیبهشتِ ۱۴۰۴ منتشر شده، حدودِ هشت ماه **بعد** از آن ایمیج.
 *
 * 🔴 و این خط سرویس SSH ندارد (`SaladClient::capabilities()` → `rebuild:false`،
 * `reset_password:false`)، پس مشتری نه می‌توانست ارتقا دهد نه ایمیج را عوض کند.
 * یعنی یک عددِ سفت‌شده در کد، محصول را برای هر مدلی که بعد از شهریور ۱۴۰۳
 * منتشر شده **غیرقابلِ استفاده** کرده بود — و هیچ خطایی هم هیچ‌جا ثبت نمی‌شد،
 * چون از دیدِ ما تحویل موفق بود.
 *
 * ⚠️ چرا تگِ متحرک جواب نبود: مخزنِ زیرساخت اصلاً تگِ `latest` **ندارد**
 * (تگ‌ها فقط نسخه‌اند: 0.24.0، 0.17.6، …). پس «تازه‌ترین» را باید خودمان پیدا
 * کنیم. سودِ جانبی‌اش هم دقیقاً همان قاعدهٔ همیشگیِ این پروژه است: نسخهٔ
 * تحویل‌شده **پین و ثبت‌شده** می‌مانَد، نه چیزی که هر بار می‌تواند زیرِ پایمان
 * عوض شود.
 */
final class SaladOllamaImage
{
    public const REPO = 'saladtechnologies/ollama';

    /**
     * کفِ نسخه — تازه‌ترینی که در لحظهٔ نوشتنِ این کد وجود داشت و qwen3 را
     * راحت اجرا می‌کند.
     *
     * 🔴 نقشش «پیش‌فرض» نیست، **گارد** است: اگر رجیستری روزی فهرستِ ناقص یا
     * بی‌معنا بدهد، بی این کف می‌توانستیم بی‌صدا به یک نسخهٔ کهنه برگردیم و
     * دقیقاً همان تیکت را از نو بسازیم.
     */
    public const FLOOR = '0.24.0';

    /** نسخهٔ ثبت‌شده — مدیر در تنظیمات می‌بیند دقیقاً چه چیزی تحویل می‌شود */
    public const SETTING = 'salad_ollama_tag';

    /**
     * ایمیجی که به زیرساخت سفارش می‌دهیم.
     *
     * ⚠️ **بی‌تماسِ شبکه.** این متد در مسیرِ ساختِ سرور صدا زده می‌شود و یک
     * تماسِ HTTP آن‌جا یعنی سفارشی که با سکسکهٔ رجیستری شکست می‌خورد. تماس
     * فقط در `refresh()` است، که همگام‌سازیِ کاتالوگ می‌زنَدش.
     */
    public function ref(): string
    {
        return self::REPO.':'.$this->tag();
    }

    public function tag(): string
    {
        $saved = trim((string) Setting::get(self::SETTING, ''));

        return $this->isVersion($saved) && version_compare($saved, self::FLOOR, '>=')
            ? $saved
            : self::FLOOR;
    }

    /**
     * آیا این ایمیج مالِ همین برنامه است؟ — مقایسه روی **مخزن**، نه تگ.
     *
     * 🔴 این متد مهم‌ترین تکهٔ این فایل است. ردیفِ `cloud_images` مشتری با تگِ
     * همان روز ذخیره می‌شود؛ وقتی کاتالوگ فردا به نسخهٔ بعدی برود، تطبیقِ
     * دقیقِ رشته‌ای دیگر نمی‌خوانَد و `appFor()` نال می‌دهد. نتیجه‌اش خاموش و
     * خراب است: نه `OLLAMA_HOST=::` تزریق می‌شود (کانتینر فقط IPv4 گوش می‌دهد
     * و پشتِ دروازهٔ IPv6 بی‌صدا تایم‌اوت می‌شود) و نه پورتِ ۱۱۴۳۴ باز می‌شود.
     * یعنی به‌محضِ اولین ارتقای نسخه، سفارش‌های روی ردیفِ قدیمی تحویلِ مرده
     * می‌گرفتند.
     */
    public static function isOurs(string $imageRef): bool
    {
        return self::repoOf($imageRef) === self::REPO;
    }

    /** مخزنِ یک ایمیج، بی‌تگ. `a/b:1.2` → `a/b` */
    public static function repoOf(string $imageRef): string
    {
        $ref = trim($imageRef);
        $pos = strrpos($ref, ':');

        // ⚠️ دو نقطه در نامِ **میزبان** هم می‌آید (`reg.io:5000/x`). اگر بعد از
        // آخرین دونقطه اسلش باشد، آن دونقطه پورت است نه تگ.
        if ($pos === false || str_contains(substr($ref, $pos), '/')) {
            return $ref;
        }

        return substr($ref, 0, $pos);
    }

    /**
     * تازه‌ترین نسخه را از رجیستری بگیر و ثبت کن — فقط از همگام‌سازیِ کاتالوگ.
     *
     * برمی‌گرداند: تگی که از این پس تحویل می‌شود.
     */
    public function refresh(): string
    {
        $newest = $this->newestOnRegistry();

        if ($newest === null) {
            // ⚠️ شکستِ خواندن هرگز «نسخهٔ تازه‌ای نیست» نیست. همان چیزی که
            //    داشتیم را نگه می‌داریم و فریاد می‌زنیم؛ سکوت این‌جا یعنی
            //    ماه‌ها روی نسخهٔ کهنه ماندن، بی‌آنکه کسی بداند.
            ErrorTracker::noteOnce('cloud',
                'فهرستِ نسخه‌های ایمیجِ Ollama خوانده نشد؛ همچنان '.$this->tag()
                .' تحویل می‌شود. اگر ادامه داشت، دستی بررسی کنید.', 21600);

            return $this->tag();
        }

        // کف هم این‌جا اعمال می‌شود: رجیستری‌ای که فقط نسخه‌های کهنه برگرداند
        // نباید ما را عقب ببرد.
        $pick = version_compare($newest, self::FLOOR, '>') ? $newest : self::FLOOR;

        if ($pick !== $this->tag()) {
            Setting::put(self::SETTING, $pick);
        }

        return $pick;
    }

    /** بالاترین نسخهٔ پایدار در رجیستری؛ `null` یعنی نتوانستیم بپرسیم */
    private function newestOnRegistry(): ?string
    {
        try {
            $r = Http::timeout(8)->acceptJson()->get(
                'https://hub.docker.com/v2/repositories/'.self::REPO.'/tags',
                ['page_size' => 100],
            );

            if (! $r->successful()) {
                return null;
            }

            $names = array_map(
                static fn ($x) => trim((string) ($x['name'] ?? '')),
                (array) ($r->json('results') ?? []),
            );
        } catch (\Throwable) {
            return null;
        }

        // ⚠️ فقط نسخهٔ پایدار. `rc`/`beta`/`-rocm` عمداً رد می‌شوند: مشتری
        //    نباید روی نسخهٔ آزمایشی یا بیلدِ AMD بنشیند.
        $stable = array_values(array_filter($names, fn ($n) => $this->isVersion($n)));

        if ($stable === []) {
            return null;
        }

        usort($stable, static fn ($a, $b) => version_compare($a, $b));

        return end($stable) ?: null;
    }

    private function isVersion(string $tag): bool
    {
        return $tag !== '' && preg_match('/^\d+\.\d+\.\d+$/', $tag) === 1;
    }
}
