<?php

namespace Tests\Feature;

use App\Models\CloudImage;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Service;
use App\Services\Cloud\CloudManager;
use App\Services\Cloud\CloudProvider;
use App\Services\Cloud\CloudProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * جزئیاتِ واقعیِ خطای زیرساخت نباید سرِ راه گم شود.
 *
 * ═══ رخدادِ واقعی که این را لازم کرد (سرویس #74، شهریور ۱۴۰۵) ═══
 *
 * 🔴 مشتری VPSِ ساعتیِ Proxmox خرید؛ کلونِ قالب شکست خورد. درایور علتِ
 * واقعی را در `raw.detail` برگرداند (متنِ خودِ Proxmox)، ولی provisioner فقط
 * `message`ِ فارسیِ کلی («ساختِ ماشین از قالب انجام نشد») را در provision_error
 * نوشت و detail را **دور ریخت**. نتیجه: مدیر روی صفحهٔ تحویل‌ها نگاه می‌کرد و
 * «دقیق نمی‌فهمید چرا» — چون علت هیچ‌جا ثبت نشده بود.
 *
 * 🔴 بدتر: قرنطینهٔ خودکار روی همان message مچ می‌کند. درایوری که پیامش را
 * فارسی می‌پیچد (Proxmox) هرگز «permission/quota/balance» در message ندارد،
 * پس قاعدهٔ «یا حتماً تحویل شود یا اصلاً نفروش» برای آن زیرساخت مرده بود.
 */
class CloudFailureDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();   // اعلان/زیرساخت — هیچ تماسِ واقعی
    }

    /** درایورِ قلابی که مثل ProxmoxClient پیامِ کلی + detailِ خام می‌دهد. */
    private function bindFailingDriver(string $message, string $detail): void
    {
        $fake = new class($message, $detail) implements CloudProvider
        {
            public function __construct(private string $msg, private string $detail)
            {
            }

            public function slug(): string
            {
                return 'proxmox';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function testConnection(): array
            {
                return ['ok' => true, 'message' => ''];
            }

            public function fetchCatalog(): array
            {
                return ['ok' => true, 'message' => '', 'locations' => [], 'plans' => [], 'images' => []];
            }

            public function createServer(array $spec): array
            {
                return [
                    'ok' => false, 'message' => $this->msg,
                    'raw' => ['detail' => $this->detail],
                    'ref' => null, 'ipv4' => null, 'ipv6' => null,
                    'root_password' => null, 'status' => 'error',
                ];
            }

            public function serverStatus(string $ref): array
            {
                return ['ok' => false, 'message' => '', 'status' => 'unknown', 'ipv4' => null, 'ipv6' => null, 'traffic_used_gb' => null];
            }

            public function listServers(): array
            {
                return ['ok' => true, 'message' => '', 'servers' => []];
            }

            public function power(string $ref, string $action): array
            {
                return ['ok' => false, 'message' => ''];
            }

            public function rebuild(string $ref, string $imageRef, ?string $password = null): array
            {
                return ['ok' => false, 'message' => '', 'root_password' => null];
            }

            public function resetPassword(string $ref): array
            {
                return ['ok' => false, 'message' => '', 'root_password' => null];
            }

            public function console(string $ref): array
            {
                return ['ok' => false, 'message' => '', 'url' => null, 'password' => null];
            }

            public function metrics(string $ref, string $window = '24h'): array
            {
                return ['ok' => false, 'message' => '', 'series' => []];
            }

            public function deleteServer(string $ref): array
            {
                return ['ok' => true, 'message' => ''];
            }

            public function resize(string $ref, string $planRef, bool $upgradeDisk = true): array
            {
                return ['ok' => false, 'message' => ''];
            }

            public function uploadSshKey(string $name, string $publicKey): array
            {
                return ['ok' => false, 'message' => '', 'ref' => null];
            }

            public function addExtraIps(string $ref, int $count): array
            {
                return ['ok' => false, 'message' => '', 'ips' => []];
            }

            public function capabilities(): array
            {
                return ['ssh_key' => false];
            }
        };

        $this->partialMock(CloudManager::class,
            fn ($m) => $m->shouldReceive('forPlan')->andReturn($fake));
    }

    /** @return array{0: Service, 1: CloudPlan} سفارشِ پرداخت‌شدهٔ آمادهٔ تحویل */
    private function paidOrder(): array
    {
        $plan = CloudPlan::create([
            'provider' => 'proxmox', 'provider_ref' => 'vps-2-2',
            'provider_location' => 'ir', 'location_code' => 'ir-tabriz',
            'public_name' => 'CV-2-2', 'slug' => 'cv-2c-2g-30d-ir-'.random_int(1, 99999),
            'vcpu' => 2, 'ram_mb' => 2048, 'disk_gb' => 30, 'disk_type' => 'ssd',
            'traffic_gb' => 1024, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 200, 'price_eur_cents' => 300, 'price_irt' => 300000,
            'is_active' => true, 'in_stock' => true,
        ]);

        CloudImage::create([
            'provider' => 'proxmox', 'provider_ref' => '9002', 'key' => 'ubuntu-24.04',
            'kind' => 'os', 'family' => 'ubuntu', 'version' => '24.04',
            'label' => 'Ubuntu 24.04', 'arch' => 'x86', 'min_disk_gb' => 10, 'is_active' => true,
        ]);

        $c = Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'cd'.random_int(1, 99999).'@example.com',
            'password' => bcrypt('secret-pass-123'), 'status' => 'active',
        ]);

        $s = Service::create([
            'customer_id' => $c->id, 'name' => 'سرور مجازی تستی', 'currency_code' => 'IRT',
            'price' => 300000, 'cycle' => 'monthly', 'status' => 'awaiting_provision',
            'provision_status' => 'pending', 'cloud_plan_id' => $plan->id,
            'cloud_image_key' => 'ubuntu-24.04',
        ]);

        return [$s, $plan];
    }

    /** 🔴 detailِ زیرساخت باید به provision_error برسد — وگرنه مدیر کور است. */
    public function test_the_infrastructure_detail_survives_into_provision_error(): void
    {
        [$s] = $this->paidOrder();
        $this->bindFailingDriver('ساختِ ماشین از قالب انجام نشد.',
            "clone failed: storage 'vmstoreid' does not exist on node 'ir'");

        $ok = app(CloudProvisioner::class)->provision($s);

        $this->assertFalse($ok);
        $fresh = $s->fresh();
        $this->assertSame('failed', $fresh->provision_status);
        $this->assertStringContainsString('ساختِ ماشین از قالب', (string) $fresh->provision_error);
        $this->assertStringContainsString('vmstoreid', (string) $fresh->provision_error,
            'علتِ واقعیِ زیرساخت (raw.detail) در provision_error نیست — مدیر هرگز نمی‌فهمد چرا');
    }

    /**
     * 🔴 قرنطینهٔ خودکار باید detail را هم ببیند: خطای permission که در
     * message فارسی پیچیده شده، بدونِ این، هرگز فروش را نمی‌بست و مشتریِ
     * بعدی همان شکست را می‌خرید.
     */
    public function test_a_permission_detail_quarantines_the_provider(): void
    {
        [$s, $plan] = $this->paidOrder();
        $this->bindFailingDriver('ساختِ ماشین از قالب انجام نشد.',
            "You don't have enough permissions for this action");

        app(CloudProvisioner::class)->provision($s);

        $this->assertTrue((bool) $plan->fresh()->admin_disabled,
            'خطای ساختاریِ داخلِ detail قرنطینه نکرد — پلنِ خراب در فروش ماند');
    }
}
