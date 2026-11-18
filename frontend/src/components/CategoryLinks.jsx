import { ArrowUpRight, Sparkles } from 'lucide-react'
import { Link } from 'react-router-dom'

function CategoryLinks({ categories = [] }) {
  if (!categories.length) return <p className="events-inline-note">Categories will appear when published events are available.</p>

  return <div className="category-grid">
    {categories.map((category) => <Link className="category-link category-dynamic" to={`/events?category=${encodeURIComponent(category)}`} key={category}>
      <span className="category-icon"><Sparkles size={18} strokeWidth={1.7} /></span><span>{category}</span><ArrowUpRight className="category-arrow" size={15} />
    </Link>)}
  </div>
}

export default CategoryLinks
