import { useQuery } from '@tanstack/react-query'
import { Link, useLocation, useParams, useSearchParams } from 'react-router-dom'
import { ErrorState, LoadingState, PageHeader } from './components'

type Detail = {
  item: {
    title: string | null
    summary: string | null
    category: string | null
    location: string | null
    sublocation: string | null
    department: string | null
    assign_to: string | number | null
    created_at: string | null
    edited_at: string | null
  }
  history: {
    items: {
      id: string | number
      summary: string | null
      category: string | null
      created_at: string | null
      edited_at: string | null
      editor_first: string | null
      editor_last: string | null
      assignee_first: string | null
      assignee_last: string | null
    }[]
    total: number
    page: number
    pageSize: number
  }
}

export default function WorkOrderDetail({ endpoint, direction }: { endpoint: string; direction: 'incoming' | 'outgoing' }) {
  const { id } = useParams()
  const location = useLocation()
  const [params, setParams] = useSearchParams()
  const page = params.get('page') ?? '1'
  const { data, error, isPending, refetch } = useQuery<Detail>({
    queryKey: ['work-order-detail', endpoint, direction, id, page],
    retry: false,
    queryFn: async ({ signal }) => {
      const url = new URL(endpoint, window.location.href)
      url.pathname += '/' + direction + '/' + encodeURIComponent(id ?? '')
      url.searchParams.set('page', page)
      const response = await fetch(url, { signal, credentials: 'same-origin', headers: { Accept: 'application/json' } })
      if (!response.ok) {
        const problem = await response.json().catch(() => ({}))
        throw new Error(problem.error || 'Could not load the Work Order.')
      }
      return response.json()
    },
  })
  const backSearch = typeof location.state?.listSearch === 'string' ? location.state.listSearch : ''
  const item = data?.item
  const history = data?.history

  return <>
    <PageHeader title={'Work Order #' + id}>
      <Link className="secondary" to={{ pathname: '/work-orders/' + direction, search: backSearch }}>Back to list</Link>
    </PageHeader>
    {isPending && <LoadingState />}
    {error && <ErrorState message={error.message} action={<button type="button" onClick={() => void refetch()}>Retry</button>} />}
    {item && history && <>
      <section className="panel">
        <header className="panel-head"><h2>{item.title}</h2></header>
        <div className="empty">
          <p>{item.summary}</p>
          <dl>
            <dt>Status</dt><dd>{item.category || '—'}</dd>
            <dt>Location</dt><dd>{[item.location, item.sublocation].filter(Boolean).join(' / ') || '—'}</dd>
            <dt>Department</dt><dd>{item.department || '—'}</dd>
            <dt>Assignee ID</dt><dd>{item.assign_to ?? '—'}</dd>
            <dt>Created</dt><dd>{item.created_at || '—'}</dd>
            <dt>Replied</dt><dd>{item.edited_at || '—'}</dd>
          </dl>
        </div>
      </section>
      <section className="panel">
        <header className="panel-head"><h2>History</h2></header>
        {history.items.length ? <div className="table-wrap"><table>
          <thead><tr>
            <th scope="col">Date</th><th scope="col">Status</th><th scope="col">Note</th>
            <th scope="col">Replied by</th><th scope="col">Assigned to</th>
          </tr></thead>
          <tbody>{history.items.map(row => <tr key={row.id}>
            <td>{row.edited_at ?? row.created_at}</td><td>{row.category}</td><td>{row.summary}</td>
            <td>{[row.editor_first, row.editor_last].filter(Boolean).join(' ')}</td>
            <td>{[row.assignee_first, row.assignee_last].filter(Boolean).join(' ')}</td>
          </tr>)}</tbody>
        </table></div> : <p className="empty">No history entries.</p>}
        <nav className="panel-head" aria-label="History pages">
          <button type="button" disabled={history.page <= 1}
            onClick={() => setParams({ page: String(history.page - 1) })}>Previous</button>
          <span>Page {history.page} · {history.total} entries</span>
          <button type="button" disabled={history.page * history.pageSize >= history.total}
            onClick={() => setParams({ page: String(history.page + 1) })}>Next</button>
        </nav>
      </section>
    </>}
  </>
}
