const assert = require('node:assert/strict')

module.exports = async function testWorkOrders(request, login, location) {
  assert.equal((await request('/api/v1/work-orders/incoming')).status, 401)
  assert.equal(location(await login('staff@example.test', 'fixture-password')), '/admin')
  assert.equal((await request('/api/v1/work-orders/outgoing')).status, 403)
  await request('/logout')

  for (const [account, corp] of [['admin', null], ['corpadmin', 1], ['gm', 2], ['engineering', 1], ['employee', 1]]) {
    assert.equal(location(await login(account+'@example.test', 'fixture-password')), '/admin')
    for (const direction of ['incoming', 'outgoing']) {
      const uri = '/api/v1/work-orders/' + direction
      const response = await request(uri + '?per_page=2')
      assert.equal(response.status, 200)
      const first = await response.json()
      assert.ok(first.total > 0 && first.items.length === 2)
      const second = await (await request(uri + '?per_page=2&page=2')).json()
      assert.equal(second.total, first.total)
      assert.ok(second.items.every(item => !first.items.some(row => row.id === item.id)))

      const otherCorp = corp === 1 ? 2 : 1
      const filtered = await (await request(uri + '?corpnya=' + otherCorp + '&q=Fixture&per_page=100')).json()
      if (corp !== null) assert.equal(filtered.total, first.total)
      assert.ok(filtered.items.length > 0)
      assert.ok(filtered.items.every(item => item.corp_id === (corp ?? otherCorp)))
      if (account === 'employee') {
        assert.ok(filtered.items.every(item => item.dept_id === 10))
        assert.ok(filtered.items.every(item => direction === 'incoming'
          ? item.role_id === 4 && item.user_id === 4 : item.role_id !== 4))
      }

      assert.equal((await request(uri + '?page=0')).status, 422)
      assert.equal((await request(uri + '?dateChoice=other&tglAwal=2020-01-01&tglAkhir=2020-01-02')).status, 422)
      const dates = await (await request(uri + '?dateChoice=created_at&tglAwal=2026-01-01&tglAkhir=2026-01-02')).json()
      assert.equal(dates.total, first.total)
      assert.equal((await request('/workspace/work-orders/' + direction)).status, 200)
    }
    await request('/logout')
  }

  assert.equal(location(await login('other@example.test', 'fixture-password')), '/admin')
  assert.equal((await request('/api/v1/work-orders/incoming')).status, 403)
  await request('/logout')

  assert.equal((await request('/api/v1/admin-directory/categories')).status, 401)
  for (const [account, corp] of [['admin', null], ['engineering', '1'], ['gm', '2']]) {
    assert.equal(location(await login(account+'@example.test','fixture-password')), '/admin')
    for (const resource of ['categories','subcategories','locations','sublocations','departments','users','administrators']) {
      const response = await request('/api/v1/admin-directory/' + resource)
      if (resource === 'administrators' && account !== 'admin') {
        assert.equal(response.status, 403)
        continue
      }
      assert.equal(response.status, 200, resource + ' ' + account)
      const { items } = await response.json()
      assert.ok(items.length > 0)
      if (['locations','sublocations','departments','users'].includes(resource) && corp !== null) {
        assert.ok(items.every(item => String(item.corp_id) === corp))
      }
      assert.ok(items.every(item => !('password' in item) && !('email' in item)))
      if (resource === 'sublocations' && corp !== null) {
        assert.equal(items.length, 1)
        assert.equal(String(items[0].parent_id), corp)
      }
    }
    const category = await (await request('/api/v1/admin-directory/subcategories?q=fixture')).json()
    assert.equal(category.total, 1)
    assert.equal((await request('/api/v1/admin-directory/users?page=0')).status, 422)
    await request('/logout')
  }
  assert.equal(location(await login('employee@example.test','fixture-password')), '/admin')
  assert.equal((await request('/api/v1/admin-directory/categories')).status,403)
  await request('/logout')
  console.log('Directories: permission, hierarchy, corporation scope, search, and response allowlist PASS')

  assert.equal((await request('/api/v1/reports/incoming')).status, 401)
  assert.equal(location(await login('staff@example.test','fixture-password')), '/admin')
  assert.equal((await request('/api/v1/reports/incoming')).status, 403)
  await request('/logout')
  assert.equal(location(await login('engineering@example.test','fixture-password')), '/admin')
  for (const direction of ['incoming','outgoing']) {
    const query = '?show=3&corpnya=2&q=Fixture'
    const response = await request('/api/v1/reports/' + direction + query)
    assert.equal(response.status, 200)
    const report = await response.json()
    assert.equal(report.pageSize, 3)
    assert.equal(report.items.length, 3)
    assert.ok(report.items.every(row => row.corp_id === 1 && row.location_name === 'Site One'))
    assert.ok(report.items.every(row => row.has_image === true && !('image_small' in row)))
    assert.deepEqual(report.items.map(row => row.id), (await (await request('/api/v1/work-orders/' + direction + query)).json()).items.map(row => row.id))
    assert.ok(report.items.every(row => row.created_at === '2026-01-01 / 10:00'))
  }
  await request('/logout')
  console.log('Reports In/Out: add_post authorization, scoped rows, date format and report columns PASS')

  for (const mode of ['drafts','scheduled']) {
    assert.equal((await request('/api/v1/work-orders/' + mode)).status,401)
  }
  assert.equal(location(await login('employee@example.test','fixture-password')), '/admin')
  assert.equal((await request('/api/v1/work-orders/scheduled')).status,403)
  const drafts = await (await request('/api/v1/work-orders/drafts?corpnya=2')).json()
  assert.equal(drafts.total,1)
  assert.ok(drafts.items.every(item=>item.corp_id===1 && item.dept_id===10 && item.role_id!==4))
  await request('/logout')

  assert.equal(location(await login('corpadmin@example.test','fixture-password')), '/admin')
  const scheduled = await (await request('/api/v1/work-orders/scheduled?corpnya=2')).json()
  assert.equal(scheduled.total,1)
  assert.ok(scheduled.items.every(item=>item.corp_id===1 && item.published_at==='2026-02-01 10:00:00'))
  await request('/logout')
  console.log('Drafts and Scheduled: add_post, manage_all_posts, corporation/department scope and schedule columns PASS')

  console.log('Work Order lists: permissions, corporation/department scope, filters, pagination, and deep links PASS')
}

