import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { ErrorState, LoadingState, PageHeader } from './components'

import { exportReport, reportHeadings } from './reportExport'

type Direction = 'incoming' | 'outgoing' | 'drafts' | 'scheduled'
type Order = {
  id: number
  title: string | null
  summary: string | null
  category_id: number | null
  corp_id: number | null
  dept_id: number | null
  role_id: number | null
  user_id: number | null
  created_at: string
  edited_at: string | null
  intervale?: string | number | null
  published_at?: string | null
}

const reportColumnKeys = [
  'category_name', 'subcategory_name', 'location_name', 'sublocation_name', 'department_name',
  'author_first', 'author_last', 'author_role', 'assigned_role_name', 'assigned_first', 'assigned_last',
] as const

type ReportOrder = Order & Record<(typeof reportColumnKeys)[number], string | null> & { has_image: boolean }

function reportCells(row: Order): string[] {
  const fields = row as ReportOrder
  const author = [fields.author_first, fields.author_last].filter(Boolean).join(' ')
  return [
    String(row.id), row.title ?? '', row.summary ?? '',
    [fields.category_name, fields.subcategory_name].filter(Boolean).join(' '),
    (fields.location_name ?? '') + ' / ' + (fields.sublocation_name ?? ''),
    fields.department_name ?? '',
    [author, fields.author_role ? '(' + fields.author_role + ')' : ''].filter(Boolean).join(' '),
    fields.assigned_role_name ?? '',
    [fields.assigned_first, fields.assigned_last].filter(Boolean).join(' '),
    row.edited_at ?? '', row.created_at ?? '', '',
  ]
}


type OrderPage = { items: Order[]; page: number; pageSize: number; total: number }
const statusNames: Record<number, string> = { 1: 'New', 2: 'Progress', 3: 'Waiting', 4: 'Closed', 5: 'Pending' }

export default function WorkOrders({ endpoint, direction, role, report = false }: { endpoint: string; direction: Direction; role: number; report?: boolean }) {
  const [params, setParams] = useSearchParams()
  const search = params.toString()
  const [exporting, setExporting] = useState(false)
  const [exportError, setExportError] = useState('')
  const title = direction === 'scheduled' ? 'Scheduled Work Orders' : direction === 'drafts' ? 'Draft Work Orders' : report
    ? (direction === 'incoming' ? 'Reports In' : 'Reports Out')
    : (direction === 'incoming' ? 'Incoming Work Orders' : 'Outgoing Work Orders')
  const query = useQuery<OrderPage>({
    queryKey: ['work-orders', endpoint, direction, search],
    retry: false,
    queryFn: async ({ signal }) => {
      const url = new URL(endpoint, location.href)
      url.pathname += '/' + direction
      url.search = search
      const response = await fetch(url, { signal, credentials: 'same-origin', headers: { Accept: 'application/json' } })
      if (!response.ok) {
        const problem = await response.json().catch(() => ({}))
        throw new Error(problem.error || 'Could not load work orders.')
      }
      return response.json()
    },
  })
  const result = query.data
  const page = result?.page ?? 1
  const pages = result ? Math.max(1, Math.ceil(result.total / result.pageSize)) : 1
  function goTo(newPage: number) {
    const next = new URLSearchParams(params)
    next.set('page', String(newPage))
    setParams(next)
  }

  async function download(format: 'pdf' | 'xlsx') {
    if (!result?.items.length) return
    setExporting(true)
    setExportError('')
    try {
      await exportReport(title, format, result.items.map(reportCells))
    } catch {
      setExportError('Could not export the report.')
    } finally {
      setExporting(false)
    }
  }

  return <>
    <PageHeader title={title}>
      {report && <>
        <button type="button" className="secondary" disabled={exporting || !result?.items.length} onClick={() => void download('pdf')}>PDF</button>
        <button type="button" className="secondary" disabled={exporting || !result?.items.length} onClick={() => void download('xlsx')}>Excel</button>
      </>}
    </PageHeader>
    {exportError && <ErrorState message={exportError} />}

    <section className="panel">
      <form key={direction + search} className="filter-panel" onSubmit={event => {
        event.preventDefault()
        const next = new URLSearchParams()
        for (const [name, value] of new FormData(event.currentTarget)) {
          if (typeof value === 'string' && value) next.set(name, value)
        }
        setParams(next)
      }}>
        <label>Title<input name="q" type="search" maxLength={255} defaultValue={params.get('q') ?? ''} /></label>
        <label>Status<select name="category" defaultValue={params.get('category') ?? ''}>
          <option value="">All</option>
          {Object.entries(statusNames).map(([id, status]) => <option key={id} value={id}>{status}</option>)}
        </select></label>
        {role === 1 && direction !== 'scheduled' && <label>Corporation ID<input name="corpnya" inputMode="numeric" defaultValue={params.get('corpnya') ?? ''} /></label>}
        {[1, 7, 8].includes(role) && direction !== 'scheduled' && (direction === 'incoming'
          ? <label>Assigned role ID<input name="role" inputMode="numeric" defaultValue={params.get('role') ?? ''} /></label>
          : <label>Department ID<input name="department" inputMode="numeric" defaultValue={params.get('department') ?? ''} /></label>)}
        <label>Author ID<input name="user" inputMode="numeric" defaultValue={params.get('user') ?? ''} /></label>
        <label>Date field<select name="dateChoice" defaultValue={params.get('dateChoice') ?? ''}>
          <option value="">All dates</option><option value="created_at">Created</option><option value="edited_at">Replied</option>
        </select></label>
        <label>From<input name="tglAwal" type="date" defaultValue={params.get('tglAwal') ?? ''} /></label>
        <label>To<input name="tglAkhir" type="date" defaultValue={params.get('tglAkhir') ?? ''} /></label>
        <label>Language ID<input name="lang_id" inputMode="numeric" defaultValue={params.get('lang_id') ?? ''} /></label>
        <label>Type<input name="post_type" defaultValue={params.get('post_type') ?? ''} /></label>
        <label>Rows per page<select name="show" defaultValue={params.get('show') ?? '15'}>
          {[15, 30, 60, 100].map(size => <option key={size} value={size}>{size}</option>)}
        </select></label>
        <button type="submit" className="secondary">Filter</button>
        <button type="button" className="secondary" onClick={() => setParams({})}>Clear filters</button>
      </form>
      {query.isPending && <LoadingState />}
      {query.error && <ErrorState message={query.error.message}
        action={<button type="button" className="secondary" onClick={() => void query.refetch()}>Retry</button>} />}
      {result && <>
        <div className="table-wrap">
          {report ? <table>
            <thead><tr>{reportHeadings.map(label => <th key={label} scope="col">{label}</th>)}</tr></thead>
            <tbody>{result.items.map(order => <tr key={order.id}>
              {reportCells(order).map((value, index) => <td key={index}>
                {index === 11 && (order as ReportOrder).has_image ? 'Image available' : value}
              </td>)}
            </tr>)}</tbody>
          </table> : <table>

            <thead><tr><th scope="col">ID</th><th scope="col">Title</th><th scope="col">Summary</th>
              <th scope="col">Status</th><th scope="col">Corporation</th><th scope="col">Department</th>
              <th scope="col">Assigned role</th><th scope="col">Author</th>
                            <th scope="col">Created</th><th scope="col">Replied</th>
              {direction === 'scheduled' && <><th scope="col">Interval</th><th scope="col">Next publication</th></>}
            </tr></thead>
            <tbody>{result.items.map(order => <tr key={order.id}>
              <td>{order.id}</td><td>{direction === 'incoming' || direction === 'outgoing'
                ? <Link to={'/work-orders/' + direction + '/' + order.id} state={{ listSearch: search }}>{order.title || 'Work Order #' + order.id}</Link>
                : order.title}</td><td>{order.summary}</td>
              <td>{order.category_id === null ? '' : statusNames[order.category_id] ?? order.category_id}</td><td>{order.corp_id}</td><td>{order.dept_id}</td>
              <td>{order.role_id}</td><td>{order.user_id}</td>              <td>{order.created_at}</td><td>{order.edited_at}</td>
              {direction === 'scheduled' && <><td>{order.intervale}</td><td>{order.published_at}</td></>}
            </tr>)}</tbody>
          </table>}
        </div>
        {result.items.length === 0 && <p className="empty">No work orders match this page.</p>}
        <nav className="panel-head" aria-label="Work order pages">
          <button type="button" className="secondary" disabled={page <= 1} onClick={() => goTo(page - 1)}>Previous</button>
          <span>Page {page} of {pages} · {result.total} work orders</span>
          <button type="button" className="secondary" disabled={page >= pages} onClick={() => goTo(page + 1)}>Next</button>
        </nav>
      </>}
    </section>
  </>
}

