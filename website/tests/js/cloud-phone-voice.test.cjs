const {test, after} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const {execFileSync} = require('node:child_process');
const {JSDOM} = require('jsdom');
const root = path.resolve(__dirname, '../..');
const supplied = process.env.CLOUD_PHONE_VOICE_FIXTURES;
const fixtures = supplied || fs.mkdtempSync(path.join(os.tmpdir(), 'servernet-voice-page-'));
const apkPath = '/downloads/servernet-voice/ServerNet-Voice-0.1.0-preview.apk';
if (!supplied) for (const locale of ['fa', 'en', 'tr']) execFileSync(process.env.PHP_BINARY || 'php', [path.join(root, 'tests/Fixtures/CloudPhoneVoicePreview.php'), locale, fixtures]);
after(() => { if (!supplied) fs.rmSync(fixtures, {recursive: true, force: true}); });
function page(locale, slug = 'cloud-phone') {
    return new JSDOM(fs.readFileSync(path.join(fixtures, `${slug}-${locale}.html`), 'utf8'), {url: `https://servernet.cloud/${locale === 'fa' ? '' : `${locale}/`}solutions/${slug}`});
}
for (const locale of ['fa', 'en', 'tr']) {
    test(`${locale}: actual page has localized metadata, one H1, and working section anchors`, () => {
        const dom = page(locale), doc = dom.window.document;
        try {
            assert.equal(doc.documentElement.lang, locale);
            assert.equal(doc.documentElement.dir, locale === 'fa' ? 'rtl' : 'ltr');
            assert.equal(doc.querySelectorAll('h1').length, 1);
            assert.ok(doc.title.length > 20);
            assert.ok(doc.querySelector('meta[name="description"]').content.length > 80);
            assert.ok(doc.querySelector('h1').textContent.trim().length > 15);
            assert.doesNotMatch(doc.body.textContent, /ui\.[a-z_]+|\{\{|@(?:include|php|section)/);
            for (const anchor of doc.querySelectorAll('a[href^="#"]')) assert.ok(doc.querySelector(anchor.getAttribute('href')), `Unresolved anchor ${anchor.getAttribute('href')}`);
            assert.ok(doc.getElementById('features'));
            assert.ok(doc.getElementById('voice-app'));
            assert.equal(doc.querySelectorAll('#voice-app').length, 1);
        } finally { dom.window.close(); }
    });
    test(`${locale}: preview download, license and connection requirements are visible and accurate`, () => {
        const dom = page(locale), doc = dom.window.document;
        try {
            const section = doc.getElementById('voice-app'), download = section.querySelector('.sn-voice-download');
            assert.equal(new URL(download.href).pathname, apkPath);
            assert.equal(download.getAttribute('download'), 'ServerNet-Voice-0.1.0-preview.apk');
            assert.ok(download.textContent.trim().length > 8);
            assert.equal(download.getAttribute('aria-disabled'), null);
            assert.match(section.textContent, /0\.1\.0-preview/);
            assert.match(section.textContent, locale === 'fa' ? /نسخهٔ آزمایشی/ : locale === 'tr' ? /Önizleme sürümü/ : /Preview release/);
            assert.match(section.textContent, /SIP/);
            assert.match(section.textContent, locale === 'fa' ? /اتصال فعال/ : locale === 'tr' ? /bağlı kalmasına/ : /active app connection/);
            const source = section.querySelector('a[href*="github.com/servernetir/server/tree/"]');
            assert.ok(source);
            assert.equal(source.getAttribute('href'), 'https://github.com/servernetir/server/tree/5de56f11361690b313cc5ac10aa9f2e16f8224b9/mobile/voice-android');
            assert.match(source.textContent, /GPL/);
            assert.doesNotMatch(doc.body.textContent, /داخلی نامحدود|unlimited extensions|Sınırsız dahili|تماس‌های داخلی رایگان/i);
        } finally { dom.window.close(); }
    });
    test(`${locale}: real screenshot mockups reserve image space and disclose demonstration data`, () => {
        const dom = page(locale), doc = dom.window.document;
        try {
            const section = doc.getElementById('voice-app'), images = [...section.querySelectorAll('img')];
            assert.equal(images.length, 2);
            for (const img of images) {
                const declaredWidth = Number(img.getAttribute('width'));
                const declaredHeight = Number(img.getAttribute('height'));
                assert.ok(Number.isInteger(declaredWidth) && declaredWidth > 0, 'Screenshot must declare a positive pixel width');
                assert.ok(Number.isInteger(declaredHeight) && declaredHeight > 0, 'Screenshot must declare a positive pixel height');
                assert.equal(img.getAttribute('loading'), 'lazy');
                assert.ok(img.alt.length > 30);
                const file = path.join(root, 'public', new URL(img.src).pathname);
                const bytes = fs.readFileSync(file);
                assert.equal(bytes.subarray(1, 4).toString(), 'PNG');
                assert.equal(declaredWidth, bytes.readUInt32BE(16), 'Declared width must match the actual PNG IHDR');
                assert.equal(declaredHeight, bytes.readUInt32BE(20), 'Declared height must match the actual PNG IHDR');
            }
            const main = section.querySelector('.sn-voice-device-main img');
            assert.ok(main.src.endsWith(locale === 'fa' ? 'voice-dialer-fa.png' : locale === 'tr' ? 'voice-accounts-tr.png' : 'voice-dialer-en.png'));
            assert.match(section.querySelector('figcaption').textContent, locale === 'fa' ? /آزمایشی/ : locale === 'tr' ? /tanıtım amaçlı/ : /demonstration/);
        } finally { dom.window.close(); }
    });
    test(`${locale}: visible FAQs and SoftwareApplication structured data agree`, () => {
        const dom = page(locale), doc = dom.window.document;
        try {
            const schemas = [...doc.querySelectorAll('script[type="application/ld+json"]')].map(script => JSON.parse(script.textContent));
            const faq = schemas.find(schema => schema['@type'] === 'FAQPage');
            const software = schemas.find(schema => schema['@type'] === 'SoftwareApplication');
            assert.ok(faq); assert.ok(software);
            for (const schema of schemas) assert.equal(schema['@context'], 'https://schema.org');
            const visible = [...doc.querySelectorAll('details.lk-faq-item')];
            assert.ok(visible.length >= 5);
            assert.equal(faq.mainEntity.length, visible.length);
            faq.mainEntity.forEach((entry, i) => {
                assert.equal(entry.name, visible[i].querySelector('summary').textContent.trim());
                assert.equal(entry.acceptedAnswer.text, visible[i].querySelector('p').textContent.trim());
            });
            assert.equal(software.name, 'ServerNet Voice');
            assert.equal(software.softwareVersion, '0.1.0-preview');
            assert.equal(new URL(software.downloadUrl).pathname, apkPath);
            assert.deepEqual(software.inLanguage, ['fa', 'en', 'tr']);
            assert.equal(software.aggregateRating, undefined);
            assert.equal(software.offers, undefined);
            assert.match(doc.querySelector('main').textContent, /SIP Trunk/);
            assert.match(doc.querySelector('main').textContent, /IVR/);
        } finally { dom.window.close(); }
    });
    test(`${locale}: another actual solution does not inherit app assets or app schema`, () => {
        const dom = page(locale, 'managed'), doc = dom.window.document;
        try {
            assert.equal(doc.querySelectorAll('h1').length, 1);
            assert.ok(doc.querySelector('h1').textContent.trim().length > 5);
            assert.equal(doc.getElementById('voice-app'), null);
            assert.equal(doc.querySelector('a[href$=".apk"]'), null);
            assert.equal(doc.querySelector('link[href*="cloud-phone-voice.css"]'), null);
            assert.ok(![...doc.querySelectorAll('script[type="application/ld+json"]')].some(s => JSON.parse(s.textContent)['@type'] === 'SoftwareApplication'));
        } finally { dom.window.close(); }
    });
}
test('app effects are scoped, responsive and respect reduced motion without a script dependency', () => {
    const css = fs.readFileSync(path.join(root, 'public/assets/css/cloud-phone-voice.css'), 'utf8');
    assert.match(css, /prefers-reduced-motion/);
    assert.match(css, /@media[^{}]*max-width/);
    assert.match(css, /\.sn-voice/);
    assert.doesNotMatch(css, /@import|url\(https?:/);
    const dom = page('en');
    try {
        assert.equal(dom.window.document.querySelectorAll('#voice-app script:not([type="application/ld+json"])').length, 0);
        assert.ok(dom.window.document.querySelector('head link[href*="cloud-phone-voice.css"]'));
    } finally { dom.window.close(); }
});
