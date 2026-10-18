<?php

namespace Tests\Feature;

use Tests\TestCase;

class AtlasPublicPagesTest extends TestCase
{
    public function test_home_and_contact_render_in_all_locales_without_breaking_forms(): void
    {
        config()->set('services.gtm.enabled', false);
        foreach (['' => 'fa', '/en' => 'en', '/tr' => 'tr'] as $prefix => $locale) {
            $home = $this->get($prefix ?: '/')->assertOk()->getContent();
            $this->assertStringContainsString('assets/js/atlas.min.js', $home);
            $this->assertStringContainsString('id="sn-atlas-css"', $home);
            $this->assertStringContainsString('atlas-earth.svg', $home);
            $this->assertStringContainsString('id="domain-form"', $home);
            $this->assertStringContainsString('name="q"', $home);
            $this->assertStringContainsString('action="'.lroute('domain.search').'" method="get"', $home);
            $this->assertStringNotContainsString('data-sn-region="IR"', $home);
            $this->assertStringContainsString($prefix.'/cloud', $home);
            $this->assertStringNotContainsString('>atlas.', $home);
            $this->assertStringContainsString('href="'.lroute('ai.home').'"', $home);
            $this->assertSeoIdentity($home, lroute('home'));
            $contact = $this->get($prefix.'/contact')->assertOk()->getContent();
            $this->assertStringContainsString('data-wireframe="true"', $contact);
            $this->assertStringContainsString('atlas-earth-wire.svg', $contact);
            $this->assertStringNotContainsString('<canvas', $contact);
            $this->assertStringContainsString('id="ct-form"', $contact);
            $this->assertStringContainsString('id="ct-open-chat"', $contact);
            foreach (['name', 'phone', 'email', 'message'] as $field) {
                $this->assertStringContainsString('name="'.$field.'"', $contact);
            }
            $this->assertStringNotContainsString('>atlas.', $contact);
            $this->assertSeoIdentity($contact, lroute('contact'));
            // Optional, isolated static fixtures for browser QA; never contains a real app key.
            if ($directory = getenv('ATLAS_PREVIEW_DIR')) {
                $folder = $directory.($prefix ?: '');
                @mkdir($folder, 0777, true);
                file_put_contents($folder.'/index.html', $this->preview($home));
                @mkdir($folder.'/contact', 0777, true);
                file_put_contents($folder.'/contact/index.html', $this->preview($contact));
            }
        }
    }

    private function assertSeoIdentity(string $html, string $canonical): void
    {
        $this->assertSame(1, preg_match_all('/<h1(?:\s|>)/i', $html));
        $this->assertStringContainsString('rel="canonical" href="'.$canonical.'"', $html);
        $this->assertStringNotContainsString('content="noindex', $html);
        foreach (['fa', 'en', 'tr', 'x-default'] as $language) {
            $this->assertStringContainsString('hreflang="'.$language.'"', $html);
        }
        preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', $html, $schemas);
        $this->assertNotEmpty($schemas[1]);
        foreach ($schemas[1] as $schema) {
            $this->assertIsArray(json_decode($schema, true, 512, JSON_THROW_ON_ERROR));
        }
    }

    private function preview(string $html): string
    {
        // Preview assets and navigation remain local. Submission is disabled only in fixtures.
        $html = str_replace('http://localhost', 'http://127.0.0.1:8765', $html);
        $html = preg_replace('/(<meta name="csrf-token" content=")[^"]*/', '$1preview-only', $html);
        return str_replace('</body>', '<script src="/preview-only.js"></script></body>', $html);
    }
}
