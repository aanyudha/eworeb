import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ErrorState, LoadingState, PageHeader } from './components'

type Choice = { id: number | string; name: string }
type Department = Choice & { corp_id: number | string }
type Location = Department & { parent_id: number | string }
type Preview = {
  reason: string
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

  return <form className="panel create-wo-form" aria-describedby="create-wo-status" onSubmit={event => event.preventDefault()}>
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
    <label className="create-wo-wide">Description<textarea name="summary" required maxLength={5000} rows={4} /></label>
    <label className="create-wo-wide">Image description<input name="image_description" /></label>
    <fieldset className="create-wo-wide" disabled>
      <legend>Image selection unavailable in preview</legend>
      <button className="secondary" type="button">Select main image</button>
      <button className="secondary" type="button">Select additional images</button>
    </fieldset>
    {data.canSchedule && <fieldset className="create-wo-wide" disabled>
      <legend>Scheduled creation unavailable</legend>
      <label><span><input type="checkbox" name="scheduled_post" value="1" /> Schedule work order</span></label>
      <label>Publication date<input type="datetime-local" name="date_published" /></label>
      <label>Interval in days<input name="intervale" /></label>
    </fieldset>}
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
      <p id="create-wo-status" role="status">{query.data.reason} This form is a preview. Entries are discarded when you leave the page.</p>
      <CreateWorkOrderForm data={query.data} />
    </>}
  </>
}
