
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const { spawn } = require('node:child_process');
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

module.exports = async function browserSmoke(binary, runtime, origin) {
    const profile = path.join(runtime, 'browser');
    const chrome = spawn(binary, ['--headless=new','--disable-background-networking','--disable-gpu','--no-first-run',
        '--remote-debugging-port=0','--user-data-dir='+profile,'about:blank'], {windowsHide:true,stdio:'ignore'});
    const prefix=new URL(origin).pathname.replace(/\/$/,'');
    let socket, seq = 0;
    const pending = new Map(), errors = [];
    async function until(fn) {
        for (let i=0; i<120; i++) {
            try { const result=await fn(); if (result) return result; } catch {}
            await sleep(100);
        }
        throw Error('Browser condition timed out: '+JSON.stringify(errors));
    }
    function send(method, params={}) {
        return new Promise((resolve,reject)=>{
            const id=++seq;
            pending.set(id,{resolve,reject});
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
        const targets=await until(async()=>await(await fetch('http://127.0.0.1:'+info.split(/\s+/)[0]+'/json')).json());
        socket=new WebSocket(targets.find(t=>t.type==='page').webSocketDebuggerUrl);
        await new Promise((resolve,reject)=>{socket.onopen=resolve;socket.onerror=reject;});
        socket.onmessage=event=>{
            const msg=JSON.parse(event.data);
            if (msg.id) {
                const call=pending.get(msg.id);pending.delete(msg.id);
                if(msg.error)call.reject(Error(msg.error.message));else call.resolve(msg.result);
            }
            if (msg.method==='Runtime.exceptionThrown') errors.push(msg.params.exceptionDetails.text);
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
        await until(()=>evaluate("location.pathname==='/admin' && document.readyState==='complete' && document.querySelector('h1')?.innerText==='Dashboard'"));
        const desktop=await evaluate("({cards:document.querySelectorAll('.dashboard-stats dd').length,grid:getComputedStyle(document.querySelector('.dashboard-stats')).display,jquery:typeof jQuery})");
        assert.deepEqual(desktop,{cards:10,grid:'grid',jquery:'function'});
        await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
        await sleep(450);
        const layout=await evaluate("({viewport:document.documentElement.clientWidth,scrollWidth:document.documentElement.scrollWidth,overflow:[...document.querySelectorAll('body *')].filter(e=>e.getBoundingClientRect().right>document.documentElement.clientWidth+1).slice(0,5).map(e=>[e.tagName,e.className,e.getBoundingClientRect().right])})");
        assert.ok(layout.scrollWidth<=layout.viewport,JSON.stringify(layout));
        await evaluate("document.querySelector('.sidebar-toggle').click()");
        await until(()=>evaluate("document.body.classList.contains('sidebar-open')"));

        assert.deepEqual(errors,[]);
        console.log('Chrome: classic dashboard smoke passed');
        await send('Emulation.setDeviceMetricsOverride',{width:1280,height:900,deviceScaleFactor:1,mobile:false});
        await evaluate("[...document.querySelectorAll('a')].find(a=>a.textContent==='React workspace').click()");
        await until(()=>evaluate("location.pathname==='/workspace' && document.querySelector('.kpi strong') !== null"));
        assert.equal(await evaluate("document.querySelectorAll('.kpi').length"),5);
        assert.equal(await evaluate("document.querySelectorAll('tbody tr').length"),10);
        await evaluate("window.__spaDocument='same'; document.querySelector('nav[aria-label=\"Workspace navigation\"] a[href$=\"/incoming\"]').click()");
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
            await evaluate("document.querySelector('.scrim').click()");
        }
        await send('Emulation.setDeviceMetricsOverride',{width:1280,height:900,deviceScaleFactor:1,mobile:false});
        await evaluate("[...document.querySelectorAll('nav a')].find(a=>a.textContent==='Knowledge Base').click()");
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
    } finally {
        if(socket?.readyState===WebSocket.OPEN) {
            await Promise.race([send('Browser.close'),sleep(1000)]).catch(()=>{});
            socket.close();
        }
        if(chrome.exitCode===null) chrome.kill();
    }
};
