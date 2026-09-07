<?php

namespace App\Support;

final class HtmlSeoInspector
{
    /**
     * Count rendered image elements and those without an alt attribute.
     *
     * Script, template and comment contents are not rendered markup. Counting
     * an example such as "<img>" inside JavaScript as a page image creates a
     * false release failure and can hide the real image-SEO signal.
     *
     * @return array{total: int, without_alt: int}
     */
    public static function imageAltCounts(string $html): array
    {
        $rendered = preg_replace('~<template\b[^>]*>.*?</template\s*>~is', '', $html) ?? $html;
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div>'.$rendered.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $total = 0;
        $withoutAlt = 0;

        foreach ($dom->getElementsByTagName('img') as $image) {
            $total++;
            $withoutAlt += $image->hasAttribute('alt') ? 0 : 1;
        }

        return [
            'total' => $total,
            'without_alt' => $withoutAlt,
        ];
    }
}
