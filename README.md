# AttendEase

AttendEase is a PHP 8 and MySQL attendance workspace for administrators, instructors, and students. It includes role-scoped dashboards, student and class management, attendance reports, notifications, and instructor-started RFC 6238 TOTP attendance sessions.

## Requirements

- PHP 8.0 or later with PDO MySQL, OpenSSL, and sessions enabled
- MySQL 8+ or MariaDB 10.4+
- HTTPS for production

## Configuration

Keep database credentials and the application encryption key in environment variables. Do not commit local environment files or secrets.

```sh
export ATTENDEASE_DB_HOST=127.0.0.1
export ATTENDEASE_DB_NAME=attendease_db
export ATTENDEASE_DB_USER=attendease_app
export ATTENDEASE_DB_PASS='your-database-password'
export ATTENDEASE_APP_KEY="$(openssl rand -base64 32)"
export ATTENDEASE_TIMEZONE='Asia/Manila'
export ATTENDEASE_BASE_URL='https://attendance.example.edu'
export ATTENDEASE_MAIL_FROM='noreply@example.edu'
# Optional: require student sign-ups to use this exact domain.
export ATTENDEASE_EMAIL_DOMAIN='example.edu'
```

For a local MySQL socket connection, set `ATTENDEASE_DB_SOCKET` to the socket path instead of using `ATTENDEASE_DB_HOST`.

Keep `ATTENDEASE_APP_KEY` stable. It encrypts active TOTP session secrets in MySQL. Rotating it ends the ability to display or verify codes for sessions already in progress; it does not affect user accounts or attendance records.

## Database setup

For a new development database, import the safe, repeatable setup file:

```sh
mysql -u "$ATTENDEASE_DB_USER" -p < schema.sql
```

The development seed accounts all use the password `password`; change their passwords before deployment. These are ordinary accounts that still require their email and password at sign-in. Fresh schemas seed accounts, classes, and enrollments but no fabricated attendance or notification history.

| Role | Email |
| --- | --- |
| Administrator | `admin@attendease.test` |
| Instructor | `daniel.reyes@attendease.test` |
| Instructor | `sofia.lim@attendease.test` |
| Student | `alyssa.cruz@attendease.test` |

For an existing AttendEase database, take and verify a database backup, then run both incremental migrations from the repository root:

```sh
mysql -u "$ATTENDEASE_DB_USER" -p "$ATTENDEASE_DB_NAME" < migrations/001_totp_attendance.sql
mysql -u "$ATTENDEASE_DB_USER" -p "$ATTENDEASE_DB_NAME" < migrations/002_workflows.sql
```

Both migrations are tracked in `schema_migrations` and can be run again safely. They retain users, classes, enrollments, notifications, and attendance rows. They add per-session TOTP data, session finalization, instructor details, reset tokens, correction audit records, and rate-limit tables; repair the malformed hash used by the original development seed accounts; replace the old student/class/day attendance uniqueness key with a student/session key; label old face-verified rows as `Legacy`; and close old PIN/QR sessions. Old attendance rows remain attached to their students and classes with `session_id` set to `NULL`. Legacy biometric and PIN/QR columns are left untouched so the migration does not discard existing data; application code does not read or expose those fields. Remove them only after a verified backup if physical deletion is required.

Do not import `schema.sql` over a populated production database. Use the migration for an existing installation.

## Run locally

From the project directory, start the PHP development server after exporting the database and application settings above:

```sh
php -S 127.0.0.1:8000
```

Open `http://127.0.0.1:8000`.

### XAMPP on Windows

1. Put the project in `C:\xampp\htdocs\AttendEase` and start MySQL from the XAMPP Control Panel.
2. In phpMyAdmin, create the database named in `ATTENDEASE_DB_NAME` (for example, `attendease_db`) and import `schema.sql`. For an existing database, take a backup and apply both migrations in order instead of importing the fresh schema.
3. Configure `ATTENDEASE_DB_HOST`, `ATTENDEASE_DB_NAME`, `ATTENDEASE_DB_USER`, `ATTENDEASE_DB_PASS`, `ATTENDEASE_APP_KEY`, and `ATTENDEASE_TIMEZONE` with Apache `SetEnv` directives in a local Apache configuration file that Apache loads. Use a dedicated MySQL account for AttendEase. Do not put credentials or the app key under `htdocs` or commit them. Generate the key once with `C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` and keep it stable.
4. Restart Apache. Open `http://localhost/AttendEase/` and sign in using a development account listed above. Change the seeded passwords before using the system beyond local testing.
5. For unattended 15-minute session expiry, add a Windows Task Scheduler task that runs `C:\xampp\php\php.exe` every minute with argument `C:\xampp\htdocs\AttendEase\expire_attendance_sessions.php`. Give that task the same database and app-key environment settings as Apache.

## Checks

Run the RFC 6238 test vectors and the database workflow checks on a development database:

```sh
php tests/totp_vectors.php
php tests/database_workflows.php
```

The database workflow check uses a transaction and rolls back its temporary accounts, enrollments, sessions, and attendance rows.

The database workflow check requires an existing development database with migrations applied. It needs `ATTENDEASE_DB_USER`, `ATTENDEASE_DB_PASS`, and a running MySQL service.

## TOTP attendance

An instructor chooses an assigned class and clicks **Start Attendance**. AttendEase creates a random session secret, encrypts it with `ATTENDEASE_APP_KEY`, displays its six-digit RFC 6238 code, and refreshes the displayed code every 30 seconds. Sessions expire after 15 minutes and instructors can end them manually. A second start request for the same class returns its existing active session instead of creating a duplicate.

Students sign in, choose an enrolled class, enter the displayed current code, and submit it. The server verifies the current 30-second TOTP step. A code remains valid for every eligible student during its current interval, while a student can create only one attendance row per session. Invalid submissions are rate limited. Ended or expired sessions reject check-ins server-side. Session closure records each still-enrolled student without a row as `Absent` with `System` verification, while preserving existing attendance. Repeated finalization is safe.

Configure a scheduler so absences are recorded at expiry even when nobody has the site open. Authenticated requests also finalize due sessions as a backup.

Linux cron example (once per minute):

```cron
* * * * * /usr/bin/php /path/to/AttendEase/expire_attendance_sessions.php
```

For XAMPP on Windows, create a Task Scheduler task that runs every minute with `C:\xampp\php\php.exe` and argument `C:\xampp\htdocs\AttendEase\expire_attendance_sessions.php`. Give the task the same database and application environment values used by Apache.

## Password recovery and instructor accounts

Set `ATTENDEASE_BASE_URL` to the canonical site URL and configure PHP `mail()` through the host or XAMPP SMTP settings. Reset links are random, single-use, stored as SHA-256 hashes, expire after one hour, and are rate limited. Responses do not reveal whether an email is registered. Without mail delivery configured, the request remains generic and the PHP error log records the missing setup.

Student registration is public; administrator accounts cannot be registered publicly. Administrators create instructor accounts from **Students & classes** and can activate or deactivate them. Set `ATTENDEASE_EMAIL_DOMAIN` to enforce one student email domain; leave it unset if multiple domains are used.

Profile photos accept JPEG, PNG, or WebP files up to 3 MB. Uploads use generated filenames under `uploads/avatars`; the uploaded filename is never used.

No face recognition, camera scanning, external authenticator, QR code, or PIN flow is used.

## Security notes

- Passwords use PHP `password_hash()` and `password_verify()`.
- Sessions use strict mode, HTTP-only cookies, SameSite Lax, session ID rotation, database-backed account status, and CSRF protection on state-changing forms.
- Sign-in and attendance code attempts are rate limited.
- Instructors can manage only their assigned classes and student rosters. Students can view their own attendance and check in only to enrolled classes.
- Student deactivation retains the account row, enrollment history, and attendance history. Class edits preserve the class ID and its attendance history.
- Configure a non-root MySQL account, use HTTPS, and rotate the seeded development passwords before deployment.

## Project map

| File | Purpose |
| --- | --- |
| `schema.sql` | Safe fresh-development schema with starter accounts, classes, and enrollments |
| `migrations/001_totp_attendance.sql` | Incremental migration for existing AttendEase databases |
| `migrations/002_workflows.sql` | Session finalization, correction audit, and password reset tables |
| `db.php` | PDO connection using environment configuration |
| `functions.php` | Session and role checks, CSRF, password/session helpers, and RFC 6238 functions |
| `index.php` | Password sign-in and student registration |
| `dashboard.php` | Role-scoped statistics, schedules, charts, and recent activity |
| `attendance_logs.php` | Attendance filters, CSV exports, corrections, and TOTP session controls |
| `attendance_session.php` / `checkin.php` | Server-side session lifecycle, code display, and student verification |
| `expire_attendance_sessions.php` | Scheduled CLI worker for unattended session expiry |
| `password_reset.php` | Rate-limited, single-use password recovery |
| `students_manage.php` | Student accounts, enrollments, class management, and instructor assignments |
| `profile.php` | Account profile, password update, courses, and attendance summary |
| `notifications.php` | Notification read, unread, filter, and delete actions |
| `assets/css/app.css` | Shared AttendEase visual styles and responsive layouts |

Bootstrap, Bootstrap Icons, and Chart.js load from public CDNs.
