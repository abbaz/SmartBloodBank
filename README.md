# Smart Blood Bank Management System

An enterprise web application for healthcare transfusion logistics, cold-chain inventory tracking, expiry-aware allocation, and blood-group compatibility matching backed by PHP and MySQL.

## Pages & Endpoints

### Frontend Pages
- `index.html` — Home dashboard with red-cell compatibility table and live MySQL inventory/request stats.
- `register.html` — Role-based user registration connected to MySQL (`users` table).
- `login.html` — Sign-in connected to MySQL with secure password verification.
- `contact.html` — Support inquiry form storing messages in MySQL (`contact_messages` table).

### Backend API (PHP + MySQL)
- `api/db.php` — Database connection handling (`smart_blood_bank` / `smart blood bank` on WAMP).
- `api/register.php` — Validates inputs, hashes password with bcrypt, checks duplicates, and inserts into `users`.
- `api/login.php` — Authenticates user identity and password against MySQL, initiates session.
- `api/contact.php` — Saves contact inquiries to `contact_messages`.
- `api/session.php` — Returns current logged-in user session.
- `api/logout.php` — Destroys session.
- `api/stats.php` — Queries inventory counts and pending requests from MySQL.
- `api/test_db.php` — Diagnostic test verifying MySQL connection and table row counts.
- `config/allocation-policy.json` — Shared compatibility and weighted-allocation policy used by the live engine and CCP simulation.
- `demo-seed.sql` — Repeatable synthetic donors, units, and hospital requests for WAMP demos.

## How to Run on WAMP

1. **Start WAMP**: Ensure the WampServer icon in your taskbar is green (Apache and MySQL running).
2. **Database**: Your database `smart_blood_bank` (or `smart blood bank`) in phpMyAdmin is ready. The schema is also available in `database.sql`.
3. **Open the Site in Browser**:
   Navigate to:
   ```
   http://localhost/SmartBloodBank/
   ```
   *(Do not open files via `file:///C:/wamp64/...` directly, because browsers cannot execute PHP without the Apache server).*

4. **Verify Database Connection** (sign in as an administrator first; this diagnostic is access-restricted):
   Open:
   ```
   http://localhost/SmartBloodBank/api/test_db.php
   ```
   You should see a JSON response with status `"OK"` and table counts. The diagnostic does not return user records.

5. **Enable atomic writes**: after taking a database backup, import `database-transactional-engines.sql` into the active application database. It converts the core tables to InnoDB so inventory, request, allocation, and audit writes can commit or roll back together. Database-backed API requests return service unavailable until all required tables are transactional.

6. **Protect audit history**: import `audit-immutability.sql` once. It installs triggers that reject UPDATE and DELETE against audit rows. Keep the MySQL administrator account private; a database owner can remove triggers.

7. **Use the live single-bank facility ID**: new hospital requests default to facility ID `1`. Set the optional WAMP environment variable `SBB_DEFAULT_FACILITY_ID` if this installation uses another numeric facility ID. Existing requests are migrated to facility `1` unless assigned manually.

## Account Roles

The registration form offers Donor (`USER`), Blood Bank Staff, Hospital Requester, and Laboratory Technician. Donor accounts sign in immediately; the donor profile itself waits for facility Staff verification. Verified donors can request/cancel appointments and review their own donation history in **Donor Portal**. Staff review donor profiles and appointments in **Donor Queue**, record actual collections through Inventory, and those units enter quarantine for Lab clearance. Donors cannot create inventory units or access screening results. Staff, Hospital, and Lab role applications remain pending until an Admin approves them in **Profile & Settings → Review Role Applications**. Admin is never offered in registration: an Admin can promote an approved Donor from **Profile & Settings → Promote a Regular User**. Admins can still provision known staff accounts directly from **Provision an Account**.

Existing databases automatically add the donor account link and donor workflow tables. `database-donor-portal.sql` is available for a manual migration; run it once after taking a backup. Existing donor records are not auto-linked to accounts; a staff member must verify identity and link the correct record from Donor Queue.

Admins can also use **Profile & Settings → Reset a User Password** to generate a one-time temporary password for another account in their facility. The temporary password is displayed once, is not stored in the audit log, and must be replaced at the user's next sign-in before protected APIs are available. Give it to the user privately. Existing databases automatically add the `must_change_password` flag; `database-admin-password-reset.sql` is also provided for manual migrations.

The Analytics page offers 30-day, 90-day, six-month, year-to-date, and custom date ranges. Period metrics use transaction date for issuances, unit expiry date for wastage, and request creation date for demand. Available stock and near-expiry counts remain current snapshots.

## Database Backups

Take a backup before importing seed data, schema migrations, or preparing a presentation. In PowerShell, adjust the database name and port to the one shown in phpMyAdmin:

```powershell
$backupDir = 'C:\wamp64\www\SmartBloodBank\backups'
New-Item -ItemType Directory -Force -Path $backupDir | Out-Null
$backupFile = Join-Path $backupDir ("smart-blood-bank-{0}.sql" -f (Get-Date -Format 'yyyyMMdd-HHmmss'))
& 'C:\wamp64\bin\mysql\mysql8.4.7\bin\mysqldump.exe' --host=127.0.0.1 --port=3306 --user=root --result-file=$backupFile 'smart blood bank'
if ($LASTEXITCODE -ne 0) { throw 'Database backup failed.' }
```

Verify the file exists and has a nonzero size. For a restore rehearsal, import the dump into a separate test database in phpMyAdmin; do not restore over the live database as a routine check.

## Validation

Run the shared allocation-policy check with Node.js:

```powershell
node tests/allocation-policy.test.js
```

Then follow [the release validation checklist](docs/validation-checklist.md) for role/privacy checks, all blood-group cases, cross-browser review, timing, and backup restoration. These manual WAMP/browser checks are not covered by the Node script.

For WAMP integration checks on Windows, run `tests/wamp-integration.ps1` from PowerShell. It backs up the configured source database to a temporary dump, restores an isolated copy, applies the transactional-engine migration to that copy, and exercises synthetic registration, two-facility isolation, partial fulfillment, and audit-failure rollback cases. It removes the temporary database and files when it finishes.

## Demo Login

Import [database-demo-accounts-reset.sql](database-demo-accounts-reset.sql) into the database configured in `api/db.php` to revoke old logins and create the local demo accounts below. Existing user rows and linked history remain in the database; old accounts are marked rejected and their credentials are replaced. The reset leaves exactly one approved Admin. The application does not recreate demo users at login.

| Role | Username | Email | Password |
|---|---|---|---|
| Admin | `admin_demo` | `admin.demo@example.test` | `AdminDemo!2026` |
| Blood bank staff | `staff_demo` | `staff.demo@example.test` | `StaffDemo!2026` |
| Hospital requester | `hospital_demo` | `hospital.demo@example.test` | `HospitalDemo!2026` |
| Lab technician | `lab_demo` | `lab.demo@example.test` | `LabDemo!2026` |
| Donor (regular User) | `donor_demo` | `donor.demo@example.test` | `DonorDemo!2026` |

These are classroom-only credentials for a local WAMP copy. Registration remains enabled: public sign-up creates a regular Donor account, while staff, hospital, and lab role applications require Admin approval; no one can register as Admin.
