const assert=require('node:assert/strict')
const {readdirSync}=require('node:fs')

module.exports=async function replyAssertions(request,login,location,audit,audioDirectory) {
  await request('/logout')
  const inactive='/api/v1/work-orders/incoming/1/reply'
  assert.equal((await request(inactive)).status,401)
  assert.equal(location(await login('employee@example.test','fixture-password')),'/workspace')
  assert.equal((await request(inactive)).status,403)
  await request('/logout')
  assert.equal(location(await login('engineering@example.test','fixture-password')),'/workspace')
  for (const direction of ['incoming', 'outgoing']) {
    const params = '?show=3&corpnya=2&q=Fixture&dateChoice=created_at&tglAwal=2000-01-01&tglAkhir=2099-01-01'
    const reportResponse = await request('/api/v1/reports/' + direction + params)
    assert.equal(reportResponse.status, 200)
    const report = await reportResponse.json()
    const list = await (await request('/api/v1/work-orders/' + direction + params)).json()
    assert.equal(report.subcategory_available, false)
    assert.equal(report.total, list.total)
    assert.equal(report.items.length, 3)
    assert.deepEqual(report.items.map(row => row.id), list.items.map(row => row.id))
    assert.ok(report.items.every(row => row.subcategory_name === null && row.corp_id === 1))
    if (direction === 'outgoing') assert.ok(report.items.every(row => row.dept_id === 10))
  }
  console.log('Reports on exact legacy schema: totals, filters and scope with unavailable subcategory PASS')
  const visible=await (await request('/api/v1/work-orders/incoming')).json()
  const sample=visible.items.find(w=>![4,6].includes(Number(w.category_id)) && Number(w.corp_id)===1)
  assert.ok(sample)
  const path='/api/v1/work-orders/incoming/'+sample.id+'/reply'
  const state=()=>JSON.parse(audit('reply-state',String(sample.id)))
  const original=state()
  const mediaPath='/api/v1/work-orders/incoming/'+sample.id+'/attachments'
  const media=await (await request(mediaPath)).json()
  assert.ok(media.items[0]?.download)
  const download=await request(mediaPath+'/main/0')
  assert.equal(download.status,200)
  assert.equal(download.headers.get('x-content-type-options'),'nosniff')
  assert.ok((await download.arrayBuffer()).byteLength>0)
  const traversal=media.items.find(item=>item.kind==='image')
  assert.equal(traversal.download,null)
  assert.equal((await request(mediaPath+'/image/'+traversal.id)).status,404)
  async function uploadImage(file, filename, csrf) {
    const data = new FormData()
    data.set('attachment', new Blob([file]), filename)
    const headers = { 'X-Requested-With': 'XMLHttpRequest' }
    if (csrf) headers[csrf.header] = csrf.hash
    return request(mediaPath + '/image', data, { headers })
  }
  assert.equal(media.upload.kind, 'image')
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGNQcEgAAAFEAMELjZZCAAAAAElFTkSuQmCC', 'base64')
  assert.equal((await uploadImage(png, 'attachment.png')).status, 403)
  assert.equal((await uploadImage('invalid content', 'attachment.png', media.upload)).status, 422)
  const fresh = await (await request(mediaPath)).json()
  assert.equal(fresh.items.length, media.items.length)
  const uploaded = await uploadImage(png, 'attachment.png', fresh.upload)
  assert.equal(uploaded.status, 201, await uploaded.clone().text())
  const updatedMedia = await (await request(mediaPath)).json()
  const newImage = updatedMedia.items.find(item => item.kind === 'image' && item.id !== traversal.id)
  assert.ok(newImage?.download)
  const imageResponse = await request(mediaPath + '/image/' + newImage.id)
  assert.deepEqual(Buffer.from(await imageResponse.arrayBuffer()), png)
  assert.deepEqual(state(), original, 'attachment upload only updates attachment relations')

  async function options(){
    const response=await request(path)
    assert.equal(response.status,200,await response.clone().text())
    return response.json()
  }
  async function reply(values, useCsrf=true) {
    const headers={'X-Requested-With':'XMLHttpRequest'}
    if(useCsrf){
      const {csrf}=await options()
      headers[csrf.header]=csrf.hash
    }
    return request(path,values,{method:'POST',headers})
  }
  const before=await options()
  const selected=before.assignees[0].id
  const payload={revision:before.revision,category_id:'4',assign_to:String(selected),summary_edit:'Verified completion note'}
  assert.ok(before.assignees.every(user=>Number(user.id)!==250))
  assert.ok(!before.categories.some(cat=>Number(cat.id)===6))
  assert.equal((await reply(payload,false)).status,403)
  assert.equal((await reply({...payload,assign_to:'250'})).status,422)
  assert.equal((await reply({...payload,category_id:'6'})).status,422)
  assert.equal((await reply({...payload,summary_edit:' '})).status,422)
  assert.deepEqual(state(),original)
  const closed=await reply(payload)
  assert.equal(closed.status,200,await closed.clone().text())
  const after=state()
  assert.equal(Number(after.order.category_id),4)
  assert.equal(Number(after.order.assign_to),Number(selected))
  assert.equal(Number(after.order.edited_by),5)
  assert.ok(after.order.edited_at)
  for(const key of Object.keys(original.order).filter(k=>!['category_id','assign_to','edited_by','edited_at'].includes(k))){
    assert.equal(after.order[key],original.order[key],key+' must stay the same')
  }
  assert.equal(after.history.length,1)
  assert.equal(after.history[0].summary,'Verified completion note')
  assert.equal(after.order.summary,'Original request')
  assert.equal((await reply(payload)).status,409)
  assert.deepEqual(state(),after,'stale replay must not mutate data')
  const reopen=await options()
  assert.deepEqual(reopen.categories.map(c=>Number(c.id)),[6])
  const reopened=await reply({...payload,revision:reopen.revision,category_id:'6',summary_edit:'Verified reopen note'})
  assert.equal(reopened.status,200,await reopened.clone().text())
  const reopenedState=state()
  assert.equal(Number(reopenedState.order.category_id),6)
  assert.equal(reopenedState.history.length,2)

  await request('/logout')
  assert.equal(location(await login('admin@example.test','fixture-password')),'/workspace')
  const other=await (await request('/api/v1/work-orders/incoming?corpnya=2')).json()
  assert.ok(other.items.length)
  const foreign=other.items[0].id
  await request('/logout')
  assert.equal(location(await login('engineering@example.test','fixture-password')),'/workspace')
  assert.equal((await request('/api/v1/work-orders/incoming/'+foreign+'/reply')).status,404)
  assert.equal((await request('/api/v1/work-orders/incoming/'+foreign+'/attachments/main/0')).status,404)

  const rollback=await options()
  audit('refuse-reply-history')
  const failed=await reply({...payload,revision:rollback.revision,category_id:'2'})
  assert.equal(failed.status,503,await failed.clone().text())
  assert.deepEqual(state(),reopenedState,'History failure must roll back the Work Order update')
  const draftsPath = '/api/v1/work-orders/drafts'
  const drafts = await (await request(draftsPath)).json()
  const draft = drafts.items.find(item => String(item.is_scheduled) === '0')
  assert.equal(draft?.title, 'Publishable fixture draft')
  assert.ok(!drafts.items.some(item => item.title.startsWith('Different ')))
  await request('/logout')
  assert.equal(location(await login('admin@example.test','fixture-password')),'/workspace')
  const globalDrafts = await (await request(draftsPath)).json()
  const excluded = globalDrafts.items.filter(item => item.title.startsWith('Different '))
  assert.equal(excluded.length, 2)
  await request('/logout')
  assert.equal(location(await login('engineering@example.test','fixture-password')),'/workspace')
  const publishPath = draftsPath + '/' + draft.id + '/publish'
  const draftBefore = JSON.parse(audit('reply-state', String(draft.id)))
  const headers = { 'X-Requested-With': 'XMLHttpRequest' }
  assert.equal((await request(publishPath, {}, { headers })).status, 403)
  for (const row of excluded) {
    const deniedToken = (await (await request(draftsPath)).json()).publish_csrf
    headers[deniedToken.header] = deniedToken.hash
    assert.equal((await request(draftsPath + '/' + row.id + '/publish', {}, { headers })).status, 404)
    assert.equal(Number(JSON.parse(audit('reply-state', String(row.id))).order.status), 0)
  }
  const check = (await (await request(draftsPath)).json()).publish_csrf
  headers[check.header] = check.hash
  const published = await request(publishPath, {}, { headers })
  assert.equal(published.status, 200, await published.clone().text())
  assert.deepEqual(JSON.parse(audit('reply-state', String(draft.id))), {
    ...draftBefore, order: { ...draftBefore.order, status: 1 },
  })
  const afterDrafts = await (await request(draftsPath)).json()
  assert.ok(afterDrafts.items.every(item => item.id !== draft.id))
  headers[afterDrafts.publish_csrf.header] = afterDrafts.publish_csrf.hash
  assert.equal((await request(publishPath, {}, { headers })).status, 404)
  const audioOrders = await (await request('/api/v1/work-orders/incoming?post_type=audio')).json()
  assert.equal(audioOrders.items.length, 1)
  const audioPath = '/api/v1/work-orders/incoming/' + audioOrders.items[0].id + '/attachments'
  const wave = Buffer.alloc(124, 128)
  wave.write('RIFF', 0)
  wave.writeUInt32LE(116, 4)
  wave.write('WAVEfmt ', 8)
  wave.writeUInt32LE(16, 16)
  wave.writeUInt16LE(1, 20)
  wave.writeUInt16LE(1, 22)
  wave.writeUInt32LE(8000, 24)
  wave.writeUInt32LE(8000, 28)
  wave.writeUInt16LE(1, 32)
  wave.writeUInt16LE(8, 34)
  wave.write('data', 36)
  wave.writeUInt32LE(80, 40)
  async function uploadAudio(allow) {
    const {upload} = await (await request(audioPath)).json()
    assert.equal(upload.kind, 'audio')
    const data = new FormData()
    data.set('attachment', new Blob([wave]), 'fixture.wav')
    data.set('audio_name', allow ? 'Downloadable fixture' : 'Private fixture audio')
    if (allow) data.set('download_button', '1')
    return request(audioPath + '/audio', data, {
      headers: { 'X-Requested-With': 'XMLHttpRequest', [upload.header]: upload.hash },
    })
  }
  for (const allow of [false, true]) {
    const response = await uploadAudio(allow)
    assert.equal(response.status, 201, await response.clone().text())
  }
  const audioEntries = (await (await request(audioPath)).json()).items.filter(item => item.kind === 'audio')
  assert.equal(audioEntries.length, 2)
  const privateAudio = audioEntries.find(item => item.label === 'Private fixture audio')
  assert.equal(privateAudio.download, null)
  assert.equal((await request(audioPath + '/audio/' + privateAudio.id)).status, 404)
  const downloadable = audioEntries.find(item => item.label === 'Downloadable fixture')
  const downloadedAudio = await request(audioPath + '/audio/' + downloadable.id)
  assert.equal(downloadedAudio.status, 200)
  assert.deepEqual(Buffer.from(await downloadedAudio.arrayBuffer()), wave)
  const count = audit('audio-count')
  const files = readdirSync(audioDirectory).sort()
  audit('refuse-audio-link')
  assert.equal((await uploadAudio(true)).status, 503)
  assert.equal(audit('audio-count'), count, 'audio metadata rolls back if linking fails')
  assert.deepEqual(readdirSync(audioDirectory).sort(), files, 'Failed link leaves no extra file')
  console.log('Audio upload: download flag, bytes, relation and transaction rollback PASS')
  console.log('Work Order draft and image upload: CSRF, status-only publish, stale publish, MIME, attachment relation and bytes PASS')
  console.log('Work Order reply: scope, CSRF, validation, assignment, completion, reopen, stale revision, history and rollback PASS')
}

