<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * `scripts/apply-marked-block.php` — درجِ یک بلوکِ نشان‌دار روی فایلِ زندهٔ مشترک
 * (layout، routes) بی‌ادغامِ کلِ فایل.
 *
 * تستِ اصلی سرور را بازمی‌سازد: فایلِ واقعیِ مخزن منهای بلوک = نسخهٔ سرور؛
 * اعمالِ ابزار باید دقیقاً فایلِ مخزن را پس بدهد، بایت‌به‌بایت، و هیچ خطِ دیگری را
 * تکان ندهد.
 */
class ApplyMarkedBlockTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/amb-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** @return array{0:int,1:string} */
    private function apply(string $source, string $target, string $marker, string $anchor, string ...$flags): array
    {
        $cmd = array_merge([PHP_BINARY, base_path('../scripts/apply-marked-block.php'), $source, $target, $marker, $anchor], $flags);
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);

        return [proc_close($p), $out];
    }

    private function file(string $name, string $content): string
    {
        file_put_contents($path = $this->dir.'/'.$name, $content);

        return $path;
    }

    /** نسخهٔ «سرور» = فایلِ مخزن بی‌خطوطِ بینِ دو نشان */
    private function withoutBlock(string $content, string $marker): string
    {
        $out = [];
        $in = false;
        foreach (explode("\n", $content) as $line) {
            if (str_contains($line, "[{$marker}:start]")) {
                $in = true;
            }
            if (! $in) {
                $out[] = $line;
            }
            if (str_contains($line, "[{$marker}:end]")) {
                $in = false;
            }
        }

        return implode("\n", $out);
    }

    public function test_real_layout_round_trips_byte_for_byte(): void
    {
        $repo = str_replace("\r\n", "\n", file_get_contents(resource_path('views/admin/layout.blade.php')));
        $source = $this->file('src.blade.php', $repo);
        $target = $this->file('prod.blade.php', $this->withoutBlock($repo, 'ai-admin-nav'));

        [$code, $out] = $this->apply($source, $target, 'ai-admin-nav', '<div class="ad-nav-sep">دامنه</div>', '--before', '--absent=/nav_ai_providers/');

        $this->assertSame(0, $code, $out);
        $this->assertStringStartsWith('INSERT before', $out);
        $this->assertSame($repo, file_get_contents($target));
    }

    public function test_real_routes_round_trip_and_stay_valid_php(): void
    {
        $repo = str_replace("\r\n", "\n", file_get_contents(base_path('routes/web.php')));
        $source = $this->file('src.php', $repo);
        $target = $this->file('prod.php', $this->withoutBlock($repo, 'ai-admin-routes-create'));

        [$code, $out] = $this->apply($source, $target, 'ai-admin-routes-create', "->name('admin.ai.models.status')", '--after', "--absent=/admin\\.ai\\.models\\.create/");

        $this->assertSame(0, $code, $out);
        $this->assertSame($repo, file_get_contents($target));
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($target), $o, $lint);
        $this->assertSame(0, $lint);
    }

    public function test_second_run_is_same_and_changed_block_is_replaced(): void
    {
        $src = $this->file('src', "a\n// [m:start]\nNEW\n// [m:end]\nb\n");
        $dst = $this->file('dst', "x\n// [m:start]\nOLD\n// [m:end]\ny\n");

        [$code, $out] = $this->apply($src, $dst, 'm', 'y');
        $this->assertSame(0, $code, $out);
        $this->assertStringStartsWith('REPLACE', $out);
        $this->assertSame("x\n// [m:start]\nNEW\n// [m:end]\ny\n", file_get_contents($dst));

        [$code, $out] = $this->apply($src, $dst, 'm', 'y');
        $this->assertSame(0, $code);
        $this->assertStringStartsWith('SAME', $out);
    }

    public function test_crlf_target_keeps_crlf(): void
    {
        $src = $this->file('src', "// [m:start]\nB\n// [m:end]\n");
        $dst = $this->file('dst', "one\r\nANCHOR\r\ntwo\r\n");

        [$code] = $this->apply($src, $dst, 'm', 'ANCHOR', '--before');
        $this->assertSame(0, $code);
        $this->assertSame("one\r\n// [m:start]\r\nB\r\n// [m:end]\r\nANCHOR\r\ntwo\r\n", file_get_contents($dst));
    }

    public function test_refusals_never_write(): void
    {
        $src = $this->file('src', "// [m:start]\nB\n// [m:end]\n");

        foreach ([
            'anchor missing' => ["one\ntwo\n", 'ANCHOR', []],
            'anchor twice' => ["ANCHOR\nANCHOR\n", 'ANCHOR', []],
            'unmarked old copy' => ["legacy nav_ai_providers\nANCHOR\n", 'ANCHOR', ['--absent=/nav_ai_providers/']],
            'half markers' => ["// [m:start]\nANCHOR\n", 'ANCHOR', []],
        ] as $case => [$content, $anchor, $flags]) {
            $dst = $this->file('dst-'.md5($case), $content);
            [$code, $out] = $this->apply($src, $dst, 'm', $anchor, ...$flags);

            $this->assertSame(2, $code, "{$case}: {$out}");
            $this->assertStringStartsWith('FATAL', $out, $case);
            $this->assertSame($content, file_get_contents($dst), "{$case}: فایل نباید عوض شود");
        }
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $src = $this->file('src', "// [m:start]\nB\n// [m:end]\n");
        $dst = $this->file('dst', "ANCHOR\n");

        [$code, $out] = $this->apply($src, $dst, 'm', 'ANCHOR', '--dry');
        $this->assertSame(0, $code);
        $this->assertStringStartsWith('DRY INSERT before line 1', $out);
        $this->assertSame("ANCHOR\n", file_get_contents($dst));
    }
}
