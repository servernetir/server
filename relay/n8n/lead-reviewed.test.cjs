const assert = require('node:assert/strict');
const { test } = require('node:test');
const fs = require('node:fs');
const crypto = require('node:crypto');
const run = new Function('$json', fs.readFileSync(process.env.BALE_RELAY_CODE_PATH || __dirname + '/verify-and-map-template.js', 'utf8'));
const secret = 'synthetic-test-secret';
const config = { relaySecret: secret, ipPanelApiKey: 'synthetic', ipPanelSenderNumber: '+983000000',
  ipPanelEndpoint: 'https://operator.example.test/send', maxMessageAgeSeconds: 180 };
function envelope(fields = {}) {
  const payload = { version: 1, template: 'lead_reviewed', mobile: '+989121234567', params: { code: 'CHAT-42' },
    request_id: 'synthetic-42', issued_at: Math.floor(Date.now() / 1000), ...fields };
  const b64 = Buffer.from(JSON.stringify(payload)).toString('base64url');
  return 'SMS_RELAY_V1:' + b64 + '.' + crypto.createHmac('sha256', secret).update(b64).digest('hex');
}
function evaluate(fields) { return run({ ...config, body: { envelope: envelope(fields) } })[0].json; }
test('unconfigured reviewed SMS is rejected without an operator body', () => {
  const result = evaluate({});
  assert.equal(result.valid, false);
  assert.equal(result.reason, 'pattern_code_not_configured');
  assert.equal(result.ipPanelBody, undefined);
});
test('reviewed SMS uses only the approved code and tracking variable bound by HMAC', () => {
  const result = evaluate({ pattern_code: 'Approved123', params: { code: 'CHAT-42', ignored: 'unused' } });
  assert.equal(result.valid, true);
  assert.equal(result.ipPanelBody.code, 'Approved123');
  assert.deepEqual(result.ipPanelBody.params, { code: 'CHAT-42' });
});
test('invalid configured IDs fail closed', () => {
  assert.equal(evaluate({ pattern_code: 'https://bad.example' }).reason, 'pattern_code_not_configured');
  assert.equal(evaluate({ pattern_code: 'Approved123\n' }).reason, 'pattern_code_not_configured');
});
test('configured code cannot override any pre-existing event mapping', () => {
  const result = evaluate({ template: 'otp', pattern_code: 'Approved123', params: { code: '123456' } });
  assert.equal(result.valid, true);
  assert.equal(result.ipPanelBody.code, 'u507b9k77p8oim0');
});
test('tampering with the configured ID invalidates the signature', () => {
  const original = envelope({ pattern_code: 'Approved123' });
  const [head, signature] = original.slice('SMS_RELAY_V1:'.length).split('.');
  const payload = JSON.parse(Buffer.from(head, 'base64url').toString());
  payload.pattern_code = 'Changed123';
  const tampered = 'SMS_RELAY_V1:' + Buffer.from(JSON.stringify(payload)).toString('base64url') + '.' + signature;
  assert.equal(run({ ...config, body: { envelope: tampered } })[0].json.reason, 'bad_signature');
});
test('missing tracking variable never reaches the operator', () => {
  assert.equal(evaluate({ pattern_code: 'Approved123', params: {} }).reason, 'missing_param');
});
