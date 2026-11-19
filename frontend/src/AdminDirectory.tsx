import { useQuery } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { ErrorState, LoadingState, PageHeader } from './components'

type DirectoryPage = {
  items: Record<string, string | number | null>[]
  page: number
  pageSize: number
  total: number
}

const fields = {
  id: 'ID', name: 'Name', first_name: 'First name', last_name: 'Last name',
  parent_id: 'Parent ID', lang_id: 'Language ID', corp_id: 'Corporation ID',
  dept_id: 'Department ID', role: 'Role', status: 'Account status',
  corp_code: 'Corporation code', corporation_status: 'Status', permissions: 'Permissions',
}

export default function AdminDirectory({ endpoint, title }: { endpoint: string; title: string }) {
  const [params, setParams] = useSearchParams()
  const search = params.toString()
  const request = useQuery<DirectoryPage>({
    queryKey: ['directory', endpoint, search],
    retry: false,
    queryFn: async ({ signal }) => {
      const url = new URL(endpoint, location.href)
      url.search = search
      const response = await fetch(url, { signal, credentials: 'same-origin', headers: { Accept: 'application/json' } })
      if (!response.ok) {
        const error = await response.json().catch(() => ({}))
        throw new Error(error.error || 'Could not load the directory.')
      }
      return response.json()
    },
  })
  const result = request.data
  const page = result?.page ?? 1
  const pageCount = result ? Math.max(1, Math.ceil(result.total / result.pageSize)) : 1
  const activeFields = Object.entries(fields).filter(([key]) => result?.items[0] && key in result.items[0])
  function goToPage(number: number) {
    const next = new URLSearchParams(params)
    next.set('page', String(number))
    setParams(next)
  }

  return <>
    <PageHeader title={title} />
    <form className="filter-panel" key={endpoint + search} onSubmit={event => {
      event.preventDefault()
      const value = String(new FormData(event.currentTarget).get('q') ?? '').trim()
      setParams(value === '' ? {} : { q: value })
    }}>
      <label>Search name<input name="q" maxLength={100} type="search" defaultValue={params.get('q') ?? ''} /></label>
      <button type="submit" className="secondary">Search</button>
    </form>
    {request.isPending && <LoadingState />}
    {request.error && <ErrorState message={request.error.message}
      action={<button type="button" className="secondary" onClick={() => void request.refetch()}>Retry</button>} />}
    {result && <section className="panel">
      {result.items.length ? <div className="table-wrap"><table>
        <thead><tr>{activeFields.map(([key, label]) => <th scope="col" key={key}>{label}</th>)}</tr></thead>
        <tbody>{result.items.map(item => <tr key={item.id}>
          {activeFields.map(([key]) => <td key={key}>{item[key]}</td>)}
        </tr>)}</tbody>
      </table></div> : <p className="empty">No records found.</p>}
      <nav className="panel-head" aria-label={title + ' pages'}>
        <button type="button" className="secondary" disabled={page <= 1} onClick={() => goToPage(page - 1)}>Previous</button>
        <span>Page {page} of {pageCount} � {result.total} records</span>
        <button type="button" className="secondary" disabled={page >= pageCount} onClick={() => goToPage(page + 1)}>Next</button>
      </nav>
    </section>}
  </>
}

