const assert = require('node:assert/strict')

module.exports = async function masterAndAccountAssertions(request, login, location, audit) {
  const base = '/api/v1/admin-directory/'
  const password = '/api/v1/account/password'
  const settings = '/api/v1/settings/application'
  const profile = '/api/v1/account/profile'
  const xhr = { 'X-Requested-With': 'XMLHttpRequest' }
  await request('/logout')
  assert.equal((await request(base + 'categories/new')).status, 401)
  assert.equal((await request(password)).status, 401)
  assert.equal((await request(profile)).status, 401)
  assert.equal((await request(settings)).status, 401)
  assert.equal(location(await login('employee@example.test', 'fixture-password')), '/workspace')
  assert.equal((await request(base + 'categories/new')).status, 403)
  assert.equal((await request(settings)).status, 403)
  const myProfile = (await (await request(profile + '?id=1')).json()).values
  assert.equal(myProfile.email, 'employee@example.test')
  assert.ok(!('password' in myProfile) && !('token' in myProfile))
  await request('/logout')
  assert.equal(location(await login('engineering@example.test', 'fixture-password')), '/workspace')
  assert.equal((await request(base + 'locations/300/edit')).status, 404)
  assert.equal((await request(base + 'corporations/new')).status, 403)
  assert.equal((await request(base + 'roles/1/edit')).status, 403)
  assert.equal((await request(base + 'users/new')).status, 403)
  assert.equal((await request(base + 'administrators/new')).status, 403)
  assert.equal((await request(base + 'categories/1/edit')).status, 409)

  async function submit(route, values, expected = 200) {
    const options = await request(route)
    assert.equal(options.status, 200, await options.clone().text())
    const {csrf} = await options.json()
    const response = await request(route, values, {headers: {...xhr, [csrf.header]: csrf.hash}})
    assert.equal(response.status, expected, await response.clone().text())
    return response.json()
  }

  const general = { name: 'Fixture catalog record', name_slug: '', color: '#dedede', order: '3', lang_id: '1' }
  assert.equal((await request(base + 'categories/new', general, {headers: xhr})).status, 403)
  const category = await submit(base + 'categories/new', {...general, visibility: '2'})
  const subcategory = await submit(base + 'subcategories/new', {...general, parent_id: String(category.id)})
  const site = await submit(base + 'locations/new', {...general, corp_id: '2'})
  const locationOptions = await (await request(base + 'locations')).json()
  assert.ok(locationOptions.items.some(item => Number(item.id) === site.id && Number(item.corp_id) === 1))
  await submit(base + 'sublocations/new', {...general, parent_id: '300'}, 422)
  const sublocation = await submit(base + 'sublocations/new', {...general, parent_id: String(site.id)})
  const department = await submit(base + 'departments/new', general)
  for (const [resource, record] of [['categories', category], ['subcategories', subcategory], ['locations', site],
    ['sublocations', sublocation], ['departments', department]]) {
    const route = base + resource + '/' + record.id + '/edit'
    const {fields} = await (await request(route)).json()
    const name = 'Updated ' + resource
    await submit(route, {...fields, name})
    assert.equal((await (await request(route)).json()).fields.name, name)
  }

  const newPassword = { old_password: 'fixture-password', password: 'fixture-new-password', password_confirm: 'fixture-new-password' }
  assert.equal((await request(password, newPassword, {headers: xhr})).status, 403)
  await submit(password, {...newPassword, old_password: 'incorrect'}, 422)
  await submit(password, newPassword)
  await request('/logout')
  assert.equal(location(await login('engineering@example.test', 'fixture-password')), '/admin/login')
  assert.equal(location(await login('engineering@example.test', 'fixture-new-password')), '/workspace')
  await submit(password, {old_password: newPassword.password, password: 'fixture-password', password_confirm: 'fixture-password'})
  await request('/logout')
  assert.equal(location(await login('admin@example.test', 'fixture-password')), '/workspace')
  const corporation = await submit(base + 'corporations/new', {name: 'Fixture corporation', corp_code: 'FIXTURE', order: '4'})
  await submit(base + 'corporations/' + corporation.id + '/edit', {name: 'Updated corporation', corp_code: 'FIXTURE', order: '5'})
  assert.equal((await (await request(base + 'corporations/' + corporation.id + '/edit')).json()).fields.name, 'Updated corporation')
  assert.equal((await request(base + 'roles/1/edit')).status, 404)
  assert.equal((await request(base + 'administrators/1/edit')).status, 403)
  const staffOptions = (await (await request(base + 'users/new')).json()).choices
  const engineeringChoice = staffOptions.role_id.find(choice => Number(choice.value) === 3)
  assert.ok(engineeringChoice)
  const createdStaff = {first_name: 'Fixture', last_name: 'Provisioned', email: 'fixture-provisioned@example.test',
    password: 'fixture-provisioned-password', corp_id: '1', dept_id: '10', role_id: String(engineeringChoice.value)}
  await submit(base + 'users/new', {...createdStaff, dept_id: '20'}, 422)
  await submit(base + 'users/new', {...createdStaff, role_id: '1'}, 422)
  const added = await submit(base + 'users/new', createdStaff)
  await submit(base + 'users/new', createdStaff, 409)
  assert.equal((await request(base + 'administrators/' + added.id + '/edit')).status, 404)
  const addedAdmin = await submit(base + 'administrators/new', {...createdStaff, email: 'fixture-admin-created@example.test'})
  assert.equal((await request(base + 'users/' + addedAdmin.id + '/edit')).status, 404)
  const staffRoute = base + 'users/' + added.id + '/edit'
  const staffData = (await (await request(staffRoute)).json()).fields
  assert.deepEqual(Object.keys(staffData), ['first_name','last_name','email','status'])
  await submit(staffRoute, {...staffData, last_name: 'Updated', status: '0', role_id: '1', corp_id: '2'})
  await request('/logout')
  assert.equal(location(await login(createdStaff.email, createdStaff.password)), '/admin/login')
  assert.equal(location(await login('admin@example.test', 'fixture-password')), '/workspace')
  await submit(staffRoute, {...staffData, last_name: 'Updated', status: '1'})
  await request('/logout')
  assert.equal(location(await login(createdStaff.email, createdStaff.password)), '/workspace')
  assert.equal((await request(base + 'administrators/new')).status, 403)
  await request('/logout')
  assert.equal(location(await login('admin@example.test', 'fixture-password')), '/workspace')
  const settingsBefore = (await (await request(settings)).json()).values
  const update = Object.fromEntries(Object.entries(settingsBefore).map(([key,value]) => [key, value ?? '']))
  update.application_name = 'Fixture settings change'
  assert.equal((await request(settings, update, {headers: xhr})).status, 403)
  await submit(settings, {...update, timezone: 'Invalid/Timezone'}, 422)
  await submit(settings, update)
  const updated = (await (await request(settings)).json()).values
  assert.equal(updated.application_name, update.application_name)
  audit('refuse-settings-timezone')
  await submit(settings, {...update, application_name: 'Must be rolled back'}, 503)
  assert.deepEqual((await (await request(settings)).json()).values, updated)
  console.log('Settings and profile: scoped fields, CSRF, timezone validation, persistence and rollback PASS')
  console.log('Users and Administrators: creation, distinct scopes, duplicate email, validation, status and login PASS')
  const roles = await (await request(base + 'roles')).json()
  const engineering = roles.items.find(item => item.role === 'engineering')
  const roleEdit = base + 'roles/' + engineering.id + '/edit'
  const permissions = (await (await request(roleEdit)).json()).fields
  const normalized = Object.fromEntries(Object.entries(permissions).map(([key,value]) => [key,String(Number(value) === 1 ? 1 : 0)]))
  await submit(roleEdit, {...normalized, manage_all_posts: '0', role: 'admin'})
  assert.equal(Number((await (await request(roleEdit)).json()).fields.manage_all_posts), 0)
  await request('/logout')
  assert.equal(location(await login('engineering@example.test', 'fixture-password')), '/workspace')
  assert.equal((await request('/api/v1/work-orders/incoming/1/reply')).status, 403)
  await request('/logout')
  console.log('Roles: fixed administrator role, permitted flag changes and enforcement PASS')
  console.log('Master data: six create/edit paths, fixed statuses, CSRF, non-admin scope and foreign parent PASS')
  console.log('Account password: guest, CSRF, current hash, compatible login and self-service replacement PASS')
}
