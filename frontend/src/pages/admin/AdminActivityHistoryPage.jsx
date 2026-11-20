import { useCallback, useEffect, useState } from 'react'
import { ChevronLeft, ChevronRight, History, Inbox, ShieldAlert } from 'lucide-react'
import { useSearchParams } from 'react-router-dom'
import { fetchSuperAdminAuditEvents } from '../../services/superAdminAuditService.js'
import ActivityDetailsDrawer from './ActivityDetailsDrawer.jsx'
import {
  AUDIT_ACTIONS,
  AUDIT_ACTOR_TYPES,
  AUDIT_ENTITY_TYPES,
  AUDIT_OUTCOMES,
  AUDIT_PAGE_SIZES,
  actionLabel,
  auditActorLabel,
  auditEntityLabel,
  auditErrorMessage,
  auditFiltersActive,
  buildAuditQuery,
  entityTypeLabel,
  formatAuditTimestamp,
  formatCount,
  humanize,
  outcomeLabel,
  parseAuditFilters,
  validateAuditFilters,
} from './activityHistoryUtils.js'

function FieldError({ id, message }) {
  return message ? <small className="admin-activity-field-error" id={id} role="alert">{message}</small> : null
}

function AdminActivityHistoryPage() {
  const [params, setParams] = useSearchParams()
  const queryString = params.toString()
  const filters = parseAuditFilters(params)
  const filtersActive = auditFiltersActive(filters)
  const [state, setState] = useState({ key: '', payload: null, error: '' })
  const [retry, setRetry] = useState(0)
  const [formErrors, setFormErrors] = useState({})
  const [selected, setSelected] = useState(null)
  const requestKey = `${queryString}|${retry}`
  const validationMessage = Object.values(validateAuditFilters(filters))[0] || ''

  useEffect(() => {
    const current = parseAuditFilters(new URLSearchParams(queryString))

    if (Object.keys(validateAuditFilters(current)).length > 0) return undefined

    const key = `${queryString}|${retry}`
    let active = true
    fetchSuperAdminAuditEvents(buildAuditQuery(current))
      .then((payload) => { if (active) setState({ key, payload, error: '' }) })
      .catch((error) => {
        if (active) setState({ key, payload: null, error: auditErrorMessage(error) })
      })

    return () => { active = false }
  }, [queryString, retry])

  const loading = !validationMessage && state.key !== requestKey
  const showSkeleton = loading && !state.payload
  const showValidation = !showSkeleton && Boolean(validationMessage)
  const showError = !showSkeleton && !showValidation && Boolean(state.error)
  const showContent = !showSkeleton && !showValidation && !showError
  const rows = showContent ? (state.payload?.data ?? []) : []
  const meta = state.payload?.meta
  const total = meta?.total ?? rows.length
  const hasFormErrors = Object.keys(formErrors).length > 0

  const closeDetails = useCallback(() => setSelected(null), [setSelected])

  const applyFilters = (formEvent) => {
    formEvent.preventDefault()
    const values = new FormData(formEvent.currentTarget)
    const next = {
      action: String(values.get('action') || ''),
      actorType: String(values.get('actor_type') || ''),
      actorId: String(values.get('actor_id') || '').trim(),
      entityType: String(values.get('entity_type') || ''),
      entityId: String(values.get('entity_id') || '').trim(),
      outcome: String(values.get('outcome') || ''),
      dateFrom: String(values.get('date_from') || ''),
      dateTo: String(values.get('date_to') || ''),
      perPage: Number(values.get('per_page')),
    }
    const problems = validateAuditFilters(next)

    if (Object.keys(problems).length > 0) {
      setFormErrors(problems)
      return
    }

    setFormErrors({})
    const nextParams = new URLSearchParams()
    if (next.action) nextParams.set('action', next.action)
    if (next.actorType) nextParams.set('actor_type', next.actorType)
    if (next.actorId) nextParams.set('actor_id', next.actorId)
    if (next.entityType) nextParams.set('entity_type', next.entityType)
    if (next.entityId) nextParams.set('entity_id', next.entityId)
    if (next.outcome) nextParams.set('outcome', next.outcome)
    if (next.dateFrom) nextParams.set('date_from', next.dateFrom)
    if (next.dateTo) nextParams.set('date_to', next.dateTo)
    if (AUDIT_PAGE_SIZES.includes(next.perPage) && next.perPage !== 15) nextParams.set('per_page', String(next.perPage))
    setParams(nextParams)
  }

  const clearFilters = () => {
    setFormErrors({})
    setParams(new URLSearchParams())
  }

  const goToPage = (nextPage) => {
    const next = new URLSearchParams(params)
    if (nextPage > 1) next.set('page', String(nextPage))
    else next.delete('page')
    setParams(next)
  }

  return <section className="admin-activity-page">
    <header className="admin-activity-heading">
      <div>
        <span className="eyebrow">GOVERNANCE</span>
        <h1>Activity history</h1>
        <p>Read-only audit history of administrative and business actions. All timestamps are recorded and displayed in UTC.</p>
      </div>
      <div className="admin-activity-heading-meta">
        {!validationMessage && state.payload && <span className="admin-activity-total">{formatCount(total)} {total === 1 ? 'event' : 'events'}</span>}
        {loading && state.payload && <span className="admin-activity-updating" role="status">Updating…</span>}
      </div>
    </header>

    <form className="admin-activity-filters" onSubmit={applyFilters} noValidate>
      <label className="admin-activity-filter-action">
        <span>Action</span>
        <select name="action" defaultValue={filters.action} key={`action:${filters.action}`}>
          <option value="">All actions</option>
          {AUDIT_ACTIONS.map(({ value, label }) => <option key={value} value={value}>{label}</option>)}
        </select>
      </label>

      <label>
        <span>Actor type</span>
        <select
          name="actor_type"
          defaultValue={filters.actorType}
          key={`actor_type:${filters.actorType}`}
          aria-invalid={Boolean(formErrors.actorType)}
          aria-describedby={formErrors.actorType ? 'activity-actor-type-error' : undefined}
        >
          <option value="">Any actor</option>
          {AUDIT_ACTOR_TYPES.map(({ value, label }) => <option key={value} value={value}>{label}</option>)}
        </select>
        <FieldError id="activity-actor-type-error" message={formErrors.actorType} />
      </label>

      <label>
        <span>Actor ID</span>
        <input
          name="actor_id"
          type="number"
          min="1"
          step="1"
          inputMode="numeric"
          placeholder="e.g. 12"
          defaultValue={filters.actorId}
          key={`actor_id:${filters.actorId}`}
          aria-invalid={Boolean(formErrors.actorId)}
          aria-describedby={formErrors.actorId ? 'activity-actor-id-error' : undefined}
        />
        <FieldError id="activity-actor-id-error" message={formErrors.actorId} />
      </label>

      <label>
        <span>Entity type</span>
        <select
          name="entity_type"
          defaultValue={filters.entityType}
          key={`entity_type:${filters.entityType}`}
          aria-invalid={Boolean(formErrors.entityType)}
          aria-describedby={formErrors.entityType ? 'activity-entity-type-error' : undefined}
        >
          <option value="">Any entity</option>
          {AUDIT_ENTITY_TYPES.map(({ value, label }) => <option key={value} value={value}>{label}</option>)}
        </select>
        <FieldError id="activity-entity-type-error" message={formErrors.entityType} />
      </label>

      <label>
        <span>Entity ID</span>
        <input
          name="entity_id"
          type="number"
          min="1"
          step="1"
          inputMode="numeric"
          placeholder="e.g. 48"
          defaultValue={filters.entityId}
          key={`entity_id:${filters.entityId}`}
          aria-invalid={Boolean(formErrors.entityId)}
          aria-describedby={formErrors.entityId ? 'activity-entity-id-error' : undefined}
        />
        <FieldError id="activity-entity-id-error" message={formErrors.entityId} />
      </label>

      <label>
        <span>Outcome</span>
        <select name="outcome" defaultValue={filters.outcome} key={`outcome:${filters.outcome}`}>
          <option value="">Any outcome</option>
          {AUDIT_OUTCOMES.map(({ value, label }) => <option key={value} value={value}>{label}</option>)}
        </select>
      </label>

      <label>
        <span>Date from</span>
        <input name="date_from" type="date" defaultValue={filters.dateFrom} key={`date_from:${filters.dateFrom}`} />
      </label>

      <label>
        <span>Date to</span>
        <input
          name="date_to"
          type="date"
          defaultValue={filters.dateTo}
          key={`date_to:${filters.dateTo}`}
          aria-invalid={Boolean(formErrors.dateTo)}
          aria-describedby={formErrors.dateTo ? 'activity-date-to-error' : undefined}
        />
        <FieldError id="activity-date-to-error" message={formErrors.dateTo} />
      </label>

      <label>
        <span>Rows per page</span>
        <select name="per_page" defaultValue={filters.perPage} key={`per_page:${filters.perPage}`}>
          {AUDIT_PAGE_SIZES.map((size) => <option key={size} value={size}>{size}</option>)}
        </select>
      </label>

      <div className="admin-activity-filter-buttons">
        <button className="button button-outline" type="button" onClick={clearFilters} disabled={loading || (!filtersActive && !hasFormErrors)}>Clear filters</button>
        <button className="button button-primary" type="submit" disabled={loading}>Apply filters</button>
      </div>
    </form>

    {hasFormErrors && <p className="admin-activity-form-error" role="alert">Check the highlighted filters and try again.</p>}

    <p className="admin-activity-note">
      <ShieldAlert size={14} aria-hidden="true" />
      Only approved metadata is returned. Actor names, emails, credentials, tokens, and payment secrets are never exposed.
    </p>

    {showSkeleton && <div className="admin-activity-skeleton" role="status" aria-live="polite">
      <span className="admin-activity-skeleton-label"><span className="admin-activity-spinner" aria-hidden="true" />Loading activity history…</span>
      <div className="admin-activity-skeleton-rows" aria-hidden="true">
        {[0, 1, 2, 3, 4].map((row) => <span key={row} />)}
      </div>
    </div>}

    {showError && <div className="admin-activity-error" role="alert">
      <p>{state.error}</p>
      <button className="button button-outline button-small" type="button" onClick={() => setRetry((value) => value + 1)}>Retry</button>
    </div>}

    {showValidation && <div className="admin-activity-error" role="alert">
      <p>{validationMessage}</p>
      <button className="button button-outline button-small" type="button" onClick={clearFilters}>Clear filters</button>
    </div>}

    {showContent && rows.length === 0 && <div className="admin-activity-empty">
      <Inbox size={26} aria-hidden="true" />
      <h2>{filtersActive ? 'No activity matches these filters.' : 'No activity has been recorded yet.'}</h2>
      <p>{filtersActive
        ? 'Adjust or clear the filters to see more audit events.'
        : 'Audit events will appear here after administrative or business actions occur.'}</p>
      {filtersActive && <button className="button button-outline button-small" type="button" onClick={clearFilters}>Clear filters</button>}
    </div>}

    {showContent && rows.length > 0 && <div className={`admin-activity-table-wrap${loading ? ' is-updating' : ''}`} aria-busy={loading}>
      <table className="admin-activity-table">
        <thead>
          <tr>
            <th>Date and time</th>
            <th>Actor</th>
            <th>Action</th>
            <th>Entity type</th>
            <th>Entity ID</th>
            <th>Outcome</th>
            <th><span className="visually-hidden">Details</span></th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => <tr key={row.id}>
            <td><time dateTime={row.occurred_at}>{formatAuditTimestamp(row.occurred_at)}</time></td>
            <td>
              <span className="admin-activity-actor">{auditActorLabel(row)}</span>
              {row.actor_role && <small>{humanize(row.actor_role)}</small>}
            </td>
            <td>
              <span className="admin-activity-action">{actionLabel(row.action)}</span>
              <small>{row.action}</small>
            </td>
            <td>{entityTypeLabel(row.entity_type)}</td>
            <td>{row.entity_id === null || row.entity_id === undefined ? '—' : `#${row.entity_id}`}</td>
            <td><span className={`admin-activity-outcome is-${row.outcome}`}>{outcomeLabel(row.outcome)}</span></td>
            <td><button className="button button-outline button-small admin-activity-details" type="button" onClick={() => setSelected(row)}>Details</button></td>
          </tr>)}
        </tbody>
      </table>

      <div className="admin-activity-mobile-list">
        {rows.map((row) => <article className="admin-activity-card" key={row.id}>
          <div className="admin-activity-card-top">
            <span className={`admin-activity-outcome is-${row.outcome}`}>{outcomeLabel(row.outcome)}</span>
            <time dateTime={row.occurred_at}>{formatAuditTimestamp(row.occurred_at)}</time>
          </div>
          <h2>{actionLabel(row.action)}</h2>
          <p>{auditActorLabel(row)} · {auditEntityLabel(row)}</p>
          <button className="button button-outline button-small" type="button" onClick={() => setSelected(row)}>View details</button>
        </article>)}
      </div>
    </div>}

    {showContent && meta && meta.last_page > 1 && <nav className="admin-activity-pagination" aria-label="Activity history pages">
      <button className="button button-outline button-small" type="button" disabled={loading || filters.page <= 1} onClick={() => goToPage(filters.page - 1)}>
        <ChevronLeft size={15} /> Previous
      </button>
      <span>Page {meta.current_page || filters.page} of {meta.last_page} · {formatCount(meta.total)} events · {meta.per_page} per page</span>
      <button className="button button-outline button-small" type="button" disabled={loading || filters.page >= meta.last_page} onClick={() => goToPage(filters.page + 1)}>
        Next <ChevronRight size={15} />
      </button>
    </nav>}

    {showContent && rows.length > 0 && meta && meta.last_page <= 1 && <p className="admin-activity-summary">
      <History size={13} aria-hidden="true" /> {formatCount(meta.total)} {meta.total === 1 ? 'event' : 'events'} · {meta.per_page} per page
    </p>}

    <ActivityDetailsDrawer event={selected} onClose={closeDetails} />
  </section>
}

export default AdminActivityHistoryPage
