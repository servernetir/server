<?php

namespace Tests\Feature;

use App\Services\Security\QrCode;
use App\Services\Security\Totp;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * کدگذارِ QR — همان چیزی که رازِ دومرحله‌ای را به دوربینِ گوشیِ کاربر می‌رساند.
 *
 * ═══ چرا این تست این‌شکلی است ═══
 *
 * QRِ خراب **خطا نمی‌دهد**. یک SVGِ کاملاً سالم رندر می‌شود، صفحه ۲۰۰ می‌گیرد،
 * و فقط گوشیِ کاربر می‌گوید «چیزی پیدا نشد» — بدونِ هیچ ردی در هیچ لاگی. پس
 * «رندر شد» این‌جا هیچ چیزی ثابت نمی‌کند و تست باید ماتریس را **بازخوانی** کند.
 *
 * درستیِ اولیه بیرون از PHP سنجیده شد: ۳۶ رشته (نسخهٔ ۱ تا ۱۰، شاملِ نشانی‌های
 * واقعیِ otpauth) با کتابخانهٔ `jsqr` رمزگشایی شدند و هر ۳۶ تا دقیقاً همان
 * ورودی را برگرداندند؛ ساختارِ ماتریس هم با `qrcode` (npm) مقایسه شد و
 * ماژول‌به‌ماژول یکی بود جز انتخابِ ماسک، که استاندارد در قاعدهٔ سومِ جریمه
 * ابهام دارد و هر دو انتخاب معتبر است.
 *
 * این‌جا همان کار با یک رمزگشای مستقل در خودِ تست تکرار می‌شود تا اگر فردا
 * کسی چیزی را جابه‌جا کرد، قرمز شود.
 */
class QrCodeTest extends TestCase
{
    /**
     * مرکزِ الگوهای تراز — مستقیم از جدولِ استاندارد، نه از فرمولِ خودِ کدگذار.
     *
     * عمداً جدول است نه فرمول: اگر فرمولِ `QrCode` غلط باشد، فرمولِ کپی‌شده در
     * تست همان غلط را تکرار می‌کند و هیچ‌وقت قرمز نمی‌شود.
     *
     * @var array<int,array<int,int>>
     */
    private const ALIGNMENT = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
        6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];

    /**
     * ساختارِ بلوک‌ها برای سطحِ خطاگیریِ M — باز هم از جدولِ استاندارد.
     *
     * قالب: نسخه => [کدواژهٔ خطاگیری در هر بلوک، [[تعداد بلوک، کدواژهٔ داده], …]]
     *
     * @var array<int,array{0:int,1:array<int,array<int,int>>}>
     */
    private const BLOCKS = [
        1  => [10, [[1, 16]]],
        2  => [16, [[1, 28]]],
        3  => [26, [[1, 44]]],
        4  => [18, [[2, 32]]],
        5  => [24, [[2, 43]]],
        6  => [16, [[4, 27]]],
        7  => [18, [[4, 31]]],
        8  => [22, [[2, 38], [2, 39]]],
        9  => [22, [[3, 36], [2, 37]]],
        10 => [26, [[4, 43], [1, 44]]],
    ];

    // ───────────────────────────── تست‌ها ─────────────────────────────

    /**
     * 🔴 قلبِ ماجرا: نشانیِ واقعیِ otpauth باید سالم برگردد.
     *
     * اگر این قرمز شود یعنی کاربر QR را اسکن می‌کند و یا چیزی پیدا نمی‌شود یا —
     * بدتر — رازِ غلطی وارد اپلیکیشنش می‌شود و کدهایش هیچ‌وقت قبول نمی‌شوند.
     */
    public function test_a_real_otpauth_uri_survives_a_round_trip(): void
    {
        $uri = Totp::uri(Totp::generateSecret(), 'customer@example.com', 'ServerNet Cloud');

        $this->assertSame($uri, $this->readBack(QrCode::encode($uri)));
    }

    /** هر ده نسخه‌ای که پشتیبانی می‌کنیم، در هر دو سرِ ظرفیتشان */
    public function test_every_supported_version_round_trips(): void
    {
        $seen = [];

        foreach ([1, 8, 14, 20, 26, 30, 40, 50, 60, 70, 84, 90, 106, 120, 122, 130, 152, 160, 180, 190, 213] as $length) {
            $text = substr(str_repeat('otpauth-payload-0123456789/:%@.', 20), 0, $length);

            $matrix = QrCode::encode($text);
            $version = intdiv(count($matrix) - 17, 4);
            $seen[$version] = true;

            $this->assertSame($text, $this->readBack($matrix), 'طول '.$length.' (نسخهٔ '.$version.')');
        }

        // اگر روزی انتخابِ نسخه عوض شد و همه در یک نسخه جا شدند، این می‌گیردش
        $this->assertGreaterThanOrEqual(8, count($seen), 'باید چند نسخهٔ مختلف پوشش داده شود');
    }

    /** ساختارِ ثابتِ هر QR — یابنده‌ها، زمان‌بندی، و ماژولِ همیشه‌تیره */
    public function test_the_fixed_patterns_are_where_the_standard_says(): void
    {
        $matrix = QrCode::encode(Totp::uri(Totp::generateSecret(), 'a@b.co', 'SN'));
        $size = count($matrix);

        foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$r, $c]) {
            // حلقهٔ بیرونیِ تیره، حلقهٔ میانیِ روشن، هستهٔ ۳×۳ تیره
            $this->assertTrue($matrix[$r][$c], 'گوشهٔ یابنده');
            $this->assertFalse($matrix[$r + 1][$c + 1], 'حلقهٔ روشنِ یابنده');
            $this->assertTrue($matrix[$r + 3][$c + 3], 'هستهٔ یابنده');
        }

        for ($i = 8; $i < $size - 8; $i++) {
            $this->assertSame($i % 2 === 0, $matrix[6][$i], 'زمان‌بندیِ افقی در '.$i);
            $this->assertSame($i % 2 === 0, $matrix[$i][6], 'زمان‌بندیِ عمودی در '.$i);
        }

        $this->assertTrue($matrix[$size - 8][8], 'ماژولِ همیشه‌تیره');
    }

    /** اطلاعاتِ قالب باید سطحِ M و همان ماسکی را بگوید که واقعاً اعمال شده */
    public function test_the_format_bits_announce_error_level_m(): void
    {
        $matrix = QrCode::encode(Totp::uri(Totp::generateSecret(), 'a@b.co', 'SN'));

        [$ecc, $mask] = $this->readFormat($matrix);

        $this->assertSame(0b00, $ecc, 'سطحِ خطاگیری باید M باشد');
        $this->assertGreaterThanOrEqual(0, $mask);
        $this->assertLessThanOrEqual(7, $mask);
    }

    /** SVG باید خودبسنده باشد — CSP هر منبعِ بیرونی را بی‌صدا بلاک می‌کند */
    public function test_the_svg_is_self_contained_and_has_a_white_background(): void
    {
        $svg = QrCode::svg(Totp::uri(Totp::generateSecret(), 'a@b.co', 'SN'));

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('fill="#fff"', $svg, 'زمینهٔ سفیدِ صریح لازم است وگرنه بعضی اسکنرها کد را وارونه می‌بینند');
        $this->assertStringNotContainsString('http://', str_replace('http://www.w3.org/2000/svg', '', $svg));
        $this->assertStringNotContainsString('<script', $svg);
        $this->assertStringNotContainsString('<image', $svg);
    }

    /**
     * ⚠️ رشتهٔ بلندتر از ظرفیت باید **استثنا** بدهد، نه QRِ ناقص.
     *
     * QRِ بی‌صدا-خرابِ رندرشده بدترین حالت است: کسی خبردار نمی‌شود.
     */
    public function test_an_oversized_payload_throws_instead_of_producing_a_broken_code(): void
    {
        $this->expectException(InvalidArgumentException::class);

        QrCode::encode(str_repeat('x', 214));
    }

    // ─────────────────── رمزگشای مستقلِ همین تست ───────────────────

    /**
     * ماتریس → متنِ اصلی. مسیرِ برعکسِ کدگذار، ولی با جدول‌های مستقل.
     *
     * @param  array<int,array<int,bool>>  $matrix
     */
    private function readBack(array $matrix): string
    {
        $size = count($matrix);
        $version = intdiv($size - 17, 4);

        [, $mask] = $this->readFormat($matrix);
        $reserved = $this->functionMap($size, $version);

        // ۱) برداشتنِ ماسک
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if (! $reserved[$y][$x] && $this->maskAt($mask, $x, $y)) {
                    $matrix[$y][$x] = ! $matrix[$y][$x];
                }
            }
        }

        // ۲) خواندنِ بیت‌ها در همان مسیرِ زیگزاگ
        $bits = '';

        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }

            for ($vert = 0; $vert < $size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $y = ((($right + 1) & 2) === 0) ? $size - 1 - $vert : $vert;

                    if (! $reserved[$y][$x]) {
                        $bits .= $matrix[$y][$x] ? '1' : '0';
                    }
                }
            }
        }

        $codewords = [];

        foreach (str_split(substr($bits, 0, intdiv(strlen($bits), 8) * 8), 8) as $byte) {
            $codewords[] = bindec($byte);
        }

        // ۳) بازکردنِ درهم‌بافتگی — فقط کدواژه‌های داده لازم است
        $data = $this->deinterleave($codewords, $version);

        // ۴) خواندنِ سرآیند: ۴ بیتِ حالت (۰۱۰۰ = بایت) + شمارندهٔ طول
        $stream = '';

        foreach ($data as $byte) {
            $stream .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }

        $this->assertSame('0100', substr($stream, 0, 4), 'حالت باید «بایت» باشد');

        $countBits = $version <= 9 ? 8 : 16;
        $length = bindec(substr($stream, 4, $countBits));

        $text = '';

        for ($i = 0; $i < $length; $i++) {
            $text .= chr(bindec(substr($stream, 4 + $countBits + $i * 8, 8)));
        }

        return $text;
    }

    /**
     * @param  array<int,int>  $codewords
     * @return array<int,int>
     */
    private function deinterleave(array $codewords, int $version): array
    {
        [$eccLen, $groups] = self::BLOCKS[$version];

        /** @var array<int,int> $lengths طولِ دادهٔ هر بلوک، به ترتیب */
        $lengths = [];

        foreach ($groups as [$count, $dataLen]) {
            for ($i = 0; $i < $count; $i++) {
                $lengths[] = $dataLen;
            }
        }

        $blocks = array_fill(0, count($lengths), []);
        $index = 0;

        for ($i = 0; $i < max($lengths); $i++) {
            foreach ($lengths as $b => $len) {
                if ($i < $len) {
                    $blocks[$b][] = $codewords[$index++];
                }
            }
        }

        return array_merge(...$blocks);
    }

    /**
     * نقشهٔ ماژول‌های ساختاری — از روی استاندارد ساخته می‌شود، نه از کدگذار.
     *
     * @return array<int,array<int,bool>>
     */
    private function functionMap(int $size, int $version): array
    {
        $map = [];

        for ($y = 0; $y < $size; $y++) {
            $map[$y] = array_fill(0, $size, false);
        }

        $fill = function (int $r0, int $c0, int $r1, int $c1) use (&$map, $size) {
            for ($y = max(0, $r0); $y <= min($size - 1, $r1); $y++) {
                for ($x = max(0, $c0); $x <= min($size - 1, $c1); $x++) {
                    $map[$y][$x] = true;
                }
            }
        };

        // یابنده‌ها با جداکننده‌شان، و نوارِ اطلاعاتِ قالب کنارشان
        $fill(0, 0, 8, 8);
        $fill(0, $size - 8, 8, $size - 1);
        $fill($size - 8, 0, $size - 1, 8);

        // زمان‌بندی
        $fill(6, 0, 6, $size - 1);
        $fill(0, 6, $size - 1, 6);

        // اطلاعاتِ نسخه (از نسخهٔ ۷ به بالا)
        if ($version >= 7) {
            $fill(0, $size - 11, 5, $size - 9);
            $fill($size - 11, 0, $size - 9, 5);
        }

        // الگوهای تراز — سه گوشهٔ یابنده‌دار کنار گذاشته می‌شوند
        $pos = self::ALIGNMENT[$version];
        $n = count($pos);

        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                $corner = ($i === 0 && $j === 0)
                    || ($i === 0 && $j === $n - 1)
                    || ($i === $n - 1 && $j === 0);

                if (! $corner) {
                    $fill($pos[$i] - 2, $pos[$j] - 2, $pos[$i] + 2, $pos[$j] + 2);
                }
            }
        }

        return $map;
    }

    /**
     * @param  array<int,array<int,bool>>  $matrix
     * @return array{0:int,1:int} [سطحِ خطاگیری، شمارهٔ ماسک]
     */
    private function readFormat(array $matrix): array
    {
        $bits = 0;

        for ($i = 0; $i <= 5; $i++) {
            $bits |= ((int) $matrix[$i][8]) << $i;
        }

        $bits |= ((int) $matrix[7][8]) << 6;
        $bits |= ((int) $matrix[8][8]) << 7;
        $bits |= ((int) $matrix[8][7]) << 8;

        for ($i = 9; $i < 15; $i++) {
            $bits |= ((int) $matrix[8][14 - $i]) << $i;
        }

        $data = ($bits ^ 0x5412) >> 10;

        return [($data >> 3) & 0b11, $data & 0b111];
    }

    private function maskAt(int $mask, int $x, int $y): bool
    {
        return match ($mask) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
            5 => $x * $y % 2 + $x * $y % 3 === 0,
            6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
            7 => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
        };
    }
}
