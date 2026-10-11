// Build a complete page subset, including menus, responsive rules and JS states.
// npm install --prefix BUILD_TOOLS --ignore-scripts purgecss@7.0.2
const fs=require('fs'),path=require('path'),crypto=require('crypto');
const [root,snapshot,toolsDir,fontDir]=process.argv.slice(2);
if(!root||!snapshot||!toolsDir||!fontDir)throw new Error('Usage: node build.cjs WEBSITE_ROOT SNAPSHOT_DIR BUILD_TOOLS FONT_DIR');
const {PurgeCSS}=require(path.resolve(toolsDir,'node_modules/purgecss'));
const hash=b=>crypto.createHash('sha256').update(b).digest('hex');
const canonical=b=>b.toString('utf8').replace(/\r\n/g,'\n');
const rels=['public/assets/css/site.min.css','public/assets/css/server-menu.css','public/assets/js/site.js',
 'resources/views/layouts/site.blade.php','resources/views/pages/cloud-location.blade.php',
 'resources/views/partials/header.blade.php','resources/views/partials/footer.blade.php','resources/views/partials/chat.blade.php',
 'resources/views/partials/server-menu.blade.php'];
const sources={},content=[];
for(const rel of ['public/assets/css/site.min.css','public/assets/css/server-menu.css']){
 if(canonical(fs.readFileSync(path.join(root,rel)))!==canonical(fs.readFileSync(path.join(snapshot,rel))))
  throw new Error('Stylesheet drift: reconcile the fresh live source before building '+rel);
}
for(const rel of rels){
 sources[rel]=[];
 for(const dir of [root,snapshot]){
  const file=path.join(dir,rel);if(!fs.existsSync(file))continue;
  const raw=canonical(fs.readFileSync(file));
  const digest=hash(raw);if(!sources[rel].includes(digest))sources[rel].push(digest);
  if(!rel.endsWith('.css'))content.push({raw,extension:rel.endsWith('.js')?'js':'html'});
 }
}
for(const file of fs.readdirSync(snapshot).filter(x=>x.endsWith('.html')))
 content.push({raw:fs.readFileSync(path.join(snapshot,file),'utf8'),extension:'html'});
(async()=>{
 const input=fs.readFileSync(path.join(snapshot,'public/assets/css/site.min.css'),'utf8');
 const [result]=await new PurgeCSS().purge({content,css:[{raw:input}],
  safelist:{standard:['show','on','active','open','scrolled','ready','locked','no-scroll','light','dark'],
   greedy:[/header|nav|menu|mega|topbar|footer|^ft|chat|btn|flag|theme|reveal|imp/]},
  keyframes:false,fontFace:false,variables:false});
 let css=result.css.replace(/\.\.\/font\//g,'/assets/font/');
 if(fontDir){
  for(const font of JSON.parse(fs.readFileSync(path.join(fontDir,'font-build.json'),'utf8'))){
   const data=fs.readFileSync(path.join(fontDir,font.subset));
   sources[font.source]=[font.source_sha256];
   sources[font.asset]=[hash(data)];
   fs.copyFileSync(path.join(fontDir,font.subset),path.join(root,font.asset));
   // Partition supported glyphs: absent glyphs use system fallback without fetching a full font.
   let matched=0;
   css=css.replace(/@font-face\{[^}]*\}/g,face=>{
    if(/font-family:\s*["']?IRANSans["']?\s*;/.test(face)&&new RegExp('font-weight:\\s*'+font.weight+'\\s*;').test(face)){
     matched++;return font.remaining_unicode_range?face.replace('font-display:swap','font-display:optional').slice(0,-1)+';unicode-range:'+font.remaining_unicode_range+'}':'';
    }return face;
   });
   if(matched!==1)throw new Error('Expected exactly one source font face: '+font.weight);
   const source='/'+font.asset.slice(7);
   css+=`@font-face{font-family:'IRANSans';src:url('${source}') format('woff2');font-weight:${font.weight};font-style:normal;font-display:optional;unicode-range:${font.unicode_range}}`;
  }
 }
 // Preserve the original cascade while eliminating the remaining blocking stylesheet.
 css+='\n'+canonical(fs.readFileSync(path.join(snapshot,'public/assets/css/server-menu.css')));
 // Keep the mobile colour accents without rasterizing three oversized blurred layers.
 css+='\n@media(max-width:1020px){.aurora .blob{display:none!important}.aurora{background:radial-gradient(ellipse at 0% 0%,rgba(59,130,246,.16),transparent 55%),radial-gradient(ellipse at 100% 20%,rgba(34,211,238,.10),transparent 50%),radial-gradient(ellipse at 60% 100%,rgba(139,92,246,.08),transparent 55%),var(--bg)}}';
 // Device fonts make mobile text available without a font request or later font swap.
 css+='\n@media(max-width:1020px){:root{--font-body:Tahoma,Arial,sans-serif;--font-disp:Tahoma,Arial,sans-serif}}';
 // Closed fixed menus do not need layout. Native offscreen containment keeps SSR content available.
 css+='\n@media(max-width:1020px){.drawer:not(.open){display:none}.drawer.open{display:flex}body>svg[width="0"][height="0"]{display:none}}';
 css+='\n@media(max-width:640px){.cvl-plan{content-visibility:auto;contain-intrinsic-block-size:auto 229px}body>footer{content-visibility:auto;contain-intrinsic-block-size:auto 764px}#main>.cvl-sec:not(#why):not(#plans){content-visibility:auto;contain-intrinsic-block-size:auto 350px}#main>.section:not(.cvl-sec):not(.cvl-top){content-visibility:auto;contain-intrinsic-block-size:auto 1100px}}';
 if(css.toLowerCase().includes('</style'))throw new Error('Unsafe style terminator');
 const dest=path.join(root,'resources/css');fs.mkdirSync(dest,{recursive:true});
 fs.writeFileSync(path.join(dest,'cloud-location-lcp.css'),css);
 fs.writeFileSync(path.join(dest,'cloud-location-lcp.json'),JSON.stringify({css_sha256:hash(css),sources},null,2)+'\n');
 console.log(JSON.stringify({inputBytes:Buffer.byteLength(input),subsetBytes:Buffer.byteLength(css),sources:Object.keys(sources).length}));
})();
