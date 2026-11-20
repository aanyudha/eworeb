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
  is_scheduled?: string | number | null
  intervale?: string | number | null
  published_at?: string | null
  corporation?: string | null
  department?: string | null
  destination_role?: string | null
  days_remaining?: number | string | null
}

const reportColumnKeys = [
  'category_name', 'subcategory_name', 'location_name', 'sublocation_name', 'department_name',
  'author_first', 'author_last', 'author_role', 'assigned_role_name', 'assigned_first', 'assigned_last',
] as const

type ReportOrder = Order & Record<(typeof reportColumnKeys)[number], string | null> & { has_image: boolean }

function reportCells(row: Order, subcategoryAvailable: boolean): string[] {
  const fields = row as ReportOrder
  const author = [fields.author_first, fields.author_last].filter(Boolean).join(' ')
  return [
    String(row.id), row.title ?? '', row.summary ?? '',
    subcategoryAvailable
      ? [fields.category_name, fields.subcategory_name].filter(Boolean).join(' ')
      : [fields.category_name, 'Subcategory unavailable'].filter(Boolean).join(' · '),
    (fields.location_name ?? '') + ' / ' + (fields.sublocation_name ?? ''),
    fields.department_name ?? '',
    [author, fields.author_role ? '(' + fields.author_role + ')' : ''].filter(Boolean).join(' '),
    fields.assigned_role_name ?? '',
    [fields.assigned_first, fields.assigned_last].filter(Boolean).join(' '),
    row.edited_at ?? '', row.created_at ?? '', fields.has_image ? 'Image available' : '',
  ]
}


type OrderPage = {
  items: Order[]
  page: number
  pageSize: number
  total: number
  subcategory_available?: boolean
  publish_csrf?: { header: string; hash: string }
  can_publish_scheduled_drafts?: boolean
}
const statusNames: Record<number, string> = { 1: 'New', 2: 'Progress', 3: 'Waiting', 4: 'Closed', 5: 'Pending', 6: 'Reopen' }

async function readOrders(url: URL, signal?: AbortSignal): Promise<OrderPage> {
  const response = await fetch(url, { signal, credentials: 'same-origin', headers: { Accept: 'application/json' } })
  if (!response.ok) {
    const problem = await response.json().catch(() => ({}))
    throw new Error(problem.error || 'Could not load work orders.')
  }
  return response.json()
}

export default function WorkOrders({ endpoint, direction, role, report = false }: { endpoint: string; direction: Direction; role: number; report?: boolean }) {
  const [params, setParams] = useSearchParams()
  const search = params.toString()
  const [exporting, setExporting] = useState(false)
  const [exportError, setExportError] = useState('')
  const [publishing, setPublishing] = useState<number | null>(null)
  const [publishError, setPublishError] = useState('')
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
      return readOrders(url, signal)
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

  async function publishOrder(id: number) {
    const csrf = result?.publish_csrf
    const action = direction === 'scheduled' ? 'Publish now and remove recurrence from Work Order #' : 'Publish Work Order #'
    if (!csrf || !window.confirm(action + id + '?')) return
    setPublishing(id)
    setPublishError('')
    try {
      const response = await fetch(endpoint + '/' + direction + '/' + id + '/publish', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', [csrf.header]: csrf.hash },
      })
      if (!response.ok) {
        const problem = await response.json().catch(() => ({}))
        throw new Error(problem.error || 'Could not publish the Work Order.')
      }
    } catch (error) {
      setPublishError(error instanceof Error ? error.message : 'Could not publish the Work Order.')
    } finally {
      await query.refetch()
      setPublishing(null)
    }
  }

  async function download(format: 'pdf' | 'xlsx') {
    if (!result?.total) return
    setExporting(true)
    setExportError('')
    try {
      const url = new URL(endpoint + '/' + direction, location.href)
      url.search = search
      url.searchParams.set('show', '100')
      url.searchParams.set('page', '1')
      const first = await readOrders(url)
      const rows = first.items.map(row => reportCells(row, first.subcategory_available !== false))
      const seen = new Set(first.items.map(row => row.id))
      const count = Math.ceil(first.total / first.pageSize)
      for (let number = 2; number <= count; number++) {
        url.searchParams.set('page', String(number))
        const next = await readOrders(url)
        if (next.total !== first.total || next.subcategory_available !== first.subcategory_available ||
          next.items.some(row => seen.has(row.id))) {
          throw new Error('Report results changed during export. Refresh and try again.')
        }
        for (const row of next.items) {
          seen.add(row.id)
          rows.push(reportCells(row, first.subcategory_available !== false))
        }
      }
      if (seen.size !== first.total || rows.length !== first.total) {
        throw new Error('Report results changed during export. Refresh and try again.')
      }
      await exportReport(title, format, rows)
    } catch (error) {
      setExportError(error instanceof Error ? error.message : 'Could not export the report.')
    } finally {
      setExporting(false)
    }
  }

  return <>
    <PageHeader title={title}>
      {report && <>
        <button type="button" className="secondary" disabled={exporting || !result?.total} onClick={() => void download('pdf')}>PDF</button>
        <button type="button" className="secondary" disabled={exporting || !result?.total} onClick={() => void download('xlsx')}>Excel</button>
      </>}
      {direction === 'scheduled' && <Link className="secondary" to="/work-orders/create">Create Work Order</Link>}
    </PageHeader>
    {exportError && <ErrorState message={exportError} />}
    {publishError && <ErrorState message={publishError} />}

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
        {report && result.subcategory_available === false &&
          <p className="empty" role="status">Subcategory is unavailable in the current legacy schema.</p>}
        <div className="table-wrap">
          {report ? <table>
            <thead><tr>{reportHeadings.map(label => <th key={label} scope="col">{label}</th>)}</tr></thead>
            <tbody>{result.items.map(order => <tr key={order.id}>
              {reportCells(order, result.subcategory_available !== false).map((value, index) => <td key={index}>{value}</td>)}
            </tr>)}</tbody>
          </table> : <table>

            <thead><tr><th scope="col">ID</th><th scope="col">Title</th><th scope="col">Summary</th>
              <th scope="col">Status</th><th scope="col">Corporation</th><th scope="col">Department</th>
              <th scope="col">Assigned role</th><th scope="col">Author</th>
                            <th scope="col">Created</th><th scope="col">Replied</th>
              {direction === 'scheduled' && <><th scope="col">Interval (days)</th><th scope="col">Next publication</th><th scope="col">Days remaining</th></>}
              {result.publish_csrf && <th scope="col">Actions</th>}
            </tr></thead>
            <tbody>{result.items.map(order => <tr key={order.id}>
              <td>{order.id}</td><td><Link to={'/work-orders/' + direction + '/' + order.id} state={{ listSearch: search }}>
                {order.title || 'Work Order #' + order.id}</Link></td><td>{order.summary}</td>
              <td>{order.category_id === null ? '' : statusNames[order.category_id] ?? order.category_id}</td><td>{order.corporation ?? order.corp_id}</td><td>{order.department ?? order.dept_id}</td>
              <td>{order.destination_role ?? order.role_id}</td><td>{order.user_id}</td>              <td>{order.created_at}</td><td>{order.edited_at}</td>
              {direction === 'scheduled' && <><td>{Number(order.intervale) > 0 ? order.intervale : 'Unavailable'}</td>
                <td>{order.published_at && Number(order.intervale) > 0 ? order.published_at : 'Schedule unavailable'}</td>
                <td>{order.published_at && Number(order.intervale) > 0 ? order.days_remaining : '—'}</td></>}
              {result.publish_csrf && <td>{(direction === 'scheduled' ? order.published_at && Number(order.intervale) > 0 :
                String(order.is_scheduled) === '0' || (result.can_publish_scheduled_drafts && order.published_at && Number(order.intervale) > 0)) &&
                <button type="button" className="secondary" disabled={publishing !== null}
                  onClick={() => void publishOrder(order.id)}>{publishing === order.id ? 'Publishing…' : direction === 'scheduled' ? 'Publish now' : 'Publish'}</button>}</td>}
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

