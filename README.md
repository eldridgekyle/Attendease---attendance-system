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
```

For a local MySQL socket connection, set `ATTENDEASE_DB_SOCKET` to the socket path instead of using `ATTENDEASE_DB_HOST`.

Keep `ATTENDEASE_APP_KEY` stable. It encrypts active TOTP session secrets in MySQL. Rotating it ends the ability to display or verify codes for sessions already in progress; it does not affect user accounts or attendance records.

## Database setup

For a new development database, import the safe, repeatable setup file:

```sh
mysql -u "$ATTENDEASE_DB_USER" -p < schema.sql
```

The development seed accounts all use the password `password`; change their passwords before deployment. These are ordinary accounts that still require their email and password at sign-in. There are no one-click demo sign-ins.

| Role | Email |
| --- | --- |
| Administrator | `admin@attendease.test` |
| Instructor | `daniel.reyes@attendease.test` |
| Instructor | `sofia.lim@attendease.test` |
| Student | `alyssa.cruz@attendease.test` |

For an existing AttendEase database, take a database backup and run the incremental migration from the repository root:

```sh
mysql -u "$ATTENDEASE_DB_USER" -p "$ATTENDEASE_DB_NAME" < migrations/001_totp_attendance.sql
```

The migration is tracked in `schema_migrations` and can be run again safely. It retains users, classes, enrollments, notifications, and attendance rows. It adds per-session TOTP data and account status, repairs the malformed hash used by the original development seed accounts, replaces the old student/class/day attendance uniqueness key with a student/session key, labels old face-verified rows as `Legacy`, closes old PIN/QR sessions, and removes obsolete face and PIN/QR fields. Old attendance rows remain attached to their students and classes with `session_id` set to `NULL`.

Do not import `schema.sql` over a populated production database. Use the migration for an existing installation.

## Run locally

From the project directory, start the PHP development server after exporting the database and application settings above:

```sh
php -S 127.0.0.1:8000
```

Open `http://127.0.0.1:8000`.

## Checks

Run the RFC 6238 test vectors and the database workflow checks on a development database:

```sh
php tests/totp_vectors.php
php tests/database_workflows.php
```

The database workflow check uses a transaction and rolls back its temporary accounts, enrollments, sessions, and attendance rows.

## TOTP attendance

An instructor chooses an assigned class and clicks **Start Attendance**. AttendEase creates a random session secret, encrypts it with `ATTENDEASE_APP_KEY`, displays its six-digit RFC 6238 code, and refreshes the displayed code every 30 seconds. Sessions expire after 15 minutes and instructors can end them manually. Starting another session for the same class ends the previous active session.

Students sign in, choose an enrolled class, enter the displayed current code, and submit it. The server verifies the current 30-second TOTP step. A code remains valid for every eligible student during its current interval, while a student can create only one attendance row per session. Invalid submissions are rate limited. Ended or expired sessions reject check-ins server-side.

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
| `schema.sql` | Safe fresh-development schema and preserved sample dataset |
| `migrations/001_totp_attendance.sql` | Incremental migration for existing AttendEase databases |
| `db.php` | PDO connection using environment configuration |
| `functions.php` | Session and role checks, CSRF, password/session helpers, and RFC 6238 functions |
| `index.php` | Password sign-in and student registration |
| `dashboard.php` | Role-scoped statistics, schedules, charts, and recent activity |
| `attendance_logs.php` | Attendance filters, CSV exports, corrections, and TOTP session controls |
| `attendance_session.php` / `checkin.php` | Server-side session lifecycle, code display, and student verification |
| `students_manage.php` | Student accounts, enrollments, class management, and instructor assignments |
| `profile.php` | Account profile, password update, courses, and attendance summary |
| `notifications.php` | Notification read, unread, filter, and delete actions |
| `assets/css/app.css` | Shared AttendEase visual styles and responsive layouts |

Bootstrap, Bootstrap Icons, and Chart.js load from public CDNs.
