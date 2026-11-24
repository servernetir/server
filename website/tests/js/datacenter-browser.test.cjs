const {test} = require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {chromium}=require('playwright');
const fixtures=process.env.DATACENTER_BROWSER_FIXTURES;
const publicRoot=path.resolve(__dirname,'../../public');
test('real localized Laravel planner and equipment hub fit mobile and desktop, and calculate interactively',{timeout:120000},async()=>{
  assert.ok(fixtures, 'Laravel fixtures required');
  const browser=await chromium.launch();
  try{
    for(const locale of ['fa','en','tr']){
      for(const width of [375,768,1366]){
        const page=await browser.newPage({viewport:{width,height:820}});
        await page.route('**/*',async route=>{
          const url=new URL(route.request().url());
          if(url.origin!=='http://localhost') return route.abort();
          if(route.request().isNavigationRequest()){
            const file=path.join(fixtures,locale+(url.pathname.endsWith('/planner')?'':'-hub')+'.html');
            return route.fulfill({contentType:'text/html; charset=utf-8',body:fs.readFileSync(file)});
          }
          const abs=path.resolve(publicRoot,'.'+decodeURIComponent(url.pathname));
          if(abs.startsWith(publicRoot+path.sep)&&fs.existsSync(abs)&&fs.statSync(abs).isFile())return route.fulfill({path:abs});
          return route.abort();
        });
        await page.goto('http://localhost/parts/planner',{waitUntil:'domcontentloaded'});
        const tool=page.locator('[data-calculator=raid]');
        await tool.locator('[name=drives]').fill('6');
        await tool.locator('[name=disk_tb]').fill('4');
        await tool.locator('[name=raid_mode]').selectOption('6');
        assert.ok((await tool.locator('[data-result=usable]').textContent()).trim()!=='—');
        assert.equal(await tool.locator('[data-error]').isVisible(),false);
        await tool.locator('[name=drives]').fill('2');
        assert.equal(await tool.locator('[data-error]').isVisible(),true);
        assert.equal((await tool.locator('[data-result=usable]').textContent()).trim(),'—');
        assert.ok(await page.locator('.dc-planner').evaluate(el=>el.scrollWidth<=el.clientWidth+2),locale+' '+width+' planner overflow');
        await page.goto('http://localhost/parts',{waitUntil:'domcontentloaded'});
        assert.equal(await page.locator('.dc-category').count(),5);
        assert.ok(await page.locator('.dc-showcase').evaluate(el=>el.scrollWidth<=el.clientWidth+2),locale+' '+width+' showcase overflow');
        await page.close();
      }
    }
  }finally{await browser.close();}
});
