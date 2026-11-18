import { useEffect, useState } from 'react'
import { Activity, ArrowUpRight, CalendarDays, ClipboardList, Ticket, UserRoundCog } from 'lucide-react'
import { Link } from 'react-router-dom'
import { fetchSuperAdminDashboardStatistics } from '../../services/superAdminDashboardService.js'
import '../../styles/admin-dashboard.css'

const numberFormat = new Intl.NumberFormat()

const USER_ROLES = [
  ['Super Admins', 'super_admin'],
  ['Organizers', 'organizer'],
  ['Staff', 'staff'],
  ['Clients', 'client'],
]
const EVENT_STATUSES = [
  ['Draft', 'draft'],
  ['Published', 'published'],
  ['Suspended', 'suspended'],
  ['Cancelled', 'cancelled'],
]
const RESERVATION_STATUSES = [
  ['Pending payment', 'pending_payment'],
  ['Confirmed', 'confirmed'],
  ['Cancelled', 'cancelled'],
  ['Expired', 'expired'],
]
const PAYMENT_STATUSES = [
  ['Unpaid', 'unpaid'],
  ['Pending', 'pending'],
  ['Paid', 'paid'],
  ['Failed', 'failed'],
  ['Refunded', 'refunded'],
  ['Expired', 'expired'],
]
const TICKET_STATUSES = [
  ['Pending', 'pending'],
  ['Valid', 'valid'],
  ['Cancelled', 'cancelled'],
]
const DEMO_PAYMENT_STATUSES = [
  ['Pending', 'pending'],
  ['Succeeded', 'succeeded'],
  ['Failed', 'failed'],
  ['Expired', 'expired'],
]

function displayCount(value) {
  return numberFormat.format(value)
}

function formatGeneratedAt(value) {
  return new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'UTC',
  }).format(new Date(value))
}

function errorMessage(error) {
  if (error.response?.status === 403) return 'Your account is not permitted to view these statistics.'
  if (error.response?.status === 401) return 'Your session has expired. Sign in again to continue.'
  return error.userMessage || error.message || 'Dashboard statistics could not be loaded. Please try again.'
}

function MetricCards({ items, compact = false }) {
  return <div className={`admin-stat-card-grid${compact ? ' is-compact' : ''}`}>
    {items.map(({ label, value, tone = '' }) => <article className={`admin-stat-card${tone ? ` ${tone}` : ''}`} key={label} aria-label={`${label}: ${displayCount(value)}`}>
      <span>{label}</span>
      <strong>{displayCount(value)}</strong>
    </article>)}
  </div>
}

function StatisticsSection({ id, title, description, items, secondary, badge }) {
  return <section className="admin-stat-section" aria-labelledby={`${id}-heading`}>
    <header className="admin-stat-section-heading">
      <div><h2 id={`${id}-heading`}>{title}</h2><p>{description}</p></div>
      {badge && <span className="admin-stat-badge">{badge}</span>}
    </header>
    <MetricCards items={items} />
    {secondary && <div className="admin-stat-secondary">
      <h3>{secondary.title}</h3>
      <MetricCards items={secondary.items} compact />
    </div>}
  </section>
}

function AdminOverviewPage() {
  const [retry, setRetry] = useState(0)
  const [state, setState] = useState({ key: -1, payload: null, error: '' })

  useEffect(() => {
    let active = true

    fetchSuperAdminDashboardStatistics()
      .then((payload) => { if (active) setState({ key: retry, payload, error: '' }) })
      .catch((error) => { if (active) setState({ key: retry, payload: null, error: errorMessage(error) }) })

    return () => { active = false }
  }, [retry])

  const loading = state.key !== retry
  const statistics = loading ? null : state.payload
  const dataHasActivity = statistics && (
    statistics.events.total > 0
    || statistics.reservations.total > 0
    || statistics.tickets.total > 0
    || Object.values(statistics.demo_payments.attempts_by_status).some((count) => count > 0)
  )
  const demoAttemptTotal = statistics
    ? Object.values(statistics.demo_payments.attempts_by_status).reduce((total, count) => total + count, 0)
    : 0

  return <section className="admin-overview-page">
    <header className="admin-welcome">
      <span className="eyebrow">EVENTPASS ADMINISTRATION</span>
      <h1>Welcome to your workspace.</h1>
      <p>Review platform activity and inspect the administration areas available to you.</p>
      {statistics && <p className="admin-stat-generated"><time dateTime={statistics.generated_at}>Statistics generated {formatGeneratedAt(statistics.generated_at)} UTC</time></p>}
    </header>

    {loading && <div className="admin-stat-state" role="status" aria-live="polite"><span className="admin-stat-spinner" aria-hidden="true" />Loading platform statistics…</div>}
    {!loading && state.error && <div className="admin-stat-error" role="alert"><p>{state.error}</p><button className="button button-outline" type="button" onClick={() => setRetry((value) => value + 1)}>Retry statistics</button></div>}
    {!loading && !state.error && statistics && <div className="admin-statistics" role="region" aria-label="Global platform statistics">
      {!dataHasActivity && <p className="admin-stat-zero-note">No events, reservations, ticket records, or demo payment attempts are recorded yet.</p>}

      <StatisticsSection
        id="users"
        title="Users"
        description="Registered accounts by role and account status."
        items={[
          { label: 'Total users', value: statistics.users.total, tone: 'is-primary' },
          ...USER_ROLES.map(([label, role]) => ({ label, value: statistics.users.by_role[role] })),
          { label: 'Active accounts', value: statistics.users.by_account_status.active },
          { label: 'Suspended accounts', value: statistics.users.by_account_status.suspended },
        ]}
      />

      <StatisticsSection
        id="events"
        title="Events"
        description="Event records by current status and published events starting in the future."
        items={[
          { label: 'Total events', value: statistics.events.total, tone: 'is-primary' },
          ...EVENT_STATUSES.map(([label, status]) => ({ label, value: statistics.events.by_status[status] })),
          { label: 'Published upcoming', value: statistics.events.published_upcoming },
        ]}
      />

      <StatisticsSection
        id="reservations"
        title="Reservations"
        description="Reservation records; confirmed and paid requires both statuses."
        items={[
          { label: 'Total reservations', value: statistics.reservations.total, tone: 'is-primary' },
          ...RESERVATION_STATUSES.map(([label, status]) => ({ label, value: statistics.reservations.by_status[status] })),
          { label: 'Confirmed and paid', value: statistics.reservations.confirmed_paid },
        ]}
        secondary={{
          title: 'Payment status breakdown',
          items: PAYMENT_STATUSES.map(([label, status]) => ({ label, value: statistics.reservations.by_payment_status[status] })),
        }}
      />

      <StatisticsSection
        id="tickets"
        title="Tickets"
        description="Counts ticket records, not configured ticket inventory."
        items={[
          { label: 'Total ticket records', value: statistics.tickets.total, tone: 'is-primary' },
          ...TICKET_STATUSES.map(([label, status]) => ({ label, value: statistics.tickets.by_status[status] })),
          { label: 'Valid for confirmed and paid reservations', value: statistics.tickets.valid_for_confirmed_paid_reservations },
        ]}
      />

      <StatisticsSection
        id="demo-payments"
        title="Demo payments"
        description="These are local payment simulations only. They do not represent real transactions or revenue."
        badge="DEMO ONLY"
        items={[
          { label: 'Total demo attempts', value: demoAttemptTotal, tone: 'is-primary' },
          ...DEMO_PAYMENT_STATUSES.map(([label, status]) => ({ label, value: statistics.demo_payments.attempts_by_status[status] })),
        ]}
      />
    </div>}

    <div className="admin-shortcut-grid">
      <Link className="admin-shortcut-card admin-shortcut-featured" to="/admin/organizer-applications"><span><UserRoundCog size={20} /></span><div><small>REVIEW QUEUE</small><h2>Organizer applications</h2><p>Review applicant details and approve or reject pending requests.</p></div><ArrowUpRight size={18} /></Link>
      <Link className="admin-shortcut-card" to="/admin/reservations"><span><ClipboardList size={20} /></span><div><small>INSPECTION</small><h2>Reservations</h2><p>Inspect reservation records using the existing read-only admin view.</p></div><ArrowUpRight size={18} /></Link>
      <Link className="admin-shortcut-card" to="/admin/events"><span><CalendarDays size={20} /></span><div><small>EVENT ADMINISTRATION</small><h2>Events</h2><p>Review event details and manage suspension, reactivation, and cancellation.</p></div><ArrowUpRight size={18} /></Link>
      <Link className="admin-shortcut-card" to="/admin/ticket-types"><span><Ticket size={20} /></span><div><small>INSPECTION</small><h2>Ticket types</h2><p>Inspect ticket type records using the existing admin view.</p></div><ArrowUpRight size={18} /></Link>
      <Link className="admin-shortcut-card" to="/admin/access"><span><Activity size={20} /></span><div><small>EVENT ENTRY</small><h2>Access monitoring</h2><p>Inspect event attendance, access history, and assigned staff.</p></div><ArrowUpRight size={18} /></Link>
    </div>
    <p className="admin-coming-note">The Users area supports server-side search and filters, safe account details, suspension, and reactivation.</p>
  </section>
}

export default AdminOverviewPage
