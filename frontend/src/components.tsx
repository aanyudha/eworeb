import { useEffect, useId, useRef, useState, type FormEvent, type ReactNode } from 'react'
import { Activity, Bell, ChevronDown, ChevronLeft, ChevronRight, ChevronUp, Database, LayoutDashboard, LogOut, Menu, Search, Settings, X } from 'lucide-react'
import { NavLink, useLocation } from 'react-router-dom'
import { flexRender, getCoreRowModel, getFilteredRowModel, getPaginationRowModel, getSortedRowModel, useReactTable, type ColumnDef } from '@tanstack/react-table'

export const statuses = ['New', 'Pending', 'Waiting', 'Progress', 'Closed'] as const
export type Status = typeof statuses[number]
export type Counts = Record<Status, number>
export type StatRow = { group: string; status: Status; count: number }
export type Filters = { corpnya: string | null; role: string | null; department: string | null }
type Corp = { id: number | string; name: string }
export type DashboardData = {
  summary: { incoming?: Counts; outgoing?: Counts; recap?: Record<string, Counts> };
  viewer: { name: string; role: string };
  corporations: Corp[];
  filters: Filters;
  canFilter: boolean;
}
const number = new Intl.NumberFormat()

export type WorkspaceNavItem = { path: string; label: string; unavailable?: string }
export type WorkspaceNavGroup = { label: string; items: WorkspaceNavItem[] }

function WorkspaceLink({ item, onNavigate }: { item: WorkspaceNavItem; onNavigate: () => void }) {
  const { path, label, unavailable } = item
  return unavailable
    ? <div className="nav-unavailable" role="link" aria-disabled="true" aria-label={label + '. ' + unavailable} title={unavailable}><Activity size={17} /><span>{label}<small>{unavailable}</small></span></div>
    : <NavLink to={path} aria-label={label} title={label} onClick={onNavigate}><Activity size={17} /><span>{label}</span></NavLink>
}

function NavigationGroup({ group, onNavigate, expandSidebar }: {
  group: WorkspaceNavGroup; onNavigate: () => void; expandSidebar: () => void;
}) {
  const { pathname } = useLocation()
  const active = group.items.some(({ path }) => pathname === path || pathname.startsWith(path + '/'))
  const [open, setOpen] = useState(active)
  const id = useId()
  useEffect(() => { if (active) setOpen(true) }, [active, pathname])
  const Icon = group.label === 'Master Data' ? Database : Settings
  return <div className="nav-group">
    <button className="nav-group-toggle" type="button" aria-label={group.label} aria-expanded={open} aria-controls={id} title={group.label}
      onClick={() => { setOpen(!open); if (!open) expandSidebar() }}>
      <Icon size={17} aria-hidden="true" /><span>{group.label}</span><ChevronDown className="nav-chevron" size={15} aria-hidden="true" />
    </button>
    <div id={id} className="nav-group-children" hidden={!open}>
      {group.items.map(item => <WorkspaceLink key={item.path} item={item} onNavigate={onNavigate} />)}
    </div>
  </div>
}

export function Sidebar({ sections, groups, onNavigate, expandSidebar }: {
  sections: WorkspaceNavItem[]; groups: WorkspaceNavGroup[]; onNavigate: () => void; expandSidebar: () => void;
}) {
  const { search } = useLocation()
  return <aside className="sidebar" id="workspace-navigation">
    <div className="brand"><span className="brand-mark">{document.getElementById('root')?.dataset.brandLogo ? <img className="brand-logo" src={document.getElementById('root')!.dataset.brandLogo} alt="" /> : <Activity size={20} />}</span><span>EWO <b>Reborn</b><small>Operations workspace</small></span></div>
    <p className="nav-caption">WORKSPACE</p>
    <nav aria-label="Workspace navigation">
      <NavLink to={{ pathname: "/", search }} aria-label="Dashboard" title="Dashboard" end onClick={onNavigate}><LayoutDashboard size={17} /><span>Dashboard</span></NavLink>
      {sections.map(item => <WorkspaceLink key={item.path} item={item} onNavigate={onNavigate} />)}
      {groups.filter(group => group.items.length > 0).map(group =>
        <NavigationGroup key={group.label} group={group} onNavigate={onNavigate} expandSidebar={expandSidebar} />)}
    </nav>
  </aside>
}

export function Topbar({ viewer, search, onSearch, toggle, expanded, accountLinks, onLogout }: {
  viewer?: DashboardData['viewer']; search: string; onSearch: (value: string) => void;
  toggle: () => void; expanded: boolean; accountLinks: WorkspaceNavItem[]; onLogout: () => void;
}) {
  const name = viewer?.name || viewer?.role || 'Account'
  const profile = useRef<HTMLDetailsElement>(null)
  return <header className="topbar">
    <button className="icon-button" type="button" aria-label="Toggle sidebar" aria-expanded={expanded} aria-controls="workspace-navigation" onClick={toggle}><Menu size={20} /></button>
    <label className="search"><Search size={17} /><input type="search" placeholder="Search dashboard rows" aria-label="Search dashboard rows" value={search} onChange={e => onSearch(e.target.value)} /></label>
    <div className="topbar-actions">
      <details className="menu"><summary className="icon-button" aria-label="Notifications"><Bell size={19} /></summary><div className="popover"><strong>Notifications</strong><p>Notifications are not available in this workspace.</p></div></details>
      <details className="menu" ref={profile}><summary className="profile" aria-label="Profile menu"><span className="avatar">{name[0].toUpperCase()}</span><span className="profile-name">{name}<small>{viewer?.role}</small></span><ChevronDown size={15} /></summary>
        <div className="popover">{accountLinks.map(({ path, label }) => <NavLink key={path} to={path} onClick={() => { if (profile.current) profile.current.open = false }}>{label}</NavLink>)}
          <button type="button" onClick={onLogout}><LogOut size={16} /> Sign out</button></div>
      </details>
    </div>
  </header>
}

export function AppShell({ sections, groups, accountLinks, viewer, search, onSearch, onLogout, children }: {
  sections: WorkspaceNavItem[]; groups: WorkspaceNavGroup[]; accountLinks: WorkspaceNavItem[];
  viewer?: DashboardData['viewer']; search: string; onSearch: (value: string) => void; onLogout: () => void; children: ReactNode;
}) {
  const [compact, setCompact] = useState(false)
  const [mobileOpen, setMobileOpen] = useState(false)
  const [mobile, setMobile] = useState(() => matchMedia('(max-width: 860px)').matches)
  useEffect(() => {
    const query = matchMedia('(max-width: 860px)')
    const change = () => { setMobile(query.matches); setMobileOpen(false) }
    query.addEventListener('change', change)
    return () => query.removeEventListener('change', change)
  }, [])
  return <div className={"shell" + (compact && !mobile ? " compact" : "") + (mobileOpen ? " mobile-open" : "")}>
    {mobileOpen && <button className="scrim" aria-label="Close sidebar" onClick={() => setMobileOpen(false)} />}
    <Sidebar sections={sections} groups={groups} onNavigate={() => setMobileOpen(false)} expandSidebar={() => { if (!mobile) setCompact(false) }} />
    <div className="workspace">
      <Topbar viewer={viewer} search={search} onSearch={onSearch} accountLinks={accountLinks} onLogout={onLogout}
        expanded={mobile ? mobileOpen : !compact} toggle={() => mobile ? setMobileOpen(v => !v) : setCompact(v => !v)} />
      <main className="content">{children}</main>
    </div>
  </div>
}

export function PageHeader({ title, children }: { title: string; children?: ReactNode }) {
  return <header className="page-header"><nav className="breadcrumb" aria-label="Breadcrumb"><NavLink to="/">Workspace</NavLink><ChevronRight size={14} /><span aria-current="page">{title}</span></nav>
    <div className="heading"><h1>{title}</h1>{children}</div>
  </header>
}

export function StatusBadge({ status }: { status: Status }) {
  return <span className={'status status-' + status.toLowerCase()}>{status}</span>
}

export function KPIStatCard({ status, count }: { status: Status; count: number }) {
  return <div className="kpi"><StatusBadge status={status} /><strong>{number.format(count)}</strong></div>
}

export function FilterPanel({ corporations, filters, onApply }: {
  corporations: Corp[]; filters: Filters; onApply: (filters: Filters) => void;
}) {
  function apply(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    onApply({ corpnya: String(form.get('corpnya') || '') || null, role: String(form.get('role') || '') || null, department: String(form.get('department') || '') || null })
  }
  const hasFilters = Object.values(filters).some(Boolean)
  return <form className="filter-panel" onSubmit={apply}>
    <label>Corporation<select name="corpnya" defaultValue={filters.corpnya ?? ''}><option value="">All corporations</option>
      {corporations.map(corp => <option key={corp.id} value={corp.id}>{corp.name}</option>)}
    </select></label>
    <label>Incoming role ID<input name="role" type="number" min="1" placeholder="All roles" defaultValue={filters.role ?? ''} /></label>
    <label>Outgoing department ID<input name="department" type="number" min="1" placeholder="All departments" defaultValue={filters.department ?? ''} /></label>
    <button className="primary" type="submit">Apply filters</button>
    {hasFilters && <button className="secondary" type="button" onClick={() => onApply({ corpnya: null, role: null, department: null })}>Clear</button>}
  </form>
}

const columns: ColumnDef<StatRow>[] = [
  { accessorKey: 'group', header: 'Work order group' },
  { accessorKey: 'status', header: 'Status', cell: ({ row }) => <StatusBadge status={row.original.status} /> },
  { accessorKey: 'count', header: 'Work orders', cell: ({ row }) => number.format(row.original.count) },
]

export function DataTable({ rows, search, onDetails }: { rows: StatRow[]; search: string; onDetails: (row: StatRow) => void }) {
  const table = useReactTable({
    data: rows, columns, state: { globalFilter: search }, initialState: { pagination: { pageSize: 10 } },
    getCoreRowModel: getCoreRowModel(), getFilteredRowModel: getFilteredRowModel(),
    getSortedRowModel: getSortedRowModel(), getPaginationRowModel: getPaginationRowModel(),
  })
  const { pageIndex, pageSize } = table.getState().pagination
  const total = table.getFilteredRowModel().rows.length
  return <section className="panel" aria-labelledby="breakdown">
    <div className="panel-head"><h2 id="breakdown">Status breakdown</h2><span>{total} rows</span></div>
    <div className="table-wrap"><table>
      <thead>{table.getHeaderGroups().map(group => <tr key={group.id}>{group.headers.map(header => {
        const sort = header.column.getIsSorted()
        return <th key={header.id} scope="col" aria-sort={sort === 'asc' ? 'ascending' : sort === 'desc' ? 'descending' : 'none'}>
          <button onClick={header.column.getToggleSortingHandler()}>{flexRender(header.column.columnDef.header, header.getContext())}
            {sort === 'asc' ? <ChevronUp size={14} /> : <ChevronDown size={14} />}</button>
        </th>
      })}<th scope="col"><span className="sr-only">Details</span></th></tr>)}</thead>
      <tbody>{table.getRowModel().rows.map(row => <tr key={row.id}>
        {row.getVisibleCells().map(cell => <td key={cell.id}>{flexRender(cell.column.columnDef.cell, cell.getContext())}</td>)}
        <td><button className="text-button" onClick={() => onDetails(row.original)}>Details</button></td>
      </tr>)}</tbody>
    </table></div>
    {total === 0 && <p className="empty" role="status">No dashboard rows match your search.</p>}
    {total > 0 && <footer className="pagination">
      <span>{pageIndex * pageSize + 1}–{Math.min((pageIndex + 1) * pageSize, total)} of {total}</span>
      <button aria-label="Previous page" disabled={!table.getCanPreviousPage()} onClick={() => table.previousPage()}><ChevronLeft size={17} /></button>
      <button aria-label="Next page" disabled={!table.getCanNextPage()} onClick={() => table.nextPage()}><ChevronRight size={17} /></button>
    </footer>}
  </section>
}

function Modal({ title, drawer = false, onClose, children }: { title: string; drawer?: boolean; onClose: () => void; children: ReactNode }) {
  const ref = useRef<HTMLDialogElement>(null)
  const id = useId()
  useEffect(() => { const dialog = ref.current; dialog?.showModal(); return () => dialog?.close() }, [])
  return <dialog ref={ref} className={drawer ? 'drawer' : 'dialog'} onCancel={onClose} aria-labelledby={id}>
    <div className="modal-head"><h2 id={id}>{title}</h2><button className="icon-button" aria-label="Close" onClick={onClose}><X size={20} /></button></div>{children}
  </dialog>
}

export function DetailDrawer({ row, onClose }: { row: StatRow; onClose: () => void }) {
  return <Modal title="Work order summary" drawer onClose={onClose}>
    <dl className="row-details"><dt>Work order group</dt><dd>{row.group}</dd><dt>Status</dt><dd><StatusBadge status={row.status} /></dd><dt>Work orders</dt><dd>{number.format(row.count)}</dd></dl>
  </Modal>
}

export function ConfirmDialog({ onClose, onConfirm }: { onClose: () => void; onConfirm: () => void }) {
  return <Modal title="Sign out?" onClose={onClose}><p>Your EWO workspace session will end.</p><div className="dialog-actions">
    <button className="secondary" onClick={onClose}>Cancel</button><button className="primary" onClick={onConfirm}>Sign out</button>
  </div></Modal>
}

export function LoadingState() {
  return <div role="status" aria-label="Loading dashboard" className="skeletons">{statuses.map(status => <div key={status} />)}</div>
}

export function ErrorState({ message, action }: { message: string; action?: ReactNode }) {
  return <div className="panel error" role="alert"><h2>Dashboard unavailable</h2><p>{message}</p>{action}</div>
}
