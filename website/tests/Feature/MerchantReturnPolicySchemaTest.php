<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Search Console (۱۶ سپتامبر ۲۰۲۶): هر ۶۹ آیتمِ Merchant listings هشدارِ
 * `Missing field "returnMethod"` داشتند. سیاستِ بازگشت دو جا ساخته می‌شد و
 * هیچ‌کدام آن فیلد را نداشت؛ حالا یک منبع است و هر دو مسیر از آن می‌خوانند.
 */
class MerchantReturnPolicySchemaTest extends TestCase
{
    use RefreshDatabase;

    /** همهٔ MerchantReturnPolicyهای JSON-LDِ یک صفحه، هر جا که لانه کرده باشند */
    private function policiesIn(string $html): array
    {
        preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', $html, $m);
        $found = [];
        $walk = function ($node) use (&$walk, &$found) {
            if (! is_array($node)) {
                return;
            }
            if (($node['@type'] ?? null) === 'MerchantReturnPolicy') {
                $found[] = $node;
            }
            foreach ($node as $v) {
                $walk($v);
            }
        };
        foreach ($m[1] as $json) {
            $walk(json_decode($json, true));
        }

        return $found;
    }

    private function assertComplete(array $policy): void
    {
        foreach (['applicableCountry', 'returnPolicyCategory', 'merchantReturnDays', 'returnMethod', 'returnFees'] as $k) {
            $this->assertArrayHasKey($k, $policy, "MerchantReturnPolicy بدونِ {$k}");
        }
        $this->assertContains($policy['returnMethod'], [
            'https://schema.org/ReturnByMail', 'https://schema.org/ReturnInStore', 'https://schema.org/ReturnAtKiosk',
        ]);
    }

    public function test_the_shared_offer_extras_carry_a_complete_policy(): void
    {
        $this->assertComplete(schema_offer_extras('IRR')['hasMerchantReturnPolicy']);
    }

    public function test_the_order_summary_page_policy_is_complete(): void
    {
        Product::create([
            'name' => 'پکیج rp-1', 'slug' => 'rp-1', 'group' => 'wordpress', 'category' => 'shared',
            'price' => 700000, 'price_eur' => 0, 'setup_fee' => 0, 'cycle' => 'monthly', 'tax_percent' => 10,
            'is_active' => true,
        ]);

        $policies = $this->policiesIn($this->get('/order/rp-1?qa=1')->assertOk()->getContent());

        $this->assertNotEmpty($policies, 'پکیجِ قابلِ بازگشت باید سیاستِ بازگشت داشته باشد');
        foreach ($policies as $p) {
            $this->assertComplete($p);
        }
    }
}
