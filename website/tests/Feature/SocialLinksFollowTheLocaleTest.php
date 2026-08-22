<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * لینکِ اینستاگرام باید به صفحهٔ **همان زبان** برود.
 *
 * ═══ نقصی که این را لازم کرد ═══
 *
 * سه صفحهٔ اینستاگرام داریم و هر کدام به زبانِ خودش می‌نویسد، ولی هر سه نسخهٔ
 * سایت به صفحهٔ فارسی لینک می‌دادند. بازدیدکنندهٔ ترک یا انگلیسی روی صفحه‌ای
 * می‌افتاد که یک کلمه‌اش را نمی‌فهمد.
 *
 * 🔴 و این از **نداشتنِ** لینک بدتر است: کاربر کلیک کرده، به جایی بی‌ربط رسیده
 * و برنمی‌گردد. هیچ خطایی هم ساخته نمی‌شود — لینک سالم است، فقط اشتباه.
 */
class SocialLinksFollowTheLocaleTest extends TestCase
{
    /** نشانیِ اینستاگرامِ رندرشده در فوترِ یک زبان. */
    private function instagramOn(string $path): string
    {
        $html = $this->get($path)->assertOk()->getContent();

        preg_match('~href="(https://www\.instagram\.com/[^"]+)"~', $html, $m);

        return $m[1] ?? '';
    }

    /** 🔴 هر زبان، حسابِ خودش. */
    public function test_each_locale_links_to_its_own_account(): void
    {
        $this->assertStringContainsString('servernet.ir', $this->instagramOn('/'));
        $this->assertStringContainsString('servernet.cloud', $this->instagramOn('/en'));
        $this->assertStringContainsString('servernet.tr', $this->instagramOn('/tr'));
    }

    /** و همین روی صفحهٔ تماس، که کارفرما مشخصاً گفت. */
    public function test_the_contact_page_follows_the_locale_too(): void
    {
        $this->assertStringContainsString('servernet.ir', $this->instagramOn('/contact'));
        $this->assertStringContainsString('servernet.cloud', $this->instagramOn('/en/contact'));
        $this->assertStringContainsString('servernet.tr', $this->instagramOn('/tr/contact'));
    }

    /**
     * 🔴 `sameAs` باید **همهٔ** حساب‌ها را بدهد، نه فقط حسابِ همین زبان.
     *
     * سؤالِ `sameAs` «کاربر کجا برود؟» نیست، «این سازمان کدام حساب‌ها را
     * دارد؟» است. فهرستِ کامل همان چیزی است که به گوگل می‌فهماند این سه حساب
     * یک شرکت‌اند، نه سه شرکت.
     */
    public function test_the_structured_data_lists_every_profile(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        preg_match('~<script type="application/ld\+json">(\{"@context".+?)</script>~s', $html, $m);
        $org = json_decode($m[1] ?? '{}', true);

        $same = implode(' ', (array) ($org['sameAs'] ?? []));

        foreach (['servernet.ir', 'servernet.cloud', 'servernet.tr', 'linkedin.com'] as $needle) {
            $this->assertStringContainsString($needle, $same, "«{$needle}» در sameAs نیست");
        }
    }

    /**
     * ⚠️ نبودِ نسخهٔ زبانی نباید لینک را خالی کند.
     *
     * جای خالی روی صفحه از لینکِ زبانِ اشتباه بدتر است: آیکنِ بی‌مقصد.
     */
    public function test_a_missing_locale_account_falls_back_instead_of_breaking(): void
    {
        config(['servernet.social.instagram_tr' => '']);

        $this->assertStringContainsString('instagram.com', $this->instagramOn('/tr'));
    }
}
