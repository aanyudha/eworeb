const assert = require('node:assert/strict');

module.exports = async function testCreate(request, login, location, audit) {
  const endpoint = '/api/v1/work-orders/create';
  const counts = () => JSON.parse(audit('create-counts'));
  const current = async () => {
    const response = await request(endpoint + '/preview');
    assert.equal(response.status, 200);
    return response.json();
  };
  const loginToken = async () => {
    const response = await request('/admin/login');
    return (await response.text()).match(/name="a2n_csrf_token" value="([^"]+)"/)[1];
  };
  async function submit(input, token) {
    const response = await request(endpoint, input, { headers: { 'X-CSRF-TOKEN': token } });
    return [response.status, await response.json()];
  }

  await request('/logout');
  const initial = counts();
  assert.equal((await submit({ title: 'Guest' }, await loginToken()))[0], 401);
  assert.equal(location(await login('no-add-post@example.test', 'fixture-password')), '/workspace');
  assert.equal((await submit({ title: 'Missing add_post' }, await loginToken()))[0], 403);
  assert.deepEqual(counts(), initial);
  await request('/logout');

  assert.equal(location(await login('employee@example.test', 'fixture-password')), '/workspace');
  let preview = await current();
  assert.equal(preview.reason, null);
  assert.equal(preview.canChooseScope, false);
  const values = {
    title: '  Create fixture work order  ', summary: 'Initial description',
    status: '1', location_id: '1', sublocation_id: '3', role_id: String(preview.destinationRoles[0].id),
    post_image_id: '1', 'additional_post_image_id[]': '2', image_description: 'Fixture image',
    corp_id: '2', dept_id: '20', user_id: '1', category_id: '9', visibility: '0', lang_id: '2',
  };
  const noToken = await request(endpoint, values);
  assert.ok([302, 303, 403].includes(noToken.status));
  for (const changes of [
    {title:'   '}, {title:'x'.repeat(501)}, {summary:'x'.repeat(5001)}, {image_description:'x'.repeat(501)},
    {summary:'<b>markup</b>'}, {summary:'invalid for utf8mb3 😃'}, {status:'2'},
    {location_id:'2'}, {sublocation_id:'4'}, {sublocation_id:'2'}, {role_id:'1'},
    {published_at:'2026-11-20 08:00:00'}, {scheduled_post:'1'}, {post_image_id:'3'}, {post_image_id:'1234'},
  ]) {
    const [status] = await submit({ ...values, ...changes }, (await current()).submission.csrf.hash);
    assert.equal(status, 422, Object.keys(changes).join());
  }
  assert.deepEqual(counts(), initial);

  let [status, result] = await submit(values, (await current()).submission.csrf.hash);
  assert.equal(status, 201, JSON.stringify(result));
  const id = result.id;
  const state = JSON.parse(audit('create-state', String(id)));
  assert.equal(state.order.title, 'Create fixture work order');
  assert.equal(state.history.length, 1);
  for (const row of [state.order, state.history[0]]) {
    assert.deepEqual([row.corp_id, row.dept_id, row.user_id, row.category_id, row.visibility, row.lang_id,
      row.status, row.is_scheduled, row.location_id, row.sublocation_id], [1,10,4,1,1,1,1,0,1,3]);
    assert.equal(row.published_at, null);
    for (const field of ['assign_to', 'edited_by', 'edited_at']) assert.equal(row[field], null);
    assert.equal(row.image_default, 'uploads/images/fixture-1.jpg');
  }
  assert.equal(state.history[0].created_at, state.order.created_at);
  assert.equal(state.history[0].post_id, id);
  assert.deepEqual(state.images, [{ image_default:'uploads/images/fixture-2.jpg' }]);
  assert.ok((await (await request('/api/v1/work-orders/outgoing?q=Create+fixture+work+order')).json()).items.some(row => row.id === id));
  const detail = await (await request('/api/v1/work-orders/outgoing/'+id)).json();
  assert.equal(detail.history.total, 1);
  assert.equal(detail.history.items[0].summary, 'Initial description');
  assert.equal(detail.item.sublocation, 'Fixture room');
  const attachments = await (await request('/api/v1/work-orders/outgoing/'+id+'/attachments')).json();
  assert.deepEqual(attachments.items.map(item => item.kind), ['main', 'image']);

  await request('/logout');
  assert.equal(location(await login('admin@example.test', 'fixture-password')), '/workspace');
  preview = await current();
  assert.equal(preview.canChooseScope, true);
  const adminDraft = { title:'Admin draft fixture', status:'0', corp_id:'2', dept_id:'20',
    location_id:'2', sublocation_id:'0', role_id: String(preview.destinationRoles[0].id) };
  assert.equal((await submit({...adminDraft, dept_id:'10'}, preview.submission.csrf.hash))[0], 422);
  [status, result] = await submit(adminDraft, (await current()).submission.csrf.hash);
  assert.equal(status, 201);
  const draft = JSON.parse(audit('create-state', String(result.id)));
  assert.deepEqual([draft.order.corp_id, draft.order.dept_id, draft.order.user_id, draft.order.status, draft.history[0].status], [2,20,1,0,0]);
  assert.equal(draft.order.summary, null);
  assert.equal(draft.history[0].summary, null);
  assert.ok((await (await request('/api/v1/work-orders/drafts?q=Admin+draft+fixture')).json()).items.some(row => row.id === result.id));
  assert.equal((await request('/api/v1/work-orders/outgoing/'+result.id)).status, 404);
  assert.equal((await request('/api/v1/work-orders/scheduled')).status, 503);

  const committed = counts();
  await request('/logout');
  await login('employee@example.test', 'fixture-password');
  audit('refuse-create-history');
  assert.equal((await submit(values, (await current()).submission.csrf.hash))[0], 503);
  assert.deepEqual(counts(), committed);
  audit('restore-create-history');
  audit('refuse-create-image');
  assert.equal((await submit(values, (await current()).submission.csrf.hash))[0], 503);
  assert.deepEqual(counts(), committed);
  const token = (await current()).submission.csrf.hash;
  audit('incompatible-create-column');
  assert.equal((await current()).submission, null);
  assert.equal((await submit(values, token))[0], 503);
  assert.deepEqual(counts(), committed);
  console.log('Create WO: permissions, CSRF, in-scope Publish and Draft, initial history, images, rollback and schema gate PASS');
};
