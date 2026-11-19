
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const { spawn } = require('node:child_process');
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

module.exports = async function browserSmoke(binary, runtime, origin) {
    const profile = path.join(runtime, 'browser');
    const chrome = spawn(binary, ['--headless=new','--disable-background-networking','--disable-gpu','--no-first-run',
        '--remote-debugging-port=0','--user-data-dir='+profile,'about:blank'], {windowsHide:true,stdio:'ignore'});
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
        const message=await send('Runtime.evaluate',{expression,returnByValue:true});
        if (message.exceptionDetails) throw Error(message.exceptionDetails.text);
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
            if (msg.method==='Log.entryAdded' && msg.params.entry.level==='error') errors.push(msg.params.entry.text);
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
        console.log('Chrome: login submitted, dashboard cards/CSS and mobile sidebar verified with no asset or JS error');
    } finally {
        if(socket?.readyState===WebSocket.OPEN) {
            await Promise.race([send('Browser.close'),sleep(1000)]).catch(()=>{});
            socket.close();
        }
        if(chrome.exitCode===null) chrome.kill();
    }
};
