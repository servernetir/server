const test=require('node:test');const assert=require('node:assert/strict');const vm=require('node:vm');
const {patchProcessor,reconciliation,payloadExpression}=require('./chat-leads-console.cjs');
test('callback serializes arbitrary customer strings safely and uses stable row identity',()=>{
 const row={id:73,ticket:'SN-123456',name:'Customer "quoted"',phone:'09120000000',email:'a@example.test',locale:'fa',request:'نیاز ERP\n<script>" & test',createdAt:'2026-10-25T10:00:00Z'};
 const raw=payloadExpression.slice(3,-3).trim(); const p=JSON.parse(vm.runInNewContext(raw,{$json:row}));
 assert.equal(p.external_id,'chat_leads:73');assert.equal(p.summary,row.request);assert.equal(p.name,row.name);assert.equal(p.notify,true);assert.equal(p.occurred_at,row.createdAt);
 assert.equal(JSON.parse(vm.runInNewContext(raw,{$json:{id:74,locale:'invalid'}})).locale,'fa');
});
test('processor preserves every original node and downstream email/SMS/result edge',()=>{
 const before={name:'processor',nodes:[{name:'Save Lead'},{name:'Internal Lead Email'},{name:'Result'}],connections:{'Save Lead':{main:[[{node:'Internal Lead Email',type:'main',index:0}]]},'Internal Lead Email':{main:[[{node:'Result',type:'main',index:0}]]}},settings:{executionOrder:'v1'}};
 const result=patchProcessor(before,'credential-ref');assert.deepEqual(result.nodes.slice(0,-1),before.nodes);assert.deepEqual(result.connections['Internal Lead Email'],before.connections['Internal Lead Email']);assert.deepEqual(result.connections['Sync Console Lead'],before.connections['Save Lead']);assert.equal(result.nodes.at(-1).onError,'continueRegularOutput');assert.equal(before.nodes.length,3);
 assert.throws(()=>patchProcessor(result,'ref'),/Already installed/);assert.throws(()=>patchProcessor({...before,connections:{}},'ref'),/Unexpected/);
});
test('reconciliation reads durable rows and uses credential-backed callback without customer messages',()=>{
 const w=reconciliation('credential-ref');assert.equal(w.nodes[0].parameters.rule.interval[0].minutesInterval,5);assert.equal(w.nodes[1].parameters.returnAll,true);assert.equal(w.nodes[1].parameters.operation,'get');assert.equal(w.nodes[2].credentials.httpHeaderAuth.id,'credential-ref');assert.equal(w.nodes[2].onError,undefined);
 assert.equal(w.nodes.filter(n=>/emailSend|sms|agent/.test(n.type)).length,0);assert(!JSON.stringify(w).includes('Bearer '));
});
