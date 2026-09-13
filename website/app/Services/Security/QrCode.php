<?php

namespace App\Services\Security;

use InvalidArgumentException;

/**
 * QR — کدگذارِ کمینه (حالتِ بایت، سطحِ خطاگیریِ M، نسخهٔ ۱ تا ۱۰) با خروجیِ SVG.
 *
 * ═══ چرا خودمان نوشتیم ═══
 *
 * ۱) **CSP هر منبعِ بیرونی را بی‌صدا بلاک می‌کند** (`SecurityHeaders`). یعنی
 *    راهِ همیشگیِ «تصویر را از api.qrserver.com بگیر» این‌جا اصلاً کار نمی‌کند،
 *    و بدتر: بی‌هیچ خطایی کار نمی‌کند — کاربر یک کادرِ خالی می‌بیند.
 * ۲) و اگر هم کار می‌کرد نباید می‌کردیم: آن URL **رازِ دوعاملیِ کاربر** را به
 *    یک سرورِ غریبه می‌فرستد. رازی که کلِ این قابلیت روی مخفی‌ماندنش بنا شده.
 * ۳) پکیجِ composer هم نه — دپلویِ این پروژه فایل‌به‌فایل است و `vendor/` روی
 *    سرور دستی به‌روز نمی‌شود.
 *
 * خروجی SVG است نه PNG: نه به GD وابسته است، در هر اندازه‌ای تیز می‌مانَد، و
 * چون درون‌خطی در HTML می‌نشیند هیچ درخواستِ شبکه‌ای و هیچ قاعدهٔ CSPِ تازه‌ای
 * نمی‌خواهد.
 *
 * ⚠️ نسخهٔ ۱۰ حدود ۲۱۳ بایت جا دارد و نشانیِ otpauth ما حدودِ ۱۴۰ بایت است؛
 * جا کافی است. اگر روزی رشتهٔ بلندتری خواستی، `encode()` استثنا می‌دهد نه
 * اینکه QRِ خرابِ بی‌صدا بسازد.
 *
 * صحتِ پیاده‌سازی با مقایسهٔ ماتریسِ خروجی با کتابخانهٔ مرجعِ `qrcode` (npm)
 * سنجیده شده — `tests/Feature/QrCodeTest.php` بردارهای همان مقایسه را قفل
 * می‌کند.
 */
class QrCode
{
    /** بیشترین نسخه‌ای که پشتیبانی می‌کنیم */
    private const MAX_VERSION = 10;

    /** تعداد کدواژهٔ خطاگیری در هر بلوک — سطحِ M، نسخهٔ ۱..۱۰ */
    private const ECC_PER_BLOCK = [1 => 10, 16, 26, 18, 24, 16, 18, 22, 22, 26];

    /** تعداد بلوکِ خطاگیری — سطحِ M، نسخهٔ ۱..۱۰ */
    private const ECC_BLOCKS = [1 => 1, 1, 1, 2, 2, 4, 4, 4, 5, 5];

    /** بیت‌های سطحِ خطاگیریِ M در اطلاعاتِ قالب */
    private const ECC_FORMAT_BITS = 0b00;

    private int $size;

    /** @var array<int,array<int,bool>> */
    private array $modules = [];

    /** @var array<int,array<int,bool>> ماژول‌هایی که نقشِ ساختاری دارند و داده نمی‌گیرند */
    private array $reserved = [];

    private function __construct(private int $version)
    {
        $this->size = $version * 4 + 17;

        for ($y = 0; $y < $this->size; $y++) {
            $this->modules[$y] = array_fill(0, $this->size, false);
            $this->reserved[$y] = array_fill(0, $this->size, false);
        }
    }

    /**
     * SVGِ آمادهٔ درون‌خطی.
     *
     * رنگ عمداً `currentColor` است تا در تمِ روشن و تاریکِ پنل خودش را وفق دهد،
     * ولی زمینه **همیشه سفیدِ صریح** است: بعضی اسکنرها روی زمینهٔ تیره کد را
     * وارونه می‌بینند و نمی‌خوانند. زمینهٔ شفاف هم همان ریسک را دارد.
     */
    public static function svg(string $text, int $quietZone = 4, int $pixel = 6): string
    {
        $matrix = self::encode($text);
        $n = count($matrix);
        $side = ($n + $quietZone * 2) * $pixel;

        // یک مسیرِ واحد برای همهٔ ماژول‌ها — هزاران <rect> صفحه را سنگین می‌کند
        $path = '';

        foreach ($matrix as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $path .= 'M'.(($x + $quietZone) * $pixel).' '.(($y + $quietZone) * $pixel)
                        .'h'.$pixel.'v'.$pixel.'h-'.$pixel.'z';
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$side.'" height="'.$side.'"'
            .' viewBox="0 0 '.$side.' '.$side.'" shape-rendering="crispEdges" role="img">'
            .'<rect width="'.$side.'" height="'.$side.'" fill="#fff"/>'
            .'<path d="'.$path.'" fill="#000"/>'
            .'</svg>';
    }

    /**
     * متن → ماتریسِ بولین (سطر، ستون). `true` یعنی ماژولِ تیره.
     *
     * @return array<int,array<int,bool>>
     */
    public static function encode(string $text): array
    {
        $version = self::pickVersion(strlen($text));

        $qr = new self($version);
        $qr->drawFunctionPatterns();
        $qr->drawCodewords($qr->buildCodewords($text));

        return $qr->applyBestMask();
    }

    /** کوچک‌ترین نسخه‌ای که این طول در آن جا می‌شود */
    private static function pickVersion(int $length): int
    {
        for ($v = 1; $v <= self::MAX_VERSION; $v++) {
            // ۴ بیت حالت + شمارندهٔ طول (۸ بیت تا نسخهٔ ۹، بعد ۱۶ بیت)
            $needed = 4 + ($v <= 9 ? 8 : 16) + $length * 8;

            if ($needed <= self::dataCodewords($v) * 8) {
                return $v;
            }
        }

        throw new InvalidArgumentException('رشته برای QRِ نسخهٔ '.self::MAX_VERSION.' بلند است: '.$length.' بایت.');
    }

    /** کلِ ماژول‌های دادهٔ نسخه (بیت) ÷ ۸ */
    private static function rawCodewords(int $version): int
    {
        $result = (16 * $version + 128) * $version + 64;

        if ($version >= 2) {
            $numAlign = intdiv($version, 7) + 2;
            $result -= (25 * $numAlign - 10) * $numAlign - 55;

            if ($version >= 7) {
                $result -= 36;
            }
        }

        return intdiv($result, 8);
    }

    private static function dataCodewords(int $version): int
    {
        return self::rawCodewords($version) - self::ECC_PER_BLOCK[$version] * self::ECC_BLOCKS[$version];
    }

    // ─────────────────────── ساختِ کدواژه‌ها ───────────────────────

    /**
     * متن → کدواژه‌های نهایی (داده + خطاگیری، درهم‌بافته).
     *
     * @return array<int,int>
     */
    private function buildCodewords(string $text): array
    {
        $bits = '';
        $bits .= '0100';                                                    // حالتِ بایت
        $bits .= str_pad(decbin(strlen($text)), $this->version <= 9 ? 8 : 16, '0', STR_PAD_LEFT);

        foreach (str_split($text) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $capacity = self::dataCodewords($this->version) * 8;

        // پایان‌بند: حداکثر ۴ بیتِ صفر، و اگر جا نبود کمتر
        $bits .= str_repeat('0', min(4, $capacity - strlen($bits)));

        // تا مرزِ بایت صفر
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);

        $data = [];

        foreach (str_split($bits, 8) as $byte) {
            $data[] = bindec($byte);
        }

        // بایت‌های پرکننده — الگوی استاندارد و متناوبِ EC/11
        $pad = [0xEC, 0x11];

        for ($i = 0; count($data) < self::dataCodewords($this->version); $i++) {
            $data[] = $pad[$i % 2];
        }

        return $this->addEccAndInterleave($data);
    }

    /**
     * افزودنِ خطاگیری و درهم‌بافتنِ بلوک‌ها.
     *
     * درهم‌بافتن (interleave) تزئینی نیست: خرابیِ فیزیکی روی QR معمولاً
     * **موضعی** است (یک لکه، یک تاخوردگی). اگر کدواژه‌های یک بلوک پشتِ هم
     * بنشینند، یک لکه کلِ یک بلوک را می‌بَرد و از توانِ ترمیم بیرون می‌زند؛
     * با درهم‌بافتن همان لکه از هر بلوک فقط چند کدواژه می‌گیرد.
     *
     * @param  array<int,int>  $data
     * @return array<int,int>
     */
    private function addEccAndInterleave(array $data): array
    {
        $numBlocks   = self::ECC_BLOCKS[$this->version];
        $eccLen      = self::ECC_PER_BLOCK[$this->version];
        $rawCw       = self::rawCodewords($this->version);
        $shortBlocks = $numBlocks - $rawCw % $numBlocks;
        $shortLen    = intdiv($rawCw, $numBlocks);

        $divisor = self::rsDivisor($eccLen);

        /** @var array<int,array<int,int>> $blocks */
        $blocks = [];
        $offset = 0;

        for ($i = 0; $i < $numBlocks; $i++) {
            $len = $shortLen - $eccLen + ($i < $shortBlocks ? 0 : 1);
            $chunk = array_slice($data, $offset, $len);
            $offset += $len;
            $blocks[] = [$chunk, self::rsRemainder($chunk, $divisor)];
        }

        $result = [];

        // ابتدا کدواژه‌های داده، ستون‌به‌ستون؛ بلوکِ کوتاه یک خانه کم دارد
        for ($i = 0; $i < $shortLen - $eccLen + 1; $i++) {
            foreach ($blocks as [$chunk]) {
                if ($i < count($chunk)) {
                    $result[] = $chunk[$i];
                }
            }
        }

        // سپس کدواژه‌های خطاگیری — طولشان در همهٔ بلوک‌ها یکی است
        for ($i = 0; $i < $eccLen; $i++) {
            foreach ($blocks as [, $ecc]) {
                $result[] = $ecc[$i];
            }
        }

        return $result;
    }

    // ─────────────────────── ریدسالمون روی GF(256) ───────────────────────

    /** @return array<int,int> */
    private static function rsDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;

        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::gfMul($result[$j], $root);

                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }

            $root = self::gfMul($root, 2);
        }

        return $result;
    }

    /**
     * @param  array<int,int>  $data
     * @param  array<int,int>  $divisor
     * @return array<int,int>
     */
    private static function rsRemainder(array $data, array $divisor): array
    {
        $degree = count($divisor);
        $result = array_fill(0, $degree, 0);

        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($result);
            $result[] = 0;

            for ($i = 0; $i < $degree; $i++) {
                $result[$i] ^= self::gfMul($divisor[$i], $factor);
            }
        }

        return $result;
    }

    /** ضرب در میدانِ گالوا با چندجمله‌ایِ اولیهٔ 0x11D */
    private static function gfMul(int $x, int $y): int
    {
        $z = 0;

        for ($i = 7; $i >= 0; $i--) {
            $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
            $z ^= (($y >> $i) & 1) * $x;
        }

        return $z & 0xFF;
    }

    // ─────────────────────── الگوهای ساختاری ───────────────────────

    private function drawFunctionPatterns(): void
    {
        // الگوی زمان‌بندی — ردیف و ستونِ ۶
        for ($i = 0; $i < $this->size; $i++) {
            $this->setFunction(6, $i, $i % 2 === 0);
            $this->setFunction($i, 6, $i % 2 === 0);
        }

        // سه الگوی یابنده با جداکننده‌شان
        $this->drawFinder(3, 3);
        $this->drawFinder(3, $this->size - 4);
        $this->drawFinder($this->size - 4, 3);

        // الگوهای تراز — سه گوشهٔ یابنده‌دار کنار گذاشته می‌شوند
        $pos = $this->alignmentPositions();
        $n = count($pos);

        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                $corner = ($i === 0 && $j === 0)
                    || ($i === 0 && $j === $n - 1)
                    || ($i === $n - 1 && $j === 0);

                if (! $corner) {
                    $this->drawAlignment($pos[$i], $pos[$j]);
                }
            }
        }

        // جای اطلاعاتِ قالب رزرو می‌شود؛ مقدارش بعد از انتخابِ ماسک نوشته می‌شود
        $this->drawFormatBits(0);
        $this->drawVersionBits();
    }

    private function drawFinder(int $row, int $col): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $dist = max(abs($dx), abs($dy));
                $y = $row + $dy;
                $x = $col + $dx;

                if ($y >= 0 && $y < $this->size && $x >= 0 && $x < $this->size) {
                    $this->setFunction($y, $x, $dist !== 2 && $dist !== 4);
                }
            }
        }
    }

    private function drawAlignment(int $row, int $col): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->setFunction($row + $dy, $col + $dx, max(abs($dx), abs($dy)) !== 1);
            }
        }
    }

    /** @return array<int,int> */
    private function alignmentPositions(): array
    {
        if ($this->version === 1) {
            return [];
        }

        $num  = intdiv($this->version, 7) + 2;
        $step = intdiv($this->version * 4 + $num * 2 + 1, $num * 2 - 2) * 2;

        $result = array_fill(0, $num, 0);
        $result[0] = 6;

        for ($i = $num - 1, $p = $this->size - 7; $i >= 1; $i--, $p -= $step) {
            $result[$i] = $p;
        }

        return $result;
    }

    /**
     * اطلاعاتِ قالب — سطحِ خطاگیری + شمارهٔ ماسک، با BCH(15,5).
     *
     * دو نسخه از این ۱۵ بیت در دو جای مختلف نوشته می‌شود تا اگر یک گوشهٔ کد
     * خراب شد، اسکنر باز هم بداند با کدام ماسک رمزگشایی کند.
     */
    private function drawFormatBits(int $mask): void
    {
        $data = self::ECC_FORMAT_BITS << 3 | $mask;
        $rem = $data;

        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }

        $bits = (($data << 10 | $rem) ^ 0x5412) & 0x7FFF;

        // نسخهٔ اول — دورِ یابندهٔ بالا-چپ
        for ($i = 0; $i <= 5; $i++) {
            $this->setFunction($i, 8, self::bit($bits, $i));
        }

        $this->setFunction(7, 8, self::bit($bits, 6));
        $this->setFunction(8, 8, self::bit($bits, 7));
        $this->setFunction(8, 7, self::bit($bits, 8));

        for ($i = 9; $i < 15; $i++) {
            $this->setFunction(8, 14 - $i, self::bit($bits, $i));
        }

        // نسخهٔ دوم — کنارِ دو یابندهٔ دیگر
        for ($i = 0; $i < 8; $i++) {
            $this->setFunction(8, $this->size - 1 - $i, self::bit($bits, $i));
        }

        for ($i = 8; $i < 15; $i++) {
            $this->setFunction($this->size - 15 + $i, 8, self::bit($bits, $i));
        }

        // ماژولِ همیشه‌تیره — بخشی از استاندارد است، نه داده
        $this->setFunction($this->size - 8, 8, true);
    }

    /** اطلاعاتِ نسخه — فقط از نسخهٔ ۷ به بالا وجود دارد */
    private function drawVersionBits(): void
    {
        if ($this->version < 7) {
            return;
        }

        $rem = $this->version;

        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }

        $bits = $this->version << 12 | $rem;

        for ($i = 0; $i < 18; $i++) {
            $bit = self::bit($bits, $i);
            $a = $this->size - 11 + $i % 3;
            $b = intdiv($i, 3);

            $this->setFunction($b, $a, $bit);
            $this->setFunction($a, $b, $bit);
        }
    }

    // ─────────────────────── چیدنِ داده و ماسک ───────────────────────

    /**
     * چیدنِ کدواژه‌ها در مسیرِ زیگزاگ — از پایین-راست، دو ستون دو ستون.
     *
     * @param  array<int,int>  $codewords
     */
    private function drawCodewords(array $codewords): void
    {
        $i = 0;
        $total = count($codewords) * 8;

        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            // ستونِ ۶ الگوی زمان‌بندی است و از شمارش کنار می‌رود
            if ($right === 6) {
                $right = 5;
            }

            for ($vert = 0; $vert < $this->size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vert : $vert;

                    if (! $this->reserved[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    /**
     * هر هشت ماسک را امتحان می‌کند و کم‌جریمه‌ترین را برمی‌گرداند.
     *
     * ماسک انتخابی نیست که «هر کدام کار کند»: بدونِ آن، داده‌ای که اتفاقاً
     * راه‌راه یا یک‌دست از آب دربیاید شبیهِ الگوی یابنده می‌شود و اسکنر یا کد
     * را پیدا نمی‌کند یا وارونه می‌خوانَد.
     *
     * @return array<int,array<int,bool>>
     */
    private function applyBestMask(): array
    {
        $best = null;
        $bestPenalty = PHP_INT_MAX;

        for ($mask = 0; $mask < 8; $mask++) {
            $this->applyMask($mask);
            $this->drawFormatBits($mask);

            $penalty = $this->penalty();

            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best = $this->modules;
            }

            // ماسک با XOR اعمال می‌شود، پس اعمالِ دوباره برش می‌گرداند
            $this->applyMask($mask);
        }

        return $best;
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->reserved[$y][$x]) {
                    continue;
                }

                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    7 => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };

                if ($invert) {
                    $this->modules[$y][$x] = ! $this->modules[$y][$x];
                }
            }
        }
    }

    /** چهار قاعدهٔ جریمهٔ استاندارد */
    private function penalty(): int
    {
        $result = 0;
        $n = $this->size;

        foreach ([true, false] as $byRow) {
            for ($a = 0; $a < $n; $a++) {
                $runColor = false;
                $runLen = 0;
                $history = [0, 0, 0, 0, 0, 0, 0];

                for ($b = 0; $b < $n; $b++) {
                    $dark = $byRow ? $this->modules[$a][$b] : $this->modules[$b][$a];

                    if ($dark === $runColor) {
                        $runLen++;

                        if ($runLen === 5) {
                            $result += 3;
                        } elseif ($runLen > 5) {
                            $result++;
                        }
                    } else {
                        $this->pushRun($runLen, $history);

                        if (! $runColor) {
                            $result += $this->finderLikeCount($history) * 40;
                        }

                        $runColor = $dark;
                        $runLen = 1;
                    }
                }

                if ($runColor) {
                    $this->pushRun($runLen, $history);
                    $runLen = 0;
                }

                $this->pushRun($runLen + $n, $history);
                $result += $this->finderLikeCount($history) * 40;
            }
        }

        // بلوک‌های ۲×۲ هم‌رنگ
        for ($y = 0; $y < $n - 1; $y++) {
            for ($x = 0; $x < $n - 1; $x++) {
                $c = $this->modules[$y][$x];

                if ($c === $this->modules[$y][$x + 1]
                    && $c === $this->modules[$y + 1][$x]
                    && $c === $this->modules[$y + 1][$x + 1]) {
                    $result += 3;
                }
            }
        }

        // نسبتِ تیره به روشن — هرچه از ۵۰٪ دورتر، جریمهٔ بیشتر
        $dark = 0;

        foreach ($this->modules as $row) {
            $dark += count(array_filter($row));
        }

        $total = $n * $n;
        $k = intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 1;

        return $result + $k * 10;
    }

    /** @param array<int,int> $history */
    private function pushRun(int $length, array &$history): void
    {
        if ($history[0] === 0) {
            // حاشیهٔ روشنِ بیرونِ کد جزوِ اولین دویدن حساب می‌شود
            $length += $this->size;
        }

        array_pop($history);
        array_unshift($history, $length);
    }

    /**
     * شمارشِ الگوی ۱:۱:۳:۱:۱ — همان نسبتی که یابنده دارد.
     *
     * @param  array<int,int>  $h
     */
    private function finderLikeCount(array $h): int
    {
        $n = $h[1];
        $core = $n > 0 && $h[2] === $n && $h[3] === $n * 3 && $h[4] === $n && $h[5] === $n;

        return ($core && $h[0] >= $n * 4 && $h[6] >= $n ? 1 : 0)
            + ($core && $h[6] >= $n * 4 && $h[0] >= $n ? 1 : 0);
    }

    // ─────────────────────── کمکی ───────────────────────

    private function setFunction(int $row, int $col, bool $dark): void
    {
        $this->modules[$row][$col] = $dark;
        $this->reserved[$row][$col] = true;
    }

    private static function bit(int $value, int $index): bool
    {
        return (($value >> $index) & 1) === 1;
    }
}
