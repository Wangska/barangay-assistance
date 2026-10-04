const { chromium } = require('playwright');
const assert = require('node:assert/strict');
(async () => {
 const browser = await chromium.launch({channel:'msedge', headless:true});
 try {
  const page = await browser.newPage();
  const errors=[]; page.on('pageerror',e=>errors.push(e.message));
  await page.goto('http://localhost:8080/barangay-assistance-system/register.php');
  assert.equal(await page.locator('#region option').count(),19);
  assert(await page.locator('#province').isDisabled());
  assert(await page.locator('#city_municipality').isDisabled());
  assert(await page.locator('#barangay').isDisabled());
  await page.selectOption('#region','Region IV-A (CALABARZON)');
  await page.selectOption('#province','Cavite');
  await page.selectOption('#city_municipality','City of Bacoor');
  await page.waitForFunction(() => {
    const field = document.querySelector('#barangay');
    return field && !field.disabled && field.options.length > 1;
  });
  await page.selectOption('#province','Laguna');
  await page.waitForFunction(() => document.querySelector('#barangay').disabled);
  assert.equal(await page.inputValue('#city_municipality'),'');
  assert.equal(await page.locator('#city_municipality option[value="City of Bacoor"]').count(),0);
  await page.selectOption('#region','National Capital Region (NCR)');
  assert.equal(await page.inputValue('#province'),'Metro Manila (no province)');
  assert.equal(await page.locator('#city_municipality option').count(),18);
  await page.selectOption('#city_municipality','City of Las Piñas');
  await page.selectOption('#region','Negros Island Region (NIR)');
  await page.selectOption('#province','Negros Oriental');
  assert(await page.locator('#city_municipality option').count()>1);
  await page.selectOption('#province','Independent cities (no province)');
  await page.selectOption('#city_municipality','City of Bacolod');
  await page.selectOption('#region','');
  assert(await page.locator('#province').isDisabled());
  assert(await page.locator('#city_municipality').isDisabled());
  assert(await page.locator('#barangay').isDisabled());
  await page.setViewportSize({width:390,height:844});
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
  assert.deepEqual(errors,[]);
  console.log('PASS: 18 regions, cascading selection/reset, NCR, accented names, NIR, independent cities, mobile width, no script errors.');
 } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exit(1);});
