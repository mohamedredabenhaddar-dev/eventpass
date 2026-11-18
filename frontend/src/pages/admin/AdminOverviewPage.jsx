import { Activity, ArrowUpRight, CalendarDays, ClipboardList, Ticket, UserRoundCog } from 'lucide-react'
import { Link } from 'react-router-dom'

function AdminOverviewPage() {
  return <section className="admin-overview-page">
    <header className="admin-welcome"><span className="eyebrow">EVENTPASS ADMINISTRATION</span><h1>Welcome to your workspace.</h1><p>Review organizer applications and inspect the administration areas already available.</p></header>
    <div className="admin-stat-unavailable" role="note"><strong>Global dashboard statistics are not available.</strong><span>The current API has no aggregate endpoint for system-wide user, event, or application totals. No totals are estimated here.</span></div>
    <div className="admin-shortcut-grid">
      <Link className="admin-shortcut-card admin-shortcut-featured" to="/admin/organizer-applications"><span><UserRoundCog size={20} /></span><div><small>REVIEW QUEUE</small><h2>Organizer applications</h2><p>Review applicant details and approve or reject pending requests.</p></div><ArrowUpRight size={18} /></Link>
      <Link className="admin-shortcut-card" to="/admin/reservations"><span><ClipboardList size={20} /></span><div><small>INSPECTION</small><h2>Reservations</h2><p>Inspect reservation records using the existing read-only admin view.</p></div><ArrowUpRight size={18} /></Link>
      <Link className="admin-shortcut-card" to="/admin/events"><span><CalendarDays size={20} /></span><div><small>EVENT ADMINISTRATION</small><h2>Events</h2><p>Review event details and manage suspension, reactivation, and cancellation.</p></div><ArrowUpRight size={18} /></Link>
      <Link className="admin-shortcut-card" to="/admin/ticket-types"><span><Ticket size={20} /></span><div><small>INSPECTION</small><h2>Ticket types</h2><p>Inspect ticket type records using the existing admin view.</p></div><ArrowUpRight size={18} /></Link>
      <Link className="admin-shortcut-card" to="/admin/access"><span><Activity size={20} /></span><div><small>EVENT ENTRY</small><h2>Access monitoring</h2><p>Inspect event attendance, access history, and assigned staff.</p></div><ArrowUpRight size={18} /></Link>
    </div>
    <p className="admin-coming-note">User management is linked in the sidebar, but the required backend user list and detail APIs are not available. Access monitoring remains future work.</p>
  </section>
}

export default AdminOverviewPage
