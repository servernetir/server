<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConsoleWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_navigation_is_simplified_and_security_lives_inside_profile_in_every_locale(): void
    {
        Http::fake();
        $c = Customer::create(['email'=>'workspace@example.test','status'=>'active','locale'=>'fa']);
        foreach ([''=>'fa','/en'=>'en','/tr'=>'tr'] as $prefix=>$locale) {
            $r = $this->actingAs($c,'customer')->get($prefix.'/account/profile')->assertOk();
            $dom = new \DOMDocument; @$dom->loadHTML('<?xml encoding="UTF-8">'.$r->getContent()); $x = new \DOMXPath($dom);
            foreach (['store','services','security'] as $path) {
                $this->assertSame(0,$x->query('//aside[@id="pnl-side"]//nav//a[contains(@href,"/account/'.$path.'")]')->length);
            }
            $brand = $x->query('//*[@id="header"]//a[contains(@class,"logo")]')->item(0)->getAttribute('href');
            $this->assertSame('https://servernet.cloud'.($prefix ?: ''), $brand);
            $this->assertSame(1,$x->query('//body[contains(@class,"sn-console")]')->length);
            $this->assertStringContainsString('tab=security',$r->getContent());
            $security = $this->get($prefix.'/account/profile?tab=security')->assertOk();
            $security->assertSee(trans('workspace.security_tab',[],$locale))->assertSee('sec-2fa');
            $this->assertStringNotContainsString('workspace.profile_title',$security->getContent());
        }
        $this->get('/account/security')->assertOk(); // Existing deep links and form actions remain valid.
    }

    public function test_profile_security_tab_still_requires_customer_authentication(): void
    {
        $this->get('/account/profile?tab=security')->assertRedirect(route('login'));
    }
}
