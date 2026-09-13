<?php

namespace Tests\Unit;

use App\Support\HtmlSeoInspector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HtmlSeoInspectorTest extends TestCase
{
    #[DataProvider('markupExamples')]
    public function test_it_counts_only_rendered_images(string $html, array $expected): void
    {
        $this->assertSame($expected, HtmlSeoInspector::imageAltCounts($html));
    }

    public static function markupExamples(): array
    {
        return [
            'rendered images' => [
                '<img src="a.jpg" alt="A"><IMG src="b.jpg" ALT = "">',
                ['total' => 2, 'without_alt' => 0],
            ],
            'missing alt' => [
                '<img src="a.jpg"><img src="b.jpg" title="alt= is not an attribute">',
                ['total' => 2, 'without_alt' => 2],
            ],
            'non-rendered examples' => [
                '<script>const example = "<img src=x>";</script>'
                .'<!-- <img src="comment.jpg"> -->'
                .'<template><img src="future.jpg"></template>'
                .'<img src="real.jpg" alt="Real">',
                ['total' => 1, 'without_alt' => 0],
            ],
        ];
    }
}
