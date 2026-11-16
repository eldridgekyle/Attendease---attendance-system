# AttendEase

AttendEase is a PHP 8 and MySQL student attendance workspace for administrators, instructors, and students. It includes a seeded class directory, role-scoped attendance reports, a one-time PIN and QR check-in flow, student registration, notification management, and a responsive dashboard.

## Requirements

- PHP 8.0 or later with PDO MySQL and sessions enabled
- MySQL 8+ or MariaDB 10.4+
- A browser with JavaScript enabled; camera access requires HTTPS or localhost

## Setup

1. Import the development database: `mysql -u root -p < schema.sql`. The script recreates the AttendEase tables and replaces existing sample records.
2. Set database credentials in `db.php` or use `ATTENDEASE_DB_HOST`, `ATTENDEASE_DB_NAME`, `ATTENDEASE_DB_USER`, and `ATTENDEASE_DB_PASS` environment variables.
3. Serve this directory through PHP, for example: `php -S 127.0.0.1:8000`.
4. Open `http://127.0.0.1:8000`.

The included demo accounts all use the password `password`:

| Role | Email |
| --- | --- |
| Administrator | `admin@attendease.test` |
| Instructor | `daniel.reyes@attendease.test` |
| Instructor | `sofia.lim@attendease.test` |
| Student | `alyssa.cruz@attendease.test` |

The login page also has one-click Admin, Teacher, and Student buttons. New student registration asks for the first class to enroll in. Administrators can create student accounts and assign classes from the directory.

## Attendance check-in

Administrators and instructors can open the attendance scanner, select an assigned class, and generate a six-digit PIN with a 2, 5, or 10 minute expiry. The QR code opens the matching check-in page. Students must be signed in and enrolled in that class; a class/date record can only be checked in once per student. An instructor can correct records from the attendance report, with the change marked as a manual override.

Face registration uses the browser camera for a live preview when available and falls back to a simulated capture. The database stores a mock registration marker, not an image or a biometric template. Connect a dedicated, consent-based biometric service before using this interface for real face identification.

## Security and deployment

Forms that change data use session CSRF tokens, database writes use PDO prepared statements, and teacher/student queries enforce role ownership. Use HTTPS, set a non-root database account, replace the demo credentials, configure secure session cookies, and remove seeded demo accounts before deployment. The included `schema.sql` is intended for a fresh development database because it drops and recreates its tables.

Bootstrap, Bootstrap Icons, Chart.js, and QRCode.js load from public CDNs.

## Project map

| File | Purpose |
| --- | --- |
| `schema.sql` | Database tables, relationships, demo accounts, classes, attendance, and notifications |
| `db.php` | PDO connection and environment-based database settings |
| `functions.php` | Sessions, access checks, CSRF protection, formatting helpers, and shared page entry points |
| `includes/` | Shared sidebar, top bar, notification menu, and page footer |
| `index.php` | Sign-in, demo access, and student sign-up |
| `dashboard.php` | Role-scoped stats, class schedule, attendance chart, and activity feed |
| `attendance_logs.php` | Attendance filters, CSV export, PIN / QR session setup, and staff corrections |
| `attendance_session.php` / `checkin.php` | Expiring PIN and QR session creation and student check-in validation |
| `students_manage.php` | Instructor/admin student directory, enrollment, and class listing |
| `profile_set_face.php` | Account password, courses, attendance summary, and face profile setup |
| `notifications.php` | Read, unread, alert filter, and delete actions |
| `assets/css/app.css` | Shared colors, layout, component styles, and mobile breakpoints |
