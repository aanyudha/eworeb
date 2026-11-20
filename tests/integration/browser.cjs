
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const { spawn } = require('node:child_process');
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

module.exports = async function browserSmoke(binary, runtime, origin) {
    const mysql = JSON.parse(process.env.EWO_HTTP_MYSQL_FIXTURE ?? 'null');
    const profile = path.join(runtime, 'browser');
    const chrome = spawn(binary, ['--headless=new','--disable-background-networking','--disable-gpu','--no-first-run',
        '--remote-debugging-port=0','--user-data-dir='+profile,'about:blank'], {windowsHide:true,stdio:'ignore'});
    const prefix=new URL(origin).pathname.replace(/\/$/,'');
    let socket, seq = 0;
    const pending = new Map(), errors = [];
    const requestTimeout = 15000;
    async function until(fn) {
        for (let i=0; i<120; i++) {
            if (chrome.exitCode !== null || chrome.signalCode !== null || socket?.readyState === WebSocket.CLOSED) {
                throw Error('Browser exited before the smoke test completed');
            }
            try { const result=await fn(); if (result) return result; } catch {}
            await sleep(100);
        }
        throw Error('Browser condition timed out: '+JSON.stringify(errors));
    }
    function send(method, params={}) {
        return new Promise((resolve,reject)=>{
            const id=++seq;
            const timer=setTimeout(()=>{
                pending.delete(id);
                reject(Error('DevTools request timed out: '+method));
            },requestTimeout);
            pending.set(id,{resolve,reject,timer});
            socket.send(JSON.stringify({id,method,params}));
        });
    }
    async function evaluate(expression) {
        const relative=expression.replaceAll('location.pathname','location.pathname.slice('+prefix.length+')');
        const message=await send('Runtime.evaluate',{expression:relative,returnByValue:true});
        if (message.exceptionDetails) throw Error(message.exceptionDetails.exception?.description ?? message.exceptionDetails.text);
        return message.result.value;
    }
    try {
        const info=await until(()=>fs.readFile(path.join(profile,'DevToolsActivePort'),'utf8'));
        const targets=await until(async()=>await(await fetch('http://127.0.0.1:'+info.split(/\s+/)[0]+'/json', {signal:AbortSignal.timeout(1000)})).json());
        socket=new WebSocket(targets.find(t=>t.type==='page').webSocketDebuggerUrl);
        await new Promise((resolve,reject)=>{socket.onopen=resolve;socket.onerror=reject;});
        socket.onmessage=event=>{
            const msg=JSON.parse(event.data);
            if (msg.id) {
                const call=pending.get(msg.id);pending.delete(msg.id);
                if (!call) return;
                clearTimeout(call.timer);
                if(msg.error)call.reject(Error(msg.error.message));else call.resolve(msg.result);
            }
            if (msg.method==='Runtime.exceptionThrown') errors.push(msg.params.exceptionDetails.exception?.description ?? msg.params.exceptionDetails.text);
            if (msg.method==='Network.responseReceived' && msg.params.response.url.startsWith(origin)
                && msg.params.response.status>=400 && msg.params.type!=='Document') errors.push(msg.params.response.url);
            if (msg.method==='Log.entryAdded' && msg.params.entry.level==='error') errors.push([msg.params.entry.text,msg.params.entry.url]);
        };
        for (const domain of ['Runtime','Network','Page','Log']) await send(domain+'.enable');
        await send('Network.setBlockedURLs',{urls:['https://*']});
        await send('Emulation.setDeviceMetricsOverride',{width:1280,height:900,deviceScaleFactor:1,mobile:false});
        await send('Page.navigate',{url:origin+'/admin/login'});
        await until(()=>evaluate("document.readyState==='complete' && document.querySelector('h1')?.innerText==='Login'"));
        assert.equal(await evaluate("getComputedStyle(document.querySelector('.login-box')).width"),'360px');
        await evaluate("document.querySelector('#email').value='admin@example.test'; document.querySelector('#password').value='fixture-password'; document.querySelector('button[type=submit]').click()");
        await until(()=>evaluate("location.pathname==='/workspace' && document.querySelector('.kpi strong') !== null"));
        assert.equal(await evaluate("[...document.querySelectorAll('a')].some(a=>a.textContent==='Classic dashboard')"),false);
        const group = name => `document.querySelector('button.nav-group-toggle[aria-label="${name}"]')`;
        if (mysql?.create) {
            async function createInBrowser(status, title, summary) {
                await evaluate("document.querySelector('nav a[href$=\"/work-orders/create\"]').click()");
                await until(()=>evaluate("Boolean(document.querySelector('.create-wo-form button[type=submit]:not(:disabled)'))"));
                for (const [name, value] of [['corp_id','1'],['dept_id','10'],['location_id','1'],['sublocation_id','3']]) {
                    await until(()=>evaluate(`Boolean(document.querySelector('[name=${name}] option[value="${value}"]'))`));
                    await evaluate(`{const field = document.querySelector('[name=${name}]'); field.value = '${value}'; field.dispatchEvent(new Event('change', {bubbles:true}));}`);
                }
                await evaluate(`{const form = document.querySelector('.create-wo-form'); form.elements.role_id.selectedIndex=1; form.elements.title.value=${JSON.stringify(title)}; form.elements.summary.value=${JSON.stringify(summary)}; form.querySelector('button[value="${status}"]').click();}`);
                return until(()=>evaluate(String.raw`document.querySelector('.create-wo-form [role=status]')?.innerText.match(/Work Order #(\d+) ${status === 1 ? 'published' : 'saved as a draft'}/)?.[1]`));
            }
            assert.ok(await createInBrowser(0, 'Browser draft WO', ''));
            await send('Page.navigate',{url:origin+'/workspace/work-orders/drafts?q=Browser+draft+WO'});
            await until(()=>evaluate("document.querySelector('tbody')?.innerText.includes('Browser draft WO')"));
            const confirmation = await createInBrowser(1, 'Browser created WO', 'Browser initial history');
            await send('Page.navigate',{url:origin+'/workspace/work-orders/outgoing?q=Browser+created+WO'});
            await until(()=>evaluate("document.querySelector('tbody')?.innerText.includes('Browser created WO')"));
            await evaluate(`document.querySelector('tbody a[href$="/outgoing/${confirmation}"]').click()`);
            await until(()=>evaluate(`document.querySelector('h1')?.innerText==='Work Order #${confirmation}' && document.querySelector('tbody')?.innerText.includes('Browser initial history')`));
            console.log('Chrome: Create Draft with blank description, Publish, Outgoing and initial history PASS');
        } else {
            assert.ok(await evaluate("document.querySelector('[aria-disabled=true][role=link][aria-label^=Create]')?.textContent.includes('publication date')"));
        }
        await send('Page.navigate',{url:origin+'/admin'});
        await until(()=>evaluate("location.pathname==='/workspace' && document.querySelector('.kpi strong') !== null"));
        await send('Page.reload');
        await until(()=>evaluate("location.pathname==='/workspace' && document.readyState==='complete' && document.querySelector('.kpi strong') !== null"));
        console.log('Chrome: login goes directly to Workspace; /admin redirects; refresh keeps session');
        assert.equal(await evaluate("document.querySelectorAll('.kpi').length"),5);
        assert.equal(await evaluate("document.querySelectorAll('tbody tr').length"),10);
        if (mysql) {
            assert.deepEqual(await evaluate("[...document.querySelectorAll('.sidebar nav > a')].filter(a=>/Incoming WO|Outgoing WO/.test(a.innerText)).map(a=>a.getAttribute('href').split('/').slice(-2).join('/'))"),['work-orders/incoming','work-orders/outgoing']);
            await evaluate(`${group('Master Data')}.focus()`);
            assert.equal(await evaluate(`document.activeElement===${group('Master Data')}`),true);
            await send('Input.dispatchKeyEvent',{type:'rawKeyDown',key:' ',code:'Space',windowsVirtualKeyCode:32,nativeVirtualKeyCode:32});
            await send('Input.dispatchKeyEvent',{type:'keyUp',key:' ',code:'Space',windowsVirtualKeyCode:32,nativeVirtualKeyCode:32});
            await until(()=>evaluate(`${group('Master Data')}.getAttribute('aria-expanded')==='true' && location.pathname==='/workspace'`));
            await evaluate(`${group('Master Data')}.click(); document.querySelector('[aria-label="Toggle sidebar"]').click()`);
            await until(()=>evaluate("document.querySelector('.shell').classList.contains('compact')"));
            await evaluate(`${group('Configuration')}.click()`);
            await until(()=>evaluate(`${group('Configuration')}.getAttribute('aria-expanded')==='true' && !document.querySelector('.shell').classList.contains('compact')`));
            await evaluate("document.querySelector('[aria-label=\"Profile menu\"]').click()");
            assert.equal(await evaluate("document.querySelector('.topbar a[href$=\"/account/profile\"]').innerText"),'My Profile');
            assert.equal(await evaluate("document.querySelector('.sidebar a[href$=\"/account/profile\"]')"),null);
            await send('Page.navigate',{url:origin+'/workspace/directory/departments'});
            await until(()=>evaluate(`${group('Master Data')}?.getAttribute('aria-expanded')==='true' && document.querySelector('h1')?.innerText==='Departments'`));
            await send('Page.navigate',{url:origin+'/workspace'});
            await until(()=>evaluate("document.querySelector('h1')?.innerText==='Dashboard' && document.querySelector('.kpi strong') !== null"));
        }
        await evaluate("window.__spaDocument='same'; document.querySelector('.switches a[href$=\"/incoming\"]').click()");
        await until(()=>evaluate("location.pathname==='/workspace/incoming' && document.querySelector('h1').innerText==='Incoming WO'"));
        assert.equal(await evaluate("window.__spaDocument"),'same');
        await evaluate("history.back()");
        await until(()=>evaluate("location.pathname==='/workspace' && document.querySelector('h1').innerText==='Dashboard'"));
        await evaluate("history.forward()");
        await until(()=>evaluate("location.pathname==='/workspace/incoming' && document.querySelector('h1').innerText==='Incoming WO'"));
        assert.equal(await evaluate("window.__spaDocument"),'same');

        await send('Page.navigate',{url:origin+'/workspace/outgoing'});
        await until(()=>evaluate("document.readyState==='complete' && document.querySelector('h1')?.innerText==='Outgoing WO' && document.querySelector('.kpi strong') !== null"));
        await send('Page.reload');
        await until(()=>evaluate("document.readyState==='complete' && document.querySelector('h1')?.innerText==='Outgoing WO' && document.querySelector('.kpi strong') !== null"));
        await evaluate("document.querySelector('[name=corpnya]').value='1'; document.querySelector('[name=department]').value='20'; document.querySelector('.filter-panel button[type=submit]').click()");
        await until(()=>evaluate("location.search.includes('department=20') && document.querySelector('.kpi strong') ?.innerText==='3'"));
        await evaluate("document.querySelector('tbody .text-button').click()");
        assert.equal(await evaluate("document.querySelector('dialog[open] h2').innerText"),'Work order summary');
        await evaluate("document.querySelector('dialog button[aria-label=Close]').click()");
        await evaluate("document.querySelector('th button').click()");
        assert.equal(await evaluate("document.querySelector('th').getAttribute('aria-sort')"),'ascending');
        await evaluate("const input=document.querySelector('input[type=search]'); Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value').set.call(input,'no-match'); input.dispatchEvent(new Event('input',{bubbles:true}));");
        await until(()=>evaluate("document.querySelector('.empty')?.innerText.includes('No dashboard rows')"));

        for (const width of [820,390]) {
            await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:true});
            await until(()=>evaluate("matchMedia('(max-width: 860px)').matches && document.querySelector('.topbar button').getAttribute('aria-expanded')==='false' && document.documentElement.scrollWidth<=document.documentElement.clientWidth"));
            await evaluate("document.querySelector('button[aria-label=\"Toggle sidebar\"]').click()");
            await until(()=>evaluate("document.querySelector('.shell').classList.contains('mobile-open')"));
            if (mysql) {
                const name = width === 820 ? 'Master Data' : 'Configuration';
                await evaluate(`if (${group(name)}.getAttribute('aria-expanded')==='true') ${group(name)}.click()`);
                await until(()=>evaluate(`${group(name)}.getAttribute('aria-expanded')==='false'`));
                await evaluate(`${group(name)}.click()`);
                await until(()=>evaluate(`${group(name)}.getAttribute('aria-expanded')==='true' && document.querySelector('.shell').classList.contains('mobile-open')`));
                await evaluate(`${group(name)}.parentElement.querySelector('a').click()`);
                await until(()=>evaluate("!document.querySelector('.shell').classList.contains('mobile-open')"));
            } else await evaluate("document.querySelector('.scrim').click()");
        }
        if (mysql) console.log('Chrome: grouped sidebar, keyboard toggle, deep link, compact, tablet/mobile and account menu PASS');
        await send('Emulation.setDeviceMetricsOverride',{width:1280,height:900,deviceScaleFactor:1,mobile:false});
        if (mysql && !mysql.replies && !mysql.create) {
            await send('Page.navigate',{url:origin+'/workspace/work-orders/create'});
            await until(()=>evaluate("document.querySelector('.create-wo-form') && document.querySelector('h1')?.innerText==='Create Work Order'"));
            assert.match(await evaluate("document.querySelector('#create-wo-status').textContent"),/posts_logs\.published_at/);
            assert.equal(await evaluate("document.querySelector('nav a[href$=\"/work-orders/create\"]') === null && document.querySelector('.create-wo-form button:not(:disabled)') === null"),true);
            assert.equal(await evaluate("document.querySelector('[name=title]').maxLength === 500 && document.querySelector('[name=summary]').maxLength === 5000 && !document.querySelector('[name=summary]').required"),true);
            async function selectCreateField(name, value) {
                await evaluate(`{const field = document.querySelector('[name=${name}]'); field.value = '${value}'; field.dispatchEvent(new Event('change', {bubbles: true}));}`);
            }
            await selectCreateField('corp_id', '1');
            await until(()=>evaluate("document.querySelector('[name=dept_id]').textContent.includes('Engineering One')"));
            await selectCreateField('location_id', '1');
            await until(()=>evaluate("document.querySelector('[name=sublocation_id]').textContent.includes('Site One Room')"));
            assert.equal(await evaluate("document.querySelector('[name=sublocation_id]').options.length"),2);
            await selectCreateField('corp_id', '2');
            assert.equal(await evaluate("document.querySelector('[name=location_id]').value === '' && document.querySelector('[name=sublocation_id]').disabled && document.querySelector('[name=dept_id]').textContent.includes('Engineering Two')"),true);
            console.log('Chrome: Create WO preview scopes dependent choices without a save action');
            await evaluate("document.querySelector('nav a[href*=\"/work-orders/incoming\"]').click()");
            await until(()=>evaluate("document.querySelector('h1')?.innerText==='Incoming Work Orders' && document.querySelector('tbody')?.innerText.includes('Fixture WO')"));
            await evaluate("[...document.querySelectorAll('nav[aria-label=\"Work order pages\"] button')].find(b=>b.textContent==='Next').click()");
            await until(()=>evaluate("location.search.includes('page=2') && document.querySelector('tbody')?.innerText.includes('Fixture WO')"));
            await send('Page.reload');
            await until(()=>evaluate("document.readyState==='complete' && document.querySelector('tbody')?.innerText.includes('Fixture WO')"));
            await send('Page.navigate',{url:origin+'/workspace/work-orders/outgoing?q=does-not-match'});
            await until(()=>evaluate("document.querySelector('h1')?.innerText==='Outgoing Work Orders' && document.body.innerText.includes('No work orders match this page.')"));
            console.log('Chrome: live Work Order list, next page, subfolder refresh, and empty search passed');
            await evaluate("document.querySelector('nav a[href*=\"/work-orders/drafts\"]').click()");
            await until(()=>evaluate("document.querySelector('h1')?.innerText==='Draft Work Orders' && document.querySelectorAll('tbody tr').length===1"));
            await evaluate("document.querySelector('nav a[href*=\"/work-orders/scheduled\"]').click()");
            await until(()=>evaluate("document.querySelector('h1')?.innerText==='Scheduled Work Orders' && document.querySelector('tbody')?.innerText.includes('2026-02-01')"));
            console.log('Chrome: Drafts and Scheduled display fixture rows');

            await evaluate("document.querySelector('nav a[href*=\"/directory/sublocations\"]').click()");
            await until(()=>evaluate("document.querySelector('h1')?.innerText==='Sublocations' && document.querySelector('tbody')?.innerText.includes('Site One Room')"));
            await send('Page.reload');
            await until(()=>evaluate("document.readyState==='complete' && document.querySelector('tbody')?.innerText.includes('Site Two Room')"));
            console.log('Chrome: admin directory navigation and refresh passed');
            const downloadDirectory=path.join(runtime,'downloads');
            await fs.mkdir(downloadDirectory);
            await send('Browser.setDownloadBehavior',{behavior:'allow',downloadPath:downloadDirectory});
            await evaluate("document.querySelector('nav a[href*=\"/reports/incoming\"]').click()");
            await until(()=>evaluate("document.querySelector('h1')?.innerText==='Reports In' && document.querySelector('tbody')?.innerText.includes('Site One')"));
            await evaluate("[...document.querySelectorAll('button')].find(b=>b.textContent==='Excel').click()");
            const xlsx=await until(()=>fs.readFile(path.join(downloadDirectory,'Reports-In.xlsx')));
            const JSZip=require('../../frontend/node_modules/jszip');
            const spreadsheet=await JSZip.loadAsync(xlsx);
            const sheet=await spreadsheet.file('xl/worksheets/sheet1.xml').async('string');
            assert.equal([...sheet.matchAll(/<row\b/g)].length,16);
            assert.match(sheet,/<t xml:space="preserve">Work Order Title<\/t>/);
            assert.match(sheet,/<t xml:space="preserve">Fixture WO<\/t>/);
            assert.doesNotMatch(sheet,/<f>/);

            await evaluate("document.querySelector('nav a[href*=\"/reports/outgoing\"]').click()");
            await until(()=>evaluate("document.querySelector('h1')?.innerText==='Reports Out' && document.querySelector('tbody')?.innerText.includes('Site One')"));
            await evaluate("[...document.querySelectorAll('button')].find(b=>b.textContent==='PDF').click()");
            const pdf=await until(()=>fs.readFile(path.join(downloadDirectory,'Reports-Out.pdf')));
            assert.equal(pdf.subarray(0,5).toString(),'%PDF-');
            assert.match(pdf.toString('latin1'),/\/MediaBox \[0 0 1190\.\d+ 841\.\d+\]/);
            console.log('Chrome: Reports In XLSX sheet, Reports Out A3 PDF, and same-origin report assets passed');

        }

        await until(()=>evaluate("document.querySelector('nav a[href*=\"/workspace/knowledge\"]') !== null"));
        await evaluate("document.querySelector('nav a[href*=\"/workspace/knowledge\"]').click()");
        await until(()=>evaluate("document.querySelector('h1')?.innerText==='Knowledge Base' && document.querySelector('.knowledge-tabs')!==null"));
        await until(()=>evaluate("[...document.querySelectorAll('button')].some(b=>b.textContent.includes('New draft'))"));
        await evaluate("[...document.querySelectorAll('button')].find(b=>b.textContent.includes('New draft')).click()");
        await until(()=>evaluate("document.querySelector('.knowledge-form')!==null"));
        await evaluate("const form=document.querySelector('.knowledge-form'); form.elements.title.value='Browser editor fixture'; form.elements.slug.value='browser-editor-fixture'; form.elements.summary.value='Review this fixture draft.'; form.elements.resolution_steps.value='Record the test result.'; form.elements.visibility.value='public'; form.requestSubmit();");
        await until(()=>evaluate("document.querySelector('.knowledge-review h3')?.innerText==='Browser editor fixture'"));
        await evaluate("[...document.querySelectorAll('.knowledge-review button')].find(b=>b.textContent.trim()==='Publish').click()");
        await evaluate("[...document.querySelectorAll('.knowledge-confirm button')].find(b=>b.textContent.includes('Confirm publish')).click()");
        await until(()=>evaluate("document.querySelector('.knowledge-review .panel-head span')?.innerText.includes('published')"));
        await evaluate("[...document.querySelectorAll('.knowledge-review button')].find(b=>b.textContent.includes('Withdraw publication')).click()");
        await evaluate("[...document.querySelectorAll('.knowledge-confirm button')].find(b=>b.textContent.includes('Confirm withdraw')).click()");
        await until(()=>evaluate("document.querySelector('.knowledge-review .panel-head span')?.innerText.includes('draft')"));
        console.log('Chrome: admin draft, review, publish, and withdraw passed');
        if (JSON.parse(process.env.EWO_HTTP_MYSQL_FIXTURE ?? 'null')?.replies) {
            await send('Page.navigate',{url:origin+'/workspace/work-orders/incoming?role=3&corpnya=1'});
            const link=await until(()=>evaluate("document.querySelector('tbody a[href*=\"/work-orders/incoming/\"]')?.href"));
            await send('Page.navigate',{url:link});
            await until(()=>evaluate("document.querySelector('form [name=assign_to] option:nth-child(2)')!==null"));
            await evaluate("const form=document.querySelector('form:has([name=summary_edit])');form.elements.category_id.value='3';form.elements.assign_to.selectedIndex=1;form.elements.summary_edit.value='Verified browser reply';form.requestSubmit();");
            await until(()=>evaluate("document.body.innerText.includes('Reply saved.') && document.body.innerText.includes('Verified browser reply')"));
            console.log('Chrome: real Reply form saves and refreshes history on isolated MySQL');
            await send('Page.navigate',{url:origin+'/workspace/directory/corporations'});
            await until(()=>evaluate("[...document.querySelectorAll('button')].some(button=>button.textContent==='Add record')"));
            await evaluate("[...document.querySelectorAll('button')].find(button=>button.textContent==='Add record').click()");
            await until(()=>evaluate("document.querySelector('form [name=corp_code]')!==null"));
            await evaluate("const form=document.querySelector('form:has([name=corp_code])');form.elements.name.value='Browser fixture corporation';form.elements.corp_code.value='BROWSER';form.requestSubmit()");
            await until(()=>evaluate("document.querySelector('tbody')?.innerText.includes('Browser fixture corporation')"));
            await send('Page.reload');
            await until(()=>evaluate("document.querySelector('tbody')?.innerText.includes('Browser fixture corporation')"));
            console.log('Chrome: corporation form saves to isolated MySQL and persists after a direct refresh');
            await send('Page.navigate',{url:origin+'/workspace/account/profile'});
            await until(()=>evaluate("document.querySelector('h1')?.innerText==='My profile' && document.querySelector('dl')?.innerText.includes('admin@example.test')"));
            await send('Page.navigate',{url:origin+'/workspace/settings/application'});
            await until(()=>evaluate("document.querySelector('h1')?.innerText==='Application settings' && document.querySelector('[name=application_name]')!==null"));
            await send('Emulation.setDeviceMetricsOverride',{width:390,height:850,deviceScaleFactor:1,mobile:true});
            await until(()=>evaluate("document.documentElement.clientWidth===390 && document.documentElement.scrollWidth<=390"));
            await evaluate("const form=document.querySelector('form:has([name=application_name])');form.elements.application_name.value='Browser fixture settings';form.requestSubmit()");
            await until(()=>evaluate("document.querySelector('[role=status]')?.textContent==='Settings saved.'"));
            await send('Page.reload');
            await until(()=>evaluate("document.querySelector('[name=application_name]')?.value==='Browser fixture settings'"));
            await send('Emulation.setDeviceMetricsOverride',{width:1280,height:900,deviceScaleFactor:1,mobile:false});
            console.log('Chrome: self profile and mobile application settings save and refresh PASS');

            const downloadDirectory=path.join(runtime,'limited-reports');
            await fs.mkdir(downloadDirectory);
            await send('Browser.setDownloadBehavior',{behavior:'allow',downloadPath:downloadDirectory});
            for (const [direction,title,format] of [['incoming','Reports In','xlsx'],['outgoing','Reports Out','pdf']]) {
                await send('Page.navigate',{url:origin+'/workspace/reports/'+direction+'?show=15&q=Fixture'});
                await until(()=>evaluate("document.querySelector('h1')?.innerText==="+JSON.stringify(title)+" && document.querySelector('tbody tr')?.innerText.includes('Subcategory unavailable')"));
                assert.ok(await evaluate("document.querySelector('[role=status]')?.innerText.includes('current legacy schema')"));
                await evaluate("[...document.querySelectorAll('button')].find(b=>b.textContent==="+JSON.stringify(format==='xlsx'?'Excel':'PDF')+").click()");
                const data=await until(()=>fs.readFile(path.join(downloadDirectory,title.replaceAll(' ','-')+'.'+format)));
                if (format==='xlsx') {
                    const spreadsheet=await require('../../frontend/node_modules/jszip').loadAsync(data);
                    const sheet=await spreadsheet.file('xl/worksheets/sheet1.xml').async('string');
                    assert.match(sheet,/Subcategory unavailable/);
                    assert.match(sheet,/Fixture WO/);
                    assert.equal([...sheet.matchAll(/<row\b/g)].length,16);
                } else {
                    assert.equal(data.subarray(0,5).toString(),'%PDF-');
                    assert.match(data.toString('latin1'),/\/MediaBox \[0 0 1190\.\d+ 841\.\d+\]/);
                }
            }
            await send('Page.navigate',{url:origin+'/workspace/settings/visual'});
            await until(()=>evaluate("document.querySelector('h1')?.innerText==='Visual settings' && document.querySelector('[name=logo]')!==null && document.querySelector('[name=favicon]')!==null"));
            const {root}=await send('DOM.getDocument');
            const {nodeId}=await send('DOM.querySelector',{nodeId:root.nodeId,selector:'input[name=favicon]'});
            await send('DOM.setFileInputFiles',{nodeId,files:[path.join(runtime,'wo-media','images','fixture.png')]});
            await evaluate("document.querySelector('form:has([name=favicon])').requestSubmit()");
            await until(()=>evaluate("document.querySelector('[role=status]')?.textContent==='Favicon updated.' && document.querySelector('img[alt=Favicon]')?.naturalWidth===1"));
            await send('Page.reload');
            await until(()=>evaluate("document.querySelector('link[rel=icon]')?.href.includes('/brand/favicon') && document.querySelector('img[alt=Favicon]')?.naturalWidth===1"));
            console.log('Chrome: exact-schema Reports In/Out, XLSX limitation content, PDF A3 and favicon upload/refresh PASS');
        }

        await evaluate("document.querySelector('summary[aria-label=\"Profile menu\"]').click(); [...document.querySelectorAll('.popover button')].find(b=>b.textContent.includes('Sign out')).click()");
        assert.equal(await evaluate("document.querySelector('dialog h2').innerText"),'Sign out?');
        await evaluate("document.querySelector('dialog button.primary').click()");
        await until(()=>evaluate("location.pathname==='/admin/login' && document.querySelector('h1')?.innerText==='Login'"));
        assert.deepEqual(errors,[]);
        console.log('Chrome: React dashboard, search, sort, drawer, filters, SPA history, deep link, refresh, tablet/mobile, and logout passed');
        await send('Page.navigate',{url:origin+'/'});
        await until(()=>evaluate("document.querySelector('h1')?.innerText==='How can we help you?' && document.querySelectorAll('.kb-category').length===4 && document.querySelectorAll('.kb-result').length===2"));
        await evaluate("window.__kbDocument='same'; const field=document.querySelector('.kb-search input'); Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value').set.call(field,'patch-cable'); field.dispatchEvent(new Event('input',{bubbles:true}));");
        await evaluate("document.querySelector('.kb-search').requestSubmit()");
        await until(()=>evaluate("location.search.includes('q=patch-cable') && document.querySelectorAll('.kb-result').length===1"));
        assert.equal(await evaluate("window.__kbDocument"),'same');
        await evaluate("document.querySelector('.kb-result').click()");
        await until(()=>evaluate("document.querySelector('h1')?.innerText==='Fixture network guide'"));
        assert.ok(await evaluate("document.querySelector('.kb-article-head p').innerText.includes('<em>plain text</em>') && !document.querySelector('.kb-article-head p em')"));
        assert.equal(await evaluate("document.querySelectorAll('.kb-related .kb-result').length"),1);
        await evaluate("history.back()");
        await until(()=>evaluate("location.search.includes('q=patch-cable') && document.querySelector('h1')?.innerText==='Knowledge Base'"));
        await evaluate("history.forward()");
        await until(()=>evaluate("document.querySelector('h1')?.innerText==='Fixture network guide'"));
        await send('Page.reload');
        await until(()=>evaluate("document.readyState==='complete' && document.querySelector('h1')?.innerText==='Fixture network guide'"));
        await send('Page.navigate',{url:origin+'/kb?category=engineering'});
        await until(()=>evaluate("document.querySelector('h1')?.innerText==='Knowledge Base' && document.querySelectorAll('.kb-result').length===1"));
        assert.equal(await evaluate("document.querySelector('.kb-result h3').innerText"),'Related fixture');
        await send('Page.navigate',{url:origin+'/kb?q=unmatched-fixture-key'});
        await until(()=>evaluate("document.querySelector('.kb-status h2')?.innerText==='No matching solutions'"));
        for(const width of [1280,820,390]) {
            await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<900});
            await until(()=>evaluate("document.documentElement.clientWidth==="+width+" && document.documentElement.scrollWidth<=document.documentElement.clientWidth"));
        }
        assert.deepEqual(errors,[]);
        console.log('Chrome: guest homepage, search, category filtering, article detail, safe text, history, refresh, and three viewports passed');
        if (JSON.parse(process.env.EWO_HTTP_MYSQL_FIXTURE ?? 'null')?.replies) {
            await send('Emulation.setDeviceMetricsOverride',{width:1280,height:900,deviceScaleFactor:1,mobile:false});
            await send('Page.navigate',{url:origin+'/admin/login'});
            await until(()=>evaluate("document.querySelector('h1')?.innerText==='Login'"));
            await evaluate("document.querySelector('#email').value='employee@example.test';document.querySelector('#password').value='fixture-password';document.querySelector('button[type=submit]').click()");
            await until(()=>evaluate("location.pathname==='/workspace' && document.querySelector('h1')?.innerText==='Dashboard'"));
            assert.equal(await evaluate("Boolean(document.querySelector('nav a[href$=\"/directory/categories\"]'))"),false);
            assert.equal(await evaluate("Boolean(document.querySelector('nav a[href$=\"/settings/application\"]'))"),false);
            await send('Page.navigate',{url:origin+'/workspace/directory/categories'});
            await until(()=>evaluate("document.body.innerText.includes('This workspace page does not exist.')"));
            await send('Page.reload');
            await until(()=>evaluate("document.body.innerText.includes('This workspace page does not exist.')"));
            assert.deepEqual(errors,[]);
            console.log('Chrome: unprivileged role has neither protected links nor access by direct refresh PASS');
        }
    } finally {
        if(socket?.readyState===WebSocket.OPEN) {
            await Promise.race([send('Browser.close'),sleep(1000)]).catch(()=>{});
            socket.close();
        }
        for (const call of pending.values()) clearTimeout(call.timer);
        if(chrome.exitCode===null) chrome.kill();
    }
};
