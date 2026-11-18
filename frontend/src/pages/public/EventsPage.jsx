import { useMemo, useState } from 'react'
import { Search, SlidersHorizontal } from 'lucide-react'
import { Link, useSearchParams } from 'react-router-dom'
import EventCard from '../../components/EventCard.jsx'
import { usePublicEvents } from '../../hooks/usePublicEvents.js'

function EventsPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const page = Math.max(1, Number(searchParams.get('page')) || 1)
  const selectedCategory = searchParams.get('category') || ''
  const [search, setSearch] = useState('')
  const [city, setCity] = useState('')
  const { events, meta, loading, error, retry } = usePublicEvents(page)
  const categories = [...new Set(events.map((event) => event.category).filter(Boolean))].sort()
  const cities = [...new Set(events.map((event) => event.city).filter(Boolean))].sort()
  const visibleEvents = useMemo(() => events.filter((event) => {
    const term = search.trim().toLocaleLowerCase()
    const matchesSearch = !term || [event.title, event.description, event.venue_name, event.city, event.country, event.category].some((value) => value?.toLocaleLowerCase().includes(term))
    return matchesSearch && (!selectedCategory || event.category === selectedCategory) && (!city || event.city === city)
  }), [events, search, selectedCategory, city])
  const changePage = (nextPage) => setSearchParams((current) => { if (nextPage > 1) current.set('page', String(nextPage)); else current.delete('page'); return current })
  const changeCategory = (value) => setSearchParams((current) => { if (value) current.set('category', value); else current.delete('category'); current.delete('page'); return current })

  return <section className="events-page page-container">
    <div className="events-page-heading"><span className="eyebrow">OUT THERE, WAITING</span><h1>Find your <em>something.</em></h1><p>Published events, straight from EventPass.</p></div>
    <div className="event-filters">
      <label className="event-search"><Search size={17} /><input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search this page’s events" aria-label="Search events on this page" /></label>
      <label className="filter-select"><SlidersHorizontal size={15} /><select value={selectedCategory} onChange={(event) => changeCategory(event.target.value)} aria-label="Filter by category"><option value="">All categories on this page</option>{categories.map((option) => <option key={option} value={option}>{option}</option>)}</select></label>
      <label className="filter-select"><select value={city} onChange={(event) => setCity(event.target.value)} aria-label="Filter by city"><option value="">All cities on this page</option>{cities.map((option) => <option key={option} value={option}>{option}</option>)}</select></label>
    </div>
    <p className="filter-scope-note">Search, category, and city filters apply to the currently loaded page. The API does not currently provide server-side filters.</p>
    {loading && <div className="events-state" role="status">Loading published events…</div>}
    {error && <div className="events-state events-error" role="alert"><p>{error}</p><button className="button button-outline" type="button" onClick={retry}>Try again</button></div>}
    {!loading && !error && visibleEvents.length === 0 && <div className="events-empty"><span className="eyebrow">NOTHING HERE YET</span><h2>No events match those filters.</h2><p>Try another search or clear a filter to see more.</p><button className="button button-outline" type="button" onClick={() => { setSearch(''); setCity(''); changeCategory('') }}>Clear filters</button></div>}
    {!loading && !error && visibleEvents.length > 0 && <div className="event-grid events-results">{visibleEvents.map((event) => <EventCard event={event} key={event.id} />)}</div>}
    {!loading && !error && meta?.last_page > 1 && <nav className="event-pagination" aria-label="Event pages"><button type="button" className="button button-outline" onClick={() => changePage(page - 1)} disabled={page <= 1}>Previous</button><span>Page {meta.current_page} of {meta.last_page}</span><button type="button" className="button button-outline" onClick={() => changePage(page + 1)} disabled={page >= meta.last_page}>Next</button></nav>}
    <div className="events-back"><Link to="/">Back to EventPass home</Link></div>
  </section>
}

export default EventsPage
