const {chromium}=require('playwright');
const fs=require('node:fs');
const assert=require('node:assert/strict');
(async()=>{
 const preview=JSON.parse(fs.readFileSync('outputs/capstone_preview.json','utf8'));
 const browser=await chromium.launch({channel:'msedge',headless:true});
 try {
 for(const [role,email] of [['admin','admin@example.invalid'],['staff','staff@example.invalid'],['senior',preview.email]]){
  const context=await browser.newContext({viewport:{width:1440,height:1050}});
  const page=await context.newPage();
  const errors=[];page.on('pageerror',error=>errors.push(error.message));
  await page.goto(preview.url+'/login.php');
  await page.locator('input[name=email]').fill(email);
  await page.locator('input[name=password]').fill(preview.password);
  await Promise.all([page.waitForURL('**/'+role+'/dashboard.php'),page.locator('button[type=submit]').click()]);
  await page.locator('.dash-welcome').waitFor();
  await page.evaluate(()=>document.fonts.ready);
  assert.equal(await page.locator('.dash-metric').count(),4);
  assert.equal(await page.locator('.dashboard h1').count(),1);
  if(role!=='senior')assert.equal(await page.locator('.chart-column').count(),7);
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
  await page.screenshot({path:'outputs/dashboard-'+role+'.png',fullPage:true});
  await page.setViewportSize({width:390,height:844});
  if(role==='senior')await page.locator('#larger-text').click();
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),role+' mobile overflow');
  await page.screenshot({path:'outputs/dashboard-'+role+'-mobile.png',fullPage:true});
  await page.locator('.dash-request').first().click();
  assert(await page.locator('h1').filter({hasText:'Application tracking'}).isVisible());
  assert.deepEqual(errors,[]);
  console.log('PASS '+role+': desktop/mobile layout, four metric cards, chart data, and request detail navigation.');
  await context.close();
 }
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});