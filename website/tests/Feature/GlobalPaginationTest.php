<?php

namespace Tests\Feature;

use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

/** The shared paginator must not depend on absent Tailwind utility classes. */
class GlobalPaginationTest extends TestCase
{
    private function paginator(int $page = 1): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            range(1, 30),
            460,
            30,
            $page,
            ['path' => 'https://example.test/admin/customers']
        );
    }

    public function test_the_global_paginator_has_fixed_controls_and_no_arrow_svg(): void
    {
        app()->setLocale('fa');

        $html = $this->paginator()->onEachSide(1)->links()->toHtml();

        $this->assertStringContainsString('class="sn-pagination"', $html);
        $this->assertStringContainsString('نمایش ۱ تا ۳۰ از ۴۶۰ نتیجه', $html);
        $this->assertStringContainsString('قبلی', $html);
        $this->assertStringContainsString('بعدی', $html);
        $this->assertStringNotContainsString('<svg', $html);
        $this->assertStringNotContainsString('Showing ', $html);
    }

    public function test_the_global_paginator_keeps_page_urls_and_localizes_english(): void
    {
        app()->setLocale('en');

        $html = $this->paginator(2)->onEachSide(1)->links()->toHtml();

        $this->assertStringContainsString('Showing 31 to 60 of 460 results', $html);
        $this->assertStringContainsString('href="https://example.test/admin/customers?page=1"', $html);
        $this->assertStringContainsString('href="https://example.test/admin/customers?page=3"', $html);
        $this->assertStringContainsString('aria-current="page">2</span>', $html);
    }
}
