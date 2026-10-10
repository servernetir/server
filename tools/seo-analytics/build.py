"""Reproduce the deployed worker files from the locked official npm package."""
from pathlib import Path
import argparse,hashlib,tempfile,shutil

here=Path(__file__).resolve().parent
root=here.parents[1]
lib=here/'node_modules/@qwik.dev/partytown'
destination=root/'website/public/assets/js/snet-analytics-worker-0.15.0-snet3'
parser=argparse.ArgumentParser();parser.add_argument('--write',action='store_true');args=parser.parse_args()
wrap=(root/'scripts/seo-analytics-health-wrapper.js').read_text()
with tempfile.TemporaryDirectory() as temp:
    folder=Path(temp)
    for p in (lib/'lib').glob('*'):
        if p.is_file():shutil.copyfile(p,folder/p.name)
    shutil.copyfile(lib/'LICENSE',folder/'LICENSE.txt')
    p=folder/'partytown.js';s=p.read_text();old='delete e[n.split(".")[0]]';assert s.count(old)==1
    p.write_text(s.replace(old,'(e.__snetRestoreAnalyticsQueue?e.__snetRestoreAnalyticsQueue():delete e[n.split(".")[0]])'),newline='\n')
    encoded=wrap.replace('\\','\\\\').replace("'","\\'").replace('\n','\\n').replace('\r','\\r')
    head='/* Partytown 0.15.0 - MIT QwikDev */\\n'
    handler='ge.onmessage=e=>{const r=e.data;'
    health='if(r[0]==="snet:analytics-health"){const h=B.__snetAnalyticsHealth||(B.__snetAnalyticsHealth=[]);h.push(r[1]);if(h.length>20)h.shift();return;}'
    for name in ['partytown-sandbox-sw.html','partytown-sw.js']:
        p=folder/name;s=p.read_text()
        marker=head if name.endswith('.html') else head.replace('\\','\\\\')
        addition=encoded if name.endswith('.html') else encoded.replace('\\','\\\\').replace("'","\\'")
        assert s.count(marker)==1 and s.count(handler)==1
        p.write_text(s.replace(marker,marker+addition).replace(handler,handler+health),newline='\n')
    for p in folder.iterdir():
        if args.write:shutil.copyfile(p,destination/p.name)
        assert p.read_bytes()==(destination/p.name).read_bytes(),'Rebuild drift: '+p.name
        print(p.name,hashlib.sha256(p.read_bytes()).hexdigest())
print('ANALYTICS_WORKER_REPRODUCTION_OK')
