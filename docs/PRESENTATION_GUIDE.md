# EventPass — Presentation & Demo Guide

## 10-Slide Presentation Outline

| Slide | Title | Talking Points |
|---|---|---|
| **1** | **Problem** | Event ticketing platforms today either lack role-based isolation, rely on external PDF dependencies, or fail on mobile QR scanning. EventPass must serve 4 roles (Client/Organizer/Staff/Super Admin) with strict authorization, concurrency-safe booking, rate-limiter determinism, and audit integrity — all while passing 0 test failures, 0 lint errors, and a clean production build. |
| **2** | **Architecture** | React 19 + Vite + Axios front end; Laravel 12 + PHP 8.2 + Sanctum back end. Concurrency via `DB::transaction()` + `lockForUpdate()`. Authentication: Sanctum bearer tokens in `sessionStorage`. Authorization: middleware chain `auth:sanctum → account.active → role: → throttle:`. Audit: `AuditRecorder` with strict per-action metadata allowlist + UTC `occurred_at`. |
| **3** | **Database & Concurrency Design** | SQLite `:memory:` for tests; MySQL in production. `lockForUpdate()` on events (prevents over-selling), reservations (prevents double-booking), tickets (prevents concurrent PDF/QR issuance). `DB::transaction()` with `attempts: 3` for resilience. Rate-limiter counters pinned in test bootstrap to avoid `429` leakage. |
| **4** | **Security & Audit Trail** | Role isolation via `User::$fillable` (name/email/password only; `role` removed). `throttle:` middleware on auth endpoints (`login`, `register`) and QR credential / scan endpoints. `AuditRecorder::ACTIONS` strict allowlist: required/optional/fields per action; unexpected keys rejected; `occurred_at` normalized to UTC; DB-level immutability caveats documented. |
| **5** | **4-Role Walkthrough — Client** | Register (`client.qa@eventpass.local`) → browse events → create reservation → demo payment success → **My Tickets** → **Download PDF** (`GET /tickets/{id}/pdf`, `200`, `Content-Type: application/pdf`, `%PDF-1.4` magic bytes, filename `eventpass-ticket-{code}.pdf`) → `403` for non-owners. |
| **6** | **4-Role Walkthrough — Organizer** | Submit organizer application → Super Admin approves → **Organizer Profile** `PUT` (org name, email, phone, bio, `200` + success banner) → create + publish event → **Event Staff** → searchable **Staff Directory** (`GET /staff/available`, one-click assign, `already_assigned` badge) → assign staff member. |
| **7** | **4-Role Walkthrough — Staff** | Login → `/staff/scanner` (390×844 mobile viewport) → **Live camera** (`facingMode: 'environment'`, rear-camera preference) → **Start/Stop toggle** → **ENTRY scan** (`200`, state `INSIDE`) → **duplicate ENTRY** (`409`, amber warning) → **EXIT scan** (`200`, state `OUTSIDE`) → **Recent Scans** list updates with 5 entries. |
| **8** | **4-Role Walkthrough — Super Admin** | Login → **Global statistics** → **Activity History** (`/admin/activity-history`) → filters (actor, action, entity, outcome, date range) → **Details drawer** per record (UTC timestamps, approved metadata, previous/new status) → organizer approval / user suspend / event cancel. |
| **9** | **Testing & QA** | 170 PHPUnit tests, 1455 assertions, `OK` every run (rate-limiter bootstrap fix). `pint --test` → 0 style issues. ESLint → 0 errors, 0 warnings. Vite build → 0 errors. Cross-test determinism (no `429` accumulation). Browser verified: PDF download, mobile scanner, activity drawer, staff directory, profile edit. |
| **10** | **Future Roadmap** | - PWA offline QR view. - Multi-database (PostgreSQL) scale. - Real-time WebSocket scan notifications. - Advanced analytics (heatmaps, conversion funnels). - Internationalization (i18n) for multilingual events and profiles. |

---

## 5-Minute Live Demo Script

| Minute | Step | Expected Outcome |
|---|---|---|
| **0:00–0:30** | **Client: discover & reserve** | Open `http://127.0.0.1:5173`, search an event, click **Get Ticket**, complete demo payment → ticket appears in **My Tickets**. |
| **0:30–1:00** | **Client: PDF download** | Click **Download PDF** on the ticket card → browser saves `eventpass-ticket-TKT-XXXX.pdf` → verify magic bytes `%PDF-1.4` and `%%EOF`; attempt as non-owner → `403` error. |
| **1:00–1:30** | **Organizer: onboarding & profile** | Login as client → submit organizer application → Super Admin approves → open **Organizer Profile**, update org name/contact/bio → **Save profile** → success banner "Profile saved successfully" → organization name persists. |
| **1:30–2:00** | **Organizer: event + staff** | Create + publish event → **Event Staff** page → search `staff.qa` in the **Available Staff Directory** → click **Assign** → badge changes to **Already assigned** → unassign with confirmation dialog. |
| **2:00–2:30** | **Staff: scanner (mobile)** | Login as staff → `/staff/scanner` → viewport auto-resizes to 390×844 → **Start camera** (rear-camera preference) → scan a QR ticket → **Green success banner** with attendee name + ticket type + `INSIDE` → attempt same scan again → **Amber warning** "already inside" → switch to **EXIT** → scan → **Green success** with `OUTSIDE` → recent scans list shows 2 entries. |
| **2:30–3:00** | **Super Admin: stats & audit** | Login as admin → **Statistics** page (attendees inside, unique tickets, entries/exits) → **Activity History** → apply filter "Organizer request approved" → details drawer shows UTC timestamp, actor name, action, approved metadata, previous/new status → navigate pages, clear filters. |
| **3:00–3:30** | **QA recap** | 170 tests, 0 failures; Pint 0 issues; ESLint 0 errors; Vite build 0 errors; rate-limiter deterministic (no cross-test `429`). All 4 roles verified via automated browser + HTTP walkthrough. |
| **3:30–5:00** | **Q&A** | Open floor for questions about architecture, concurrency, audit design, or any role-specific feature. |

---

## Files Created / Updated (Portfolio)

| Path | Description |
|---|---|
| `README.md` | Project overview, key features by role, tech stack, local setup, phase milestones. |
| `docs/PRESENTATION_GUIDE.md` | 10-slide presentation outline + 5-minute live demo script. |
| `backend/app/Services/TicketPdfService.php` | Pure-PHP PDF 1.4 generator (no DomPDF). |
| `frontend/src/pages/staff/StaffScannerPage.jsx` | Mobile QR scanner (camera, fallback, ENTRY/EXIT, history). |
| `frontend/src/pages/organizer/OrganizerEventStaffPage.jsx` | Searchable staff directory from `/staff/available`. |
| `frontend/src/pages/organizer/OrganizerProfilePage.jsx` | Profile edit with validation + success banner. |
| `backend/routes/api.php` | Staff directory endpoint `GET /organizer/events/{event}/staff/available`. |
| `backend/tests/Support/sqlite-test-bootstrap.php` | Rate-limiter leak fix (pins `CACHE_STORE=array`, `SESSION_DRIVER=array`). |
| `backend/tests/Feature/OrganizerEventStaffDirectoryTest.php` | 5 new staff-directory feature tests. |

---

## Safeguards (AGENTS.md)

- **Never** touch port `3306` (MySQL/MariaDB eventpass datastore).
- **Never** touch port `3308` / PID `14200` (MariaDB data directory).
- **Never** modify `.env` or `.env.testing` (tracked repository files).
- Always run PHP with `$env:variables_order=EGPCS`.
- No destructive git commands (`commit`, `push`, `reset`, `clean`) — only `status --short` and `diff`.
- Temp verification directories cleaned after each milestone.