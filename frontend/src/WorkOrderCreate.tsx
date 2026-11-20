import { useRef, useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ErrorState, LoadingState, PageHeader } from './components'

type Choice = { id: number | string; name: string }
type Department = Choice & { corp_id: number | string }
type Location = Department & { parent_id: number | string }
type Csrf = { header: string; hash: string }
type Preview = {
  reason: string | null
  submission: { endpoint: string; csrf: Csrf } | null
  canChooseScope: boolean
  canSchedule: boolean
  corporations: Choice[]
  departments: Department[]
  locations: Location[]
  destinationRoles: Choice[]
  category: Choice | null
}

async function loadPreview(endpoint: string, signal: AbortSignal): Promise<Preview> {
  const response = await fetch(endpoint, { headers: { Accept: 'application/json' }, signal })
  if (!response.ok) {
    const body = await response.json().catch(() => ({}))
    throw new Error(body.error || 'Could not load Create Work Order options.')
  }
  return response.json()
}

function options(choices: Choice[]) {
  return choices.map(({ id, name }) => <option key={id} value={id}>{name}</option>)
}

function CreateWorkOrderForm({ data }: { data: Preview }) {
  const [corporation, setCorporation] = useState(data.canChooseScope ? '' : String(data.corporations[0].id))
  const [location, setLocation] = useState('')
  const departments = data.departments.filter(row => String(row.corp_id) === corporation)
  const locations = data.locations.filter(row => String(row.corp_id) === corporation)
  const sublocations = location ? locations.filter(row => String(row.parent_id) === location) : []
  const queryClient = useQueryClient()
  const pending = useRef(false)
  const csrf = useRef<Csrf | null>(null)
  const mutation = useMutation({
    mutationFn: async ({ fields, submission }: { fields: FormData; submission: NonNullable<Preview['submission']> }) => {
      const token = csrf.current ?? submission.csrf
      const response = await fetch(submission.endpoint, {
        method: 'POST', body: fields, headers: { Accept: 'application/json', [token.header]: token.hash },
      })
      const result = await response.json().catch(() => ({}))
      if (result.csrf) csrf.current = result.csrf
      if (response.status !== 201 || !Number.isSafeInteger(result.id) || result.id < 1) {
        throw new Error(result.error || 'Create could not be confirmed. Check the work order lists before retrying.')
      }
      return { id: result.id as number, draft: fields.get('status') === '0' }
    },
    retry: false,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['work-orders'] })
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
    },
    onSettled: () => { pending.current = false },
  })

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const button = (event.nativeEvent as SubmitEvent).submitter
    if (!data.submission || data.category === null || pending.current || mutation.isSuccess || !(button instanceof HTMLButtonElement)) return
    pending.current = true
    mutation.mutate({ fields: new FormData(event.currentTarget, button), submission: data.submission })
  }

  return <form className="panel create-wo-form" aria-describedby={data.reason ? 'create-wo-status' : undefined} onSubmit={submit}>
    <input type="hidden" name="post_type" value="post" />
    <input type="hidden" name="lang_id" value="1" />
    <input type="hidden" name="visibility" value="1" />
    <label>Corporation{data.canChooseScope
      ? <select name="corp_id" required value={corporation} onChange={event => {
        setCorporation(event.target.value)
        setLocation('')
      }}><option value="">Select corporation</option>{options(data.corporations)}</select>
      : <output>{data.corporations[0].name}</output>}
    </label>
    <label>Department{data.canChooseScope
      ? <select key={corporation} name="dept_id" required disabled={!corporation}>
        <option value="">Select department</option>{options(departments)}
      </select>
      : <output>{data.departments[0].name}</output>}
    </label>
    <label className="create-wo-wide">Title<input name="title" required maxLength={500} pattern={String.raw`.*\S.*`} /></label>
    <label>Assign to role<select name="role_id" required>
      <option value="">Select destination role</option>{options(data.destinationRoles)}
    </select></label>
    <label>Initial category<output>{data.category?.name ?? 'New category unavailable'}</output></label>
    {data.category && <input type="hidden" name="category_id" value={data.category.id} />}
    <label>Location<select name="location_id" required disabled={!corporation} value={location} onChange={event => setLocation(event.target.value)}>
      <option value="">Select location</option>{options(locations.filter(row => Number(row.parent_id) === 0))}
    </select></label>
    <label>Sublocation<select key={location} name="sublocation_id" required disabled={!location}>
      <option value="0">Select sublocation</option>{options(sublocations)}
    </select></label>
    <label className="create-wo-wide">Description<textarea name="summary" maxLength={5000} rows={4} /></label>
    <label className="create-wo-wide">Image description<input name="image_description" maxLength={500} /></label>
    <fieldset className="create-wo-wide" disabled>
      <legend>Image selection unavailable</legend>
      <button className="secondary" type="button">Select main image</button>
      <button className="secondary" type="button">Select additional images</button>
    </fieldset>
    {data.canSchedule && <fieldset className="create-wo-wide" disabled>
      <legend>Scheduled creation unavailable</legend>
      <label><span><input type="checkbox" name="scheduled_post" value="1" /> Schedule work order</span></label>
      <label>Publication date<input type="datetime-local" name="date_published" /></label>
      <label>Interval in days<input name="intervale" /></label>
    </fieldset>}
    {data.submission && <div className="create-wo-wide">
      <button className="secondary" type="submit" name="status" value="0" disabled={mutation.isPending || mutation.isSuccess || data.category === null}>Save Draft</button>{' '}
      <button className="primary" type="submit" name="status" value="1" disabled={mutation.isPending || mutation.isSuccess || data.category === null}>Publish Work Order</button>
      {mutation.isPending && <p role="status">Saving work order…</p>}
      {mutation.error && <p role="alert">{mutation.error.message}</p>}
      {mutation.data && <p role="status">Work Order #{mutation.data.id} {mutation.data.draft ? 'saved as a draft' : 'published'}.</p>}
    </div>}
  </form>
}

export default function WorkOrderCreate({ endpoint }: { endpoint: string }) {
  const query = useQuery({ queryKey: ['work-order-create-preview', endpoint],
    queryFn: ({ signal }) => loadPreview(endpoint, signal), retry: false })

  return <>
    <PageHeader title="Create Work Order" />
    {query.isPending && <LoadingState />}
    {query.error && <ErrorState message={query.error.message} />}
    {query.data && <>
      {query.data.reason && <p id="create-wo-status" role="status">{query.data.reason} This form is a preview. Entries are discarded when you leave the page.</p>}
      <CreateWorkOrderForm data={query.data} />
    </>}
  </>
}
