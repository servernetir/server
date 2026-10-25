<?php

namespace Tests\Feature;

use App\Models\CrmLead;
use App\Models\CrmMessage;
use App\Services\ExchangeRate;
use App\Services\TransactionalEmailQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransactionalEmailTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return ['name' => 'Test Buyer', 'company' => 'Test Factory', 'email' => 'buyer@example.test',
            'phone' => '+989121234567', 'domain' => 'example.test', 'volume' => 50000,
            'message' => 'ERP invoices and approval notifications', 'request_id' => (string) Str::uuid(), 'consent' => '1'];
    }

    public function test_three_languages_have_real_content_canonical_and_matching_faq_schema(): void
    {
        Http::preventStrayRequests();
        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            $html = $this->get($prefix.'/transactional-email?qa=1')->assertOk()->getContent();
            $this->assertSame(1, substr_count($html, '<h1>'));
            $this->assertStringContainsString('href="http://localhost'.$prefix.'/transactional-email"', $html);
            $this->assertStringContainsString('hreflang="'.$locale.'"', $html);
            $this->assertStringNotContainsString('noindex', $html);
            $this->assertStringContainsString('name="request_id"', $html);
            $this->assertStringNotContainsString('99%+', $html);
            preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', $html, $matches);
            $schemas = array_map(fn ($s) => json_decode($s, true, flags: JSON_THROW_ON_ERROR), $matches[1]);
            $faq = collect($schemas)->firstWhere('@type', 'FAQPage');
            $this->assertCount(8, $faq['mainEntity']);
            foreach ($faq['mainEntity'] as $question) {
                $this->assertStringContainsString(e($question['name']), $html);
                $this->assertStringContainsString(e($question['acceptedAnswer']['text']), $html);
            }
        }
        Http::assertNothingSent();
    }

    public function test_estimate_uses_valid_cached_fx_and_integer_rounding(): void
    {
        $fx = \Mockery::mock(ExchangeRate::class);
        $fx->shouldReceive('toToman')->with('USD')->andReturn(165000);
        $fx->shouldReceive('toToman')->with('EUR')->andReturn(180000);
        app()->instance(ExchangeRate::class, $fx);
        $fa = $this->get('/transactional-email')->assertOk();
        $fa->assertSee('۸۰۹,۰۰۰', false);
        $en = $this->get('/en/transactional-email')->assertOk();
        $en->assertSee('4.50', false);
        $quote = app(TransactionalEmailQuote::class);
        foreach ([1000 => 490, 10000 => 490, 10001 => 1490, 50000 => 1490, 50001 => 2490, 100000 => 2490, 100001 => 2515, 250000 => 6240, 1000000 => 24990] as $volume => $cents) {
            $this->assertSame($cents, $quote->usdCents($volume));
        }
        $this->assertSame(809000, $quote->displayAmount(490, 'IRT', 165000, 180000));
        $this->assertSame(450, $quote->displayAmount(490, 'EUR', 165000, 180000));
    }

    public function test_cold_fx_shows_usd_instead_of_free_or_invented_toman_price(): void
    {
        $fx = \Mockery::mock(ExchangeRate::class);
        $fx->shouldReceive('toToman')->andReturn(null);
        app()->instance(ExchangeRate::class, $fx);
        $this->get('/transactional-email')->assertOk()->assertSee('4.90')->assertSee(__('transactional-email.fx_fallback'));
    }

    public function test_request_is_saved_once_as_inbound_without_outreach_or_payment(): void
    {
        Mail::fake();
        Http::preventStrayRequests();
        $payload = $this->payload();
        foreach ([1, 2] as $attempt) {
            $this->post('/en/transactional-email/quote', $payload)
                ->assertRedirect('http://localhost/en/transactional-email#request')
                ->assertSessionHas('email_quote_reference')
                ->assertSessionHas('email_quote_created', $attempt === 1);
        }
        $this->assertDatabaseCount('crm_leads', 1);
        $this->assertDatabaseCount('crm_messages', 1);
        $lead = CrmLead::firstOrFail();
        $this->assertSame('replied', $lead->stage);
        $this->assertNull($lead->observation);
        $this->assertNull($lead->next_action_at);
        $this->assertDatabaseHas('crm_messages', ['lead_id' => $lead->id, 'direction' => 'in', 'status' => 'received', 'channel' => 'web']);
        $this->assertStringContainsString('Monthly recipients: 50000', CrmMessage::first()->body);
        $this->assertDatabaseCount('customers', 0);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_independent_contacts_and_new_requests_do_not_overwrite_existing_leads(): void
    {
        $existing = CrmLead::create(['domain_hash' => CrmLead::hashFor('example.test'), 'company' => 'Existing lead', 'stage' => 'won']);
        $one = $this->payload();
        $this->post('/transactional-email/quote', $one)->assertRedirect();
        $two = $one; $two['request_id'] = (string) Str::uuid(); $two['message'] = 'New requirement';
        $this->post('/transactional-email/quote', $two)->assertRedirect();
        $three = $this->payload(); $three['email'] = 'another@example.test';
        $this->post('/transactional-email/quote', $three)->assertRedirect();
        $this->assertDatabaseCount('crm_leads', 3);
        $this->assertDatabaseCount('crm_messages', 3);
        $this->assertSame('Existing lead', $existing->fresh()->company);
        $this->assertSame('won', $existing->fresh()->stage);
    }

    public function test_invalid_bot_and_unconsented_requests_do_not_create_leads(): void
    {
        foreach ([['consent' => '0'], ['website_url' => 'https://spam.example'], ['email' => 'invalid'], ['volume' => 1000001], ['domain' => 'https://example.test/path']] as $invalid) {
            $this->from('/transactional-email')->post('/transactional-email/quote', array_replace($this->payload(), $invalid))->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('crm_leads', 0);
        $this->assertDatabaseCount('crm_messages', 0);
    }

    public function test_redirects_discovery_and_internal_links_use_the_dedicated_product(): void
    {
        foreach (['' , '/en', '/tr'] as $prefix) {
            $this->get($prefix.'/solutions/email')->assertStatus(301)->assertRedirect('http://localhost'.$prefix.'/transactional-email');
            foreach (['', '/solutions', '/hosting/email'] as $source) {
                $html = $this->get($prefix.$source)->assertOk()->getContent();
                $this->assertStringContainsString('href="http://localhost'.$prefix.'/transactional-email"', $html);
            }
        }
        $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();
        foreach (['', '/en', '/tr'] as $prefix) $this->assertStringContainsString('<loc>http://localhost'.$prefix.'/transactional-email</loc>', $sitemap);
        $this->assertStringNotContainsString('/solutions/email</loc>', $sitemap);
        $this->get('/llms.txt')->assertOk()->assertSee('/transactional-email');
    }

    public function test_real_receipt_markup_distinguishes_a_new_request_from_a_retry(): void
    {
        $payload = $this->payload();
        $this->post('/transactional-email/quote', $payload)->assertRedirect();
        $this->get('/transactional-email')->assertOk()->assertSee('data-email-lead-created="1"', false);
        $this->post('/transactional-email/quote', $payload)->assertRedirect();
        $this->get('/transactional-email')->assertOk()->assertSee('data-email-lead-created="0"', false);
        $this->assertDatabaseCount('crm_messages', 1);
    }
}
