# AttendEase

Responsive PHP 8+ attendance dashboard with MySQL/MariaDB schema and PDO data access.

## Setup

1. Import the database: `mysql -u root -p < schema.sql`.
2. Set database host, name, username, and password in `db.php`.
3. Serve this directory with PHP 8+ and the PDO MySQL extension enabled.
4. Add accounts directly to `users`; the `password` column must contain a PHP `password_hash()` result. Student profile data is connected through `students.user_id`.

Example account insert (replace with the output of `password_hash('your-password', PASSWORD_DEFAULT)`):

```sql
INSERT INTO users (name, email, password, role)
VALUES ('Admin User', 'admin@school.edu', '$2y$10$replace_with_password_hash', 'admin');
```

Login uses `password_verify()`. Dashboard and reports show database records. The face registration button updates the student's status as a UI placeholder; connect it to a camera enrollment service before treating it as biometric registration.

Bootstrap 5.3.3 and Google Fonts are loaded from CDNs.
