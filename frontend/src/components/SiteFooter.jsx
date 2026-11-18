import { ArrowUpRight, Sparkles } from 'lucide-react'
import { Link } from 'react-router-dom'
import BrandMark from './BrandMark.jsx'

function SiteFooter() {
  return (
    <footer className="site-footer">
      <div className="footer-main">
        <div className="footer-brand"><BrandMark footer /><p>Make room for the moments<br />you’ll talk about tomorrow.</p></div>
        <div className="footer-column"><span className="footer-label">Explore</span><Link to="/events">Find events</Link><a href="/#categories">Browse categories</a></div>
        <div className="footer-column"><span className="footer-label">For creators</span><a href="/#for-organizers">Host with EventPass</a><Link to="/register">Create an account</Link></div>
        <div className="footer-social"><span className="footer-promise"><Sparkles size={15} /> Made for real life</span></div>
      </div>
      <div className="footer-bottom"><span>© 2026 EventPass. Made for going out.</span><span className="footer-note">A demo experience <ArrowUpRight size={13} /></span></div>
    </footer>
  )
}

export default SiteFooter
