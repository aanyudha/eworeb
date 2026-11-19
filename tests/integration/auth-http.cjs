
const assert=require('node:assert/strict');
const fs=require('node:fs');
const os=require('node:os');
const net=require('node:net');
const path=require('node:path');
const {spawn,execFileSync}=require('node:child_process');
const target=path.resolve(__dirname,'..','..'), php=process.argv[2];
if (!php) throw Error('Specify the absolute path to a PHP executable');
const ext=path.join(path.dirname(php),'ext');
const runtime=fs.mkdtempSync(path.join(os.tmpdir(),'eworeb-http-test-'));
const db=path.join(runtime,'auth.sqlite');
const sessionName='eworeb_fixture_'+process.pid;
const flags=['-n','-d','extension_dir='+ext,'-d','extension=intl','-d','extension=mbstring','-d','extension=sqlite3'];
const fixture=path.join(__dirname,'auth-http-fixture.php');
const phpRun=(args)=>execFileSync(php,[...flags,...args],{cwd:target,encoding:'utf8'});
let server, output='';
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
(async()=>{
  phpRun([fixture,db]);
  const before=phpRun([fixture,db,'snapshot']);
  assert.match(before,/^[a-f0-9]{64}$/);
  const probe=net.createServer();
  await new Promise((resolve,reject)=>probe.once('error',reject).listen(0,'127.0.0.1',resolve));
  const addr='127.0.0.1:'+probe.address().port;
  await new Promise(resolve=>probe.close(resolve));
  const origin='http://'+addr;
  const fixtureEnv=[
    'CI_ENVIRONMENT = production', 'app.baseURL = '+origin+'/',
    'database.ewo.database = "'+db+'"',
    'database.ewo.username = fixture', 'database.ewo.DBDriver = SQLite3',
    'ewo.sessionKey = synthetic-http-key', 'session.cookieName = '+sessionName,
  ].join('\n');
  fs.writeFileSync(path.join(runtime,'.env'), fixtureEnv);
  const dotenv=path.join(target,'vendor/codeigniter4/framework/system/Config/DotEnv.php').replaceAll('\\','/');
  const prepend=path.join(runtime,'load-fixture.php');
  fs.writeFileSync(prepend, '<?php require '+JSON.stringify(dotenv)+'; (new CodeIgniter\\Config\\DotEnv(__DIR__))->load();');
  fs.writeFileSync(path.join(runtime,'php.ini'), [
    'extension_dir="'+ext+'"','extension=intl','extension=mbstring','extension=sqlite3',
    'auto_prepend_file="'+prepend+'"',
  ].join('\n'));
  const env={...process.env,PHPRC:runtime,PHP_INI_SCAN_DIR:''};
  for (const key of Object.keys(env)) {
    if (/^(?:CI_ENVIRONMENT|app[._]baseURL|database[._]ewo[._]|ewo[._]sessionKey|session[._]cookieName)/i.test(key)) delete env[key];
  }
  server=spawn(php,['spark','serve','--host','127.0.0.1','--port',addr.split(':')[1]],{cwd:target,env,windowsHide:true});
  for(const stream of [server.stderr,server.stdout]) stream.on('data',d=>{output+=d;});
  let ok=false;
  for(let i=0;i<35;i++){
    try {await fetch(origin+'/admin/login');ok=true;break;} catch {await sleep(100);}
  }
  assert.ok(ok,'isolated HTTP listener started');
  const cookies=new Map();
  async function request(route,params) {
    const headers=new Headers();
    headers.set('Cookie',[...cookies].map(([k,v])=>k+'='+v).join('; '));
    const res=await fetch(origin+route,{headers,body:params?new URLSearchParams(params):undefined,
      method:params?'POST':'GET',redirect:'manual'});
    for(const c of res.headers.getSetCookie()) {
      const [token]=c.split(';');
      const i=token.indexOf('=');
      if(token.slice(i+1)) cookies.set(token.slice(0,i),token.slice(i+1));else cookies.delete(token.slice(0,i));
    }
    return res;
  }
  const login=async(email,password)=>{
    const res=await request('/admin/login');
    assert.equal(res.status,200);
    const html=await res.text();
    assert.match(html,/Synthetic EWO/);
    const token=html.match(/name="a2n_csrf_token" value="([^"]+)"/);
    assert.ok(token,'login CSRF token');
    return request('/auth_controller/admin_login_post',{email,password,a2n_csrf_token:token[1]});
  };
  const location=(res)=>new URL(res.headers.get('location'),origin).pathname;
  assert.equal(location(await request('/admin')),'/admin/login');
  const css=await request('/assets/admin/css/AdminLTE.min.css');
  assert.equal(css.status,200);
  const blocked=await request('/auth_controller/admin_login_post',{email:'admin@example.test',password:'fixture-password'});
  assert.ok(blocked.status===403||blocked.status===302||blocked.status===303);
  assert.notEqual(location(blocked),'/admin');
  assert.equal(location(await login('admin@example.test','wrong')),'/admin/login');
  assert.match(await (await request('/admin/login')).text(),/Wrong username or password/);
  assert.equal(location(await login('blocked@example.test','fixture-password')),'/admin/login');
  assert.match(await (await request('/admin/login')).text(),/Your account has been banned/);
  assert.equal(location(await login('staff@example.test','fixture-password')),'/admin');
  assert.equal((await request('/admin')).status,403);
  assert.equal((await request('/')).status,403);
  await request('/logout');
  assert.equal(location(await login('admin@example.test','fixture-password')),'/admin');
  const dashboard=await request('/admin');
  assert.equal(dashboard.status,200);
  assert.match(await dashboard.text(),/Incoming Work Order/);
  for (const asset of ['admin/css/dashboard.css','admin/js/jquery.min.js','vendor/bootstrap/js/bootstrap.min.js','admin/js/adminlte.min.js']) {
    assert.equal((await request('/assets/'+asset)).status,200);
  }
  assert.equal(location(await request('/logout')),'/admin/login');
  assert.equal(location(await request('/admin')),'/admin/login');
  console.log(phpRun([fixture,db,'check']));
  const scoped=await login('other@example.test','fixture-password');
  assert.equal(location(scoped),'/admin');
  assert.equal((await request('/admin')).status,403);
  await request('/logout');
  if (process.env.EWO_LEGACY_SOURCE) {
    const scenarios=JSON.parse(phpRun(['-d','error_reporting=22527',path.join(__dirname,'legacy-dashboard.php'),process.env.EWO_LEGACY_SOURCE,db]));
    for (const [account,query,expected] of scenarios) {
      assert.equal(location(await login(account+'@example.test','fixture-password')),'/admin');
      const response=await request('/admin'+(query?'?'+query:''));
      assert.equal(response.status,200);
      const rendered={};
      const html=await response.text();
      for (const section of html.matchAll(/<section [^>]*id="(incoming|outgoing|recap)"[^>]*>([\s\S]*?)<\/section>/g)) {
        rendered[section[1]]={};
        for (const group of section[2].matchAll(/data-group="([^"]*)"[^>]*>([\s\S]*?)<\/dl>/g)) {
          rendered[section[1]][group[1]]=Object.fromEntries([...group[2].matchAll(/<dt>(\w+)<\/dt>\s*<dd>(\d+)<\/dd>/g)].map(m=>[m[1],Number(m[2])]));
        }
      }
      assert.deepEqual(rendered,expected,account+' dashboard '+query);
      await request('/logout');
    }
    console.log('Dashboard parity: '+scenarios.length+' CI3 scenarios on identical synthetic rows');
  }
  if (process.env.EWO_TEST_BROWSER) await require('./browser.cjs')(process.env.EWO_TEST_BROWSER,runtime,origin);
  assert.equal(phpRun([fixture,db,'snapshot']),before,'fixture Work Order rows remain identical');
  console.log('HTTP: guest, CSRF, login, invalid password, ban, permission, activity, logout PASS');
})().catch(error=>{console.error(error);console.error(output);process.exitCode=1;}).finally(()=>{
  if(server?.pid && server.exitCode===null) {
    if(process.platform==='win32') spawn('taskkill.exe',['/PID',String(server.pid),'/T','/F'],{windowsHide:true,stdio:'ignore'});
    else server.kill();
  }
});
