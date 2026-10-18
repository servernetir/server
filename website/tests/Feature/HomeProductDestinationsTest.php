<?php

namespace Tests\Feature;

use App\Models\{Customer, CustomerProfile};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\{NavasanRates, ShahkarFixture};
use Tests\TestCase;

class HomeProductDestinationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pagecache.enabled' => false]);
        Http::preventStrayRequests();
        NavasanRates::cloudSnapshot();
    }

    private function customer(bool $shahkar): Customer
    {
        $customer = Customer::create(['email' => 'home-destinations@example.test', 'phone' => '09120000000',
            'password' => 'synthetic', 'status' => 'active']);
        CustomerProfile::create(['customer_id' => $customer->id, 'type' => 'individual', 'is_default' => true,
            'status' => 'verified', 'email' => $customer->email]);
        if ($shahkar) ShahkarFixture::verify($customer);

        return $customer;
    }

    /** Read the actual six service-card destinations, rather than header links. */
    private function destinations(string $prefix): array
    {
        $html = $this->get($prefix ?: '/')->assertOk()->getContent();
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try { $dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $xpath = new \DOMXPath($dom);
        $cards = $xpath->query('//section[@id="products"]//a[contains(concat(" ", normalize-space(@class), " "), " pcard-link ")]');
        $this->assertCount(6, $cards);

        return array_map(fn ($card) => $card->getAttribute('href'), iterator_to_array($cards));
    }

    private function assertEligibleDefaults(): void
    {
        foreach (['', '/en', '/tr'] as $prefix) {
            $hrefs = $this->destinations($prefix);
            $this->assertSame(url($prefix.'/cloud'), $hrefs[2]);
            $this->assertSame(url($prefix.'/dedicated/germany'), $hrefs[3]);
            $this->assertSame(url($prefix.'/hosting/linux'), $hrefs[0]);
            $this->assertSame(url($prefix.'/hosting/wordpress'), $hrefs[1]);
        }
        Http::assertNothingSent();
    }

    public function test_guest_default_cards_keep_vps_and_dedicated_in_their_own_eligible_categories(): void
    {
        $this->assertEligibleDefaults();
    }

    public function test_manual_profile_approval_does_not_enable_iran_or_change_the_dedicated_category(): void
    {
        $this->actingAs($this->customer(false), 'customer');
        $this->assertEligibleDefaults();
    }

    public function test_real_shahkar_keeps_the_configured_iran_destinations_in_every_language(): void
    {
        $this->actingAs($this->customer(true), 'customer');
        foreach (['', '/en', '/tr'] as $prefix) {
            $hrefs = $this->destinations($prefix);
            $this->assertSame(url($prefix.'/vps/iran'), $hrefs[2]);
            $this->assertSame(url($prefix.'/dedicated/iran'), $hrefs[3]);
        }
        Http::assertNothingSent();
    }

    public function test_permitted_country_hosting_builder_and_domain_links_are_preserved(): void
    {
        $products = config('servernet.products');
        $products[2]['link'] = ['catalog', ['category' => 'vps', 'slug' => 'germany']];
        $products[3]['link'] = ['catalog', ['category' => 'dedicated', 'slug' => 'finland']];
        $products[4]['link'] = ['tools', 'site-builder'];
        config(['servernet.products' => $products]);
        foreach (['', '/en', '/tr'] as $prefix) {
            $hrefs = $this->destinations($prefix);
            $this->assertSame(url($prefix.'/hosting/linux'), $hrefs[0]);
            $this->assertSame(url($prefix.'/hosting/wordpress'), $hrefs[1]);
            $this->assertSame(url($prefix.'/vps/germany'), $hrefs[2]);
            $this->assertSame(url($prefix.'/dedicated/finland'), $hrefs[3]);
            $this->assertSame(url($prefix.'/tools/site-builder'), $hrefs[4]);
            $this->assertSame(url($prefix.'/domain/popular-tlds'), $hrefs[5]);
        }
        Http::assertNothingSent();
    }

    public function test_a_restricted_hosting_card_falls_back_to_hosting_rather_than_virtual_servers(): void
    {
        $products = config('servernet.products');
        $products[0]['link'] = ['hosting', 'iran'];
        config(['servernet.products' => $products]);
        foreach (['', '/en', '/tr'] as $prefix) {
            $this->assertSame(url($prefix.'/hosting/linux'), $this->destinations($prefix)[0]);
        }
        Http::assertNothingSent();
    }
}
