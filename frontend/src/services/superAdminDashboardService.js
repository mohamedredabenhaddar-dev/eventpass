import api from './api.js'

const ROLES = ['super_admin', 'organizer', 'staff', 'client']
const ACCOUNT_STATUSES = ['active', 'suspended']
const EVENT_STATUSES = ['draft', 'published', 'suspended', 'cancelled']
const RESERVATION_STATUSES = ['pending_payment', 'confirmed', 'cancelled', 'expired']
const PAYMENT_STATUSES = ['unpaid', 'pending', 'paid', 'failed', 'refunded', 'expired']
const TICKET_STATUSES = ['pending', 'valid', 'cancelled']
const PAYMENT_ATTEMPT_STATUSES = ['pending', 'succeeded', 'failed', 'expired']

let inFlightRequest

function isRecord(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

function assertCount(value, path) {
  if (!Number.isSafeInteger(value) || value < 0) {
    throw new Error(`Statistics response contains an invalid count at ${path}.`)
  }
}

function assertCountMap(value, keys, path) {
  if (!isRecord(value)
    || Object.keys(value).length !== keys.length
    || keys.some((key) => !Object.hasOwn(value, key))) {
    throw new Error(`Statistics response is missing status counts at ${path}.`)
  }

  keys.forEach((key) => assertCount(value[key], `${path}.${key}`))
}

function validateStatistics(value) {
  const isoTimestamp = typeof value?.generated_at === 'string'
    && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/.test(value.generated_at)
    && Number.isFinite(Date.parse(value.generated_at))

  if (!isRecord(value) || !isoTimestamp) {
    throw new Error('Statistics response is missing a valid generation timestamp.')
  }

  if (!isRecord(value.users) || !isRecord(value.events) || !isRecord(value.reservations)
    || !isRecord(value.tickets) || !isRecord(value.demo_payments)) {
    throw new Error('Statistics response is incomplete. Please retry.')
  }

  assertCount(value.users.total, 'users.total')
  assertCountMap(value.users.by_role, ROLES, 'users.by_role')
  assertCountMap(value.users.by_account_status, ACCOUNT_STATUSES, 'users.by_account_status')

  assertCount(value.events.total, 'events.total')
  assertCountMap(value.events.by_status, EVENT_STATUSES, 'events.by_status')
  assertCount(value.events.published_upcoming, 'events.published_upcoming')

  assertCount(value.reservations.total, 'reservations.total')
  assertCountMap(value.reservations.by_status, RESERVATION_STATUSES, 'reservations.by_status')
  assertCountMap(value.reservations.by_payment_status, PAYMENT_STATUSES, 'reservations.by_payment_status')
  assertCount(value.reservations.confirmed_paid, 'reservations.confirmed_paid')

  assertCount(value.tickets.total, 'tickets.total')
  assertCountMap(value.tickets.by_status, TICKET_STATUSES, 'tickets.by_status')
  assertCount(value.tickets.valid_for_confirmed_paid_reservations, 'tickets.valid_for_confirmed_paid_reservations')

  if (value.demo_payments.demo_only !== true) {
    throw new Error('Statistics response did not identify demo payment metrics.')
  }
  assertCountMap(value.demo_payments.attempts_by_status, PAYMENT_ATTEMPT_STATUSES, 'demo_payments.attempts_by_status')
  assertCount(value.demo_payments.successful_simulations, 'demo_payments.successful_simulations')

  return value
}

export function fetchSuperAdminDashboardStatistics() {
  if (!inFlightRequest) {
    inFlightRequest = api.get('/super-admin/dashboard/statistics')
      .then(({ data }) => validateStatistics(data?.data))
      .finally(() => { inFlightRequest = undefined })
  }

  return inFlightRequest
}
