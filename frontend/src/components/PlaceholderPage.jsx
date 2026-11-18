import { ArrowLeft, Sparkles } from 'lucide-react'
import { Link } from 'react-router-dom'

function PlaceholderPage({ eyebrow, title, description }) {
  return <section className="placeholder-page page-container"><div className="placeholder-card">
    <span className="eyebrow"><Sparkles size={14} /> {eyebrow}</span><h1>{title}</h1><p>{description}</p>
    <Link className="text-link" to="/"><ArrowLeft size={16} /> Back to home</Link>
  </div></section>
}

export default PlaceholderPage
