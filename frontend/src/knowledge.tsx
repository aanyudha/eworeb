import { useState, type FormEvent } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter, Link, Route, Routes, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query'
import { ArrowRight, BookOpen, ChevronRight, CircleHelp, LogIn, Monitor, Search, Sparkles, Wrench } from 'lucide-react'
import './knowledge.css'

type Category = { id: string; label: string; count: number }
type ArticleCard = { slug: string; title: string; summary: string; category: string; updated_at: string }
type Article = ArticleCard & { symptoms: string[]; causes: string[]; resolution_steps: string[] }
type ArticleList = { articles: ArticleCard[]; page: number; limit: number; total: number }

const element = document.getElementById('knowledge-root')!
const { home, api, staff } = element.dataset
const client = new QueryClient()
const icons = { 'it-support': Monitor, engineering: Wrench, housekeeping: Sparkles, 'general-how-to': CircleHelp }

class RequestError extends Error {
  constructor(readonly status: number) { super('Request failed') }
}

async function load<T>(resource: string): Promise<T> {
  const reply = await fetch(api + '/' + resource, { headers: { Accept: 'application/json' }, credentials: 'omit' })
  if (!reply.ok) throw new RequestError(reply.status)
  return reply.json()
}

function SearchBox({ value = '', category = '' }: { value?: string; category?: string }) {
  const [text, setText] = useState(value)
  const navigate = useNavigate()
  function search(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const query = new URLSearchParams()
    if (text.trim()) query.set('q', text.trim())
    if (category) query.set('category', category)
    navigate({ pathname: '/kb', search: query.toString() })
  }
  return <form className="kb-search" onSubmit={search} role="search">
    <Search size={21} aria-hidden="true" />
    <input type="search" aria-label="Search solutions" placeholder="Search by topic, symptom, or keyword" value={text} maxLength={120} onChange={event => setText(event.target.value)} />
    <button type="submit">Search</button>
  </form>
}

function Status({ kind, retry }: { kind: 'loading' | 'error' | 'empty' | 'no-results' | 'not-found'; retry?: () => void }) {
  if (kind === 'loading') return <div className="kb-skeleton" role="status" aria-label="Loading content"><i /><i /><i /></div>
  const messages = {
    error: ['Knowledge Center temporarily unavailable', 'Try again in a moment.'],
    empty: ['No published articles yet', 'Approved solutions will appear here when they are published.'],
    'no-results': ['No matching solutions', 'Try a different keyword or clear the category filter.'],
    'not-found': ['Article not found', 'It may have been moved or removed.'],
  }
  const [title, detail] = messages[kind]
  return <div className="kb-status" role={kind === 'error' ? 'alert' : 'status'}><BookOpen size={23} /><h2>{title}</h2><p>{detail}</p>
    {retry ? <button className="kb-action" onClick={retry}>Try again</button>
      : (kind === 'no-results' || kind === 'not-found') && <Link to="/kb">Browse all guides</Link>}</div>
}

function Crumb({ children }: { children: string }) {
  return <nav className="kb-crumb" aria-label="Breadcrumb"><Link to="/">Home</Link><ChevronRight size={13} /><Link to="/kb">Knowledge Base</Link><ChevronRight size={13} /><span>{children}</span></nav>
}

function Results({ articles, categories }: { articles: ArticleCard[]; categories: Category[] }) {
  return <div className="kb-results">{articles.map(item => <Link className="kb-result" to={'/kb/' + item.slug} key={item.slug}>
    <span className="kb-category-label">{categories.find(category => category.id === item.category)?.label ?? item.category}</span>
    <h3>{item.title}</h3><p>{item.summary}</p><span className="kb-read">View article <ArrowRight size={15} /></span>
  </Link>)}</div>
}

function KnowledgeList({ landing = false }: { landing?: boolean }) {
  const [params, setParams] = useSearchParams()
  const query = params.get('q') ?? ''
  const selected = params.get('category') ?? ''
  const page = Number(params.get('page') ?? 1)
  const search = new URLSearchParams({ q: query, category: selected, page: String(page), limit: landing ? '3' : '12' }).toString()
  const categories = useQuery({ queryKey: ['categories'], queryFn: () => load<{ categories: Category[] }>('categories') })
  const articles = useQuery({ queryKey: ['articles', search], queryFn: () => load<ArticleList>('articles?' + search) })
  const topics = categories.data?.categories ?? []
  function changePage(next: number) {
    const updated = new URLSearchParams(params)
    updated.set('page', String(next))
    setParams(updated)
  }
  return <>
    <section className={landing ? 'kb-hero' : 'kb-list-head'}>
      <div className="kb-frame">
        {landing ? <><p>SUPPORT & SELF-SERVICE</p><h1>How can we help you?</h1><p className="kb-subtitle">Find step-by-step solutions, troubleshooting guides, and answers from EWO.</p></>
          : <><Crumb>Browse guides</Crumb><h1>Knowledge Base</h1></>}
        <SearchBox key={query + '\0' + selected} value={query} category={selected} />
      </div>
    </section>
    <div className="kb-frame kb-content">
      <section aria-labelledby="category-title" className="kb-section">
        <div className="kb-section-head"><h2 id="category-title">Browse by category</h2>{selected && <Link to="/kb">Clear category</Link>}</div>
        {categories.isPending ? <Status kind="loading" /> : categories.isError ? <Status kind="error" retry={() => void categories.refetch()} /> :
          <div className="kb-categories">{topics.map(category => {
            const Icon = icons[category.id as keyof typeof icons] ?? BookOpen
            return <Link className={selected === category.id ? 'kb-category active' : 'kb-category'} key={category.id} to={'/kb?category=' + category.id} aria-current={selected === category.id ? 'true' : undefined}>
              <span className="kb-category-icon"><Icon size={22} /></span><div><h3>{category.label}</h3><p>{category.count} {category.count === 1 ? 'article' : 'articles'}</p></div><ChevronRight size={16} />
            </Link>
          })}</div>}
      </section>
      <section className="kb-section" aria-labelledby="articles-title">
        <div className="kb-section-head"><h2 id="articles-title">{landing ? 'Featured solutions' : query ? 'Search results' : 'Published guides'}</h2>
          {landing && <Link to="/kb">Browse all <ArrowRight size={15} /></Link>}
          {!landing && articles.data && <span>{articles.data.total} results</span>}
        </div>
        {articles.isPending ? <Status kind="loading" /> : articles.isError ? <Status kind="error" retry={() => void articles.refetch()} />
          : articles.data.articles.length === 0 ? <Status kind={query || selected || page > 1 ? 'no-results' : 'empty'} />
          : <Results articles={articles.data.articles} categories={topics} />}
        {!landing && articles.data && articles.data.total > articles.data.limit &&
          <div className="kb-pages"><button className="kb-action" disabled={page <= 1} onClick={() => changePage(page - 1)}>Previous</button>
            <span>Page {page} of {Math.ceil(articles.data.total / articles.data.limit)}</span>
            <button className="kb-action" disabled={page * articles.data.limit >= articles.data.total} onClick={() => changePage(page + 1)}>Next</button></div>}
      </section>
    </div>
  </>
}

function KnowledgeDetail() {
  const { slug } = useParams()
  const result = useQuery({ queryKey: ['article', slug], queryFn: () => load<{ article: Article; related: ArticleCard[] }>('articles/' + encodeURIComponent(slug!)), retry: false })
  const category = useQuery({ queryKey: ['categories'], queryFn: () => load<{ categories: Category[] }>('categories') })
  const topics = category.data?.categories ?? []
  if (result.isPending) return <div className="kb-frame kb-content"><Status kind="loading" /></div>
  if (result.isError) return <div className="kb-frame kb-content"><Crumb>Article</Crumb><Status
    kind={result.error instanceof RequestError && result.error.status === 404 ? 'not-found' : 'error'}
    retry={result.error instanceof RequestError && result.error.status === 404 ? undefined : () => void result.refetch()} /></div>

  const { article, related } = result.data
  const date = new Date(article.updated_at)
  return <div className="kb-frame kb-content">
    <Crumb>{article.title}</Crumb>
    <article className="kb-article">
      <div className="kb-article-head">
        <span className="kb-category-label">{topics.find(item => item.id === article.category)?.label ?? article.category}</span>
        <h1>{article.title}</h1><p>{article.summary}</p>
        {!Number.isNaN(date.valueOf()) && <time dateTime={article.updated_at}>Updated {date.toLocaleDateString(undefined, { dateStyle: 'medium' })}</time>}
      </div>
      {([['Symptoms', article.symptoms], ['Causes', article.causes]] as const).map(([label, items]) => items.length > 0 &&
        <section key={label}><h2>{label}</h2><ul>{items.map((item, index) => <li key={index}>{item}</li>)}</ul></section>)}
      <section><h2>Troubleshooting steps</h2><ol className="kb-steps">{article.resolution_steps.map((step, index) => <li key={index}>{step}</li>)}</ol></section>
    </article>
    {related.length > 0 && <section className="kb-section kb-related"><div className="kb-section-head"><h2>Related articles</h2></div><Results articles={related} categories={topics} /></section>}
  </div>
}

function PublicApp() {
  return <div className="kb-app">
    <header className="kb-top"><div className="kb-frame">
      <Link className="kb-brand" to="/"><span><BookOpen size={21} /></span><div>EWO <b>Reborn</b><small>Knowledge Center</small></div></Link>
      <nav aria-label="Public navigation"><Link to="/kb">Browse guides</Link><a className="kb-staff" href={staff}><LogIn size={16} /> Staff Login</a></nav>
    </div></header>
    <main><Routes>
      <Route path="/" element={<KnowledgeList landing />} />
      <Route path="/kb" element={<KnowledgeList />} />
      <Route path="/kb/:slug" element={<KnowledgeDetail />} />
      <Route path="*" element={<div className="kb-frame kb-content"><Status kind="not-found" /></div>} />
    </Routes></main>
    <footer className="kb-footer"><div className="kb-frame">EWO Reborn Knowledge Center</div></footer>
  </div>
}

createRoot(element).render(<QueryClientProvider client={client}>
  <BrowserRouter basename={new URL(home!, location.href).pathname}><PublicApp /></BrowserRouter>
</QueryClientProvider>)
