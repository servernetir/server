// Produce the exact limited release scope for both hardware phases.
const fs=require('node:fs'), path=require('node:path'), crypto=require('node:crypto'), cp=require('node:child_process');
const root=path.resolve(__dirname,'../..');
const before='17074561fc0609c5fcb3eb50c2e87acaec2d1221';
const phase1='a605d977d5800a684823ec2783bd876a7420f535';
const files=[
  // New dependencies before their consumers.
  'database/catalog/hardware-growth-20261123.json','database/catalog/datacenter-equipment-20261124.json',
  'config/hardware_topics.php','config/datacenter_content.php',
  'lang/fa/equipment.php','lang/en/equipment.php','lang/tr/equipment.php',
  'resources/views/partials/hardware-stock-quote.blade.php','resources/views/partials/hardware-topic-links.blade.php',
  'resources/views/partials/datacenter-catalogue.blade.php','resources/views/partials/equipment-schematic.blade.php',
  'resources/views/pages/parts-topic.blade.php','resources/views/pages/hardware-planner.blade.php',
  'public/assets/css/datacenter-equipment.css','public/assets/js/datacenter-planner.js',
  'config/hardware_catalog.php','config/hardware_shop.php','config/parts_content.php','app/Models/ServerPart.php',
  'lang/fa/shop_seo.php','lang/en/shop_seo.php','lang/tr/shop_seo.php',
  'app/Http/Controllers/PartsShopController.php','routes/web.php',
  'app/Http/Controllers/SiteController.php','app/Http/Controllers/ServerShopController.php',
  'resources/views/partials/hardware-assets.blade.php','resources/views/partials/hardware-guide.blade.php','resources/views/partials/part-card.blade.php',
  'resources/views/pages/parts-index.blade.php','resources/views/pages/parts-category.blade.php','resources/views/pages/part-detail.blade.php',
  'resources/views/pages/parts-compare.blade.php','resources/views/pages/parts-generation.blade.php',
  'resources/views/pages/server-detail.blade.php','resources/views/pages/servers-index.blade.php','config/servernet.php',
];
const sha=b=>crypto.createHash('sha256').update(b).digest('hex');
function old(commit,source) {
  const r=cp.spawnSync('git',['show',commit+':'+source],{cwd:root,maxBuffer:2e7});
  if(r.status!==0) {
    // Verify commit exists; absent path is intentional, unavailable commit is not.
    cp.execFileSync('git',['cat-file','-e',commit+'^{commit}'],{cwd:root});
    return null;
  }
  return [sha(r.stdout),sha(Buffer.from(r.stdout.toString('utf8').replace(/\r?\n/g,'\r\n')))];
}
// Exact production hashes were verified by a read-only anchored insertion
// simulation. Preserve every unrelated byte of the three divergent files.
const liveOverlays={
  'routes/web.php':['d16003b4e7ef99108fa07ebe65cf416da29316f4454dc96eb62561ee834f116e','2e70d23789d2bcce33fb63d6054efa26547cc7e5b3dead8f6bdf4cb4eefa0230'],
  'app/Http/Controllers/SiteController.php':['cd795be3ad85f2b476ba47baf852dde72391c68b8c3dc9738352074fd549f312','11174cada96f03b44721b6682d878855dd7485d2aa6f331ce8eba79c13b923ed'],
  'config/servernet.php':['9fbc1b18e2a5931b6423449aa14ceab76d7879ef75ff048dbb0f9264ad1b9296','187d020b64aa0b33c19ca339907ed646a08bd6757d0b5bbc5b279648e3f2281d'],
};
function edits(source) {
  return JSON.parse(cp.execFileSync('python3',['-c',`import difflib,json,subprocess,sys,pathlib
a=subprocess.check_output(['git','show',sys.argv[1]+':'+sys.argv[2]]).decode().splitlines(keepends=True)
b=pathlib.Path(sys.argv[2]).read_bytes().decode().splitlines(keepends=True)
edits=[]
for tag,i,j,k,l in difflib.SequenceMatcher(None,a,b,autojunk=False).get_opcodes():
    if tag=='equal': continue
    assert tag=='insert' and i>=3
    edits.append({'anchor': ''.join(a[i-3:i]),'insert': ''.join(b[k:l])})
print(json.dumps(edits))`,before,source],{cwd:root,encoding:'utf8'}));
}
const rows=files.flatMap(file=>{
  const source='website/'+file, after=sha(fs.readFileSync(path.join(root,source)));
  const previous=[...new Set([old(before,source),old(phase1,source)].flat())];
  if (liveOverlays[file]) {
    const [live,merged]=liveOverlays[file];
    return [{source,destination:'servernet_app/'+file,before:[live],after:merged,source_sha:after,edits:edits(source)}];
  }
  const dest=['servernet_app/'+file];
  if(file.startsWith('public/assets/')) dest.push('public_html/'+file.slice('public/'.length));
  return dest.map(destination=>({source,destination,before:previous,after}));
});
fs.writeFileSync(path.join(__dirname,'release-manifest.json'),JSON.stringify({baseline:before,phase1,files:rows},null,2)+'\n');
console.log('Limited release targets:',rows.length);
