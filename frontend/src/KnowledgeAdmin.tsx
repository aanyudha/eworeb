import { useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { NavLink, useLocation } from 'react-router-dom'
import { BookOpen, Eye, FilePlus2, Globe2, Pencil, Save } from 'lucide-react'
import { PageHeader } from './components'
import './knowledge-admin.css'

type Card = {
  id: string; slug: string; title: string; summary: string; category: string;
  status: 'draft' | 'published'; visibility: 'public' | 'internal'; updated_at: string;
}
type Article = Card & { keywords: string[]; symptoms: string[]; causes: string[]; resolution_steps: string[]; related_articles: string[] }
type Catalog = { articles: Card[]; categories: Record<string, string>; csrf: { header: string; hash: string } }
type Operation = { type: 'save'; article: Partial<Article> } | { type: 'publish' | 'withdraw'; article: Article }
type Mode = 'all' | 'drafts' | 'published' | 'categories'
const { knowledgeAdmin: endpoint, knowledgeHome: publicHome } = document.getElementById('root')!.dataset

async function request<T>(url: string, options?: RequestInit): Promise<T> {
  const headers = new Headers(options?.headers)
  headers.set('Accept', 'application/json')
  headers.set('X-Requested-With', 'XMLHttpRequest')
  const response = await fetch(url, { ...options, headers, credentials: 'same-origin', cache: 'no-store' })
  const data = await response.json().catch(() => ({}))
  if (!response.ok) throw new Error(data.error || (response.status === 403 ? 'Access denied or the security token expired.' : 'Knowledge Base request failed.'))
  return data
}

async function update(operation: Operation): Promise<Article> {
  const { csrf } = await request<Catalog>(endpoint!)
  const article = operation.article
  const isNew = operation.type === 'save' && !article.id
  const url = isNew ? endpoint! : endpoint + '/' + encodeURIComponent(article.id!)
    + (operation.type === 'save' ? '' : '/' + operation.type)
  const body = operation.type === 'save' ? article : { updated_at: article.updated_at }
  const result = await request<{ article: Article }>(url, {
    method: operation.type === 'save' && !isNew ? 'PUT' : 'POST',
    headers: { 'Content-Type': 'application/json', [csrf.header]: csrf.hash }, body: JSON.stringify(body),
  })
  return result.article
}

const textLists = {
  keywords: 'Keywords', symptoms: 'Symptoms', causes: 'Causes',
  resolution_steps: 'Troubleshooting steps', related_articles: 'Related article slugs',
} as const

function Editor({ article, categories, disabled, onSave, onCancel }: {
  article?: Article; categories: Record<string, string>; disabled: boolean; onSave: (article: Partial<Article>) => void; onCancel: () => void;
}) {
  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const fields = new FormData(event.currentTarget)
    const read = (key: string) => String(fields.get(key) ?? '').trim()
    const lists = Object.fromEntries(Object.keys(textLists).map(key => [key, read(key).split(/\r?\n/u).map(value => value.trim()).filter(Boolean)]))
    onSave({
      id: article?.id, updated_at: article?.updated_at, title: read('title'), slug: read('slug'), category: read('category'),
      summary: read('summary'), visibility: read('visibility') as Card['visibility'], ...lists,
    })
  }

  return <form className="knowledge-form panel" onSubmit={submit}>
    <div className="panel-head"><h2>{article ? 'Edit draft' : 'New draft'}</h2></div>
    <div className="knowledge-form-fields">
      <label>Title<input name="title" defaultValue={article?.title} maxLength={150} required /></label>
      <label>Slug<input name="slug" defaultValue={article?.slug} maxLength={96} pattern="[a-z0-9]+(?:-[a-z0-9]+)*" required placeholder="example-article" /></label>
      <label>Category<select name="category" defaultValue={article?.category}>{Object.entries(categories).map(([id,label]) => <option key={id} value={id}>{label}</option>)}</select></label>
      <label>Visibility<select name="visibility" defaultValue={article?.visibility ?? 'internal'}><option value="internal">Internal</option><option value="public">Public when published</option></select></label>
      <label className="knowledge-form-wide">Summary<textarea name="summary" defaultValue={article?.summary} maxLength={1200} rows={3} required /></label>
      {Object.entries(textLists).map(([key, label]) => <label key={key} className={key === 'resolution_steps' ? 'knowledge-form-wide' : ''}>
        {label}<textarea name={key} defaultValue={article?.[key as keyof typeof textLists].join('\n')} rows={key === 'resolution_steps' ? 5 : 3} placeholder="One per line" />
      </label>)}
    </div>
    <div className="knowledge-actions"><button className="secondary" type="button" onClick={onCancel}>Cancel</button><button type="submit" className="primary" disabled={disabled}><Save size={15} /> Save draft</button></div>
  </form>
}

function Review({ article, categories, busy, edit, transition }: {
  article: Article; categories: Record<string, string>; busy: boolean; edit: () => void; transition: (type: 'publish' | 'withdraw') => void;
}) {
  const [confirm, setConfirm] = useState<'publish' | 'withdraw' | null>(null)
  return <section className="panel knowledge-review">
    <div className="panel-head"><h2>Review article</h2><span>{article.status} · {article.visibility}</span></div>
    <div className="knowledge-review-body">
      <small>{categories[article.category]} / {article.slug}</small><h3>{article.title}</h3><p>{article.summary}</p>
      {Object.entries(textLists).map(([key,label]) => {
        const items = article[key as keyof typeof textLists]
        return items.length > 0 && <div key={key}><h4>{label}</h4>{key === 'resolution_steps'
          ? <ol>{items.map((item, index) => <li key={index}>{item}</li>)}</ol>
          : <ul>{items.map((item,index) => <li key={index}>{item}</li>)}</ul>}</div>
      })}
    </div>
    {confirm && <div className="knowledge-confirm" role="alert">
      {confirm === 'publish' ? (article.visibility === 'public' ? 'Publish this article to the public Knowledge Center?' : 'Publish this article with internal visibility?')
        : 'Withdraw this article and return it to drafts?'}
      <button className="secondary" onClick={() => setConfirm(null)}>Cancel</button>
      <button className="primary" disabled={busy} onClick={() => { transition(confirm); setConfirm(null) }}>Confirm {confirm}</button>
    </div>}
    <div className="knowledge-actions">
      {article.status === 'draft' ? <>
        <button className="secondary" onClick={edit}><Pencil size={15} /> Edit draft</button>
        <button className="primary" disabled={busy} onClick={() => setConfirm('publish')}><Globe2 size={15} /> Publish</button>
      </> : <button className="secondary" disabled={busy} onClick={() => setConfirm('withdraw')}>Withdraw publication</button>}
    </div>
  </section>
}

export default function KnowledgeAdmin() {
  const cache = useQueryClient()
  const location = useLocation()
  const mode = (location.pathname.split('/')[2] ?? 'all') as Mode
  const [selected, setSelected] = useState<string | null>(null)
  const [editing, setEditing] = useState(false)
  const [notice, setNotice] = useState('')
  const catalog = useQuery({ queryKey: ['knowledge-admin'], queryFn: () => request<Catalog>(endpoint!), retry: false })
  const detail = useQuery({ queryKey: ['knowledge-article', selected], enabled: selected !== null,
    queryFn: () => request<{ article: Article }>(endpoint + '/' + encodeURIComponent(selected!)), retry: false })
  const mutation = useMutation({ mutationFn: update, onSuccess: (article, operation) => {
    cache.setQueryData(['knowledge-article', article.id], { article })
    void cache.invalidateQueries({ queryKey: ['knowledge-admin'] })
    setSelected(article.id); setEditing(false)
    setNotice(operation.type === 'save' ? 'Draft saved.' : operation.type === 'publish' ? 'Article published.' : 'Publication withdrawn.')
  } })
  const categories = catalog.data?.categories ?? {}
  const nav: [Mode, string][] = [['all','All Articles'], ['drafts','Drafts'], ['published','Published Articles'], ['categories','Categories']]
  const rows = (catalog.data?.articles ?? []).filter(row =>
    (mode !== 'drafts' || row.status === 'draft') && (mode !== 'published' || row.status === 'published'))

  function reset() { setSelected(null); setEditing(false); setNotice(''); mutation.reset() }
  return <div className="knowledge-admin">
    <PageHeader title="Knowledge Base"><a href={publicHome}><BookOpen size={16} /> Public Knowledge Center</a></PageHeader>
    <nav className="knowledge-tabs" aria-label="Knowledge management">{nav.map(([path,label]) =>
      <NavLink key={path} to={'/knowledge/' + path} onClick={reset}>{label}</NavLink>)}</nav>
    {catalog.isPending && <div className="panel knowledge-message" role="status">Loading articles…</div>}
    {catalog.error && <div className="panel knowledge-message" role="alert">{catalog.error.message} <button className="secondary" onClick={() => void catalog.refetch()}>Retry</button></div>}
    {notice && <p className="knowledge-message" role="status">{notice}</p>}
    {mutation.error && <p className="knowledge-message" role="alert">{mutation.error.message}</p>}
    {catalog.data && mode === 'categories' && <section className="panel">
      <div className="panel-head"><h2>Categories</h2></div>
      <ul className="knowledge-category-list">{Object.entries(categories).map(([id,label]) => <li key={id}>{label}<span>{catalog.data.articles.filter(row => row.category === id).length} articles</span></li>)}</ul>
    </section>}
    {catalog.data && mode !== 'categories' && <>
      <section className="panel">
        <div className="panel-head"><h2>{nav.find(item => item[0] === mode)?.[1] ?? 'All Articles'}</h2>
          <button className="primary" onClick={() => { reset(); setEditing(true) }}><FilePlus2 size={15} /> New draft</button></div>
        {rows.length === 0 ? <p className="knowledge-message">No articles in this view.</p> :
          <div className="table-wrap"><table><thead><tr><th>Article</th><th>Category</th><th>Status</th><th>Visibility</th><th><span className="sr-only">Review</span></th></tr></thead>
          <tbody>{rows.map(row => <tr key={row.id}><td>{row.title}</td><td>{categories[row.category]}</td><td>{row.status}</td>
            <td>{row.visibility}</td><td><button className="text-button" onClick={() => { reset(); setSelected(row.id) }}><Eye size={14} /> Review</button></td></tr>)}</tbody></table></div>}
      </section>
      {selected && detail.isPending && <div className="panel knowledge-message" role="status">Loading article…</div>}
      {detail.error && <p className="knowledge-message" role="alert">{detail.error.message}</p>}
      {editing ? <Editor key={selected ?? 'new'} article={detail.data?.article} categories={categories} disabled={mutation.isPending}
        onSave={article => mutation.mutate({ type: 'save', article })} onCancel={reset} />
        : selected && detail.data && <Review key={selected} article={detail.data.article} categories={categories}
          busy={mutation.isPending} edit={() => { setNotice(''); setEditing(true) }}
          transition={type => mutation.mutate({ type, article: detail.data.article })} />}
    </>}
  </div>
}
