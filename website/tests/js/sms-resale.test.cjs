const {test}=require('node:test');
const assert=require('node:assert/strict');
const {segments}=require('../../public/assets/js/sms-resale.js');
test('GSM, extension and UTF-16 segment boundaries',()=>{
  for(const [text,expected] of [['',0],['a'.repeat(160),1],['a'.repeat(161),2],['^'.repeat(80),1],['^'.repeat(81),2],['س'.repeat(70),1],['س'.repeat(71),2],['😀'.repeat(35),1],['😀'.repeat(36),2]])assert.equal(segments(text),expected);
});
