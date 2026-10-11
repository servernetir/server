// Sequential, cold-profile mobile runs. Preserve every result, including failures.
const {spawnSync}=require('child_process'),fs=require('fs'),path=require('path');
const root=path.resolve(__dirname,'..');
const cli=path.join(root,'output/seo-analytics-start-20261010/lighthouse/node_modules/lighthouse/cli/index.js');
const out=path.join(root,'output/seo-country-lcp-20261011');
const count=Number(process.argv[2]||5),prefix=process.argv[3]||'after';
const method=process.argv[4]||'simulate';
if(!['simulate','devtools'].includes(method))throw new Error('Unknown throttling method');
const results=[];
for(let run=1;run<=count;run++)for(const [city,slug] of [['dubai','ae-dubai'],['manila','ph-manila']]){
 const file=path.join(out,`${city}-${prefix}-${run}.report.json`);
 if(fs.existsSync(file))throw new Error('Result already exists; inspect instead of replacing: '+file);
 const r=spawnSync(process.execPath,[cli,'https://servernet.cloud/cloud/'+slug,
 '--only-categories=performance,seo','--chrome-flags=--headless=new --no-sandbox --disable-dev-shm-usage',
 '--throttling-method='+method,
 '--output=json','--output-path='+file,'--save-assets','--quiet'],{env:{...process.env,CHROME_PATH:'C:/Program Files/Google/Chrome/Application/chrome.exe'},encoding:'utf8',timeout:180000});
 if(r.status!==0)throw new Error(r.stderr||'Lighthouse failed');
 const report=JSON.parse(fs.readFileSync(file,'utf8')),a=report.audits;
 const metric={city,run,throttlingMethod:method,fetchTime:report.fetchTime,LCP:a['largest-contentful-paint'].numericValue,TBT:a['total-blocking-time'].numericValue,CLS:a['cumulative-layout-shift'].numericValue,FCP:a['first-contentful-paint'].numericValue,performance:report.categories.performance.score,seo:report.categories.seo.score};
 results.push(metric);fs.writeFileSync(path.join(out,`${prefix}-metrics.json`),JSON.stringify(results,null,2));console.log(JSON.stringify(metric));
}
