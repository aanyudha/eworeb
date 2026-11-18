
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
  const probe=net.createServer();
  await new Promise((resolve,reject)=>probe.once('error',reject).listen(0,'127.0.0.1',resolve));
  const addr='127.0.0.1:'+probe.address().port;
  await new Promise(resolve=>probe.close(resolve));
  const origin='http://'+addr;
  const env={...process.env,CI_ENVIRONMENT:'production','app.baseURL':origin+'/',
    'database.ewo.database':db,'database.ewo.username':'fixture','database.ewo.DBDriver':'SQLite3',
    'ewo.sessionKey':'synthetic-http-key','session.cookieName':sessionName};
  server=spawn(php,[...flags,'-S',addr,'-t','public'],{cwd:target,env,windowsHide:true});
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
  assert.equal(location(await request('/admin')),'/');
  await request('/logout');
  assert.equal(location(await login('admin@example.test','fixture-password')),'/admin');
  assert.equal((await request('/admin')).status,501);
  assert.equal(location(await request('/logout')),'/admin/login');
  assert.equal(location(await request('/admin')),'/admin/login');
  console.log(phpRun([fixture,db,'check']));
  if (process.env.EWO_TEST_BROWSER) {
    const { stdout, stderr, status }=require('node:child_process').spawnSync(process.env.EWO_TEST_BROWSER,[
      '--headless=new','--disable-background-networking','--disable-gpu','--no-first-run',
      '--user-data-dir='+path.join(runtime,'browser'),'--dump-dom',origin+'/admin/login'
    ],{encoding:'utf8',timeout:15000,windowsHide:true});
    assert.equal(status,0,stderr);
    assert.match(stdout,/<h1[^>]*>Login<\/h1>/);
    assert.match(stdout,/name="a2n_csrf_token"/);
    console.log('Browser: login heading and CSRF form visible in rendered DOM');
  }
  console.log('HTTP: guest, CSRF, login, invalid password, ban, permission, activity, logout PASS');
})().catch(error=>{console.error(error);console.error(output);process.exitCode=1;}).finally(()=>{
  if(server?.exitCode===null) server.kill();
});
