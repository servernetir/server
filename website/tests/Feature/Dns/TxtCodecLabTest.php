<?php

namespace Tests\Feature\Dns;

use App\Services\Dns\TxtCodec;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * آنچه TxtCodec می‌سازد باید **عیناً** از APIِ PowerDNSِ واقعی برگردد — وگرنه
 * diffِ ناشر همیشه «تفاوت» می‌بیند و zone را بی‌پایان دوباره منتشر می‌کند.
 * فقط با DNS_LAB=1 و DNS_LAB_API_KEY.
 */
#[Group('pdns-lab')]
class TxtCodecLabTest extends TestCase
{
    private string $api = 'http://127.0.0.1:8081/api/v1/servers/localhost';

    private string $zone;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('DNS_LAB') !== '1' || ! getenv('DNS_LAB_API_KEY')) {
            $this->markTestSkipped('DNS_LAB=1 and DNS_LAB_API_KEY are required');
        }
        Http::allowStrayRequests();
        $this->zone = 'txtlab-'.bin2hex(random_bytes(4)).'.test.';
        $this->http()->post($this->api.'/zones', ['name' => $this->zone, 'kind' => 'Native', 'nameservers' => ['ns.lab.test.']])->throw();
    }

    protected function tearDown(): void
    {
        if (isset($this->zone)) {
            $this->http()->delete($this->api.'/zones/'.$this->zone);
        }
        parent::tearDown();
    }

    private function http()
    {
        return Http::withHeaders(['X-API-Key' => getenv('DNS_LAB_API_KEY')])->acceptJson()->timeout(10);
    }

    public function test_canonical_txt_round_trips_through_the_real_api(): void
    {
        $cases = [
            'spf'   => 'v=spf1 include:_spf.google.com ~all',
            'utf8'  => 'سلام دنیا ✓',
            'quote' => 'say "hi" there',
            'long'  => 'v=DKIM1; k=rsa; p='.str_repeat('Q', 600),
            'multi' => '"first part" "second part"',
        ];

        $rrsets = [];
        $expected = [];
        foreach ($cases as $label => $input) {
            $r = TxtCodec::fromUserInput($input);
            $this->assertTrue($r['ok'], $label);
            $expected[$label.'.'.$this->zone] = $r['value'];
            $rrsets[] = ['name' => $label.'.'.$this->zone, 'type' => 'TXT', 'ttl' => 300, 'changetype' => 'REPLACE',
                'records' => [['content' => $r['value'], 'disabled' => false]]];
        }
        $this->http()->patch($this->api.'/zones/'.$this->zone, ['rrsets' => $rrsets])->throw();

        $live = collect($this->http()->get($this->api.'/zones/'.$this->zone)->throw()->json('rrsets'))
            ->where('type', 'TXT')->mapWithKeys(fn ($s) => [$s['name'] => $s['records'][0]['content']]);

        foreach ($expected as $name => $value) {
            $this->assertSame($value, $live[$name] ?? null, "API must return exactly what we sent for $name");
        }
    }
}
