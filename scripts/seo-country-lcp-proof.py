"""Sanitize performance evidence; never publish raw analytics identifiers."""
from pathlib import Path
from urllib.parse import urlparse,parse_qs
import json,sys
ROOT=Path(__file__).resolve().parents[1]
OUT=ROOT/'output/seo-country-lcp-20261011'
prefix=sys.argv[1] if len(sys.argv)>1 else 'menu'
proof=[]
for report in sorted(OUT.glob('*-'+prefix+'-*.report.json')):
    j=json.loads(report.read_text(encoding='utf-8'));a=j.get('audits',{})
    if not a.get('metrics',{}).get('details'):continue
    metrics=a['metrics']['details']['items'][0]
    log=json.loads(report.with_name(report.name.replace('.json','-0.devtoolslog.json')).read_text(encoding='utf-8'))
    requests={};responses={}
    for event in log:
        p=event['params']
        if event['method']=='Network.requestWillBeSent':
            u=urlparse(p['request']['url'])
            if u.path.endswith('/g/collect'):
                values=parse_qs(u.query)
                # Only event name and HTTP status enter the shareable proof.
                requests[p['requestId']]=values.get('en',['unknown'])[0]
        elif event['method']=='Network.responseReceived':responses[p['requestId']]=p['response']['status']
    proof.append({'page':j['finalDisplayedUrl'].split('?')[0],'fetchTime':j['fetchTime'],
        'throttling_method':j['configSettings']['throttlingMethod'],
        'LCP_ms':a['largest-contentful-paint']['numericValue'],'TBT_ms':a['total-blocking-time']['numericValue'],
        'CLS':a['cumulative-layout-shift']['numericValue'],'observed_LCP_ms':metrics['observedLargestContentfulPaint'],
        'performance':j['categories']['performance']['score'],
        'analytics_transport':[{'event':name,'http_status':responses.get(key)} for key,name in requests.items()],
        'render_blocking_stylesheets':[x['url'].split('?')[0] for x in a['network-requests']['details']['items'] if x['resourceType']=='Stylesheet'],
        'font_assets':[{'path':'embedded-font' if x['url'].startswith('data:') else urlparse(x['url']).path,'bytes':x['transferSize']} for x in a['network-requests']['details']['items'] if x['resourceType']=='Font']})
(OUT/(prefix+'-proof.json')).write_text(json.dumps(proof,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
print('SANITIZED_PROOF',len(proof),'reports',sum(any(e['event']=='page_view' and e['http_status']==204 for e in r['analytics_transport']) for r in proof),'page_view 204')
