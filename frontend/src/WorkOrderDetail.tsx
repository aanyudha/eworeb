import { useState } from 'react'
import type { FormEvent } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link, useLocation, useParams, useSearchParams } from 'react-router-dom'
import { ErrorState, LoadingState, PageHeader } from './components'
import WorkOrderReply from './WorkOrderReply'

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
    corporation?: string | null
    corp_id?: string | number | null
    dept_id?: string | number | null
    destination_role?: string | null
    role_id?: string | number | null
    published_at?: string | null
    intervale?: string | number | null
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
      published_at?: string | null
      intervale?: string | number | null
    }[]
    total: number
    page: number
    pageSize: number
  }
}

export default function WorkOrderDetail({ endpoint, direction, replyEnabled = false }: { endpoint: string; direction: 'incoming' | 'outgoing' | 'drafts' | 'scheduled'; replyEnabled?: boolean }) {
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
  const [uploading, setUploading] = useState(false)
  const [uploadError, setUploadError] = useState('')
  const attachmentUrl = endpoint + '/' + direction + '/' + encodeURIComponent(id ?? '') + '/attachments'
  const attachments = useQuery<{
    items: { kind: string; id: number; label: string; download: string | null; unlink: string | null }[]
    upload?: { kind: 'image' | 'audio'; header: string; hash: string }
    csrf?: { header: string; hash: string }
  }>({
    queryKey: ['work-order-attachments', endpoint, direction, id],
    enabled: Boolean(item),
    retry: false,
    queryFn: async ({ signal }) => {
      const response = await fetch(attachmentUrl, { credentials: 'same-origin', signal })
      if (!response.ok) throw new Error('Could not load attachments.')
      return response.json()
    },
  })

  const upload = attachments.data?.upload
  async function unlinkAudio(url: string) {
    const csrf = attachments.data?.csrf
    if (!csrf || !window.confirm('Remove this audio attachment from the Work Order?')) return
    setUploading(true)
    setUploadError('')
    try {
      const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', [csrf.header]: csrf.hash },
      })
      if (!response.ok) {
        const problem = await response.json().catch(() => ({}))
        throw new Error(problem.error || 'Could not remove the attachment.')
      }
    } catch (error) {
      setUploadError(error instanceof Error ? error.message : 'Could not remove the attachment.')
    } finally {
      await attachments.refetch()
      setUploading(false)
    }
  }

  async function uploadAttachment(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!upload) return
    const form = event.currentTarget
    setUploading(true)
    setUploadError('')
    try {
      const response = await fetch(attachmentUrl + '/' + upload.kind, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', [upload.header]: upload.hash },
        body: new FormData(form),
      })
      if (!response.ok) {
        const problem = await response.json().catch(() => ({}))
        throw new Error(problem.error || 'Could not upload the attachment.')
      }
      form.reset()
    } catch (error) {
      setUploadError(error instanceof Error ? error.message : 'Could not upload the attachment.')
    } finally {
      await attachments.refetch()
      setUploading(false)
    }
  }

  return <>
    <PageHeader title={'Work Order #' + id}>
      <Link className="secondary" to={{ pathname: '/work-orders/' + direction, search: backSearch }}>Back to list</Link>
    </PageHeader>
    {isPending && <LoadingState />}
    {error && <ErrorState message={error.message} action={<button type="button" onClick={() => void refetch()}>Retry</button>} />}
    {item && history && <>
      {replyEnabled && id && <WorkOrderReply key={direction + id} url={endpoint + '/' + direction + '/' + encodeURIComponent(id) + '/reply'} />}
      <section className="panel">
        <header className="panel-head"><h2>{item.title}</h2></header>
        <div className="empty">
          <p>{item.summary}</p>
          <dl>
            <dt>Status</dt><dd>{item.category || '—'}</dd>
            <dt>Location</dt><dd>{[item.location, item.sublocation].filter(Boolean).join(' / ') || '—'}</dd>
            <dt>Department</dt><dd>{item.department || (direction === 'scheduled' ? item.dept_id ?? '—' : '—')}</dd>
            {direction === 'scheduled' && <>
              <dt>Corporation</dt><dd>{item.corporation ?? item.corp_id ?? '—'}</dd>
              <dt>Destination role</dt><dd>{item.destination_role ?? item.role_id ?? '—'}</dd>
              <dt>Next publication</dt><dd>{item.published_at && Number(item.intervale) > 0 ? item.published_at : 'Schedule unavailable'}</dd>
              <dt>Interval (days)</dt><dd>{Number(item.intervale) > 0 ? item.intervale : 'Unavailable'}</dd>
            </>}
            <dt>Assignee ID</dt><dd>{item.assign_to ?? '—'}</dd>
            <dt>Created</dt><dd>{item.created_at || '—'}</dd>
            <dt>Replied</dt><dd>{item.edited_at || '—'}</dd>
          </dl>
        </div>
      </section>
      <section className="panel">
        <header className="panel-head"><h2>Attachments</h2></header>
        {attachments.isPending && <LoadingState />}
        {attachments.error && <ErrorState message={attachments.error.message} />}
        {uploadError && <ErrorState message={uploadError} />}
        {upload && <form className="filter-panel" onSubmit={event => void uploadAttachment(event)}>
          <label>{upload.kind === 'audio' ? 'Audio (MP3 or WAV, up to 20 MB)' : 'Image (GIF, JPEG or PNG, up to 5 MB)'}
            <input type="file" name="attachment" required
              accept={upload.kind === 'audio' ? '.mp3,.wav' : '.gif,.jpg,.jpeg,.png'} />
          </label>
          {upload.kind === 'audio' && <>
            <label>Audio title<input name="audio_name" maxLength={150} required /></label>
            <label>Artist<input name="musician" maxLength={150} /></label>
            <label><input type="checkbox" name="download_button" value="1" /> Allow download</label>
          </>}
          <button type="submit" className="secondary" disabled={uploading}>{uploading ? 'Uploading…' : 'Upload'}</button>
        </form>}
        {attachments.data && (attachments.data.items.length ? <ul>
          {attachments.data.items.map(file => <li key={file.kind + ':' + file.id}>
            {file.download ? <a href={file.download}>{file.label}</a> : <span>{file.label} (unavailable)</span>}
            {file.unlink && <button className="secondary" type="button" disabled={uploading}
              onClick={() => void unlinkAudio(file.unlink!)}>Remove audio</button>}
          </li>)}
        </ul> : <p className="empty">No attachments.</p>)}
      </section>
      <section className="panel">
        <header className="panel-head"><h2>History</h2></header>
        {history.items.length ? <div className="table-wrap"><table>
          <thead><tr>
            <th scope="col">Date</th><th scope="col">Status</th><th scope="col">Note</th>
            <th scope="col">Replied by</th><th scope="col">Assigned to</th>
            {direction === 'scheduled' && <><th scope="col">Publication date</th><th scope="col">Interval (days)</th></>}
          </tr></thead>
          <tbody>{history.items.map(row => <tr key={row.id}>
            <td>{row.edited_at ?? row.created_at}</td><td>{row.category}</td><td>{row.summary}</td>
            <td>{[row.editor_first, row.editor_last].filter(Boolean).join(' ')}</td>
            <td>{[row.assignee_first, row.assignee_last].filter(Boolean).join(' ')}</td>
            {direction === 'scheduled' && <><td>{row.published_at ?? 'Unavailable'}</td><td>{row.intervale ?? 'Unavailable'}</td></>}
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
