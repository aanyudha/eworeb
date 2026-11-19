const assert=require('node:assert/strict')

module.exports=async function replyAssertions(request,login,location,audit) {
  await request('/logout')
  const inactive='/api/v1/work-orders/incoming/1/reply'
  assert.equal((await request(inactive)).status,401)
  assert.equal(location(await login('employee@example.test','fixture-password')),'/workspace')
  assert.equal((await request(inactive)).status,403)
  await request('/logout')
  assert.equal(location(await login('engineering@example.test','fixture-password')),'/workspace')
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
  console.log('Work Order reply: scope, CSRF, validation, assignment, completion, reopen, stale revision, history and rollback PASS')
}

