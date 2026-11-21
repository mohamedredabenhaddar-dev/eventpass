# EventPass — Portfolio README

## Problem Statement

EventPass is a full-stack event ticketing, reservation, and real-time QR access-control platform that must serve four distinct user roles (Client, Organizer, Staff, Super Admin) with strict authorization, rate limiting, audit integrity, and mobile-first UX — all while passing 0 PHPUnit failures, 0 Pint style issues, 0 ESLint errors, and a clean Vite production build.

Core challenges include:

- **Multi-tenant role isolation**: Clients book, Organizers publish, Staff scan, Super Admin audits — each with separate middleware (`role:`, `account.active`, `throttle:`).
- **Concurrency-safe booking**: `DB::transaction()` + `lockForUpdate()` on events, reservations, and tickets to preventver over-selling or duplicate scans.
- **PDF generation without external dependencies**: A pure-PHP PDF generator (`TicketPdfService`) producing valid `%PDF-1.4` output, replacing an unavailable `barryvdh/laravel-dompdf`.
- **Mobile-first QR scanning**: Live camera with `facingMode: 'environment'` (rear-camera preference), scan-lock cooldown, manual code fallback, and ENTRY/EXIT state management on viewport as narrow as 390px.
- **Rate-limiter determinism**: OS-level `CACHE_STORE=file` leaking across test runs → accumulated `429` failures; fixed by pinning `CACHE_STORE=array` + `SESSION_DRIVER=array` in the test bootstrap.
- **Audit immutability & metadata enforcement**: `AuditRecorder::ACTIONS` strict allowlist per action type, `occurred_at` normalized to UTC, metadata keys validated against a per-action whitelist.

---

## Key Features by Role

### Client

- **Public event discovery** with search & sales-window filtering (`GET /events` + `?category=` + `?start_at/end_at=`).
- **Atomic reservation** via `lockForUpdate` on events + `lockForUpdate` on reservation creation.
- **Simulated demo checkout**: `POST /payments/{id}/simulate-success` → `200`.
- **QR ticket view** and **pure-PHP PDF download** (`GET /tickets/{ticket}/pdf`):
  - `200` + `Content-Type: application/pdf` for the ticket owner.
  - `403` for non-owners.
  - Body starts with `%PDF-1.4` and ends with `%%EOF`.
- **My Tickets page** shows ticket reference, event, venue, date, and a **Download PDF** button that saves `eventpass-ticket-{code}.pdf`.

### Organizer

- **Organizer onboarding application**: Client → submit organizer request → Super Admin approves/rejects.
- **Organizer Profile** management: `GET /api/organizer/profile` / `PUT /api/organizer/profile` with fields organization_name, contact_email, contact_phone, bio/description; `422` validation errors, success banner, loading state.
- **Event lifecycle**: create → publish/unpublish/cancel, ticket-type quota & pricing management (`POST /organizer/events`, `PATCH /organizer/events/{event}/ticket-types`, `PATCH .../publish`).
- **Staff Directory & Assignment**: Searchable `GET /organizer/events/{event}/staff/available` returning active `role=staff` + `account_status=active` users with `already_assigned` badge; one-click assign; unassign with confirmation.
- **Access reports**: view event access statistics and history.

### Staff

- **Assigned-event gate access**: only assigned staff can visit `/staff/events/{event}/scanner`.
- **Mobile Camera QR Scanner** (`/staff/scanner`):
  - **Live camera mode**: `constraints={{ facingMode: { ideal: 'environment' } }}` (rear-camera preference on mobile).
  - **Start/Stop toggle**: clean camera start/stop, tracks stopped on unmount.
  - **Scan lock**: `inFlightRef` + 2.5s cooldown + 8s same-code guard prevents duplicate rapid-fire scans.
  - **Graceful fallback**: if camera denied/unavailable/insecure-context → non-blocking amber banner + manual QR/ticket reference input stays fully usable.
  - **ENTRY / EXIT toggle**: high-contrast buttons; `action` state persists across camera stop/start.
  - **Visual feedback banners**:
    - **Green success**: scan accepted, attendee name + ticket type, access state (INSIDE/OUTSIDE), gate, scanned-at.
    - **Amber warning**: duplicate entry (`409`), invalid/cancelled ticket, wrong event.
    - **Red alert**: other failures.
  - **Recent scans list**: last 5 scans from the access report with action icon, ticket reference, gate, staff name, timestamp.
- **Manual scan mode**: paste/enter ticket token; submit validates backend; ENTRY/EXIT mode toggle persists.

### Super Admin

- **Global platform statistics**: attendees inside, unique tickets, successful entries/exits.
- **User management**: suspend / reactivate accounts.
- **Organizer application approval**: `PATCH /super-admin/organizer-requests/{id}/approved` / `.../rejected` with `AuditRecorder` metadata.
- **Event moderation**: suspend / reactivate / cancel events.
- **Staff provisioning**: assign staff accounts to the super-admin scope.
- **Activity History** (`GET /admin/activity-history`):
  - Filters: actor type (User/System), action, entity type, outcome, date range.
  - Only **approved metadata** is returned; credentials, tokens, QR secrets, payment secrets, and personal contact details are **never displayed**.
  - Timestamps are **UTC** (`occurred_at` formatted with `TIMESTAMP (UTC)` / `TIMESTAMP (LOCAL)`).
  - Details drawer shows per-record metadata (actor name, action, entity, outcome, approved fields, previous/new status).
  - Pagination + clear filters / apply filters UI.

---

## Tech Stack & Architecture

| Layer | Framework / Library | Notes |
|---|---|---|
| **Frontend** | React 19 + Vite + Axios + `@yudiel/react-qr-scanner` | ES modules (`"type": "module"`); `VITE_API_BASE_URL` / `VITE_API_URL` env normalization |
| **Backend** | Laravel 12 + PHP 8.2 + Sanctum + MySQL / SQLite | `composer.json` uses pure-PHP `TicketPdfService` (no DomPDF); rate limits in `config/rate_limits.php` |
| **Concurrency** | `DB::transaction()` + `lockForUpdate()` on events, reservations, tickets | Prevents over-selling + duplicate scans |
| **Authentication** | Sanctum bearer tokens (`sessionStorage.getItem('eventpass_access_token')`) | 4 role tokens: client / admin / organizer / staff |
| **Authorization** | Middleware chain: `auth:sanctum` → `account.active` → `role:` (per enum `UserRole`) → `throttle:` (per endpoint) | `User::$fillable` = name/email/password only (role removed) |
| **Audit** | `AuditRecorder::recordUserAction(User $actor, string $action, int $entityId, string $outcome='success', array $metadata=[])` | `ACTIONS` allowlist per type; required/optional/fields enforced; `occurred_at` UTC; immutable DB |
| **Database** | SQLite in tests (`:memory:`) + MySQL in production | `phpunit.xml` + `tests/Support/sqlite-test-bootstrap.php` pins `CACHE_STORE=array`, `SESSION_DRIVER=array`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` |
| **PDF** | Pure-PHP generator (`TicketPdfService`): PDF 1.4 structure (header → objs → xref → trailer → EOF), no external deps | Replaces unavailable `barryvdh/laravel-dompdf` |
| **Styles** | Tailwind-inspired CSS vars (`--line`, `--muted`, `--text`, `--accent`) + component-scoped `.css` files | Mobile-first breakpoints at 480px / 600px / 390px |

---

## Local Setup & Running Tests

### Backend

```bash
cd backend
composer install               # (or verify vendor/ already present)
cp .env.example .env           # never modify tracked .env / .env.testing
# Ensure these env vars are set (process-scoped, never edit .env):
#   DB_CONNECTION=sqlite
#   DB_DATABASE=:memory:
#   DB_URL=""
#   APP_ENV=local
#   APP_DEBUG=false
#   SESSION_DRIVER=array
#   CACHE_STORE=array
#   CORS_ALLOWED_ORIGINS=http://127.0.0.1:5173
php -d variables_order=EGPCS artisan migrate --force          # creates all tables
php -d variables_order=EGPCS artisan app:create-super-admin   # provision admin
php -d variables_order=EGPCS vendor/bin/phpunit              # 170 tests, 1455 assertions
php -d variables_order=EGPCS vendor/bin/pint --test          # 0 style issues
```

### Frontend

```bash
cd frontend
npm install
# Verify .env (create if missing; do NOT modify tracked .env / .env.testing):
#   VITE_API_BASE_URL=http://127.0.0.1:8000/api
#   VITE_API_URL=http://127.0.0.1:8000
npm run dev                    # Vite dev server on http://127.0.0.1:5173
npm run lint                   # ESLint exit 0 — 0 errors, 0 warnings
npm run build                  # Vite production bundle, 0 errors
```

### Test Suite

The 170-test PHPUnit suite covers:

- Client ticket ownership & PDF download (owner `200` + PDF, non-owner `403`).
- Organizer profile CRUD + validation.
- Staff directory search + assign / unassign.
- Rate-limiter determinism (no cross-test `429` leakage).
- PDF generator structure (`%PDF-1.4` header, xref, trailer, `%%EOF`).
- 4-role end-to-end walkthrough (register → reserve → scan → audit).
- Super admin activity history + audit-metadata allowlist.

---

## Phase Milestones Summary

| Phase | Deliverable | Status |
|---|---|---|
| 16 | Initial state: 1402/1402 tests, Pint, ESLint, Vite | ✅ Closed |
| 17 | Closing: docs, commits, clean tree | ✅ Closed |
| 18A | PDF ticket download (pure-PHP), organizer profile API, 165 tests | ✅ Closed |
| 18B | Staff directory endpoint, rate-limiter bootstrap fix, 170 tests | ✅ Closed |
| 18B-2 | Frontend: staff directory UI, QR scanner polish (390px, fallback, ENTRY/EXIT, history) | ✅ Closed |
| 19 | Full 4-role E2E walkthrough (client PDF, supervisor activity, organizer profile+staff, staff scanner) | ✅ Closed |
| 20 | Portfolio README + Presentation guide | ✅ In progress |

---

## Contact & Repository

- **Repository**: `https://github.com/mohamedredabenhaddar-dev/eventpass`
- **Primary branch**: `main` (latest commit: `f56649d` — feat(backend): … feat(frontend): …)
- **Running processes**: port `3308` (PID `14200`, MariaDB data dir) left untouched per safeguards; ports `8000` / `5173` used for local dev / verification only.