import assert from 'node:assert/strict'
import test from 'node:test'
import {
  AUDIT_ACTIONS,
  AUDIT_PAGE_SIZES,
  actionLabel,
  auditActorLabel,
  auditEntityLabel,
  auditErrorMessage,
  auditFiltersActive,
  buildAuditQuery,
  entityTypeLabel,
  formatAuditTimestamp,
  formatMetadataValue,
  metadataLabel,
  outcomeLabel,
  parseAuditFilters,
  validateAuditFilters,
} from './activityHistoryUtils.js'

function filters(overrides = {}) {
  return {
    action: '',
    actorType: '',
    actorId: '',
    entityType: '',
    entityId: '',
    outcome: '',
    dateFrom: '',
    dateTo: '',
    page: 1,
    perPage: 15,
    ...overrides,
  }
}

test('parseAuditFilters returns safe defaults', () => {
  assert.deepEqual(parseAuditFilters(new URLSearchParams()), filters())
})

test('parseAuditFilters reads supported filters and pagination', () => {
  const parsed = parseAuditFilters(new URLSearchParams(
    'action=event.created&actor_type=user&actor_id=12&entity_type=event&entity_id=48'
    + '&outcome=success&date_from=2026-11-01&date_to=2026-11-19&page=2&per_page=50',
  ))

  assert.deepEqual(parsed, filters({
    action: 'event.created',
    actorType: 'user',
    actorId: '12',
    entityType: 'event',
    entityId: '48',
    outcome: 'success',
    dateFrom: '2026-11-01',
    dateTo: '2026-11-19',
    page: 2,
    perPage: 50,
  }))
})

test('parseAuditFilters ignores unsupported enum values and invalid pagination', () => {
  const parsed = parseAuditFilters(new URLSearchParams(
    'action=bogus&entity_type=secret&outcome=maybe&actor_type=robot&page=-3&per_page=1000',
  ))

  assert.equal(parsed.action, '')
  assert.equal(parsed.entityType, '')
  assert.equal(parsed.outcome, '')
  assert.equal(parsed.actorType, '')
  assert.equal(parsed.page, 1)
  assert.equal(parsed.perPage, 15)
})

test('validateAuditFilters requires an entity type when an entity ID is present', () => {
  assert.equal(
    validateAuditFilters(filters({ entityId: '48' })).entityType,
    'Select an entity type before filtering by entity ID.',
  )
})

test('validateAuditFilters requires the user actor type when an actor ID is present', () => {
  assert.equal(
    validateAuditFilters(filters({ actorId: '12' })).actorType,
    'Select “User” as the actor type when filtering by actor ID.',
  )
  assert.equal(
    validateAuditFilters(filters({ actorId: '12', actorType: 'system' })).actorType,
    'Select “User” as the actor type when filtering by actor ID.',
  )
})

test('validateAuditFilters rejects invalid identifiers and date ranges', () => {
  assert.ok(validateAuditFilters(filters({ actorId: 'abc', actorType: 'user' })).actorId)
  assert.ok(validateAuditFilters(filters({ entityId: '0', entityType: 'event' })).entityId)
  assert.ok(validateAuditFilters(filters({ dateFrom: '2026-11-19', dateTo: '2026-11-01' })).dateTo)
})

test('validateAuditFilters accepts a fully valid combined filter set', () => {
  const errors = validateAuditFilters(filters({
    action: 'event.created',
    actorType: 'user',
    actorId: '12',
    entityType: 'event',
    entityId: '48',
    outcome: 'success',
    dateFrom: '2026-11-01',
    dateTo: '2026-11-19',
  }))

  assert.deepEqual(errors, {})
})

test('buildAuditQuery only sends supported parameters', () => {
  const query = buildAuditQuery(filters({
    action: 'event.created',
    actorType: 'user',
    actorId: '12',
    entityType: 'event',
    entityId: '48',
    outcome: 'success',
    dateFrom: '2026-11-01',
    dateTo: '2026-11-19',
    page: 3,
    perPage: 50,
  }))

  assert.deepEqual(query, {
    page: 3,
    per_page: 50,
    action: 'event.created',
    actor_type: 'user',
    actor_id: 12,
    entity_type: 'event',
    entity_id: 48,
    outcome: 'success',
    date_from: '2026-11-01',
    date_to: '2026-11-19',
  })
  assert.deepEqual(Object.keys(query).sort(), [
    'action', 'actor_id', 'actor_type', 'date_from', 'date_to',
    'entity_id', 'entity_type', 'outcome', 'page', 'per_page',
  ])
})

test('buildAuditQuery omits empty filters', () => {
  assert.deepEqual(buildAuditQuery(filters()), { page: 1, per_page: 15 })
})

test('audit labels handle null actors and entities safely', () => {
  assert.equal(auditActorLabel({ actor_type: 'system', actor_id: null }), 'System')
  assert.equal(auditActorLabel({ actor_type: 'user', actor_id: 7 }), 'User #7')
  assert.equal(auditActorLabel(null), '—')
  assert.equal(auditEntityLabel({ entity_type: 'reservation', entity_id: null }), 'Reservation')
  assert.equal(auditEntityLabel({ entity_type: 'reservation', entity_id: 5 }), 'Reservation #5')
  assert.equal(auditEntityLabel({}), '—')
})

test('action and entity labels keep readable names with safe fallbacks', () => {
  assert.equal(actionLabel('demo_payment.simulation_failed'), 'Demo payment failed')
  assert.equal(actionLabel('custom.action'), 'Custom Action')
  assert.equal(entityTypeLabel('event_staff_assignment'), 'Staff assignment')
  assert.equal(entityTypeLabel('custom_entity'), 'Custom Entity')
  assert.equal(entityTypeLabel(''), '—')
  assert.equal(outcomeLabel('success'), 'Success')
  assert.equal(outcomeLabel('failed'), 'Failed')
})

test('metadata formatting never stringifies unapproved objects', () => {
  assert.equal(formatMetadataValue(null), '—')
  assert.equal(formatMetadataValue('draft'), 'draft')
  assert.equal(formatMetadataValue(true), 'Yes')
  assert.equal(formatMetadataValue(false), 'No')
  assert.equal(formatMetadataValue(['capacity', 'end_at']), 'Capacity, End At')
  assert.equal(formatMetadataValue([]), '—')
  assert.equal(formatMetadataValue({ password: 'must-not-leak' }), '—')
  assert.equal(metadataLabel('previous_status'), 'Previous status')
  assert.equal(metadataLabel('unknown_field'), 'Unknown Field')
})

test('formatAuditTimestamp renders UTC and handles missing values', () => {
  assert.equal(formatAuditTimestamp(null), '—')

  const formatted = formatAuditTimestamp('2026-11-19T14:32:10.000Z')
  assert.ok(formatted.endsWith(' UTC'))
  assert.ok(formatted.includes('2026'))
})

test('auditErrorMessage maps authorization, validation, and network failures', () => {
  assert.match(auditErrorMessage({ response: { status: 401 } }), /session has expired/i)
  assert.match(auditErrorMessage({ response: { status: 403 } }), /not allowed/i)
  assert.match(
    auditErrorMessage({
      response: {
        status: 422,
        data: { errors: { entity_type: ['The entity type field is required when an entity ID is provided.'] } },
      },
    }),
    /entity type field is required/i,
  )
  assert.match(auditErrorMessage({}), /could not be reached/i)
})

test('auditFiltersActive detects any active filter', () => {
  assert.equal(auditFiltersActive(filters()), false)
  assert.equal(auditFiltersActive(filters({ outcome: 'failed' })), true)
  assert.equal(auditFiltersActive(filters({ dateFrom: '2026-11-01' })), true)
})

test('action catalogue covers every supported backend action', () => {
  assert.equal(AUDIT_ACTIONS.length, 24)
  assert.equal(new Set(AUDIT_ACTIONS.map(({ value }) => value)).size, 24)
  assert.deepEqual(AUDIT_PAGE_SIZES, [15, 25, 50, 100])
})
