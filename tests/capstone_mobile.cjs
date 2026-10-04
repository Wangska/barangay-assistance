const {chromium}=require('playwright');
const fs=require('node:fs');
const assert=require('node:assert/strict');
(async()=>{
 const p=JSON.parse(fs.readFileSync('outputs/capstone_preview.json','utf8'));
 const b=await chromium.launch({channel:'msedge',headless:true});
 try{
 const page=await b.newPage({viewport:{width:390,height:844}});
 await page.goto(p.url+'/login.php');
 await page.locator('input[name=email]').fill(p.email);
 await page.locator('input[name=password]').fill(p.password);
 await Promise.all([page.waitForURL('**/senior/dashboard.php'),page.locator('button[type=submit]').click()]);
 await page.goto(p.url+'/senior/application.php');
 assert.equal(await page.locator('#main-navigation').isVisible(),false);
 await page.locator('.mobile-navigation-toggle').click();
 assert.equal(await page.locator('#main-navigation').isVisible(),true);
 await page.locator('.mobile-navigation-toggle').click();
 assert.equal(await page.locator('#main-navigation').isVisible(),false);
 await page.locator('#interface-language').selectOption('ceb');
 await page.locator('#larger-text').click();
 assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
 await page.screenshot({path:'outputs/application-mobile.png',fullPage:true});
 console.log('PASS: mobile menu opens/closes; larger Cebuano layout has no horizontal overflow.');
 } finally{await b.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});