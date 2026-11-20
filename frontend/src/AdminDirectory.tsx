import { useState } from 'react'
import type { FormEvent } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { ErrorState, LoadingState, PageHeader } from './components'

type DirectoryPage = {
  items: (Record<string, string | number | boolean | null> & { editable?: boolean })[]
  editing?: boolean
  canCreate?: boolean
  page: number
  pageSize: number
  total: number
}

type EditForm = {
  fields: Record<string, string | number | null>
  checks?: string[]
  choices?: Record<string, {value: string | number; label: string; corp_id?: string | number}[]>
  csrf: { header: string; hash: string }
}

const editLabels: Record<string, string> = {
  name: 'Name', order: 'Order', corp_code: 'Corporation code', name_slug: 'Slug',
  lang_id: 'Language ID', color: 'Color', visibility: 'Visibility', parent_id: 'Parent ID', corp_id: 'Corporation',
  admin_panel: 'Admin panel', add_post: 'Add post', manage_all_posts: 'Manage all posts',
  navigation: 'Navigation', categories: 'Categories', users: 'Users', settings: 'Settings',
  first_name: 'First name', last_name: 'Last name', email: 'Email', password: 'Initial password',
  dept_id: 'Department', role_id: 'Role', status: 'Account status', confirm_email: 'Mark current email as verified',
}
const numericFields = ['order', 'lang_id', 'visibility', 'parent_id', 'corp_id', 'dept_id']
const relationFields = ['lang_id', 'parent_id', 'corp_id']

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
  const [editing, setEditing] = useState<{ path: string; existing: boolean } | null>(null)
  const [saving, setSaving] = useState(false)
  const [saveError, setSaveError] = useState('')
  const [chosenCorporation, setChosenCorporation] = useState<string | null>(null)
  const editor = useQuery<EditForm>({
    queryKey: ['directory-editor', endpoint, editing?.path],
    enabled: editing !== null,
    retry: false,
    staleTime: 0,
    queryFn: async ({ signal }) => {
      const response = await fetch(endpoint + '/' + editing?.path,
        { signal, credentials: 'same-origin', headers: { Accept: 'application/json' } })
      if (!response.ok) {
        const problem = await response.json().catch(() => ({}))
        throw new Error(problem.error || 'Could not open the editor.')
      }
      return response.json()
    },
  })
  function openEditor(path: string, existing: boolean) {
    setSaveError('')
    setChosenCorporation(null)
    setEditing({ path, existing })
  }
  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!editing || !editor.data) return
    const form = new FormData(event.currentTarget)
    const body = new URLSearchParams()
    for (const [name, value] of form) if (typeof value === 'string') body.set(name, value)
    setSaving(true)
    setSaveError('')
    try {
      const csrf = (await editor.refetch({ throwOnError: true })).data?.csrf
      if (!csrf) throw new Error('Could not refresh the form session.')
      const response = await fetch(endpoint + '/' + editing.path, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', [csrf.header]: csrf.hash },
        body,
      })
      if (!response.ok) {
        const problem = await response.json().catch(() => ({}))
        throw new Error(problem.error || 'Could not save the record.')
      }
      setEditing(null)
      await request.refetch()
    } catch (error) {
      setSaveError(error instanceof Error ? error.message : 'Could not save the record.')
    } finally {
      setSaving(false)
    }
  }
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
    <PageHeader title={title}>
      {result?.canCreate && <button type="button" className="secondary" disabled={saving}
        onClick={() => openEditor('new', false)}>Add record</button>}
    </PageHeader>
    {editing && <section className="panel" aria-label="Master data editor">
      <header className="panel-head"><h2>{editing.existing ? 'Edit record' : 'Add record'}</h2>
        <button type="button" className="secondary" disabled={saving} onClick={() => setEditing(null)}>Cancel</button>
      </header>
      {editor.isFetching && !editor.data && <LoadingState />}
      {editor.error && <ErrorState message={editor.error.message} />}
      {saveError && <ErrorState message={saveError} />}
      {editor.data && <form className="filter-panel" key={editing.path} onSubmit={event => void save(event)}>
        {Object.entries(editor.data.fields).map(([name, value]) => <label key={name}>{name === 'password' && editing.existing ? 'New password (optional)' : editLabels[name]}
          {name === 'status' ? <select name={name} defaultValue={value ?? ''} required>
            <option value="1">Active</option><option value="0">Inactive</option>
          </select> : editor.data?.choices?.[name] ? <select name={name} required
            key={name === 'dept_id' ? chosenCorporation : name}
            defaultValue={name === 'dept_id' && chosenCorporation !== null ? '' : value ?? ''}
            onChange={name === 'corp_id' ? event => setChosenCorporation(event.target.value) : undefined}>
            <option value="">Select</option>
            {editor.data.choices[name]
              .filter(option => option.corp_id === undefined || String(option.corp_id) === (chosenCorporation ?? String(editor.data?.fields.corp_id ?? '')))
              .map(option => <option value={option.value} key={option.value}>{option.label}</option>)}
          </select> : editor.data?.checks?.includes(name) ? <>
            <input name={name} type="hidden" value="0" />
            <input name={name} type="checkbox" value="1" defaultChecked={Number(value) === 1} />
          </> : <input name={name} defaultValue={value ?? ''} required={name !== 'name_slug' && !(name === 'password' && editing.existing)}
            type={numericFields.includes(name) ? 'number' : name === 'password' || name === 'email' ? name : 'text'}
            autoComplete={name === 'password' ? 'new-password' : undefined}
            min={['order', 'visibility'].includes(name) ? 0 : 1} max={name === 'visibility' ? 2 : undefined}
            maxLength={name === 'password' ? 50 : ['corp_code', 'first_name', 'last_name'].includes(name) ? 100 : 200}
            readOnly={editing.existing && relationFields.includes(name)} />}
        </label>)}
        <button type="submit" className="secondary" disabled={saving}>{saving ? 'Saving…' : 'Save'}</button>
      </form>}
    </section>}
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
        <thead><tr>{activeFields.map(([key, label]) => <th scope="col" key={key}>{label}</th>)}{result.editing && <th scope="col">Actions</th>}</tr></thead>
        <tbody>{result.items.map(item => <tr key={String(item.id)}>
          {activeFields.map(([key]) => <td key={key}>{item[key]}</td>)}
          {result.editing && <td>{item.editable && <button type="button" className="secondary" disabled={saving}
            onClick={() => openEditor(item.id + '/edit', true)}>Edit</button>}</td>}
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

