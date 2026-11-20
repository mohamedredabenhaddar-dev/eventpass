// Keep these values in sync with AuditRecorder::supportedFilterValues() in the
// backend. The API validates every filter against that allowlist.
export const AUDIT_ACTIONS = [
  { value: 'user.suspended', label: 'User suspended' },
  { value: 'user.reactivated', label: 'User reactivated' },
  { value: 'organizer_request.approved', label: 'Organizer request approved' },
  { value: 'organizer_request.rejected', label: 'Organizer request rejected' },
  { value: 'event.created', label: 'Event created' },
  { value: 'event.updated', label: 'Event updated' },
  { value: 'event.capacity_changed', label: 'Event capacity changed' },
  { value: 'event.published', label: 'Event published' },
  { value: 'event.unpublished', label: 'Event unpublished' },
  { value: 'event.cancelled', label: 'Event cancelled' },
  { value: 'event.suspended', label: 'Event suspended' },
  { value: 'event.reactivated', label: 'Event reactivated' },
  { value: 'ticket_type.created', label: 'Ticket type created' },
  { value: 'ticket_type.updated', label: 'Ticket type updated' },
  { value: 'ticket_type.deactivated', label: 'Ticket type deactivated' },
  { value: 'ticket_type.deleted', label: 'Ticket type deleted' },
  { value: 'staff_assignment.created', label: 'Staff assigned' },
  { value: 'staff_assignment.removed', label: 'Staff assignment removed' },
  { value: 'reservation.created', label: 'Reservation created' },
  { value: 'reservation.cancelled', label: 'Reservation cancelled' },
  { value: 'reservation.expired', label: 'Reservation expired' },
  { value: 'reservation.confirmed', label: 'Reservation confirmed' },
  { value: 'demo_payment.simulation_succeeded', label: 'Demo payment succeeded' },
  { value: 'demo_payment.simulation_failed', label: 'Demo payment failed' },
]

export const AUDIT_ACTOR_TYPES = [
  { value: 'user', label: 'User' },
  { value: 'system', label: 'System' },
]

export const AUDIT_ENTITY_TYPES = [
  { value: 'user', label: 'User' },
  { value: 'organizer_request', label: 'Organizer request' },
  { value: 'event', label: 'Event' },
  { value: 'ticket_type', label: 'Ticket type' },
  { value: 'event_staff_assignment', label: 'Staff assignment' },
  { value: 'reservation', label: 'Reservation' },
  { value: 'payment', label: 'Payment' },
]

export const AUDIT_OUTCOMES = [
  { value: 'success', label: 'Success' },
  { value: 'failed', label: 'Failed' },
]

export const AUDIT_PAGE_SIZES = [15, 25, 50, 100]

const ACTION_LABELS = new Map(AUDIT_ACTIONS.map(({ value, label }) => [value, label]))
const ENTITY_LABELS = new Map(AUDIT_ENTITY_TYPES.map(({ value, label }) => [value, label]))
const OUTCOME_LABELS = new Map(AUDIT_OUTCOMES.map(({ value, label }) => [value, label]))
const ACTION_VALUES = new Set(AUDIT_ACTIONS.map(({ value }) => value))
const ACTOR_TYPE_VALUES = new Set(AUDIT_ACTOR_TYPES.map(({ value }) => value))
const ENTITY_TYPE_VALUES = new Set(AUDIT_ENTITY_TYPES.map(({ value }) => value))
const OUTCOME_VALUES = new Set(AUDIT_OUTCOMES.map(({ value }) => value))

const METADATA_LABELS = {
  previous_status: 'Previous status',
  new_status: 'New status',
  target_role: 'Target role',
  applicant_user_id: 'Applicant user ID',
  previous_role: 'Previous role',
  new_role: 'New role',
  applicant_role: 'Applicant role',
  status: 'Status',
  changed_fields: 'Changed fields',
  previous_capacity: 'Previous capacity',
  new_capacity: 'New capacity',
  event_id: 'Event ID',
  quantity: 'Quantity',
  is_active: 'Active',
  staff_user_id: 'Staff user ID',
  item_count: 'Item count',
  total_quantity: 'Total quantity',
  source: 'Source',
  payment_mode: 'Payment mode',
  payment_id: 'Payment ID',
  reservation_id: 'Reservation ID',
}

const numberFormat = new Intl.NumberFormat()
const utcFormatter = new Intl.DateTimeFormat(undefined, {
  year: 'numeric',
  month: 'short',
  day: '2-digit',
  hour: '2-digit',
  minute: '2-digit',
  second: '2-digit',
  hour12: false,
  timeZone: 'UTC',
})

export function humanize(value) {
  if (!value) return ''
  return String(value)
    .replaceAll('_', ' ')
    .replaceAll('.', ' ')
    .replace(/\b\w/g, (letter) => letter.toUpperCase())
}

export function actionLabel(value) {
  return ACTION_LABELS.get(value) || humanize(value) || 'Unknown action'
}

export function entityTypeLabel(value) {
  return ENTITY_LABELS.get(value) || (value ? humanize(value) : '—')
}

export function outcomeLabel(value) {
  return OUTCOME_LABELS.get(value) || (value ? humanize(value) : 'Unknown')
}

export function auditActorTypeLabel(value) {
  if (value === 'system') return 'System'
  if (value === 'user') return 'User'
  return value ? humanize(value) : '—'
}

export function auditActorLabel(event) {
  if (!event) return '—'
  if (event.actor_type === 'system' || event.actor_id === null || event.actor_id === undefined) {
    return 'System'
  }
  return `User #${event.actor_id}`
}

export function auditEntityLabel(event) {
  if (!event?.entity_type) return '—'
  return event.entity_id === null || event.entity_id === undefined
    ? entityTypeLabel(event.entity_type)
    : `${entityTypeLabel(event.entity_type)} #${event.entity_id}`
}

export function formatCount(value) {
  return numberFormat.format(Number(value) || 0)
}

export function formatAuditTimestamp(value) {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return `${utcFormatter.format(date)} UTC`
}

export function formatAuditLocalTimestamp(value) {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString()
}

export function metadataLabel(key) {
  return METADATA_LABELS[key] || humanize(key) || 'Field'
}

export function formatMetadataValue(value) {
  if (value === null || value === undefined || value === '') return '—'
  if (Array.isArray(value)) {
    if (value.length === 0) return '—'
    return value.map((item) => (typeof item === 'string' ? humanize(item) : String(item))).join(', ')
  }
  if (typeof value === 'boolean') return value ? 'Yes' : 'No'
  // The API only returns allowlisted scalar metadata. Never stringify unknown
  // objects, which could expose values outside the approved schema.
  if (typeof value === 'object') return '—'
  return String(value)
}

export function auditErrorMessage(error, fallback = 'Activity history could not be loaded.') {
  const status = error.response?.status
  if (status === 401) return 'Your session has expired. Please sign in again.'
  if (status === 403) return 'Your account is not allowed to view activity history.'
  if (status === 422) {
    const errors = error.response?.data?.errors
    const messages = errors ? Object.values(errors).flat().filter(Boolean) : []
    return messages.length
      ? messages.join(' ')
      : error.response?.data?.message || 'The activity filters are not valid. Adjust them and try again.'
  }
  if (!error.response) return 'The service could not be reached. Check your connection and try again.'
  return error.response?.data?.message || error.userMessage || fallback
}

function pick(value, allowed) {
  return value && allowed.has(value) ? value : ''
}

function positiveInteger(value) {
  return /^\d+$/.test(String(value)) && Number(value) >= 1
}

export function parseAuditFilters(searchParams) {
  const requestedPage = Number(searchParams.get('page'))
  const requestedPageSize = Number(searchParams.get('per_page'))

  return {
    action: pick(searchParams.get('action'), ACTION_VALUES),
    actorType: pick(searchParams.get('actor_type'), ACTOR_TYPE_VALUES),
    actorId: (searchParams.get('actor_id') || '').trim(),
    entityType: pick(searchParams.get('entity_type'), ENTITY_TYPE_VALUES),
    entityId: (searchParams.get('entity_id') || '').trim(),
    outcome: pick(searchParams.get('outcome'), OUTCOME_VALUES),
    dateFrom: (searchParams.get('date_from') || '').trim(),
    dateTo: (searchParams.get('date_to') || '').trim(),
    page: Number.isInteger(requestedPage) && requestedPage > 0 ? requestedPage : 1,
    perPage: AUDIT_PAGE_SIZES.includes(requestedPageSize) ? requestedPageSize : 15,
  }
}

export function validateAuditFilters(filters) {
  const errors = {}

  if (filters.actorId && !positiveInteger(filters.actorId)) {
    errors.actorId = 'Enter a positive numeric actor ID.'
  } else if (filters.actorId && filters.actorType !== 'user') {
    errors.actorType = 'Select “User” as the actor type when filtering by actor ID.'
  }

  if (filters.entityId && !positiveInteger(filters.entityId)) {
    errors.entityId = 'Enter a positive numeric entity ID.'
  } else if (filters.entityId && !filters.entityType) {
    errors.entityType = 'Select an entity type before filtering by entity ID.'
  }

  if (filters.dateFrom && filters.dateTo && filters.dateFrom > filters.dateTo) {
    errors.dateTo = 'The end date must be on or after the start date.'
  }

  return errors
}

export function buildAuditQuery(filters) {
  const query = { page: filters.page, per_page: filters.perPage }

  if (filters.action) query.action = filters.action
  if (filters.actorType) query.actor_type = filters.actorType
  if (filters.actorId) query.actor_id = Number(filters.actorId)
  if (filters.entityType) query.entity_type = filters.entityType
  if (filters.entityId) query.entity_id = Number(filters.entityId)
  if (filters.outcome) query.outcome = filters.outcome
  if (filters.dateFrom) query.date_from = filters.dateFrom
  if (filters.dateTo) query.date_to = filters.dateTo

  return query
}

export function auditFiltersActive(filters) {
  return Boolean(
    filters.action
    || filters.actorType
    || filters.actorId
    || filters.entityType
    || filters.entityId
    || filters.outcome
    || filters.dateFrom
    || filters.dateTo,
  )
}
