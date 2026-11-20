# EventPass — Permanent Agent Rules & Safety Mandates

This file documents the non-negotiable rules that apply across all phases of EventPass development. Violation of these rules may corrupt the environment, cause data loss, or violate the isolation requirements of this project.

## 1. Protected Ports & Processes
- **Port 3306 (MySQL/MariaDB):** NEVER connect to, query, or alter the `eventpass` or `eventpass_test` databases on port 3306. This is the production and test EventPass datastore. All PHP/Artisan commands must use process-scoped `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:` when running tests or verification checks.
- **PID 7652 / Port 3308:** NEVER interact with, restart, or stop this process. It is a MariaDB instance that must remain running and untouched throughout all phases.
- **Port 3307:** Used for isolated temporary MariaDB instances only. Do not use for persistent services. Any port 3307 instance must be gracefully shut down and its directory deleted before finishing.

## 2. Environment Variable Policy
- NEVER modify `.env` or `.env.testing` files. These are tracked repository files.
- Always use **process-scoped** `$env:...` variables when running PHP/Artisan commands.
- Always pass `-d variables_order=EGPCS` to every `php` invocation so PHP reads process environment variables ahead of `.env`.
- Example: `php -d variables_order=EGPCS artisan serve --host=127.0.0.1 --port=8000`

## 3. Git Commands — Destructive Operations Forbidden
- NEVER run `git commit`, `git push`, `git reset`, `git checkout`, `git restore`, or `git clean`.
- Only `git status --short` and `git diff` are permitted for read-only verification.

## 4. Mandatory Cleanup of Temporary Files
- At the conclusion of every milestone, remove any temporary helper scripts created during the phase:
  - `seed_sqlite.php`
  - `init_db.php`
  - `start_backend.ps1`
  - `start_vite_background.bat`
  - `frontend/vite.log`
  - Any `phase145c-*` directories in `C:\Users\reda\AppData\Local\Temp\opencode\`
  - Any `phase147-mysql-*` directories in `C:\Users\reda\AppData\Local\Temp\opencode\`
- Verify `git status --short` returns to the exact pre-phase baseline (12 modified / 28 untracked baseline match).

## 5. SQLite Driver Check
- When bootstrapping Laravel with SQLite, the driver check must output exactly:
  ```
  sqlite => <exact $SqliteFile path>
  ```
- If the output is not strictly `sqlite => <path>`, the setup must be stopped and debugged before proceeding.

## 6. Rate Limiting (API)
- Authentication endpoints (`login`, `register`) and QR credential generation/validation endpoints must be protected by explicit rate limiters (`throttle:...`).
- Feature tests must verify `429 Too Many Requests` when rate limits are exceeded.

## 7. Audit Recording — UTC & Metadata
- All audit timestamps (`occurred_at`) must be explicitly normalized to **UTC**.
- `AuditRecorder` enforces required metadata keys per action type, raising a clear `InvalidArgumentException` on missing required metadata.
- The allowlist of permitted metadata fields per action is strictly enforced; unexpected keys cause rejection.
- `AUDIT_HISTORY.md` must document the UTC strategy, required metadata keys, and DB-level immutability caveats.

## 8. CORS Configuration
- Origins must be driven by environment/config (`FRONTEND_URL` / `CORS_ALLOWED_ORIGINS`), defaulting to `http://localhost:5173,http://127.0.0.1:5173` rather than `*`.
- `allowed_headers` must explicitly support `Authorization` and `Content-Type`.
- `OPTIONS` preflight must work cleanly.

---

**Last reviewed:** $(date)
**Sovereign to:** EventPass project — C:\Users\reda\OneDrive\Desktop\tickets