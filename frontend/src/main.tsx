import { createRoot } from 'react-dom/client'
import { useMemo, useState } from 'react'
import { BrowserRouter, NavLink, Route, Routes, useLocation, useSearchParams } from 'react-router-dom'
import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query'
import {
  AppShell, ConfirmDialog, DataTable, DetailDrawer, ErrorState, FilterPanel, KPIStatCard, LoadingState, PageHeader, statuses,
  type Counts, type DashboardData, type Filters, type StatRow,
} from './components'
import './style.css'
import KnowledgeAdmin from './KnowledgeAdmin'

const mount = document.getElementById('root')!
const { workspace, dashboardApi, login, logout, originalDashboard } = mount.dataset
const queryClient = new QueryClient()
const formats = new Intl.NumberFormat()
const sections = [
  { key: 'incoming', path: '/incoming', label: 'Incoming WO' },
  { key: 'outgoing', path: '/outgoing', label: 'Outgoing WO' },
  { key: 'recap', path: '/departments', label: 'Departments' },
] as const

class DashboardError extends Error {
  constructor(readonly status: number, message: string) { super(message) }
}

async function loadDashboard(search: string): Promise<DashboardData> {
  const url = new URL(dashboardApi!, location.href)
  url.search = search
  const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
  if (!response.ok) {
    const body = await response.json().catch(() => ({}))
    throw new DashboardError(response.status, body.error || 'Could not load the dashboard. Try again.')
  }
  return response.json()
}

function getGroups(data: DashboardData): [string, Counts][] {
  const groups: [string, Counts][] = []
  if (data.summary.incoming) groups.push(['Incoming WO', data.summary.incoming])
  if (data.summary.outgoing) groups.push(['Outgoing WO', data.summary.outgoing])
  if (data.summary.recap) groups.push(...Object.entries(data.summary.recap))
  return groups
}

function StatusComparison({ groups }: { groups: [string, Counts][] }) {
  const largest = Math.max(1, ...groups.flatMap(([, counts]) => Object.values(counts)))
  return <aside className="panel comparison" aria-labelledby="compare">
    <div className="panel-head"><h2 id="compare">Workload by status</h2></div>
    <div className="chart">
      <div className="legend">{groups.map(([label], index) => <span key={label} className={'series-' + index}>{label}</span>)}</div>
      {statuses.map(status => <div className="chart-status" key={status}><h3>{status}</h3>
        {groups.map(([label, counts], index) => <div className={'bar-row series-' + index} key={label} aria-label={label + ': ' + counts[status]}>
          <span className="track"><span style={{ width: (counts[status] / largest) * 100 + '%' }} /></span><span>{formats.format(counts[status])}</span>
        </div>)}
      </div>)}
    </div>
  </aside>
}

function DashboardPage({ data, mode, search, onApply, onDetails }: {
  data: DashboardData; mode?: (typeof sections)[number]['key']; search: string;
  onApply: (filters: Filters) => void; onDetails: (row: StatRow) => void;
}) {
  const groups = useMemo(() => {
    if (!mode) return getGroups(data)
    if (mode === 'recap') return Object.entries(data.summary.recap ?? {})
    const counts = data.summary[mode]
    return counts ? [[mode === 'incoming' ? 'Incoming WO' : 'Outgoing WO', counts] as [string, Counts]] : []
  }, [data, mode])
  const rows = useMemo(() => groups.flatMap(([group, counts]) => statuses.map(status => ({ group, status, count: counts[status] }))), [groups])
  const title = sections.find(section => section.key === mode)?.label ?? 'Dashboard'
  const focus = groups[0]
  return <>
    <PageHeader title={title} />
    {data.canFilter && <FilterPanel key={JSON.stringify(data.filters)} corporations={data.corporations} filters={data.filters} onApply={onApply} />}
    {focus && <>
      <div className="summary-head"><h2>{focus[0]}</h2>{data.summary.incoming && data.summary.outgoing && <nav className="switches" aria-label="Work order direction">
        <NavLink to={{ pathname: '/incoming', search: location.search }}>Incoming</NavLink><NavLink to={{ pathname: '/outgoing', search: location.search }}>Outgoing</NavLink>
      </nav>}</div>
      <section className="kpis" aria-label={focus[0] + ' totals'}>{statuses.map(status => <KPIStatCard key={status} status={status} count={focus[1][status]} />)}</section>
      <div className="details-grid"><DataTable rows={rows} search={search} onDetails={onDetails} /><StatusComparison groups={groups} /></div>
    </>}
    {!focus && <ErrorState message="This account does not have this dashboard view." action={<NavLink to="/">Return to dashboard</NavLink>} />}
  </>
}

function Workspace() {
  const [params, setParams] = useSearchParams()
  const [search, setSearch] = useState('')
  const [activeRow, setActiveRow] = useState<StatRow | null>(null)
  const [confirm, setConfirm] = useState(false)
  const route = useLocation()
  const filterQuery = new URLSearchParams()
  for (const name of ['corpnya', 'role', 'department']) {
    const val = params.get(name)
    if (val) filterQuery.set(name, val)
  }
  const query = filterQuery.toString()
  const { data, error, isPending, refetch } = useQuery({
    queryKey: ['dashboard', query], queryFn: () => loadDashboard(query), retry: false,
  })
  const mode = sections.find(section => section.path === route.pathname)?.key
  function apply(filters: Filters) {
    const next = new URLSearchParams()
    Object.entries(filters).forEach(([key, value]) => { if (value) next.set(key, value) })
    setParams(next)
  }
  const page = data ? <DashboardPage data={data} mode={mode} search={search} onApply={apply} onDetails={setActiveRow} /> : null
  const readableError = error instanceof DashboardError ? error.message : 'Request failed. Check your connection and try again.'
  const links = sections.filter(section => data?.summary[section.key]).map(({path, label}) => ({ path: String(path), label: String(label) }))
  const knowledgeAdmin = data?.viewer.role === 'admin'
  if (knowledgeAdmin) links.push({ path: '/knowledge', label: 'Knowledge Base' })
  return <AppShell viewer={data?.viewer} sections={links}
    search={search} onSearch={setSearch} classic={originalDashboard!} onLogout={() => setConfirm(true)}>
    {isPending && <><PageHeader title="Dashboard" /><LoadingState /></>}
    {error && <><PageHeader title="Dashboard" /><ErrorState message={readableError}
      action={error instanceof DashboardError && error.status === 401 ? <a href={login}>Sign in</a> : <button className="secondary" onClick={() => void refetch()}>Retry</button>} /></>}
    {page && <Routes>
      <Route path="/" element={page} />
      {sections.map(section => <Route key={section.path} path={section.path} element={page} />)}
      {knowledgeAdmin && <Route path="/knowledge/*" element={<KnowledgeAdmin />} />}
      <Route path="*" element={<ErrorState message="This workspace page does not exist." action={<NavLink to="/">Return to dashboard</NavLink>} />} />
    </Routes>}
    {activeRow && <DetailDrawer row={activeRow} onClose={() => setActiveRow(null)} />}
    {confirm && <ConfirmDialog onClose={() => setConfirm(false)} onConfirm={() => window.location.assign(logout!)} />}
  </AppShell>
}

createRoot(mount).render(<QueryClientProvider client={queryClient}>
  <BrowserRouter basename={new URL(workspace!, location.href).pathname}><Workspace /></BrowserRouter>
</QueryClientProvider>)
