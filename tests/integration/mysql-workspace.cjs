const fs = require('node:fs')
const net = require('node:net')
const os = require('node:os')
const path = require('node:path')
const { randomBytes } = require('node:crypto')
const { spawn, execFileSync } = require('node:child_process')

const [, , php, mysqld, ...extraArgs] = process.argv
const mysqladmin = mysqld && path.join(path.dirname(mysqld), 'mysqladmin.exe')
const quiet = { windowsHide: true, stdio: 'ignore' }
const wait = ms => new Promise(resolve => setTimeout(resolve, ms))

async function loopbackPort() {
  const listener = net.createServer()
  await new Promise((resolve, reject) => listener.once('error', reject).listen(0, '127.0.0.1', resolve))
  const port = listener.address().port
  await new Promise(resolve => listener.close(resolve))
  if (port < 49152) throw Error('A private high port is required for the synthetic server')
  return port
}

async function testMySql() {
  if (!php || !mysqld || !path.isAbsolute(php) || !path.isAbsolute(mysqld)) {
    throw Error('Pass absolute paths to php.exe and MySQL 8.0 mysqld.exe')
  }
  const replies = extraArgs.length === 1 && extraArgs[0] === '--wo-replies'
  if (extraArgs.length && !replies) throw Error('Only --wo-replies is supported')
  const runtime = fs.mkdtempSync(path.join(os.tmpdir(), 'eworeb-mysql-'))
  const datadir = path.join(runtime, 'data')
  const mysqlLog = path.join(runtime, 'mysql.log')
  const port = await loopbackPort()
  const rootPassword = randomBytes(24).toString('hex')
  const password = randomBytes(24).toString('hex')
  const options = path.join(runtime, 'admin.cnf')
  const initFile = path.join(runtime, 'init.sql')
  const addr = ['--protocol=TCP', '--host=127.0.0.1', '--port='+port, '--user=root']
  const admin = (...args) => execFileSync(mysqladmin, ['--defaults-file='+options, ...addr, ...args], quiet)
  fs.writeFileSync(options, '[client]\npassword='+rootPassword+'\n')
  fs.writeFileSync(initFile, "ALTER USER 'root'@'localhost' IDENTIFIED BY '"+rootPassword+"';\n")
  execFileSync(mysqld, ['--no-defaults','--initialize-insecure','--datadir='+datadir,'--log-error='+mysqlLog], quiet)
  const server = spawn(mysqld, ['--no-defaults','--datadir='+datadir, '--port='+port, '--bind-address=127.0.0.1',
    '--mysqlx=OFF','--skip-log-bin','--log-error='+mysqlLog,'--init-file='+initFile], quiet)
  try {
    let ready = false
    for (let tries = 0; tries < 60; tries++) {
      if (server.exitCode !== null) break
      try { admin('status'); ready = true; break } catch { await wait(200) }
    }
    if (!ready) throw Error('MySQL synthetic server failed to start; see '+mysqlLog)
    const sqlite = path.join(runtime, 'baseline.sqlite')
    const fixture = path.join(__dirname, 'mysql-fixture.php')
    const extensions = ['-n','-d','extension_dir='+path.join(path.dirname(php),'ext'),
      '-d','extension=sqlite3','-d','extension=mysqli']
    execFileSync(php, [...extensions,path.join(__dirname,'auth-http-fixture.php'),sqlite], { ...quiet })
    const settings = { port, datadir, password, replies }
    const env = { ...process.env }
    execFileSync(php, [...extensions,fixture,sqlite,'init'],
      { ...quiet, stdio:'inherit', env:{ ...env, EWO_HTTP_MYSQL_FIXTURE:JSON.stringify({ ...settings, rootPassword }) } })
    execFileSync(process.execPath, [path.join(__dirname,'auth-http.cjs'),php],
      { windowsHide:true, stdio:'inherit', env:{ ...env, EWO_REPLY_FIXTURE_ADMIN: replies ? rootPassword : "", EWO_HTTP_MYSQL_FIXTURE:JSON.stringify(settings) } })
  } finally {
    if (server.exitCode === null) {
      try { admin('shutdown') } catch { server.kill() }
    }
    for (const file of [options, initFile]) fs.unlinkSync(file)
  }
}

testMySql().catch(error => {
  console.error(error.message)
  process.exitCode = 1
})
