import { useState } from 'react'
import type { FormEvent } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ErrorState, LoadingState, PageHeader } from './components'

type Values = {
  values: Record<string, string | null>
  writable?: boolean
  csrf?: {header: string; hash: string}
}

const labels: Record<string, string> = {
  first_name: 'First name', last_name: 'Last name', email: 'Email', corp_id: 'Corporation ID', dept_id: 'Department ID',
  timezone: 'Timezone', application_name: 'Application name', about_footer: 'Footer description',
  optional_url_button_name: 'Optional link label', copyright: 'Copyright', contact_address: 'Contact address',
  contact_email: 'Contact email', contact_phone: 'Contact phone',
}

export default function WorkspaceSettings({ endpoint, title }: {endpoint: string; title: string}) {
  const [saving, setSaving] = useState(false)
  const [saved, setSaved] = useState(false)
  const [failure, setFailure] = useState('')
  const options = useQuery<Values>({
    queryKey: ['workspace-settings', endpoint],
    retry: false,
    queryFn: async ({signal}) => {
      const response = await fetch(endpoint, {signal, credentials: 'same-origin', headers: {Accept: 'application/json'}})
      if (!response.ok) throw new Error('Could not load this page.')
      return response.json()
    },
  })
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const body = new FormData(event.currentTarget)
    setSaving(true)
    setSaved(false)
    setFailure('')
    try {
      const csrf = (await options.refetch({throwOnError: true})).data?.csrf
      if (!csrf) throw new Error('Could not refresh the form session.')
      const response = await fetch(endpoint, {
        method: 'POST', body, credentials: 'same-origin',
        headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', [csrf.header]: csrf.hash},
      })
      if (!response.ok) {
        const problem = await response.json().catch(() => ({}))
        throw new Error(problem.error || 'Could not save settings.')
      }
      await options.refetch()
      setSaved(true)
    } catch (error) {
      setFailure(error instanceof Error ? error.message : 'Could not save settings.')
    } finally {
      setSaving(false)
    }
  }

  const values = Object.entries(options.data?.values ?? {})
  return <>
    <PageHeader title={title} />
    {options.isPending && <LoadingState />}
    {options.error && <ErrorState message={options.error.message} />}
    {options.data && <section className="panel">
      {failure && <ErrorState message={failure} />}
      {saved && <p className="empty" role="status">Settings saved.</p>}
      {options.data.writable ? <form className="filter-panel" onSubmit={event => void submit(event)}>
        {values.map(([name, value]) => <label key={name}>{labels[name]}
          {name === 'about_footer' ? <textarea name={name} defaultValue={value ?? ''} maxLength={1000} />
            : <input name={name} defaultValue={value ?? ''} maxLength={500} required={name === 'timezone'} />}
        </label>)}
        <button type="submit" className="secondary" disabled={saving}>{saving ? 'Saving…' : 'Save settings'}</button>
      </form> : <dl className="empty">
        {values.map(([name, value]) => <div key={name}><dt>{labels[name]}</dt><dd>{value || '—'}</dd></div>)}
      </dl>}
    </section>}
  </>
}
