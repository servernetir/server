const {test, after} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const {execFileSync} = require('node:child_process');
const {JSDOM} = require('jsdom');
const root = path.resolve(__dirname, '../..');
const supplied = process.env.STUDIO_HOME_FIXTURES;
const fixtures = supplied || fs.mkdtempSync(path.join(os.tmpdir(), 'servernet-studio-home-'));
if (!supplied) for (const locale of ['fa', 'en', 'tr']) execFileSync(process.env.PHP_BINARY || 'php', [path.join(root, 'tests/Fixtures/StudioHomePreview.php'), locale, fixtures]);
after(() => { if (!supplied) fs.rmSync(fixtures, {recursive: true, force: true}); });
const code = fs.readFileSync(path.join(root, 'public/assets/js/studio-home.js'), 'utf8');
for (const locale of ['fa', 'en', 'tr']) {
  test(locale + ': four capabilities switch locally and keep one accessible selection', () => {
    const dom = new JSDOM(fs.readFileSync(path.join(fixtures, 'partial-' + locale + '.html'), 'utf8'), {runScripts: 'outside-only', url: 'https://servernet.cloud/'});
    try {
      const w = dom.window, doc = w.document;
      w.fetch = () => { throw new Error('Preview must not call an API'); };
      w.XMLHttpRequest = function () { throw new Error('Preview must not call an API'); };
      assert.equal(doc.querySelectorAll('[data-studio-mode]:not([hidden])').length, 0);
      assert.equal(doc.querySelectorAll('[data-studio-panel]:not([hidden])').length, 1);
      w.eval(code);
      const buttons = Array.from(doc.querySelectorAll('[data-studio-mode]'));
      assert.equal(buttons.length, 4);
      for (const button of buttons) {
        assert.equal(button.hidden, false);
        button.click();
        const visible = doc.querySelectorAll('[data-studio-panel]:not([hidden])');
        assert.equal(visible.length, 1);
        assert.equal(visible[0].id, button.getAttribute('aria-controls'));
        assert.equal(doc.querySelectorAll('[aria-pressed="true"]').length, 1);
        assert.equal(button.getAttribute('aria-pressed'), 'true');
      }
      assert.equal(doc.querySelector('.sn-studio-launch').href, 'https://studio.servernet.cloud/');
      assert.equal(doc.querySelector('.sn-studio-benefits a').getAttribute('href'), (locale === 'fa' ? '' : '/' + locale) + '/studio/download#studio-web-install');
      assert.equal(doc.querySelector('.sn-studio-explore').hash, '#studio-capabilities');
      assert.ok(doc.getElementById('studio-capabilities'));
      assert.doesNotMatch(doc.body.textContent, /studio_home\./);
      assert.equal(doc.querySelectorAll('form,input,textarea,a[href$=".exe"]').length, 0);
    } finally { dom.window.close(); }
  });
}
test('unrelated pages do not need a Studio section', () => {
  const dom = new JSDOM('<main>Other page</main>', {runScripts: 'outside-only'});
  try { assert.doesNotThrow(() => dom.window.eval(code)); }
  finally { dom.window.close(); }
});
