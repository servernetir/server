<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AtlasExpansionTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_analytics_can_use_the_existing_worker_while_authentication_keeps_its_layout(): void
    {
        config()->set('services.gtm.enabled', true);
        config()->set('services.gtm.id', 'GTM-TEST123');
        foreach (['/hosting/linux','/gpu','/vps/iran','/en/hosting/linux','/tr/gpu'] as $path) {
            $this->get($path)->assertOk()->assertSee('var atlasMarketingPage=true;', false)
                ->assertSee('snet-analytics-worker-0.15.0-snet3', false);
        }
        $this->get('/login')->assertOk()->assertDontSee('id="sn-public-css"', false)
            ->assertDontSee('var atlasMarketingPage=true;', false);
    }

    public function test_public_product_content_and_localized_identity_survive_the_shared_presentation(): void
    {
        config()->set('services.gtm.enabled', false);
        foreach ([''=>'fa','/en'=>'en','/tr'=>'tr'] as $prefix=>$locale) {
            foreach (['/','/contact','/hosting/linux','/hosting/wordpress','/vps/iran','/vps/germany','/gpu','/cloud','/vps/hourly','/about','/solutions','/privacy'] as $path) {
                $response=$this->get($prefix.($path==='/' ? '' : $path) ?: '/')->assertOk();
                $html=$response->getContent();
                $this->assertSame(1,preg_match_all('/<h1(?:\s|>)/i',$html));
                $this->assertStringContainsString('id="sn-public-css"',$html);
                $this->assertStringContainsString('hreflang="'.$locale.'"',$html);
                preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s',$html,$schemas);
                foreach($schemas[1] as $schema)$this->assertIsArray(json_decode($schema,true,512,JSON_THROW_ON_ERROR));
                if(in_array($path,['/hosting/linux','/hosting/wordpress','/vps/iran','/vps/germany','/gpu','/cloud','/vps/hourly']))$this->assertStringContainsString('class="sn-product-stage',$html);
                if($path==='/'){
                    $default=match($locale){'en'=>'US','tr'=>'TR',default=>'IR'};
                    $this->assertStringContainsString('data-sn-selected="'.$default.'"',$html);
                    $this->assertStringContainsString('id="domain-form"',$html);
                    $this->assertStringContainsString('name="q"',$html);
                }
                if($directory=getenv('ATLAS_EXPANSION_PREVIEW')){
                    $folder=$directory.$prefix.($path==='/'?'':$path);@mkdir($folder,0777,true);
                    $html=str_replace('http://localhost','http://127.0.0.1:8765',$html);
                    $html=preg_replace('/(<meta name="csrf-token" content=")[^"]*/','$1preview-only',$html);
                    file_put_contents($folder.'/index.html',str_replace('</body>','<script src="/preview-only.js"></script></body>',$html));
                }
            }
        }
    }
}
