
const assert=require('node:assert/strict');
const admin='/api/v1/admin/kb/articles';
const pub='/api/v1/kb/articles';

module.exports=async function knowledgeAssertions(request) {
  async function write(route, method, json, csrf = true) {
    const headers={'X-Requested-With':'XMLHttpRequest'};
    if(csrf) {
      const response=await request(admin);
      assert.equal(response.status,200);
      const {csrf:token}=await response.json();
      headers[token.header]=token.hash;
    }
    return request(route,undefined,{method,json,headers});
  }
  async function expect(response,status) {
    const text=await response.text();
    assert.equal(response.status,status,text.slice(0,250));
    return JSON.parse(text);
  }

  const draft={
    slug:'fixture-network', title:'Fixture network guide', summary:'A public troubleshooting example with <em>plain text</em>.',
    category:'it-support', visibility:'public', keywords:['connectivity','patch-cable'],
    symptoms:['Network link is unavailable.'], causes:['A cable is not seated.'], resolution_steps:['Check the cable is seated.','Check the link status.'],
    related_articles:['fixture-visible','fixture-private'],
  };
  assert.equal((await write(admin,'POST',draft,false)).status,403);
  assert.equal((await request(pub)).status,200);
  assert.equal((await request(pub+'?limit=25')).status,422);
  assert.equal((await request(pub+'?q[]=invalid')).status,422);
  const invalid=await write(admin,'POST',{...draft, slug:'bad/child'});
  assert.equal(invalid.status,422);
  const first=(await expect(await write(admin,'POST',draft),201)).article;
  assert.equal(first.status,'draft');
  assert.equal((await request(pub+'/'+first.slug)).status,404);
  assert.equal((await request('/kb/'+first.slug)).status,404);
  const updated=(await expect(await write(admin+'/'+first.id,'PUT',{...first,summary:'Updated guide with <em>plain text</em>.'}),200)).article;
  assert.notEqual(updated.updated_at,first.updated_at);
  assert.equal((await write(admin+'/'+first.id,'PUT',first)).status,409);
  const internal=(await expect(await write(admin,'POST',{...draft,slug:'fixture-private',title:'Internal fixture',visibility:'internal',related_articles:[]}),201)).article;
  await expect(await write(admin+'/'+internal.id+'/publish','POST',internal),200);
  const related=(await expect(await write(admin,'POST',{...draft,slug:'fixture-visible',title:'Related fixture',category:'engineering',related_articles:[],keywords:[]}),201)).article;
  await expect(await write(admin+'/'+related.id+'/publish','POST',related),200);
  const live=(await expect(await write(admin+'/'+updated.id+'/publish','POST',updated),200)).article;
  assert.equal(live.status,'published');
  assert.equal((await write(admin+'/'+live.id,'PUT',live)).status,409);
  const {article,related:links}=await expect(await request(pub+'/'+live.slug),200);
  assert.equal(article.summary,'Updated guide with <em>plain text</em>.');
  assert.deepEqual(links.map(link=>link.slug),['fixture-visible']);
  assert.equal(Object.hasOwn(article,'visibility'),false);
  assert.equal((await request(pub+'/'+internal.slug)).status,404);
  assert.equal((await request(pub+'/invalid.slug')).status,404);
  assert.equal((await request('/kb/does-not-exist')).status,404);
  const byKeyword=await expect(await request(pub+'?q=patch-cable&category=it-support&limit=1'),200);
  assert.deepEqual(byKeyword.articles.map(a=>a.slug),['fixture-network']);
  assert.equal(byKeyword.total,1);
  const counts=await expect(await request('/api/v1/kb/categories'),200);
  assert.equal(counts.categories.reduce((sum,c)=>sum+c.count,0),2);
  assert.equal((await request('/kb/'+live.slug)).status,200);
  const withdrawn=(await expect(await write(admin+'/'+related.id+'/withdraw','POST',related.status==='published'?related:
    (await expect(await request(admin+'/'+related.id),200)).article),200)).article;
  assert.equal(withdrawn.status,'draft');
  assert.equal((await request(pub+'/'+related.slug)).status,404);
  await expect(await write(admin+'/'+withdrawn.id+'/publish','POST',withdrawn),200);
  console.log('Knowledge Base HTTP: public filtering, limits, 404s, CSRF, draft/edit, publication, withdrawal, visibility, and revision passed');
};
