import { useState } from 'react'
import type { FormEvent } from 'react'
import { ErrorState, PageHeader } from './components'

export default function AccountPassword({ endpoint }: { endpoint: string }) {
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [saved, setSaved] = useState(false)
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = event.currentTarget
    const body = new FormData(form)
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    setSaving(true)
    setSaved(false)
    setError('')
    try {
      const options = await fetch(endpoint, { credentials: 'same-origin', headers })
      if (!options.ok) throw new Error('Password changes are unavailable.')
      const { csrf } = await options.json()
      const result = await fetch(endpoint, {
        method: 'POST', credentials: 'same-origin', body, headers: { ...headers, [csrf.header]: csrf.hash },
      })
      if (!result.ok) {
        const problem = await result.json().catch(() => ({}))
        throw new Error(problem.error || 'Could not change the password.')
      }
      form.reset()
      setSaved(true)
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Could not change the password.')
    } finally {
      setSaving(false)
    }
  }

  return <>
    <PageHeader title="Change password" />
    <section className="panel">
      {error && <ErrorState message={error} />}
      {saved && <p className="empty" role="status">Password changed.</p>}
      <form className="filter-panel" onSubmit={event => void submit(event)}>
        <label>Current password<input name="old_password" type="password" autoComplete="current-password" required /></label>
        <label>New password<input name="password" type="password" autoComplete="new-password" required minLength={4} maxLength={50} /></label>
        <label>Confirm new password<input name="password_confirm" type="password" autoComplete="new-password" required minLength={4} maxLength={50} /></label>
        <button type="submit" className="secondary" disabled={saving}>{saving ? 'Saving…' : 'Change password'}</button>
      </form>
    </section>
  </>
}
