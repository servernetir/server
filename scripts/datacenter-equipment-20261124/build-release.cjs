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
  return sha(r.stdout);
}
const rows=files.flatMap(file=>{
  const source='website/'+file, after=sha(fs.readFileSync(path.join(root,source)));
  const previous=[...new Set([old(before,source),old(phase1,source)])];
  const dest=['servernet_app/'+file];
  if(file.startsWith('public/assets/')) dest.push('public_html/'+file.slice('public/'.length));
  return dest.map(destination=>({source,destination,before:previous,after}));
});
fs.writeFileSync(path.join(__dirname,'release-manifest.json'),JSON.stringify({baseline:before,phase1,files:rows},null,2)+'\n');
console.log('Limited release targets:',rows.length);
