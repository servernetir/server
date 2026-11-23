// Offline design review from the real Blade carousel, styles, and runtime.
// Header and initial globe are presentation fixtures, not live server data.
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const output = path.resolve(process.argv[2] || 'parts-hero-review');
const fixtureDir = path.join(output, 'fixture');
const dist = path.join(output, 'dist');
fs.mkdirSync(dist, {recursive: true});
for(const locale of ['fa','en','tr'])execFileSync(process.env.PHP_BINARY||'php',[path.join(__dirname,'StudioHomePreview.php'),locale,fixtureDir],{stdio:'inherit'});
const publicRoot=path.join(root,'public');
const media={'.woff2':'font/woff2','.woff':'font/woff','.webp':'image/webp','.svg':'image/svg+xml'};
const uri=file=>`data:${media[path.extname(file)]};base64,${fs.readFileSync(file).toString('base64')}`;
const assets=Object.fromEntries(['atlas-earth.svg','studio-coffee-campaign.webp','home-parts-exploded.webp'].map(name=>{const rel='assets/img/'+name;return [rel,uri(path.join(publicRoot,rel))];}));
const styles=['site','atlas','atlas-public','studio-carousel','home-gpu-slide','email-showcase','home-parts-slide'].map(name=>{
  const source=path.join(publicRoot,'assets/css',name+'.css');
  return fs.readFileSync(source,'utf8').replace(/,\s*url\(['"]?[^)'\"]+\.woff['"]?\)\s*format\(['"]woff['"]\)/g,'').replace(/url\(['"]?([^)'"\s]+)['"]?\)/g,(all,rel)=>{const file=path.resolve(path.dirname(source),rel);return file.startsWith(publicRoot+path.sep)&&fs.existsSync(file)&&media[path.extname(file)]?`url('${uri(file)}')`:all;});
}).join('\n');
const copy={
  fa:{brand:'سرورنت',notice:'پیش‌نمایش طراحی · منتشر نشده',support:'پشتیبانی ۲۴/۷',products:'محصولات',services:'خدمات',tools:'ابزارهای تخصصی',knowledge:'پایگاه دانش',contact:'تماس با ما',account:'ناحیه کاربری',tip:'اسلاید پیشنهادی فروشگاه قطعات — قطعات به‌ترتیب از شاسی باز بیرون می‌آیند، نام هر قطعه ظاهر می‌شود و آرام در هوا حرکت می‌کند. پخش خودکار دو ثانیه پس از آخرین کلیک ادامه می‌یابد؛ دکمهٔ توقف، حرکت آرام قطعات و گردش اسلایدها را متوقف می‌کند.'},
  en:{brand:'ServerNet',notice:'Design preview · Not published',support:'24/7 support',products:'Products',services:'Services',tools:'Specialized tools',knowledge:'Knowledge base',contact:'Contact us',account:'Client area',tip:'Proposed hardware slide — the components emerge in sequence from the open chassis, reveal their names and float gently. Autoplay resumes two seconds after the last click; the pause button deliberately holds both rotation and gentle hovering.'},
  tr:{brand:'ServerNet',notice:'Tasarım önizlemesi · Yayınlanmadı',support:'7/24 destek',products:'Ürünler',services:'Hizmetler',tools:'Özel araçlar',knowledge:'Bilgi bankası',contact:'İletişim',account:'Müşteri paneli',tip:'Önerilen donanım slaydı — bileşenler açık kasadan sırayla çıkar, adları görünür ve hafifçe süzülür. Otomatik geçiş son tıklamadan iki saniye sonra devam eder; duraklatma düğmesi hem geçişi hem hafif hareketi durdurur.'}
};
const icon=name=>`<svg class="icon" aria-hidden="true"><use href="#i-${name}"/></svg>`;
function header(locale){const t=copy[locale],prefix=locale==='fa'?'':'/'+locale,site='https://servernet.cloud'+prefix;const nav=[[t.products,'/cloud'],[t.services,'/gpu'],[t.tools,'/ai/studio'],[t.knowledge,'/knowledge'],['AI Gateway','/ai'],[t.contact,'/contact']];
  return `<div class="site-header-wrap" id="header-wrap"><div class="topbar"><div class="container"><div class="topbar-contacts"><a href="tel:+982171057757">${icon('phone')}02171057757</a><a href="mailto:support@servernet.cloud">${icon('mail')}support@servernet.cloud</a><span class="topbar-status hide-sm">${icon('headset')}${t.support}</span></div><div class="topbar-side"><span class="preview-tag">${t.notice}</span><nav class="lang-links" aria-label="Language">${['fa','en','tr'].map(lang=>`<a href="?lang=${lang}" ${lang===locale?'class="active"':''}>${{fa:'فارسی',en:'English',tr:'Türkçe'}[lang]}</a>`).join('<span aria-hidden="true">|</span>')}</nav></div></div></div><header id="header"><div class="container"><a href="${site}" target="_blank" rel="noopener" class="logo"><span class="logo-mark">${icon('server')}</span>${t.brand}</a><nav class="desktop" id="desktop-nav" aria-label="Main">${nav.map(([label,url])=>`<a class="nav-link" href="${site+url}" target="_blank" rel="noopener">${label}</a>`).join('')}</nav><div class="header-actions"><button class="theme-toggle" id="theme-toggle" aria-label="Light / Dark" type="button">${icon('sun')}</button><a class="login-btn" href="https://console.servernet.cloud${prefix}/account" target="_blank" rel="noopener">${icon('user')}<span>${t.account}</span></a></div></div></header></div>`;
}
const templates=['fa','en','tr'].map(locale=>`<template id="review-${locale}">${header(locale)}<main id="main">${fs.readFileSync(path.join(fixtureDir,`partial-${locale}.html`),'utf8')}<p class="review-help" role="note">${copy[locale].tip}</p></main></template>`).join('\n');
const icons=fs.readFileSync(path.join(root,'resources/views/partials/icons.blade.php'),'utf8').replace(/\{\{--[\s\S]*?--\}\}/g,'');
const runtime=fs.readFileSync(path.join(publicRoot,'assets/js/studio-carousel.js'),'utf8');
const page=`<!doctype html><html lang="fa" dir="rtl" data-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>ServerNet · Hardware collection · Design preview</title><style>${styles}\n.preview-tag{font-size:.72rem;color:var(--cyan);white-space:nowrap;border:1px solid #72cde633;border-radius:999px;padding:3px 8px;line-height:1.4}.review-help{max-width:1040px;padding:18px 26px;margin:auto;color:var(--muted);font-size:.875rem;text-align:center;line-height:1.9}.sn-public .topbar .container{min-height:38px}.sn-public .header-actions .icon{width:16px;height:16px}@media(max-width:800px){.topbar-contacts{display:none!important}.topbar-side{width:100%;justify-content:space-between}.preview-tag{font-size:.64rem}.login-btn{font-size:.7rem}.review-help{font-size:.75rem}}</style></head><body class="sn-public" id="review-page">${icons}${templates}<div id="review-mount"></div><script>
(function(){
 const q=new URLSearchParams(location.search),locale=['fa','en','tr'].includes(q.get('lang'))?q.get('lang'):'fa';
 document.documentElement.lang=locale;document.documentElement.dir=locale==='fa'?'rtl':'ltr';document.documentElement.dataset.theme=q.get('theme')==='light'?'light':'dark';
 const assets=${JSON.stringify(assets)},mount=document.getElementById('review-mount'),content=document.getElementById('review-'+locale).content.cloneNode(true);
 content.querySelectorAll('img[src]').forEach(img=>{const src=img.getAttribute('src');if(assets[src])img.src=assets[src];});
 content.querySelectorAll('img[data-hero-image-src]').forEach(img=>{if(assets[img.dataset.heroImageSrc])img.dataset.heroImageSrc=assets[img.dataset.heroImageSrc];});
 mount.append(content);
 mount.querySelectorAll('main a[href^="/"]').forEach(a=>{a.href='https://servernet.cloud'+a.getAttribute('href');a.target='_blank';a.rel='noopener';});
 function localizeTheme(){mount.querySelectorAll('.lang-links a').forEach(a=>{const query=new URLSearchParams(a.search);query.set('theme',document.documentElement.dataset.theme);a.href='?'+query.toString();});}
 mount.querySelector('#theme-toggle').addEventListener('click',()=>{document.documentElement.dataset.theme=document.documentElement.dataset.theme==='light'?'dark':'light';localizeTheme();});localizeTheme();
})();
</script><script>${runtime}</script><script>(function(){const q=new URLSearchParams(location.search),slide=['infrastructure','gpu','studio','n8n','hosting','email','parts'].includes(q.get('slide'))?q.get('slide'):'parts',toggle=document.querySelector('[data-hero-autoplay]');if(q.get('autoplay')==='0'&&toggle.getAttribute('aria-pressed')==='false')toggle.click();if(slide!=='infrastructure')document.querySelector('[data-hero-target="'+slide+'"]').click();})();</script></body></html>`;
fs.writeFileSync(path.join(dist,'index.html'),page);
fs.writeFileSync(path.join(dist,'viewport.html'),`<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"><style>body{margin:0;background:#303740;color:#fff;font:14px system-ui;display:grid;justify-content:center;gap:10px;padding:10px}iframe{border:0;box-shadow:0 6px 25px #0007;max-width:100%}</style></head><body><span id="size"></span><iframe title="Responsive review" id="frame"></iframe><script>const q=new URLSearchParams(location.search),w=Math.max(320,Math.min(1300,Number(q.get('w'))||390)),h=Math.max(580,Math.min(960,Number(q.get('h'))||844));const f=document.getElementById('frame');f.width=w;f.height=h;document.getElementById('size').textContent=w+' × '+h;const args=new URLSearchParams();for(const k of ['lang','theme','slide','autoplay'])if(q.has(k))args.set(k,q.get(k));f.src='index.html?'+args.toString();</script></body></html>`);
console.log('Standalone hardware preview:',path.join(dist,'index.html'),Buffer.byteLength(page),'bytes');
