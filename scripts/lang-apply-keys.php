<?php

/*
|------------------------------------------------------------------------------
| اعمالِ «کلیدهای عوض‌شدهٔ ترجمه» روی فایلِ زندهٔ سرور — بدونِ جابه‌جاییِ کلِ فایل
|------------------------------------------------------------------------------
|
| 🔴 چرا این ابزار هست (شهریور ۱۴۰۵):
|
| `lang/{fa,en,tr}/ui.php` سه‌هزار خط است و روی سرور **دریفت** دارد (هر انتشار چند کلید
| اضافه می‌کند و انتشارها موازی‌اند). ادغامِ سه‌طرفهٔ کلِ فایل همان‌جایی تداخل
| می‌کند که دو انتشار در یک ناحیه کلید افزوده باشند — و آن‌وقت یک قابلیتِ سالم
| بی‌صدا منتشر نمی‌شود. تجربهٔ واقعی: `lang/tr/ui.php` تداخل کرد در حالی که
| fa و en تمیز ادغام شدند.
|
| ولی فایلِ ترجمه **نقشهٔ کلید→مقدار** است، نه کدِ ترتیب‌دار: جای کلید در فایل
| هیچ معنایی ندارد. پس به‌جای ادغامِ متنی، فقط همان کلیدهایی را که نسخهٔ هدف
| نسبت به پایه عوض کرده روی فایلِ زنده می‌نشانیم:
|
|   • کلیدی که در فایلِ زنده هست  → مقدارش جایگزین می‌شود
|   • کلیدی که نیست             → پیش از `];`ِ پایانی اضافه می‌شود
|
| ⚠️ کامنت‌های فارسیِ فایل حفظ می‌شوند، چون فایل **بازنویسی نمی‌شود**؛ فقط
|    خط‌های همان کلیدها دست می‌خورند.
|
| ⚠️ ورودیِ چندخطه: اگر کلیدی در فایلِ زنده چندخطی نوشته شده باشد، خطِ تک‌خطی
|    پیدا نمی‌شود و کلید **افزوده** می‌شود. در PHP آخرین کلیدِ تکراری برنده
|    است، پس نتیجه همان مقدارِ درست می‌مانَد؛ ولی هشدار چاپ می‌شود.
|
| استفاده:
|   php lang-apply-keys.php <base.php> <mine.php> <live.php> [--dry]
|
| خروجی: خطِ خلاصه + کدِ خروجِ ۰ در موفقیت. در حالتِ غیرِ dry، پس از نوشتن،
| مقدارها را از **خودِ فایلِ مقصد** می‌خواند و تطبیق می‌دهد (درسِ ثبت‌شده:
| «موفقیتِ چاپ‌شدهٔ بی‌اثبات»).
*/

$argvLocal = $argv ?? [];
$dry = in_array('--dry', $argvLocal, true);
$args = array_values(array_filter(array_slice($argvLocal, 1), fn ($a) => $a !== '--dry'));

if (count($args) < 3) {
    fwrite(STDERR, "usage: php lang-apply-keys.php <base.php> <mine.php> <live.php> [--dry]\n");
    exit(2);
}

[$basePath, $minePath, $livePath] = $args;

foreach ([$basePath, $minePath, $livePath] as $p) {
    if (! is_file($p)) {
        fwrite(STDERR, "FATAL: فایل نیست: {$p}\n");
        exit(2);
    }
}

$load = static function (string $p): array {
    $v = require $p;

    return is_array($v) ? $v : [];
};

$base = $load($basePath);
$mine = $load($minePath);
$live = $load($livePath);

if ($mine === [] || $live === []) {
    fwrite(STDERR, "FATAL: فایلِ ترجمه خالی خوانده شد (نسخهٔ هدف یا زنده)\n");
    exit(2);
}

// کلیدهایی که نسخهٔ هدف نسبت به پایه عوض کرده یا افزوده — فقط رشته‌ها
$changed = [];

foreach ($mine as $k => $v) {
    if (! is_string($k) || ! is_string($v)) {
        continue;
    }

    if (! array_key_exists($k, $base) || $base[$k] !== $v) {
        $changed[$k] = $v;
    }
}

/*
| 🔴 گاردِ «پایهٔ غلط»: اگر پایه از قبل شاملِ تغییرِ من باشد، مجموعهٔ عوض‌شده
| خالی می‌شود و اسکریپت با خیالِ راحت **هیچ کاری نمی‌کند** — همان شکلِ بی‌صدای
| «دیپلوی موفق ولی بی‌اثر». پس خالی‌بودن خطاست، نه موفقیت.
*/
if ($changed === []) {
    fwrite(STDERR, "FATAL: هیچ کلیدی بینِ پایه و نسخهٔ هدف عوض نشده — پایه اشتباه است؟
");
    exit(4);
}

// کلیدی که روی سرور از قبل درست است، کاری لازم ندارد
$todo = [];

foreach ($changed as $k => $v) {
    if (! array_key_exists($k, $live) || $live[$k] !== $v) {
        $todo[$k] = $v;
    }
}

$quote = static fn (string $s): string => "'".str_replace(["\\", "'"], ["\\\\", "\\'"], $s)."'";

$text = (string) file_get_contents($livePath);
$replaced = 0;
$appended = 0;
$multiline = [];

foreach ($todo as $k => $v) {
    $pattern = "~^([ \t]*)'".preg_quote($k, '~')."'\s*=>\s*'(?:[^'\\\\]|\\\\.)*',[ \t]*$~mu";
    $out = preg_replace_callback($pattern, fn (array $m): string => $m[1]."'".$k."' => ".$quote($v).',', $text, 1, $count);

    if ($out !== null && $count === 1) {
        $text = $out;
        $replaced++;

        continue;
    }

    if (array_key_exists($k, $live)) {
        $multiline[] = $k;       // هست ولی تک‌خطی نیست ⇒ افزودن، و آخرین برنده است
    }

    // افزودن پیش از `];`ِ پایانی
    $pos = strrpos($text, '];');

    if ($pos === false) {
        fwrite(STDERR, "FATAL: پایانِ آرایه در {$livePath} پیدا نشد\n");
        exit(2);
    }

    $text = substr($text, 0, $pos)."    '".$k."' => ".$quote($v).",\n".substr($text, $pos);
    $appended++;
}

$name = basename(dirname($livePath)).'/'.basename($livePath);

if ($multiline !== []) {
    fwrite(STDERR, "WARN  {$name}: کلیدِ چندخطی افزوده شد (آخرین برنده): ".implode(', ', $multiline)."\n");
}

if ($todo === []) {
    echo "OK   {$name} — همهٔ ".count($changed)." کلید از قبل درست است\n";
    exit(0);
}

if ($dry) {
    echo "DRY  {$name} — {$replaced} جایگزینی، {$appended} افزودن (از ".count($changed)." کلیدِ نسخهٔ هدف)\n";
    exit(0);
}

if (file_put_contents($livePath, $text) === false) {
    fwrite(STDERR, "FATAL: نوشتن در {$livePath} نشد\n");
    exit(2);
}

// 🔴 اثبات از خودِ مقصد، نه از چاپِ موفقیت
$after = $load($livePath);
$bad = [];

foreach ($todo as $k => $v) {
    if (! array_key_exists($k, $after) || $after[$k] !== $v) {
        $bad[] = $k;
    }
}

if ($bad !== []) {
    fwrite(STDERR, "FATAL {$name}: این کلیدها ننشستند: ".implode(', ', array_slice($bad, 0, 5))."\n");
    exit(3);
}

echo "LANG {$name} — {$replaced} جایگزینی، {$appended} افزودن، ".count($after)." کلیدِ نهایی\n";
exit(0);
