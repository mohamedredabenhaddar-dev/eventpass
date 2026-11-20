# DEPLOYMENT AND OPERATIONS GUIDE

## Production Deployment Checklist

### 1. PHP & Server Requirements
- PHP 8.2+ with required extensions (pdo, mbstring, xml, tokenizer, ctype, json)
- Composer install with `--no-dev` flag
- `php artisan key:generate` - generate application key
- `php artisan config:cache` - cache configuration
- `php artisan route:cache` - cache routes
- `php artisan view:cache` - cache views
- `npm run build` - build Vite frontend assets

### 2. Initial Super Admin Provisioning
- Never provision Super Admin in production without the secure Artisan command:
  ```
  php artisan app:create-super-admin
  ```
- The command prompts for administrator name, email, and secret password input
- Validates strong password rules (minimum 8 characters, letters, numbers, symbols)
- Prevents accidental duplicate provisioning (blocks if Super Admin already exists)
- `--force` flag required to override existing Super Admin (use with extreme caution)
- All actions logged via `AuditRecorder` with system actor type
- After creation, verify:
  - User has `role = super_admin`
  - User has `account_status = active`
  - No `suspended_at` value
  - Primary email is verified and unique

### 3. Automated MySQL Backups

#### Backup Command
```bash
mysqldump --single-transaction --routines --triggers \
  -u [username] -p[password] \
  [database_name] > backup_$(date +%Y%m%d_%H%M%S).sql
```

#### Recommended Backup Schedule
- **Full daily backup** at midnight UTC
- **Retention policy**: Keep 7 daily backups, 4 weekly backups, 3 monthly backups
- **Store backups** in off-site storage (S3, cloud storage, or separate server)

#### Tested Restore/Rollback Steps
1. Stop the application during restore to prevent data inconsistency
2. Drop existing tables: `mysql -u [username] -p[database_name] < drop_tables.sql`
3. Restore from backup: `mysql -u [username] -p[database_name] < backup_YYYYMMDD_HHMMSS.sql`
4. Verify data integrity: check key tables (users, events, reservations, audit_events)
5. Resume application
6. Validate critical workflows: booking, login, reservation management

### 4. Security Checklist

#### HTTPS & Domain
- **Enforce HTTPS** through web server (nginx/apache) or load balancer
- **Redirect HTTP to HTTPS** automatically
- **Valid SSL certificate** from trusted provider

#### Application Configuration
- `APP_ENV=production` - ensure environment is set to production
- `APP_DEBUG=false` - never enable debug mode in production
- `APP_TIMEZONE=UTC` - use UTC for all datetime operations
- `CORS_ALLOWED_ORIGINS` restricted to explicit trusted HTTPS origins (never `*`)
- Verify `CORS_ORIGINS` does not include `http://localhost` or `http://127.0.0.1` unless explicitly needed
- `SANCTUM_STATEFUL_DOMAINS` configured for production domains only

#### Rate Limiting
- Verify rate limiters are active on authentication endpoints:
  - `throttle:login` on `/login`
  - `throttle:registration` on `/register`
  - `throttle:qr-credentials` on `/tickets/{ticket}/qr-credential`
  - `throttle:staff-scans` on `/staff/scans`
  - `throttle:payments` on `/payments` and `/payments/{payment}/simulate-*`
- Rate limit values from `config/rate_limits.php`:
  - `login_per_minute=5`
  - `registration_per_hour=5`
  - `qr_credentials_per_minute=10`
  - `staff_scans_per_minute=120`
  - `payments_per_minute=10`

#### Audit Events Retention
- `audit_events` table should have append-only behavior
- Recommended retention policy: keep 24 months of audit logs
- Consider database- level append-only recommendations:
  - Use MariaDB/MySQL triggers or application-level logic to prevent audit log deletion
  - Regular cleanup of old audit entries outside retention policy
  - Backup audit database before any structural changes

#### Additional Security Measures
- `Fortified` Sanctum token expiration configured
- No hardcoded secrets in source code
- Environment variables prioritized over `.env` via `variables_order=EGPCS`
- Regular security reviews of dependencies

### 5. Rollback Procedure

#### When to Rollback
- Critical bug discovered in production
- Database migration causes data corruption
- Security vulnerability exploited
- Third-party integration failure

#### Rollback Steps
1. Identify the previous stable release (tag or commit hash)
2. `git checkout [stable_commit_hash]`
3. `composer install --no-dev`
4. `php artisan key:generate` (if changing codebase)
5. `php artisan migrate:rollback` (or specific migration rollback)
6. `php artisan config:clear`
7. `php artisan route:clear`
8. `php artisan view:clear`
9. `npm run build` (if frontend assets changed)
10. Restart application server
11. Verify critical workflows function correctly
12. Restore from MySQL backup if database was affected

#### Post-Rollback Verification
- All API endpoints returning expected responses
- Authentication workflow functioning
- Booking/reservation flow functional
- Admin dashboard accessible
- No JavaScript errors in browser console

### 6. Monitoring & Health Checks

#### Daily Checks
- Application server status and process availability
- Database connection stability
- Failed login attempt rates (verify rate limiting is working)
- Audit log entry rates
- Backup success verification

#### Weekly Checks
- Disk space utilization
- Backup integrity verification
- Rate limit configuration review
- CORS configuration review
- Token expiration review

#### Monthly Security Review
- Password policy enforcement verification
- Permission and role audit
- Dependency security scan (composer audit)
- Log rotation verification
- SSL certificate expiry check