import { Ticket } from 'lucide-react'
import { Link } from 'react-router-dom'

function BrandMark({ footer = false }) {
  return (
    <Link className={`brand-mark${footer ? ' brand-mark-footer' : ''}`} to="/" aria-label="EventPass home">
      <span className="brand-icon"><Ticket size={19} strokeWidth={2.2} /></span>
      <span>event<span className="brand-accent">pass</span></span>
    </Link>
  )
}

export default BrandMark
