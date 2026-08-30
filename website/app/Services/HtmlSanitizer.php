<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * پاک‌سازی HTML محتوای مقاله‌ها بر پایه‌ی فهرست مجاز.
 *
 * چرا لازم است: بدنه‌ی مقاله با {!! !!} خام رندر می‌شود. دو منبع محتوا داریم که
 * هیچ‌کدام کاملاً قابل‌اعتماد نیستند:
 *   ۱) ویرایشگر پنل — کاربر با نقش «نویسنده» می‌تواند HTML دلخواه بچسباند
 *   ۲) خروجی هوش مصنوعی — با تزریق پرامپت می‌تواند <script> تولید کند
 * بدون پاک‌سازی، هر دو به XSS ذخیره‌شده روی سایت عمومی تبدیل می‌شوند.
 *
 * رویکرد: فهرست مجاز (allowlist). هر تگ، صفت یا طرح URL که صراحتاً مجاز نباشد حذف می‌شود.
 */
class HtmlSanitizer
{
    /** تگ‌هایی که در مقاله‌ها واقعاً استفاده می‌کنیم */
    private const TAGS = [
        'p', 'br', 'hr', 'h2', 'h3', 'h4', 'strong', 'b', 'em', 'i', 'u', 's',
        'ul', 'ol', 'li', 'a', 'blockquote', 'code', 'pre', 'img', 'figure', 'figcaption',
        'table', 'thead', 'tbody', 'tr', 'th', 'td', 'span', 'div',
    ];

    /** صفات مجاز به تفکیک تگ */
    private const ATTRS = [
        'a'   => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'title', 'width', 'height', 'loading', 'decoding'],
        'td'  => ['colspan', 'rowspan'],
        'th'  => ['colspan', 'rowspan', 'scope'],
        '*'   => ['id'],
    ];

    private const SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public static function clean(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        // تگ‌هایی که محتوایشان هم باید کامل برود (نه فقط خود تگ)
        $html = preg_replace('~<(script|style|iframe|object|embed|form|input|button|svg|math)\b[^>]*>.*?</\1>~is', '', $html);
        $html = preg_replace('~<(script|style|iframe|object|embed|form|input|button|svg|math)\b[^>]*/?>~i', '', $html);

        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"><div id="__root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $xp = new DOMXPath($doc);
        $root = $doc->getElementById('__root');
        if (! $root) {
            return '';
        }

        // از انتها به ابتدا پیمایش می‌کنیم تا حذف گره، پیمایش را خراب نکند
        $all = iterator_to_array($xp->query('.//*', $root));
        foreach (array_reverse($all) as $el) {
            if (! $el instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($el->nodeName);

            if (! in_array($tag, self::TAGS, true)) {
                self::unwrap($el);      // تگ ناشناس حذف، ولی متن داخلش می‌ماند
                continue;
            }

            self::scrubAttributes($el, $tag);
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    private static function scrubAttributes(DOMElement $el, string $tag): void
    {
        $allowed = array_merge(self::ATTRS[$tag] ?? [], self::ATTRS['*']);

        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->nodeName);

            // هر on* (onclick، onerror، …) و هر چیز خارج از فهرست حذف می‌شود
            if (! in_array($name, $allowed, true)) {
                $el->removeAttribute($attr->nodeName);

                continue;
            }

            if (in_array($name, ['href', 'src'], true) && ! self::safeUrl($attr->nodeValue)) {
                $el->removeAttribute($attr->nodeName);
            }
        }

        // لینک خارجی همیشه با noopener باز شود
        if ($tag === 'a' && $el->getAttribute('target') === '_blank') {
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }

    /** فقط طرح‌های امن؛ javascript: و data: رد می‌شوند */
    private static function safeUrl(?string $url): bool
    {
        $url = trim((string) $url);
        if ($url === '') {
            return false;
        }
        if (str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return true;                     // نسبی
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return $scheme !== '' && in_array($scheme, self::SCHEMES, true);
    }

    /** تگ را حذف کن ولی فرزندانش را سر جای خودش بگذار */
    private static function unwrap(DOMElement $el): void
    {
        $parent = $el->parentNode;
        if (! $parent) {
            return;
        }
        while ($el->firstChild) {
            $parent->insertBefore($el->firstChild, $el);
        }
        $parent->removeChild($el);
    }
}
