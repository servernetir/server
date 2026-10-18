import test from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { applyHunks, buildPayload, parseHunks } from './build-arvan-owner-console-deploy-20261018.mjs';

test('deterministic payload contains exactly the two console files, not credential repair', () => {
  const serialized = buildPayload();
  assert.equal(serialized, buildPayload());
  const payload = JSON.parse(serialized);
  assert.deepEqual(payload.files.map(f => f.path), [
    'app/Http/Controllers/Account/CloudServerController.php',
    'app/Services/Cloud/ArvanConsoleBroker.php',
  ]);
  assert.equal(serialized.includes('CloudProvisioner.php'), false);
  for (const file of payload.files) {
    assert.match(file.expected_live_sha256, /^[a-f0-9]{64}$/);
    const base = Buffer.from(file.base_b64, 'base64').toString();
    const patched = Buffer.from(file.patched_b64, 'base64').toString();
    const hunks = file.hunks.map(h => ({ before: Buffer.from(h.before_b64, 'base64').toString(), after: Buffer.from(h.after_b64, 'base64').toString() }));
    assert.equal(createHash('sha256').update(base).digest('hex'), file.base_sha256);
    assert.equal(createHash('sha256').update(patched).digest('hex'), file.patched_sha256);
    assert.equal(applyHunks(base, hunks), patched);
    assert.equal(applyHunks('unrelated-live-prefix\n' + base, hunks), 'unrelated-live-prefix\n' + patched);
  }
});

test('context matching fails closed for missing, empty or duplicate context', () => {
  assert.throws(() => applyHunks('a a', [{ before: 'a', after: 'b' }]));
  assert.throws(() => applyHunks('z', [{ before: 'a', after: 'b' }]));
  assert.throws(() => applyHunks('z', [{ before: '', after: 'b' }]));
});

test('parser preserves unified context and refuses incomplete newline contracts', () => {
  assert.deepEqual(parseHunks('@@ -1,2 +1,2 @@\n context\n-old\n+new\n'), [{ before: 'context\nold\n', after: 'context\nnew\n' }]);
  assert.throws(() => parseHunks(''));
  assert.throws(() => parseHunks('@@ -1 +1 @@\n-a\n+b\n\\ No newline at end of file\n'));
});
