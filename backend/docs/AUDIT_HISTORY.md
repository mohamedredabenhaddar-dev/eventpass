# EventPass audit history

## Scope

`audit_events` records selected successful business state changes. Phase 14.1
added the recording foundation and Phase 14.2 adds a read-only Super Admin API.
There is no audit-event write, update, or delete API. QR ENTRY and EXIT movements
remain in `access_logs`, which is the operational ticket access history and is
not duplicated here.

## Schema and actor attribution

Each row contains `occurred_at` (written in UTC), `actor_type` (`user` or
`system`), nullable `actor_id`, nullable `actor_role` snapshot, stable `action`,
`entity_type`, nullable `entity_id`, `outcome`, and allowlisted JSON `metadata`.
There is no `updated_at` and no cascading foreign key to actors or entities, so
deleting a business record cannot erase its audit history. IDs may remain after
their corresponding record is deleted.

The recorder derives user actor ID and role from the authenticated user passed
by the business operation. System expiration uses a null actor ID and role and
the fixed metadata source `reservations:expire-pending`.

MySQL and MariaDB connections set their session timezone to `+00:00` using
Laravel's MySQL connector `timezone` option. The connection URL is normalized
to remove only a URL-level `timezone` query option before Laravel resolves it,
so that option cannot override the explicit UTC setting. Other URL query options
are retained. This matches the application's UTC timezone and makes MySQL
`TIMESTAMP` conversion independent of the server's default session timezone.
The SQLite test verifies UTC-formatted recorder input and resolves simulated
MySQL/MariaDB configuration through Laravel's URL parser; it does not verify a
live server session. Deployment verification should confirm the effective
session timezone on each supported MySQL-compatible server.

## Read-only Super Admin API (Phase 14.2)

`GET /api/super-admin/audit-events` exposes paginated audit history. It is
read-only: no endpoint creates, updates, or deletes audit events.

Authentication and authorization:

- Laravel Sanctum authentication is required (`auth:sanctum`).
- The account must be active (`account.active`); suspended accounts receive 401.
- The authenticated user must have the `super_admin` role; other roles receive 403.

Supported query parameters:

| Parameter | Meaning |
| --- | --- |
| `page` | Page number, 1 to 100000 |
| `per_page` | Page size, 1 to 100 (default 15) |
| `action` | Exact supported action name |
| `actor_id` | Positive user actor ID; requires `actor_type=user` when `actor_type` is also supplied |
| `actor_type` | `user` or `system` |
| `entity_type` | Supported entity type |
| `entity_id` | Positive entity ID; requires `entity_type` |
| `outcome` | `success` or `failed` |
| `date_from` | Inclusive UTC calendar date, `Y-m-d` |
| `date_to` | Inclusive UTC calendar date, `Y-m-d` |

Unsupported parameter names, unsupported action/type/outcome values, invalid
calendar dates, contradictory actor filters, `entity_id` without `entity_type`,
and out-of-range pagination values are rejected with HTTP 422.

Date semantics: `date_from` is inclusive from 00:00:00 UTC. `date_to` is
inclusive through 23:59:59 UTC by querying `occurred_at` below 00:00:00 UTC on
the following calendar day. Records at 00:00:00 UTC on the following day are
therefore excluded. The API uses UTC calendar days regardless of the caller's
local timezone.

Ordering is `occurred_at DESC, id DESC`. Pagination is server-side.
Generated pagination links preserve active filters and `per_page` so following
`next` does not silently change the result set. Page size is capped at 100 and
the page number is capped at 100000.

Response fields: `id`, `occurred_at`, `actor_type`, `actor_id`, `actor_role`,
`action`, `entity_type`, `entity_id`, `outcome`, and sanitized `metadata`.
Metadata is reduced to the action's allowlisted keys and then revalidated. If
stored metadata is unknown or invalid, `metadata` is returned as `null` rather
than exposing unapproved values. The API does not return actor names, emails,
credentials, tokens, QR secrets, payment secrets, or free-text reasons.

## Outcome semantics

`outcome=success` means the recorded business transition succeeded and committed.
For `demo_payment.simulation_failed`, `outcome=failed` records the simulated
payment result; it does not mean that audit insertion failed. That action never
represents a successful payment. Rejected HTTP operations and failed
authorization attempts do not create successful state-change audit entries.

## Action and metadata allowlist

Only the following action-specific fields are accepted by `AuditRecorder`.
Entity and actor IDs are stored in their dedicated columns rather than repeated
in metadata unless noted.

| Action | Entity | Allowed metadata |
| --- | --- | --- |
| `user.suspended`, `user.reactivated` | `user` | `previous_status`, `new_status`, `target_role` |
| `organizer_request.approved` | `organizer_request` | `applicant_user_id`, `previous_status`, `new_status`, `previous_role`, `new_role` |
| `organizer_request.rejected` | `organizer_request` | `applicant_user_id`, `previous_status`, `new_status`, `applicant_role` |
| `event.created` | `event` | `status` |
| `event.updated` | `event` | `changed_fields` (approved event field names only) |
| `event.capacity_changed` | `event` | `previous_capacity`, `new_capacity`, `changed_fields` |
| `event.published`, `event.unpublished`, `event.cancelled`, `event.suspended`, `event.reactivated` | `event` | `previous_status`, `new_status` |
| `ticket_type.created` | `ticket_type` | `event_id`, `quantity`, `is_active` |
| `ticket_type.updated`, `ticket_type.deactivated` | `ticket_type` | `event_id`, `changed_fields` (approved ticket-type field names only) |
| `ticket_type.deleted` | `ticket_type` | `event_id` |
| `staff_assignment.created`, `staff_assignment.removed` | `event_staff_assignment` | `event_id`, `staff_user_id` |
| `reservation.created`, `reservation.cancelled`, `reservation.expired` | `reservation` | `event_id`, `item_count`, `total_quantity`; expiration also requires the fixed `source` value |
| `reservation.confirmed` | `reservation` | `event_id`, `payment_mode=demo`, `payment_id`, `previous_status`, `new_status` |
| `demo_payment.simulation_succeeded`, `demo_payment.simulation_failed` | `payment` | `reservation_id`, `payment_mode=demo`, `previous_status`, `new_status` |

Free-text reasons and descriptions are deliberately excluded. Every action
declares required and optional metadata keys. The recorder rejects unknown
action names, actor/action mismatches, unsupported outcomes, missing required
fields, unexpected metadata keys, enum values outside their project enums, and
invalid constrained values or field lists.

## Transactional behavior

Business changes and their audit row are written in the same database
transaction. An audit insertion exception is not swallowed; it aborts that
transaction so the business change rolls back as well. Event creation now places
the event insert and audit insert inside a transaction while preserving the
existing slug collision retry behavior.

An event update emits one audit row: `event.capacity_changed` when capacity
changes (with the changed field names), otherwise `event.updated` when actual
business fields change. A no-op update is not recorded. A ticket-type transition
from active to inactive uses `ticket_type.deactivated`; other actual changes use
`ticket_type.updated`.

Successful demo payment simulation records both the payment-attempt transition
and the reservation confirmation in the same existing transaction. Failed demo
simulation records only its failed simulation action; it does not confirm the
reservation or activate tickets. This remains DEMO ONLY and does not represent
real payment processing.

## Application-level append-only behavior and limits

`AuditEvent` rejects direct Eloquent model creation, updates, and deletes, and
there are no public update/delete endpoints. The Phase 14.2 API is read-only and
exposes only `GET /api/super-admin/audit-events`. The recorder uses a
query-builder insert after validating the action and metadata. These model
protections can be bypassed by `saveQuietly()`, query-builder updates/deletes,
bulk operations, raw SQL, or direct database access. This system is not
cryptographically immutable or tamper-proof. Stronger protection would require
separately controlled database privileges or an external append-only
destination.

## Privacy and retention

Do not record passwords, hashes, tokens, session credentials, raw QR credentials,
payment secrets, card data, full request/payment payloads, names, emails,
addresses, or free-text suspension/rejection/cancellation reasons. Do not add
unapproved metadata fields without reviewing this allowlist and the recorder.

Recommended retention is 90 days for local development. Production retention is
provisionally 12 months, subject to privacy/legal review and a documented
retention process. No automatic deletion is implemented in this phase. QR scan
history remains subject to its own retention policy in `access_logs`.

Failed login attempts and rejected requests are outside this initial audit
history scope. They are not committed business state changes and can be high
volume; any future security-event logging should have a separate design,
redaction policy, and rate/retention controls.

## Migration status

The `2026_11_19_100000_create_audit_events_table` migration file is present in
the repository. This document does not assert that the migration has been
applied to development, staging, production, or any other environment. Verify
the target environment's migration state before relying on the API.

## Migration rollback warning

The additive migration's `down()` drops `audit_events` and all recorded history.
Do not roll it back after audit rows have been written except under a reviewed
recovery plan with an appropriate backup. Prefer a forward migration for schema
corrections.
