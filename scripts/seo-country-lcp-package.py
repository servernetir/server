"""Create the four-file guarded release from the fresh live layout."""
from pathlib import Path
import hashlib, json, shutil, zipfile, sys
ROOT=Path(__file__).resolve().parents[1]
WT=Path('C:/Users/admin/.codex/worktrees/seo-mobile-performance/servernet')
OUT=ROOT/'output/seo-country-lcp-20261011'
stage=sys.argv[1] if len(sys.argv)>1 else 'release'
assert stage in ['release','font-v2','font-v3','menu-v4','optional-v5','drawer-v6','paint-v7','font-policy-v8','layout-v9','menu-priority-v10','single-font-v11','critical-v12','reconcile-v13','device-font-v14','render-v15','reconcile-v16','reconcile-v17','render-v18']
RELEASE=OUT/stage
paths=['app/Services/Cloud/LandingPageStyles.php','resources/css/cloud-location-lcp.css','resources/css/cloud-location-lcp.json','resources/views/layouts/site.blade.php']
if stage=='font-v3':paths+=['public/assets/font/woff2/IRANSans-location-20261011-400.woff2','public/assets/font/woff2/IRANSans-location-20261011-700.woff2']
if stage=='menu-priority-v10':paths+=['resources/views/partials/server-menu.blade.php']
digest=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
assert not RELEASE.exists(), 'Inspect existing packet before creating another stage'
manifest=[]
for rel in paths:
    dst=RELEASE/'files'/rel;dst.parent.mkdir(parents=True,exist_ok=True)
    shutil.copyfile(WT/'website'/rel,dst)
    before=OUT/('snapshot' if stage=='release' or rel=='resources/views/partials/server-menu.blade.php' else 'reconcile-v17/files' if stage=='render-v18' else 'reconcile-v16/files' if stage=='reconcile-v17' else 'render-v15/files' if stage=='reconcile-v16' else 'device-font-v14/files' if stage=='render-v15' else 'reconcile-v13/files' if stage=='device-font-v14' else 'single-font-v11/files' if stage=='critical-v12' else 'menu-priority-v10/files' if stage in ['single-font-v11','reconcile-v13'] else 'paint-v7/files' if stage in ['font-policy-v8','layout-v9','menu-priority-v10'] else 'optional-v5/files' if stage in ['drawer-v6','paint-v7'] else 'font-v3/files' if stage in ['menu-v4','optional-v5'] else 'release/files')/rel
    manifest.append({'path':rel,'before':digest(before) if before.is_file() else None,'after':digest(dst)})
(RELEASE/'manifest.json').write_text(json.dumps(manifest,indent=2),encoding='utf-8')
helper=(WT/'scripts/seo-country-deploy-20261011.py').read_text(encoding='utf-8')
start=helper.index('    assert rel in ')
end=helper.index('\n',start)
helper=helper[:start]+'    assert rel in '+repr(paths)+helper[end:]
helper=helper.replace('.seo-stage-countries-20261011','.seo-stage-country-lcp-20261011')
compile(helper,'deploy.py','exec')
(RELEASE/'deploy.py').write_text(helper,encoding='utf-8')
(ROOT/'scripts/seo-country-lcp-deploy-20261011.py').write_text(helper,encoding='utf-8')
with zipfile.ZipFile(OUT/(stage+'.zip'),'w',zipfile.ZIP_DEFLATED) as z:
    for f in RELEASE.rglob('*'):
        if f.is_file():z.write(f,f.relative_to(RELEASE).as_posix())
print('LCP_PACKET_READY',len(paths),'files',(OUT/(stage+'.zip')).stat().st_size,'bytes')
