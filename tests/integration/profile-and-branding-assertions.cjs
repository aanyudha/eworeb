const assert = require('node:assert/strict')
const { readdirSync } = require('node:fs')

module.exports = async function (request, login, location, audit, profileDir) {
  const profile = '/api/v1/account/profile'
  const visuals = '/api/v1/settings/visual'
  const xhr = { 'X-Requested-With': 'XMLHttpRequest' }
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGNQcEgAAAFEAMELjZZCAAAAAElFTkSuQmCC', 'base64')

  async function submit(optionsPath, target, fields, expected = 200) {
    const {csrf} = await (await request(optionsPath)).json()
    const response = await request(target, fields, {headers: {...xhr, [csrf.header]: csrf.hash}})
    assert.equal(response.status, expected, await response.clone().text())
  }
  function image(field, bytes, name) {
    const form = new FormData()
    form.set(field, new Blob([bytes]), name)
    return form
  }

  await request('/logout')
  assert.equal((await request(profile + '/avatar')).status, 401)
  assert.equal((await request(visuals)).status, 401)
  assert.equal(location(await login('admin@example.test', 'fixture-password')), '/workspace')
  const before = (await (await request(visuals)).json()).items
  assert.equal(before.logo.url, null)
  const logo = image('logo', png, 'fixture.png')
  assert.equal((await request(visuals + '/logo', logo, {headers: xhr})).status, 403)
  await submit(visuals, visuals + '/logo', image('logo', '<svg></svg>', 'fixture.png'), 422)
  await submit(visuals, visuals + '/logo', logo)
  await submit(visuals, visuals + '/favicon', image('favicon', png, 'fixture.png'))
  const after = (await (await request(visuals)).json()).items
  assert.ok(after.logo.url && after.favicon.url)
  assert.match(await (await request('/workspace')).text(), /data-brand-logo=/)
  await request('/logout')
  const publicAsset = await request('/brand/logo')
  assert.equal(publicAsset.status, 200)
  assert.match(publicAsset.headers.get('content-type'), /^image\/png(?:;|$)/)
  assert.deepEqual(Buffer.from(await publicAsset.arrayBuffer()), png)
  assert.equal((await request('/brand/avatar')).status, 404)

  assert.equal(location(await login('employee@example.test', 'fixture-password')), '/workspace')
  assert.equal((await request(visuals)).status, 403)
  assert.equal((await request(profile + '/avatar')).status, 404)
  const options = await (await request(profile)).json()
  assert.equal(options.email_editable, true)
  assert.equal(options.avatar_editable, true)
  const newEmail = 'fixture-changed-email@example.test'
  assert.equal((await request(profile + '/email', {email: newEmail, old_password: 'fixture-password'}, {headers: xhr})).status, 403)
  await submit(profile, profile + '/email', {email: newEmail, old_password: 'incorrect'}, 422)
  await submit(profile, profile + '/email', {email: 'admin@example.test', old_password: 'fixture-password'}, 409)
  await submit(profile, profile + '/email', {email: newEmail, old_password: 'fixture-password', dept_id: '20', id: '1'})
  const current = (await (await request(profile)).json()).values
  assert.equal(current.email, newEmail)
  assert.equal(String(current.dept_id), String(options.values.dept_id))
  await request('/logout')
  assert.equal(location(await login(newEmail, 'fixture-password')), '/workspace')
  await submit(profile, profile + '/avatar', image('avatar', 'broken', 'avatar.jpg'), 422)
  await submit(profile, profile + '/avatar', image('avatar', png, 'avatar.png'))
  const avatar = await request(profile + '/avatar')
  assert.equal(avatar.status, 200)
  assert.match(avatar.headers.get('content-type'), /^image\/jpeg(?:;|$)/)
  assert.match(avatar.headers.get('cache-control'), /no-store/)
  const jpeg = Buffer.from(await avatar.arrayBuffer())
  assert.deepEqual([...jpeg.subarray(0, 2)], [255, 216])
  const files = readdirSync(profileDir).sort()
  audit('refuse-avatar')
  await submit(profile, profile + '/avatar', image('avatar', png, 'avatar.png'), 503)
  assert.deepEqual(readdirSync(profileDir).sort(), files)
  audit('require-email-verification')
  assert.equal((await (await request(profile)).json()).email_editable, false)
  await submit(profile, profile + '/email', {email: 'fixture-unapproved@example.test', old_password: 'fixture-password'}, 409)
  await request('/logout')
  assert.equal(location(await login('engineering@example.test', 'fixture-password')), '/workspace')
  assert.equal((await request(profile + '/avatar?id=4')).status, 404)
  await request('/logout')
  console.log('Profile and branding: owner scope, activation guard, CSRF, MIME, persisted assets and avatar failure cleanup PASS')
}
