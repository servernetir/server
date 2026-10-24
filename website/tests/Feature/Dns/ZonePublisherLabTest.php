<?php

namespace Tests\Feature\Dns;

use App\Models\CdnZone;
use App\Models\Customer;
use App\Models\Setting;
use App\Services\Dns\NodeVerifier;
use App\Services\Dns\ZonePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * انتشارِ واقعی: کدِ برنامه → APIِ primaryِ PowerDNS → catalog + AXFRِ امضاشده →
 * دو secondary؛ و سنجشِ مستقیمِ هر دو با NodeVerifier.
 *
 * فقط با DNS_LAB=1، DNS_LAB_IP (نشانیِ WSL) و DNS_LAB_API_KEY.
 * reconcilerِ TSIG در آزمایشگاه timer ندارد، پس تست خودش اجرایش می‌کند.
 */
#[Group('pdns-lab')]
class ZonePublisherLabTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('DNS_LAB') !== '1' || ! getenv('DNS_LAB_IP') || ! getenv('DNS_LAB_API_KEY')) {
            $this->markTestSkipped('DNS_LAB=1, DNS_LAB_IP and DNS_LAB_API_KEY are required');
        }
        Http::allowStrayRequests();
        config(['edge.dns.allow_insecure_api' => true]);
        $ip = getenv('DNS_LAB_IP');
        Setting::put('pdns_api_url', 'http://127.0.0.1:8081');
        Setting::putSecret('pdns_api_key', getenv('DNS_LAB_API_KEY'));
        Setting::put('dns_catalog_zone', 'catalog.invalid');
        Setting::put('dns_xfr_tsig_key', 'xfr-lab');
        Setting::put('dns_nameservers', json_encode([
            ['host' => 'ns-a.lab.test', 'ipv4' => $ip, 'port' => 5301],
            ['host' => 'ns-b.lab.test', 'ipv4' => $ip, 'port' => 5302],
        ]));
    }

    private function reconcileSecondaries(): void
    {
        shell_exec('wsl -d Ubuntu -u root -- bash -c "for n in 1 2; do PDNS_SQLITE_DB=/root/pdns-lab/db/sec\$n.sqlite3 PDNS_XFR_TSIG_KEY=xfr-lab PDNS_CONFIG_DIR=/root/pdns-lab/conf PDNS_CONFIG_NAME=sec\$n PDNS_SOCKET_DIR=/root/pdns-lab/run/sec\$n /root/tsigfix.sh; done"');
    }

    private function waitInSync(CdnZone $z, int $seconds = 60): array
    {
        $end = microtime(true) + $seconds;
        do {
            $this->reconcileSecondaries();
            $s = app(NodeVerifier::class)->check($z->fresh());
            if (collect($s)->every(fn ($x) => $x['state'] === 'in_sync')) {
                return $s;
            }
            usleep(1_000_000);
        } while (microtime(true) < $end);

        return $s;
    }

    private function dig(int $port, string $name, string $type): string
    {
        return trim((string) shell_exec("wsl -d Ubuntu -u root -- dig +short +norec @127.0.0.1 -p $port $name $type"));
    }

    public function test_publish_update_delete_on_real_powerdns(): void
    {
        $c = Customer::create(['email' => 'lab'.random_int(1, 1e9).'@x.test', 'phone' => '0912'.random_int(1000000, 9999999), 'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa']);
        $domain = 'lab-'.bin2hex(random_bytes(3)).'.test';
        $z = CdnZone::create(['customer_id' => $c->id, 'domain' => $domain, 'mode' => 'ns', 'status' => 'pending', 'verify_token' => 'x', 'ownership' => 'txt']);
        foreach (['185.10.20.30', '185.10.20.31'] as $a) {
            $z->records()->create(['type' => 'A', 'name' => 'www.'.$domain, 'content' => $a, 'ttl' => 300]);
        }
        $z->records()->create(['type' => 'TXT', 'name' => $domain, 'content' => '"v=spf1 -all" "سلام"', 'ttl' => 300]);
        $z->records()->create(['type' => 'MX', 'name' => $domain, 'content' => 'mail.'.$domain, 'prio' => 10, 'ttl' => 300]);
        $pub = app(ZonePublisher::class);

        $r = $pub->publish($z);
        $this->assertTrue($r->ok, (string) $r->error);
        $states = $this->waitInSync($z);
        $this->assertSame(['in_sync', 'in_sync'], array_values(array_column($states, 'state')), json_encode($states));
        $this->assertTrue($z->fresh()->isPublishedEverywhere());

        $this->assertSame('unchanged', $pub->publish($z->fresh())->code, 'no phantom drift on real PowerDNS');

        // حذفِ یک عضوِ RRset — عضوِ دیگر می‌مانَد، روی هر دو نود
        $z->records()->where('content', '185.10.20.31')->delete();
        $z->bumpVersion();
        $this->assertSame(1, $pub->publish($z->fresh())->changes);
        $this->waitInSync($z);
        $this->assertSame('185.10.20.30', $this->dig(5301, 'www.'.$domain, 'A'));
        $this->assertSame('185.10.20.30', $this->dig(5302, 'www.'.$domain, 'A'));
        $this->assertSame('10 mail.'.$domain.'.', $this->dig(5302, $domain, 'MX'));

        // A → CNAME و برعکس روی همان نام: پیش‌تر REPLACE پیش از DELETE بود و PowerDNS با ۴۲۲
        // («Conflicts with pre-existing RRset») رد می‌کرد — انتشارِ zone برای همیشه گیر می‌کرد
        $z->records()->where('name', 'www.'.$domain)->delete();
        $z->records()->create(['type' => 'CNAME', 'name' => 'www.'.$domain, 'content' => 'edge.example.net', 'ttl' => 300]);
        $z->bumpVersion();
        $r = $pub->publish($z->fresh());
        $this->assertTrue($r->ok, 'A→CNAME: '.$r->error);
        $this->waitInSync($z);
        $this->assertSame('edge.example.net.', $this->dig(5301, 'www.'.$domain, 'CNAME'));

        $z->records()->where('name', 'www.'.$domain)->delete();
        $z->records()->create(['type' => 'A', 'name' => 'www.'.$domain, 'content' => '185.10.20.32', 'ttl' => 300]);
        $z->bumpVersion();
        $r = $pub->publish($z->fresh());
        $this->assertTrue($r->ok, 'CNAME→A: '.$r->error);
        $this->waitInSync($z);
        $this->assertSame('185.10.20.32', $this->dig(5302, 'www.'.$domain, 'A'));

        // حذفِ zone
        $z = $z->fresh();
        $z->status = 'deleting';
        $z->save();
        $z->bumpVersion();
        $end = microtime(true) + 90;
        do {
            CdnZone::whereKey($z->id)->update(['next_publish_at' => null]);
            $r = $pub->publish(CdnZone::find($z->id) ?? $z);
            if (CdnZone::find($z->id) === null) {
                break;
            }
            usleep(2_000_000);
        } while (microtime(true) < $end);

        $this->assertNull(CdnZone::find($z->id), 'zone row removed only after nodes stopped answering');
        $this->assertSame('', $this->dig(5301, 'www.'.$domain, 'A'));
    }
}
