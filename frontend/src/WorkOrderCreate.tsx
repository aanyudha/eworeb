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
  imagesEndpoint: string | null
  canChooseScope: boolean
  canSchedule: boolean
  corporations: Choice[]
  departments: Department[]
  locations: Location[]
  destinationRoles: Choice[]
  category: Choice | null
}

type CatalogImage = { id: number; preview: string | null }

function ImageSelection({ endpoint, corporation, disabled, upload }: {
  endpoint: string; corporation: string; disabled: boolean; upload: (file: File) => Promise<number>;
}) {
  const [main, setMain] = useState(0)
  const [additional, setAdditional] = useState<number[]>([])
  const [before, setBefore] = useState(0)
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState('')
  const { data, error, isPending, refetch } = useQuery({
    queryKey: ['create-images', endpoint, corporation, before], retry: false,
    queryFn: async ({ signal }): Promise<{ items: CatalogImage[]; canUpload: boolean }> => {
      const url = new URL(endpoint, location.href)
      url.search = new URLSearchParams({ corp_id: corporation, before: String(before) }).toString()
      const response = await fetch(url, { headers: { Accept: 'application/json' }, signal })
      if (!response.ok) throw new Error('Could not load images for this corporation.')
      return response.json()
    },
  })
  const items = data?.items ?? []

  async function send(file: File) {
    setBusy(true)
    setMessage('')
    try {
      const id = await upload(file)
      setBefore(0)
      if (before === 0) await refetch()
      setMessage(`Image #${id} is ready to select.`)
    } catch (error) {
      setMessage(error instanceof Error ? error.message : 'Could not upload the image.')
    } finally {
      setBusy(false)
    }
  }

  return <fieldset className="create-wo-wide" disabled={disabled || busy}>
    <legend>Work Order images</legend>
    {main !== 0 && <input type="hidden" name="post_image_id" value={main} />}
    {additional.map(id => <input key={id} type="hidden" name="additional_post_image_id[]" value={id} />)}
    {data?.canUpload && <label>Upload image (GIF, JPEG, PNG)
      <input type="file" accept=".gif,.jpg,.jpeg,.png" onChange={event => {
        const file = event.target.files?.[0]
        event.target.value = ''
        if (file) void send(file)
      }} />
    </label>}
    {busy && <span role="status">Uploading image…</span>}
    {message && <span role="status">{message}</span>}
    <div className="create-image-catalog">
      {isPending && <LoadingState />}
      {error && <p role="alert">{error.message}</p>}
      {data && items.length === 0 && <p>No images on this page.</p>}
      {items.map(image => <div className="create-image-choice" key={image.id}>
        {image.preview && <img src={image.preview} alt="" width="75" height="55" loading="lazy" />}
        <span>Image #{image.id}</span>
        <button type="button" className="secondary" aria-pressed={main === image.id}
          onClick={() => setMain(main === image.id ? 0 : image.id)}>Main</button>
        <button type="button" className="secondary" aria-pressed={additional.includes(image.id)}
          disabled={!additional.includes(image.id) && additional.length >= 50}
          onClick={() => setAdditional(ids => ids.includes(image.id) ? ids.filter(id => id !== image.id) : [...ids, image.id])}>Additional</button>
      </div>)}
    </div>
    {before > 0 && <button type="button" className="secondary" onClick={() => setBefore(0)}>Newest</button>}
    {items.length === 25 && <button type="button" className="secondary" onClick={() => setBefore(items[items.length - 1].id)}>Older images</button>}
    <p>Main image {main ? `#${main}` : 'unselected'}. {additional.length} additional selected.</p>
    {(main !== 0 || additional.length > 0) && <button type="button" className="secondary" onClick={() => { setMain(0); setAdditional([]) }}>Clear selections</button>}
  </fieldset>
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
  const [uploading, setUploading] = useState(false)

  async function uploadImage(file: File): Promise<number> {
    if (!data.submission || !data.imagesEndpoint || pending.current) throw new Error('Image upload is unavailable.')
    pending.current = true
    setUploading(true)
    try {
      const fields = new FormData()
      fields.set('corp_id', corporation)
      fields.set('image', file)
      const token = csrf.current ?? data.submission.csrf
      const response = await fetch(data.imagesEndpoint, { method: 'POST', body: fields,
        headers: { Accept: 'application/json', [token.header]: token.hash } })
      const result = await response.json().catch(() => ({}))
      if (result.csrf) csrf.current = result.csrf
      if (response.status !== 201) throw new Error(result.error || 'Could not upload the image.')
      return result.id
    } finally {
      pending.current = false
      setUploading(false)
    }
  }
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
      ? <select name="corp_id" required value={corporation} disabled={uploading} onChange={event => {
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
    {data.imagesEndpoint && corporation && <ImageSelection key={corporation} endpoint={data.imagesEndpoint} corporation={corporation}
      disabled={mutation.isPending || mutation.isSuccess} upload={uploadImage} />}
    {data.canSchedule && <fieldset className="create-wo-wide" disabled>
      <legend>Scheduled creation unavailable</legend>
      <label><span><input type="checkbox" name="scheduled_post" value="1" /> Schedule work order</span></label>
      <label>Publication date<input type="datetime-local" name="date_published" /></label>
      <label>Interval in days<input name="intervale" /></label>
    </fieldset>}
    {data.submission && <div className="create-wo-wide">
      <button className="secondary" type="submit" name="status" value="0" disabled={uploading || mutation.isPending || mutation.isSuccess || data.category === null}>Save Draft</button>{' '}
      <button className="primary" type="submit" name="status" value="1" disabled={uploading || mutation.isPending || mutation.isSuccess || data.category === null}>Publish Work Order</button>
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
