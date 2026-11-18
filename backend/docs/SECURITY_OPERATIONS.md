# EventPass security operations

## Initial Super Admin provisioning

Provision the first administrator only from a trusted server shell, after the
application environment and database have been verified:

```sh
php artisan app:create-super-admin
```

The command interactively asks for a name and email, then securely prompts for
and confirms a password. Use a unique email and a password with at least eight
characters, letters, numbers, and symbols. The command does not accept
credentials as command-line arguments, writes no password to output, hashes it
using Laravel, and refuses to run after a Super Admin exists. A shared cache
store that supports atomic locks is required so simultaneous operator runs
cannot both pass the one-time bootstrap check. The account is created in a
database transaction.

Do not run this command during routine deployment or application startup. Do
not use public registration, seeders, or application code to grant the role.
The command is intentionally not a recovery or role-reset mechanism. If the
only Super Admin account is lost, use the organization’s controlled incident
recovery process: verify the operator and target account, obtain the required
approval, perform a reviewed and recorded recovery through trusted server-side
administrative access, then rotate credentials and verify access. There is no
hidden command option or public endpoint that bypasses the initial-account
guard. This repository does not automate that recovery process.

## API rate limits

Named route limiters return a generic JSON `429 Too Many Requests` response.
They do not include user, ticket, token, or credential data. Unauthenticated
login and registration requests are counted by source IP. Authenticated QR
credential and staff scanning requests are counted by the authenticated user
ID. Authentication and role middleware remain in force.

Defaults can be adjusted in the server environment and are loaded from
`config/rate_limits.php`:

| Environment variable | Default | Scope |
| --- | ---: | --- |
| `RATE_LIMIT_LOGIN_PER_MINUTE` | 5 | Login requests per source IP |
| `RATE_LIMIT_REGISTRATION_PER_HOUR` | 5 | Registration requests per source IP |
| `RATE_LIMIT_QR_CREDENTIALS_PER_MINUTE` | 10 | QR issue/re-issue requests per authenticated user |
| `RATE_LIMIT_STAFF_SCANS_PER_MINUTE` | 120 | Scan requests per authenticated staff user |

After changing environment values on a deployment with cached configuration,
rebuild the Laravel config cache as part of the normal deployment procedure.
These limits do not apply to public event discovery or ordinary dashboard GET
requests.

## Account suspension and in-flight requests

Authenticated requests that reach the `account.active` middleware after an
account has been suspended are rejected. Suspension also revokes that user's
existing Sanctum tokens. A request that already passed the middleware before
suspension may finish; the middleware does not cancel work already in progress.
Business operations do not take broad user-row locks for their full duration.

Login serializes token issuance with account suspension by locking the user row,
checking the active status, and creating the Sanctum token in the same
transaction. If login obtains the lock first, a later suspension waits and then
revokes the token. If suspension obtains the lock first, login observes the
suspended status and does not issue a token.

## Account-status migration deployment and rollback

Apply migration
`2026_11_18_150000_add_account_status_to_users_table.php` before deploying code
that reads account status or suspension metadata. The migration adds
`account_status`, `suspended_at`, `suspended_by`, and `suspension_reason`, plus
the associated index and foreign key. Deployments should use the normal reviewed
backup and migration procedure and verify the migration is recorded as applied
before enabling the new application code.

Do not roll this migration back casually after account suspensions have been
recorded. Its `down()` operation drops the suspension metadata and the
`suspended_by` relationship. Those records cannot be recovered by the rollback;
the deployed application also depends on these columns, so rolling back only
the schema creates a code/schema mismatch and can block authenticated requests.
Preserve the records and use a reviewed forward migration or coordinated recovery
plan when correcting a deployment. Do not use rollback as routine application
code rollback preparation.
