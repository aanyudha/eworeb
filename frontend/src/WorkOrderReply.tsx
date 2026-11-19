import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { ErrorState, LoadingState } from './components'

type Options = {
  revision: string
  category_id: string | number
  assign_to: string | number | null
  categories: { id: string | number; name: string }[]
  assignees: { id: string | number; first_name: string; last_name: string }[]
  csrf: { header: string; hash: string }
}

async function optionsAt(url: string): Promise<Options | null> {
  const response = await fetch(url, { credentials: 'same-origin' })
  if (response.status === 403 || response.status === 404) return null
  if (!response.ok) throw new Error('Could not load Work Order reply choices.')
  return response.json()
}

export default function WorkOrderReply({ url }: { url: string }) {
  const cache = useQueryClient()
  const query = useQuery({ queryKey: ['work-order-reply', url], queryFn: () => optionsAt(url), retry: false })
  const [saved, setSaved] = useState(false)
  const mutation = useMutation({
    mutationFn: async (fields: FormData) => {
      const fresh = await optionsAt(url)
      if (!fresh) throw new Error('This Work Order is no longer available to reply to.')
      const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { [fresh.csrf.header]: fresh.csrf.hash, 'X-Requested-With': 'XMLHttpRequest' },
        body: fields,
      })
      const data = await response.json().catch(() => ({}))
      if (!response.ok || !data.history_id) throw new Error(data.error || 'Could not save the reply.')
      return data.history_id
    },
    onSuccess: async () => {
      setSaved(true)
      await Promise.all([
        cache.invalidateQueries({ queryKey: ['work-order-reply', url] }),
        cache.invalidateQueries({ queryKey: ['work-order-detail'] }),
        cache.invalidateQueries({ queryKey: ['work-orders'] }),
        cache.invalidateQueries({ queryKey: ['dashboard'] }),
      ])
    },
  })
  function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSaved(false)
    mutation.mutate(new FormData(event.currentTarget))
  }
  if (query.isPending) return <LoadingState />
  if (query.error) return <ErrorState message={query.error.message} />
  if (!query.data) return null
  const data = query.data
  return <section className="panel">
    <header className="panel-head"><h2>Reply</h2></header>
    <form key={data.revision} className="filter-panel" onSubmit={save}>
      <input name="revision" type="hidden" value={data.revision} />
      <label>Status<select name="category_id" required defaultValue={String(data.category_id)}>
        <option value="">Select a status</option>
        {data.categories.map(row => <option key={row.id} value={row.id}>{row.name}</option>)}
      </select></label>
      <label>Assignee<select name="assign_to" required defaultValue={data.assign_to ?? ''}>
        <option value="">Select an assignee</option>
        {data.assignees.map(row => <option key={row.id} value={row.id}>{row.first_name} {row.last_name}</option>)}
      </select></label>
      <label>Reply note<textarea name="summary_edit" required maxLength={5000} rows={4} /></label>
      <button className="secondary" type="submit" disabled={mutation.isPending}>
        {mutation.isPending ? 'Saving…' : 'Save reply'}
      </button>
      {saved && <p role="status">Reply saved.</p>}
      {mutation.error && <p role="alert">{mutation.error.message}</p>}
    </form>
  </section>
}
