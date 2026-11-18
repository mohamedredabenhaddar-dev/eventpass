import { ArrowLeft, CircleSlash, Code2 } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'

function AdminUsersUnavailablePage() {
  const { id } = useParams()
  return <section className="admin-users-unavailable">
    <Link className="admin-back-link" to="/admin/dashboard"><ArrowLeft size={15} /> Admin overview</Link>
    <article className="admin-users-unavailable-card">
      <span className="admin-users-unavailable-icon"><CircleSlash size={23} /></span>
      <span className="eyebrow">USER MANAGEMENT</span>
      <h1>{id ? 'User details are unavailable' : 'User directory is unavailable'}</h1>
      <p>The backend does not currently provide a Super Admin users list or user detail endpoint. This page does not fetch or invent user records.</p>
      <div className="admin-users-api-gap"><Code2 size={17} /><div><strong>Backend API needed</strong><span>Authenticated, paginated user index and individual user detail endpoints with an explicitly defined safe resource.</span></div></div>
      <p className="admin-users-readonly-note">Account status, suspension, reactivation, and role updates are also unsupported. No account-changing controls are available.</p>
    </article>
  </section>
}

export default AdminUsersUnavailablePage
