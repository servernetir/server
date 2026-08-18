/*
| سازندهٔ گرهٔ «Cloud Phone Relay» و فایلِ ایمپورتِ n8n.
|
|   node build-cloud-phone-relay.js [secretPath]
|
| ═══ 🔴 چرا ساخته می‌شود و دستی نوشته نمی‌شود ═══
|
| گرهٔ n8n `require` ندارد، پس توابعِ SHA-256/HMAC باید **داخلِ** خودِ گره
| باشند. راهِ ساده کپی‌کردنِ آن‌ها از `verify-and-map-template.js` بود — و
| دقیقاً همان‌جا واگرایی شروع می‌شود: کسی باگی را در یکی درست می‌کند و آن یکی
| کهنه می‌ماند. نتیجه‌اش `bad_signature` است؛ یعنی تماسی که بی‌هیچ خطای
| قابل‌فهمی برقرار نمی‌شود.
|
| پس بلوکِ رمزنگاری **در لحظهٔ ساخت** از همان فایلِ رلهٔ پیامک استخراج می‌شود.
| یک منبع، بدونِ امکانِ واگرایی. اگر مرزها عوض شوند، این اسکریپت صریحاً
| می‌شکند — نه اینکه گرهٔ ناقص تولید کند.
*/

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const SOURCE = path.join(__dirname, 'verify-and-map-template.js');
const LOGIC = path.join(__dirname, 'cloud-phone-relay.logic.js');
const NODE_OUT = path.join(__dirname, 'cloud-phone-relay.node.js');

/** بلوکِ رمزنگاری: از `const K256` تا پیش از سرآیندِ «منطقِ رله». */
function extractCryptoBlock() {
  const src = fs.readFileSync(SOURCE, 'utf8');

  const start = src.indexOf('const K256 = new Uint32Array([');
  if (start === -1) throw new Error('مرزِ آغازِ بلوکِ رمزنگاری در verify-and-map-template.js پیدا نشد');

  const end = src.indexOf('منطقِ رله', start);
  if (end === -1) throw new Error('مرزِ پایانِ بلوکِ رمزنگاری پیدا نشد');

  // تا ابتدای خطی که سرآیند را دارد عقب می‌رویم
  const lineStart = src.lastIndexOf('\n', src.lastIndexOf('/*', end));

  const block = src.slice(start, lineStart).trimEnd();

  for (const fn of ['function sha256', 'function hmacSha256Hex', 'function b64urlDecode', 'function fromUtf8', 'function safeEqual']) {
    if (!block.includes(fn)) throw new Error('بلوکِ استخراج‌شده «' + fn + '» را ندارد');
  }

  return block;
}

const cryptoBlock = extractCryptoBlock();
const logic = fs.readFileSync(LOGIC, 'utf8');

const header = `/*
| ⚠️⚠️ این فایل **ساخته می‌شود** — دستی ویرایشش نکن. ⚠️⚠️
|
|   node relay/n8n/build-cloud-phone-relay.js
|
| منطق در  cloud-phone-relay.logic.js
| رمزنگاری از  verify-and-map-template.js  (همان بلوک، بدونِ کپیِ دوم)
*/

`;

const nodeCode = header + cryptoBlock + '\n\n' + logic;

fs.writeFileSync(NODE_OUT, nodeCode, 'utf8');

// ── فایلِ ایمپورتِ n8n ────────────────────────────────────────────────────
const secretPath = process.argv[2] || ('cpo-' + crypto.randomBytes(16).toString('hex'));

const uid = () => crypto.randomBytes(16).toString('hex').replace(
  /^(.{8})(.{4})(.{4})(.{4})(.{12})$/, '$1-$2-$3-$4-$5',
);

const workflow = {
  name: 'ServerNet Cloud Phone — Outgoing Call Relay',
  nodes: [
    {
      parameters: {
        httpMethod: 'POST',
        path: secretPath,
        responseMode: 'responseNode',
        options: {},
      },
      id: uid(),
      name: 'Webhook',
      type: 'n8n-nodes-base.webhook',
      typeVersion: 2,
      position: [-260, 0],
      webhookId: uid(),
      notes: 'نشانی‌اش در .env لاراول: CLOUD_PHONE_RELAY_URL',
    },
    {
      parameters: {
        assignments: {
          assignments: [
            { id: uid(), name: 'relaySecret', value: 'REPLACE_ME_same_as_CLOUD_PHONE_RELAY_SECRET', type: 'string' },
            { id: uid(), name: 'phoneToken', value: 'REPLACE_ME_PHONE_TOKEN', type: 'string' },
            { id: uid(), name: 'apiBase', value: 'https://coreapi.daftareshoma.com', type: 'string' },
            { id: uid(), name: 'fromNumber', value: '02171057757', type: 'string' },
          ],
        },
        includeOtherFields: true,
        options: {},
      },
      id: uid(),
      name: 'Relay Config',
      type: 'n8n-nodes-base.set',
      typeVersion: 3.4,
      position: [-40, 0],
      notes: '🔴 رازها فقط این‌جا. هرگز در مخزن کامیت نشوند.',
    },
    {
      parameters: { mode: 'runOnceForAllItems', jsCode: nodeCode },
      id: uid(),
      name: 'Verify & Call',
      type: 'n8n-nodes-base.code',
      typeVersion: 2,
      position: [200, 0],
      notes: 'ساخته‌شده از cloud-phone-relay.logic.js — این‌جا ویرایش نکن.',
    },
    {
      parameters: { respondWith: 'allIncomingItems', options: {} },
      id: uid(),
      name: 'Respond',
      type: 'n8n-nodes-base.respondToWebhook',
      typeVersion: 1,
      position: [440, 0],
      notes: 'لاراول فقط status=sent را موفق می‌شمارد.',
    },
  ],
  connections: {
    Webhook: { main: [[{ node: 'Relay Config', type: 'main', index: 0 }]] },
    'Relay Config': { main: [[{ node: 'Verify & Call', type: 'main', index: 0 }]] },
    'Verify & Call': { main: [[{ node: 'Respond', type: 'main', index: 0 }]] },
  },
  settings: { executionOrder: 'v1' },
  active: false,
  pinData: {},
  tags: [],
};

fs.writeFileSync(
  path.join(__dirname, 'cloud-phone-relay.workflow.json'),
  JSON.stringify(workflow, null, 2),
  'utf8',
);

console.log('');
console.log('  ساخته شد: cloud-phone-relay.node.js  (' + nodeCode.length + ' کاراکتر)');
console.log('            cloud-phone-relay.workflow.json');
console.log('');
console.log('  در .env سرور بگذار:');
console.log("    CLOUD_PHONE_RELAY_URL='https://flow.servernet.cloud/webhook/" + secretPath + "'");
console.log("    CLOUD_PHONE_RELAY_SECRET='<یک راز بساز و همان را در گرهٔ Relay Config بگذار>'");
console.log('');
console.log('  ⚠️ در گرهٔ Relay Config هر چهار مقدار REPLACE_ME را پر کن، وگرنه گره');
console.log('     صراحتاً relay_not_configured برمی‌گرداند (fail-closed).');
console.log('');
