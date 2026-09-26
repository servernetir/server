<?php

/**
 * اعمالِ یک «بلوکِ نشان‌دار» روی فایلِ زندهٔ مشترک — بی‌ادغامِ کلِ فایل.
 *
 *   php apply-marked-block.php <source> <target> <marker> <anchor> [--before|--after] [--absent=REGEX] [--dry]
 *
 *   source   نسخهٔ هدف از مخزن؛ بلوک از بینِ دو خطِ نشانِ آن بیرون کشیده می‌شود
 *   target   فایلِ زنده روی سرور
 *   marker   نامِ نشان؛ خطِ آغاز شاملِ «[marker:start]» و پایان «[marker:end]» است
 *   anchor   رشته‌ای که باید **دقیقاً یک خط** از target را مشخص کند (جای درج)
 *   --absent الگویی که اگر در target باشد ولی نشان نباشد، یعنی نسخهٔ بی‌نشانِ همین
 *            بلوک از قبل هست ⇒ درجِ دوباره یعنی تکرار ⇒ خطا، نه حدس
 *
 * ═══ چرا این ابزار ═══
 *
 * `layout.blade.php` و `routes/web.php` مشترکِ همهٔ شاخه‌ها‌اند و سرور زیرمجموعهٔ
 * develop است. ادغامِ سه‌طرفهٔ کلِ فایل هر تغییری را که بینِ پایه و نسخهٔ ما در
 * develop رخ داده هم می‌آورد — در routes یعنی روتی به کنترلری که روی سرور نیست ⇒
 * ۵۰۰ ِ سراسری (درسِ ثبت‌شده). این ابزار فقط همان بلوک را جابه‌جا می‌کند و بقیهٔ
 * فایلِ زنده بایت‌به‌بایت دست نمی‌خورد؛ پایانِ خطِ فایلِ زنده (CRLF/LF) حفظ می‌شود.
 *
 * خروجی: SAME | INSERT | REPLACE (کدِ ۰)، یا FATAL با کدِ ۲ و بی‌هیچ نوشتنی.
 */

$args = array_values(array_filter($argv, fn ($a) => ! str_starts_with($a, '--')));
$flags = array_values(array_filter($argv, fn ($a) => str_starts_with($a, '--')));
[, $source, $target, $marker, $anchor] = $args + [null, null, null, null, null];

function fail(string $m): never
{
    fwrite(STDOUT, "FATAL: {$m}\n");
    exit(2);
}

if (! $source || ! $target || ! $marker || ! $anchor) {
    fail('usage: <source> <target> <marker> <anchor> [--before|--after] [--absent=REGEX] [--dry]');
}

$dry = in_array('--dry', $flags, true);
$before = ! in_array('--after', $flags, true);
$absent = null;
foreach ($flags as $f) {
    if (str_starts_with($f, '--absent=')) {
        $absent = substr($f, 9);
    }
}

$start = "[{$marker}:start]";
$end = "[{$marker}:end]";

/** @return list<string> خط‌ها بی پایانِ خط */
function lines(string $text): array
{
    return preg_split('/\r\n|\n/', rtrim(str_replace("\r\n", "\n", $text), "\n"));
}

/** @return array{0:int,1:int}|null اندیسِ خطِ آغاز و پایان */
function span(array $lines, string $start, string $end, string $what): ?array
{
    $s = $e = [];
    foreach ($lines as $i => $l) {
        if (str_contains($l, $start)) {
            $s[] = $i;
        }
        if (str_contains($l, $end)) {
            $e[] = $i;
        }
    }
    if ($s === [] && $e === []) {
        return null;
    }
    if (count($s) !== 1 || count($e) !== 1 || $e[0] <= $s[0]) {
        fail("{$what}: نشانِ بلوک ناقص یا تکراری است (آغاز ".count($s).'، پایان '.count($e).')');
    }

    return [$s[0], $e[0]];
}

if (! is_file($source)) {
    fail("source نیست: {$source}");
}
if (! is_file($target)) {
    fail("target نیست: {$target}");
}

$src = lines(file_get_contents($source));
$sspan = span($src, $start, $end, 'source') ?? fail("source نشانِ {$marker} ندارد");
$block = array_slice($src, $sspan[0], $sspan[1] - $sspan[0] + 1);

$raw = file_get_contents($target);
$eol = str_contains($raw, "\r\n") ? "\r\n" : "\n";
$dst = lines($raw);
$tspan = span($dst, $start, $end, 'target');

if ($tspan !== null) {
    $current = array_slice($dst, $tspan[0], $tspan[1] - $tspan[0] + 1);
    if ($current === $block) {
        fwrite(STDOUT, "SAME {$marker}\n");
        exit(0);
    }
    array_splice($dst, $tspan[0], $tspan[1] - $tspan[0] + 1, $block);
    $action = 'REPLACE lines '.($tspan[0] + 1).'-'.($tspan[1] + 1);
} else {
    if ($absent !== null && preg_match($absent, implode("\n", $dst)) === 1) {
        fail("target بی‌نشان است ولی الگوی {$absent} را دارد — نسخهٔ قدیمیِ همین بلوک؛ درج یعنی تکرار");
    }
    $hits = array_keys(array_filter($dst, fn ($l) => str_contains($l, $anchor)));
    if (count($hits) !== 1) {
        fail('anchor باید دقیقاً یک خط باشد؛ '.count($hits).' خط پیدا شد: '.$anchor);
    }
    $at = $before ? $hits[0] : $hits[0] + 1;
    array_splice($dst, $at, 0, $block);
    $action = 'INSERT '.($before ? 'before' : 'after').' line '.($hits[0] + 1);
}

$out = implode($eol, $dst).$eol;

if ($dry) {
    fwrite(STDOUT, "DRY {$action} ({$marker}, ".count($block)." lines)\n");
    exit(0);
}

$tmp = $target.'.mb-tmp';
if (file_put_contents($tmp, $out) === false || ! rename($tmp, $target)) {
    @unlink($tmp);
    fail("نوشتن در {$target} ممکن نشد");
}

// از خودِ مقصد بسنج، نه از چاپِ موفقیت
$check = lines(file_get_contents($target));
$again = span($check, $start, $end, 'target-after');
if ($again === null || array_slice($check, $again[0], $again[1] - $again[0] + 1) !== $block) {
    fail("پس از نوشتن، بلوکِ {$marker} در {$target} همانی نیست که باید");
}

fwrite(STDOUT, "{$action} ({$marker}, ".count($block)." lines)\n");
