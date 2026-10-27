const {chromium}=require(process.env.PIVOT_PLAYWRIGHT_MODULE || '../.dev/browser/node_modules/playwright-core');
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.PIVOT_CHROMIUM || '/usr/bin/chromium',headless:true,args:['--no-sandbox']});
 const page=await browser.newPage({viewport:{width:1440,height:1100}});
 const errors=[]; page.on('pageerror',e=>errors.push(e.message));
 await page.goto('http://127.0.0.1:8081/?page=login');
 await page.screenshot({path:'.dev/test-output/login-desktop.png',fullPage:true});
 await page.getByLabel('Email address').fill('owner@example.test');
 await page.getByLabel('Password',{exact:true}).fill('TestOwner!12345');
 await page.getByRole('button',{name:'Sign in'}).click();
 await page.waitForURL('**/?page=dashboard');
 await page.screenshot({path:'.dev/test-output/dashboard-desktop.png',fullPage:true});
 for(const width of [1440,390]) {
  await page.setViewportSize({width,height:1000});
  for(const path of ['dashboard','applications','application&id=1','statistics','settings','team','queue','account']) {
   const r=await page.goto('http://127.0.0.1:8081/?page='+path);
   if(r.status()!==200) throw new Error(path+' status '+r.status());
   const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth);
   if(overflow) throw new Error(path+' overflows at '+width);
  }
 }
 await page.goto('http://127.0.0.1:8081/?page=application&id=1');
 await page.screenshot({path:'.dev/test-output/application-mobile.png',fullPage:true});
 if(errors.length) throw new Error(errors.join('\n'));
 console.log('PASS browser login, 16 desktop/mobile page layout checks, no page errors');
 await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});
