import { useState } from 'react'
import type { FormEvent } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ErrorState, LoadingState, PageHeader } from './components'

type ImageSetting = { url: string | null; editable: boolean }
type Settings = {
  values?: Record<string, string | null>
  email_editable?: boolean
  avatar_url?: string | null
  avatar_editable?: boolean
  items?: { logo: ImageSetting; favicon: ImageSetting }
  csrf: { header: string; hash: string }
}

const labels: Record<string, string> = {
  first_name: 'First name', last_name: 'Last name', email: 'Email', corp_id: 'Corporation ID', dept_id: 'Department ID',
  avatar: 'Avatar', logo: 'Logo', favicon: 'Favicon',
}

export default function ProfileAndBranding({ endpoint, visual = false }: { endpoint: string; visual?: boolean }) {
  const [pending, setPending] = useState('')
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [version, setVersion] = useState(0)
  const options = useQuery<Settings>({
    queryKey: ['profile-and-branding', endpoint],
    retry: false,
    queryFn: async ({ signal }) => {
      const response = await fetch(endpoint, { signal, credentials: 'same-origin', headers: { Accept: 'application/json' } })
      if (!response.ok) throw new Error('Could not load this page.')
      return response.json()
    },
  })

  async function save(event: FormEvent<HTMLFormElement>, action: string) {
    event.preventDefault()
    const form = event.currentTarget
    const body = new FormData(form)
    setPending(action)
    setMessage('')
    setError('')
    try {
      const csrf = (await options.refetch({ throwOnError: true })).data?.csrf
      if (!csrf) throw new Error('Could not refresh the form session.')
      const response = await fetch(endpoint + '/' + action, {
        method: 'POST', body, credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', [csrf.header]: csrf.hash },
      })
      if (!response.ok) {
        const result = await response.json().catch(() => ({}))
        throw new Error(result.error || 'Could not save the change.')
      }
      form.reset()
      const updated = (await options.refetch({ throwOnError: true })).data
      if (action === 'avatar') setVersion(value => value + 1)
      if (action === 'favicon' && updated?.items?.favicon.url) {
        document.querySelector<HTMLLinkElement>('link[rel="icon"]')?.setAttribute('href', updated.items.favicon.url)
      }
      setMessage(labels[action] + ' updated.')
    } catch (problem) {
      setError(problem instanceof Error ? problem.message : 'Could not save the change.')
    } finally {
      setPending('')
    }
  }

  const data = options.data
  const values = Object.entries(data?.values ?? {})
  const uploadForm = (name: string, accept: string) => <form className="filter-panel" onSubmit={event => void save(event, name)}>
    <label>Replace {labels[name].toLowerCase()}<input name={name} type="file" accept={accept} required /></label>
    <button className="secondary" type="submit" disabled={Boolean(pending)}>{pending === name ? 'Saving…' : 'Save ' + labels[name].toLowerCase()}</button>
  </form>

  return <>
    <PageHeader title={visual ? 'Visual settings' : 'My profile'} />
    {options.isPending && <LoadingState />}
    {options.error && <ErrorState message={options.error.message} />}
    {error && <ErrorState message={error} />}
    {message && <p className="empty" role="status">{message}</p>}
    {data && !visual && <section className="panel">
      {data.avatar_url && <img className="profile-avatar" src={data.avatar_url + '?v=' + version} alt="Current avatar" />}
      <dl className="empty">{values.map(([key, value]) => <div key={key}><dt>{labels[key]}</dt><dd>{value || '—'}</dd></div>)}</dl>
      {data.email_editable && <form className="filter-panel" onSubmit={event => void save(event, 'email')}>
        <label>New email<input name="email" type="email" autoComplete="email" maxLength={200} required /></label>
        <label>Current password<input name="old_password" type="password" autoComplete="current-password" required /></label>
        <button className="secondary" type="submit" disabled={Boolean(pending)}>{pending === 'email' ? 'Saving…' : 'Save email'}</button>
      </form>}
      {data.avatar_editable && uploadForm('avatar', '.png,.jpg,.jpeg,.gif')}
    </section>}
    {data?.items && Object.entries(data.items).map(([kind, item]) => <section className="panel" key={kind}>
      <header className="panel-head"><h2>{labels[kind]}</h2></header>
      {item.url ? <img className="settings-image" src={item.url} alt={labels[kind]} />
        : <p className="empty">Current {kind} file is unavailable.</p>}
      {item.editable && uploadForm(kind, kind === 'favicon' ? '.png' : '.png,.jpg,.jpeg,.gif')}
    </section>)}
  </>
}
