const fs=require('node:fs');
const path=require('node:path');
const assert=require('node:assert/strict');
const {chromium}=require(process.env.SMS_PLAYWRIGHT_MODULE);
(async()=>{
  const browser=await chromium.launch({headless:true});
  const source=path.resolve(__dirname,'../../public/assets');
  const fixtures=process.env.SMS_UI_PREVIEW_DIR;
  try{
    for(const locale of ['fa','en','tr'])for(const width of [390,1280]){
      const page=await browser.newPage({viewport:{width,height:950}});const errors=[];
      page.on('pageerror',e=>errors.push(e.message));
      await page.route('**/*',async route=>{
        const url=new URL(route.request().url());
        const filename=url.pathname==='/sms.css'?'css/sms-resale.css':url.pathname==='/sms.js'?'js/sms-resale.js':null;
        if(filename)await route.fulfill({body:fs.readFileSync(path.join(source,filename)),contentType:filename.endsWith('.css')?'text/css':'application/javascript'});
        else await route.abort();
      });
      const html=fs.readFileSync(path.join(fixtures,locale+'.html'),'utf8');
      const component=html.match(/<main class="sms-product"[\s\S]*?<\/main>/)?.[0];assert(component,'actual Blade component missing');
      await page.setContent('<!doctype html><html lang="'+locale+'" dir="'+(locale==='fa'?'rtl':'ltr')+'"><head><meta charset="utf-8"><link rel="stylesheet" href="https://sms.test/sms.css"></head><body>'+component+'<script src="https://sms.test/sms.js"></script></body></html>');
      await page.locator('[data-sms-estimate][data-recommended="true"]').waitFor();
      assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'horizontal overflow '+locale+' '+width);
      await page.locator('[data-sms-text]').fill('س'.repeat(71));
      await page.locator('[data-sms-count]').fill('1000');
      assert((await page.locator('[data-sms-parts]').innerText()).match(/[2۲]/),'segmentation did not update');
      await page.locator('[data-sms-count]').fill('-1');assert(await page.locator('[data-sms-error]').innerText());
      await page.locator('[data-sms-count]').fill('2000');
      assert.equal(await page.locator('[data-sms-error]').innerText(),'');
      assert.deepEqual(errors,[]);
      await page.screenshot({path:path.join(fixtures,locale+'-'+width+'.png'),fullPage:true});
      await page.close();
    }
    console.log('PASS: six actual SMS Blade component layouts, calculator interaction and JavaScript runtime');
  }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
