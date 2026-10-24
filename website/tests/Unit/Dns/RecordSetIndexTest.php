<?php

namespace Tests\Unit\Dns;

use App\Services\Dns\RecordSetIndex;
use App\Services\Dns\RecordValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * نمایهٔ واردکردن باید دقیقاً همان کدهای `RecordValidator::conflicts()` را بدهد —
 * فقط بدونِ هزینهٔ درجهٔ دو.
 */
class RecordSetIndexTest extends TestCase
{
    private const EXISTING = [
        ['id' => 1, 'type' => 'A', 'name' => 'www.ex.com', 'content' => '185.10.20.30', 'prio' => null],
        ['id' => 2, 'type' => 'A', 'name' => 'www.ex.com', 'content' => '185.10.20.31', 'prio' => null],
        ['id' => 3, 'type' => 'CNAME', 'name' => 'blog.ex.com', 'content' => 'x.example.net.', 'prio' => null],
        ['id' => 4, 'type' => 'MX', 'name' => 'ex.com', 'content' => 'mx1.ex.com.', 'prio' => 10],
        ['id' => 5, 'type' => 'TXT', 'name' => 'ex.com', 'content' => '"v=spf1 -all"', 'prio' => null],
        ['id' => 6, 'type' => 'NS', 'name' => 'sub.ex.com', 'content' => 'ns1.sub.ex.com', 'prio' => null],
        ['id' => 7, 'type' => 'A', 'name' => 'ns1.sub.ex.com', 'content' => '185.10.20.40', 'prio' => null],
        ['id' => 8, 'type' => 'A', 'name' => 'a.blog2.ex.com', 'content' => '185.10.20.50', 'prio' => null],
    ];

    public static function candidates(): array
    {
        $r = fn (string $type, string $name, string $content, ?int $prio = null) => [['type' => $type, 'name' => $name, 'content' => $content, 'prio' => $prio]];

        return [
            'duplicate A'                 => $r('A', 'www.ex.com', '185.10.20.30'),
            'new A sibling'               => $r('A', 'www.ex.com', '185.10.20.32'),
            'CNAME beside A'              => $r('CNAME', 'www.ex.com', 'y.example.net.'),
            'A beside CNAME'              => $r('A', 'blog.ex.com', '185.10.20.33'),
            'same CNAME again'            => $r('CNAME', 'blog.ex.com', 'x.example.net.'),
            'TXT same wire'               => $r('TXT', 'ex.com', '"v=spf1 -all"'),
            'TXT other string split'      => $r('TXT', 'ex.com', '"v=spf1" " -all"'),
            'MX other prio'               => $r('MX', 'ex.com', 'mx1.ex.com.', 20),
            'MX same prio'                => $r('MX', 'ex.com', 'mx1.ex.com.', 10),
            'MX case-insensitive dup'     => $r('MX', 'ex.com', 'MX1.EX.COM.', 10),
            'below delegation'            => $r('A', 'x.sub.ex.com', '185.10.20.34'),
            'glue for delegation'         => $r('A', 'ns1.sub.ex.com', '185.10.20.41'),
            'TXT at the cut'              => $r('TXT', 'sub.ex.com', '"x"'),
            'second NS with glue below'   => $r('NS', 'sub.ex.com', 'ns2.sub.ex.com'),
            'NS over existing A'          => $r('NS', 'www.ex.com', 'ns.other.net'),
            'NS over records below'       => $r('NS', 'blog2.ex.com', 'ns.other.net'),
            'NS on a fresh name'          => $r('NS', 'fresh.ex.com', 'ns.other.net'),
            'NS below an existing cut'    => $r('NS', 'deep.sub.ex.com', 'ns.other.net'),
            'unrelated name'              => $r('AAAA', 'v6.ex.com', '2a01:4f8::1'),
        ];
    }

    #[DataProvider('candidates')]
    public function test_gives_exactly_the_same_answer_as_conflicts(array $candidate): void
    {
        $index = new RecordSetIndex(self::EXISTING);

        $this->assertSame(
            (new RecordValidator)->conflicts($candidate, self::EXISTING),
            $index->conflict($candidate),
        );
    }

    public function test_added_rows_take_part_in_later_checks(): void
    {
        $index = new RecordSetIndex([]);
        $a = ['type' => 'A', 'name' => 'n.ex.com', 'content' => '185.10.20.30', 'prio' => null];
        $this->assertNull($index->conflict($a));
        $index->add($a);

        $this->assertSame('duplicate', $index->conflict($a)['code']);
        $this->assertSame('cname_conflict', $index->conflict(['type' => 'CNAME', 'name' => 'n.ex.com', 'content' => 'x.net.', 'prio' => null])['code']);
        $index->add(['type' => 'A', 'name' => 'h.d.ex.com', 'content' => '185.10.20.31', 'prio' => null]);
        $this->assertSame('ns_occludes', $index->conflict(['type' => 'NS', 'name' => 'd.ex.com', 'content' => 'ns.x.net', 'prio' => null])['code'] ?? null);

        $index->add(['type' => 'NS', 'name' => 'cut.ex.com', 'content' => 'ns.x.net', 'prio' => null]);
        $this->assertSame('delegated_name', $index->conflict(['type' => 'TXT', 'name' => 'a.cut.ex.com', 'content' => '"t"', 'prio' => null])['code'] ?? null);
    }
}
