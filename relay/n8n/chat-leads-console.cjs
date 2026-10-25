'use strict';
// Only registration metadata is sent. The n8n header-auth credential holds the secret.
const endpoint = 'https://console.servernet.cloud/api/internal/chat-leads';
const payloadExpression = "={{ JSON.stringify({external_id:'chat_leads:'+$json.id,ticket:String($json.ticket||'').slice(0,80),name:String($json.name||'').slice(0,120),phone:String($json.phone||'').slice(0,40),email:String($json.email||'').trim().slice(0,190),locale:['fa','en','tr'].includes($json.locale)?$json.locale:'fa',summary:String($json.request||'درخواست تماس از طریق دستیار سایت').slice(0,10000),occurred_at:$json.createdAt||null,notify:true}) }}";
function callback(credentialId) {
  return {
    id: 'servernet-console-chat-lead', name: 'Sync Console Lead',
    type: 'n8n-nodes-base.httpRequest', typeVersion: 4.2, position: [740, 180],
    retryOnFail: true, maxTries: 2, waitBetweenTries: 1000,
    parameters: {
      method: 'POST', url: endpoint, authentication: 'genericCredentialType', genericAuthType: 'httpHeaderAuth',
      sendBody: true, specifyBody: 'json', jsonBody: payloadExpression,
      options: {timeout: 6000},
    },
    credentials: {httpHeaderAuth: {id: credentialId, name: 'ServerNet Console Chat Leads'}},
  };
}
function patchProcessor(workflow, credentialId) {
  const copy = JSON.parse(JSON.stringify(workflow));
  if (copy.nodes.some(n => n.name === 'Sync Console Lead')) throw Error('Already installed');
  const edges = copy.connections['Save Lead']?.main;
  if (!edges || edges.length !== 1 || edges[0].length !== 1 || edges[0][0].node !== 'Internal Lead Email') throw Error('Unexpected lead processor topology');
  const node = callback(credentialId);
  // Existing emails, SMS, and the final tool result keep their original chain.
  node.onError = 'continueRegularOutput';
  copy.nodes.push(node);
  copy.connections['Save Lead'].main = [[{node:node.name,type:'main',index:0}]];
  copy.connections[node.name] = {main:edges};
  return {name:copy.name,nodes:copy.nodes,connections:copy.connections,settings:copy.settings || {}};
}
function reconciliation(credentialId) {
  return {
    name:'ServerNet Chat Leads — Console Reconciliation',
    nodes:[
      {id:'chat-sync-schedule',name:'Every Five Minutes',type:'n8n-nodes-base.scheduleTrigger',typeVersion:1.2,position:[0,0],parameters:{rule:{interval:[{field:'minutes',minutesInterval:5}]}}},
      {id:'chat-sync-rows',name:'Read Saved Chat Leads',type:'n8n-nodes-base.dataTable',typeVersion:1,position:[250,0],parameters:{operation:'get',dataTableId:{__rl:true,mode:'id',value:'uAkkCEvWgAQf87Z3'},returnAll:true}},
      {...callback(credentialId),position:[500,0]},
    ],
    connections:{'Every Five Minutes':{main:[[{node:'Read Saved Chat Leads',type:'main',index:0}]]},'Read Saved Chat Leads':{main:[[{node:'Sync Console Lead',type:'main',index:0}]]}},
    settings:{executionOrder:'v1'},
  };
}
module.exports={callback,patchProcessor,reconciliation,payloadExpression};
