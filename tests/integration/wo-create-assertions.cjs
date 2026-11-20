const assert = require('node:assert/strict');
const fs = require('node:fs');

module.exports = async function testCreate(request, login, location, audit, uploadDirectory) {
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
  assert.equal((await request(endpoint+'/images')).status, 403);
  assert.deepEqual(counts(), initial);
  await request('/logout');

  assert.equal(location(await login('employee@example.test', 'fixture-password')), '/workspace');
  let preview = await current();
  assert.equal(preview.reason, null);
  assert.equal(preview.canChooseScope, false);
  assert.equal(preview.canSchedule, false);
  assert.equal((await request('/api/v1/work-orders/scheduled')).status,403);
  const catalog = await (await request(endpoint+'/images?corp_id=2')).json();
  assert.equal(catalog.canUpload, true);
  assert.deepEqual(catalog.items.map(row=>row.id).filter(id=>id<=3), [2,1]);
  assert.equal((await request(endpoint+'/images/3?corp_id=2')).status, 404);

  async function upload(bytes, name) {
    const data = new FormData();
    data.append('corp_id', '2');
    data.append('image', new Blob([bytes]), name);
    return request(endpoint+'/images',data,{ headers:{'X-CSRF-TOKEN':(await current()).submission.csrf.hash} });
  }
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAAElFTkSuQmCC','base64');
  const imageBytes = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQImWNgYGAAAAAEAAGjChXjAAAAAElFTkSuQmCC','base64');
  assert.equal((await upload(png,'invalid.png')).status,422);
  assert.equal((await upload(imageBytes,'mismatch.jpg')).status,422);
  const beforeUpload = fs.readdirSync(uploadDirectory).length;
  const uploaded = await upload(imageBytes,'fixture.png');
  assert.equal(uploaded.status,201,await uploaded.clone().text());
  const uploadedId = (await uploaded.json()).id;
  assert.equal(fs.readdirSync(uploadDirectory).length,beforeUpload+5);
  const thumb = await request(endpoint+'/images/'+uploadedId+'?corp_id=2');
  assert.equal(thumb.status,200);
  assert.match(thumb.headers.get('content-type'),/image\/jpeg/);
  const gif = Buffer.from('R0lGODlhAQABAPAAAP///wAAACH5BAAAAAAALAAAAAABAAEAAAICRAEAOw==','base64');
  const gifUpload = await upload(gif,'fixture.gif');
  assert.equal(gifUpload.status,201,await gifUpload.clone().text());
  const gifPreview = await request(endpoint+'/images/'+(await gifUpload.json()).id);
  assert.equal(gifPreview.status,200);
  assert.match(gifPreview.headers.get('content-type'),/image\/gif/);
  assert.deepEqual(Buffer.from(await gifPreview.arrayBuffer()),gif,'GIF bytes must be preserved');
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
    {published_at:'2026-11-20 08:00:00'}, {post_image_id:'3'}, {post_image_id:'1234'},
  ]) {
    const [status] = await submit({ ...values, ...changes }, (await current()).submission.csrf.hash);
    assert.equal(status, 422, Object.keys(changes).join());
  }
  assert.equal((await submit({...values, scheduled_post:'1', date_published:'2030-11-20T10:30', intervale:'2'}, (await current()).submission.csrf.hash))[0],403);
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
  assert.equal(preview.canSchedule, true);
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

  const scheduled = '/api/v1/work-orders/scheduled';
  const fields = { ...adminDraft, title:'Fixture Scheduled Draft', scheduled_post:'1', date_published:'2030-11-20T10:30', intervale:'2' };
  assert.ok([302,303,403].includes((await request(endpoint,fields)).status));
  for (const invalid of [{date_published:'2030-02-30T10:30'},{date_published:'bad'},{date_published:''},
    {intervale:'0'},{intervale:'-1'},{intervale:'1.5'},{intervale:'2147483647'}]) {
    assert.equal((await submit({...fields,...invalid},(await current()).submission.csrf.hash))[0],422);
  }
  [status,result] = await submit(fields,(await current()).submission.csrf.hash);
  assert.equal(status,201,JSON.stringify(result));
  const templateDraft = JSON.parse(audit('create-state', String(result.id)));
  assert.deepEqual([templateDraft.order.status,templateDraft.order.is_scheduled,templateDraft.order.dept_id],[0,1,20]);
  assert.equal(templateDraft.history[0].intervale,2);
  assert.equal((await request(scheduled+'/'+result.id)).status,404);

  const adminFields = {...fields,status:'1',title:'Fixture Scheduled Published'};
  audit('refuse-create-history');
  const beforeRollback = counts();
  assert.equal((await submit(adminFields,(await current()).submission.csrf.hash))[0],503);
  assert.deepEqual(counts(),beforeRollback);
  audit('restore-create-history');
  [status,result] = await submit(adminFields,(await current()).submission.csrf.hash);
  assert.equal(status,201);
  const template = result.id;
  const publishedSchedule = JSON.parse(audit('create-state',String(template)));
  const history = publishedSchedule.history[0];
  assert.deepEqual([history.status,history.is_scheduled,history.dept_id,history.intervale,history.published_at],[1,1,20,2,'2030-11-20 10:30:00']);
  const listing = await (await request(scheduled+'?q=Fixture+Scheduled')).json();
  assert.deepEqual(listing.items.map(row=>row.id),[template]);
  assert.equal(listing.items[0].department,'Foreign department');
  assert.equal((await (await request(scheduled+'/'+template)).json()).history.items[0].published_at,'2030-11-20 10:30:00');
  assert.equal((await request(scheduled+'/'+template+'/attachments')).status,200);
  assert.equal((await request('/api/v1/work-orders/outgoing/'+template)).status,404);
  const historical = (await (await request(scheduled+'?per_page=100')).json()).items.find(row=>row.published_at===null);
  assert.ok(historical,'A historical published template remains browsable');
  assert.equal(historical.intervale,null);
  assert.equal((await request(scheduled+'/'+historical.id+'/publish',{},{ headers:{'X-CSRF-TOKEN':(await current()).submission.csrf.hash} })).status,404);
  assert.equal((await request(scheduled+'/'+template+'/publish',{},{ headers:{'X-CSRF-TOKEN':(await current()).submission.csrf.hash} })).status,200);
  assert.equal(JSON.parse(audit('create-state',String(template))).order.is_scheduled,0);
  assert.equal((await request(scheduled+'/'+template)).status,404);
  assert.equal((await request('/api/v1/work-orders/outgoing/'+template)).status,200);

  await request('/logout');
  await login('corpadmin@example.test','fixture-password');
  assert.equal((await current()).canSchedule,true);
  assert.equal((await request(scheduled+'/'+historical.id)).status,historical.corp_id===1 ? 200 : 404);
  [status,result] = await submit({...fields,title:'Corp Scheduled',location_id:'1',sublocation_id:'0',status:'1'},(await current()).submission.csrf.hash);
  assert.equal(status,201,JSON.stringify(result));
  const scoped = JSON.parse(audit('create-state',String(result.id)));
  assert.deepEqual([scoped.order.corp_id,scoped.order.dept_id,scoped.history[0].dept_id],[1,0,0]);
  console.log('Scheduled authoring: permission, CSRF, fields, Draft/Publish, scope, list/detail, historic safety, manual Publish and rollback PASS');

  const committed = counts();
  await request('/logout');
  await login('employee@example.test', 'fixture-password');
  audit('refuse-create-history');
  assert.equal((await submit(values, (await current()).submission.csrf.hash))[0], 503);
  assert.deepEqual(counts(), committed);
  audit('restore-create-history');
  audit('refuse-catalog-image');
  assert.equal((await upload(imageBytes,'no-row.png')).status,503);
  assert.equal(fs.readdirSync(uploadDirectory).length,beforeUpload+6,'Rejected image metadata must not leave rendition files');
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
