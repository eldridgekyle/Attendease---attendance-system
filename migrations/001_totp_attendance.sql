-- Incremental migration from the original AttendEase schema.
-- It preserves users, student profiles, classes, enrollments, and attendance rows.
CREATE TABLE IF NOT EXISTS schema_migrations (
  version VARCHAR(64) NOT NULL PRIMARY KEY,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

DELIMITER //
DROP PROCEDURE IF EXISTS migrate_attendease_totp_001//
CREATE PROCEDURE migrate_attendease_totp_001()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM schema_migrations WHERE version='001_totp_attendance') THEN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='is_active') THEN
      ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role;
    END IF;
    -- Repair the malformed hash used by the original development seed accounts.
    UPDATE users SET password_hash='$2y$12$HcWuEYNek6nPwaS7XQcFWunI6Y03No93kgKX5ce2RBcuCfvkndne.'
      WHERE password_hash='$2y$10$92IXUNpkjpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='attendance_logs' AND column_name='session_id') THEN
      ALTER TABLE attendance_logs ADD COLUMN session_id BIGINT UNSIGNED NULL AFTER class_id;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='attendance_logs' AND index_name='idx_attendance_student') THEN
      ALTER TABLE attendance_logs ADD INDEX idx_attendance_student (student_id);
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='attendance_logs' AND index_name='uq_student_class_day') THEN
      ALTER TABLE attendance_logs DROP INDEX uq_student_class_day;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='attendance_logs' AND index_name='uq_attendance_session_student') THEN
      ALTER TABLE attendance_logs ADD UNIQUE KEY uq_attendance_session_student (student_id,session_id);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='attendance_logs' AND index_name='idx_attendance_session') THEN
      ALTER TABLE attendance_logs ADD INDEX idx_attendance_session (session_id);
    END IF;

    ALTER TABLE attendance_logs MODIFY verification_method ENUM('Facial Scan','Manual Override','TOTP','Legacy') NOT NULL DEFAULT 'Legacy';
    UPDATE attendance_logs SET verification_method='Legacy' WHERE verification_method='Facial Scan';
    ALTER TABLE attendance_logs MODIFY verification_method ENUM('TOTP','Manual Override','Legacy') NOT NULL DEFAULT 'Legacy';

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='attendance_sessions' AND column_name='secret_ciphertext') THEN
      ALTER TABLE attendance_sessions ADD COLUMN secret_ciphertext VARCHAR(512) NULL AFTER created_by;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='attendance_sessions' AND column_name='ended_at') THEN
      ALTER TABLE attendance_sessions ADD COLUMN ended_at DATETIME NULL AFTER expires_at;
    END IF;
    UPDATE attendance_sessions SET ended_at=created_at WHERE secret_ciphertext IS NULL AND ended_at IS NULL;
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='attendance_sessions' AND column_name='pin_hash') THEN
      ALTER TABLE attendance_sessions DROP COLUMN pin_hash;
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='attendance_sessions' AND column_name='qr_token_hash') THEN
      ALTER TABLE attendance_sessions DROP COLUMN qr_token_hash;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='attendance_logs' AND constraint_name='fk_attendance_log_session') THEN
      ALTER TABLE attendance_logs ADD CONSTRAINT fk_attendance_log_session FOREIGN KEY (session_id) REFERENCES attendance_sessions(id) ON DELETE SET NULL;
    END IF;

    CREATE TABLE IF NOT EXISTS attendance_attempts (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      student_id INT UNSIGNED NOT NULL,
      session_id BIGINT UNSIGNED NOT NULL,
      attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      CONSTRAINT fk_attendance_attempt_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
      CONSTRAINT fk_attendance_attempt_session FOREIGN KEY (session_id) REFERENCES attendance_sessions(id) ON DELETE CASCADE,
      INDEX idx_attendance_attempt_window (student_id,session_id,attempted_at)
    ) ENGINE=InnoDB;

    CREATE TABLE IF NOT EXISTS login_attempts (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      email VARCHAR(190) NOT NULL,
      ip_hash CHAR(64) NOT NULL,
      attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_login_attempt_window (email,ip_hash,attempted_at)
    ) ENGINE=InnoDB;

    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='students' AND column_name='face_status') THEN
      ALTER TABLE students DROP COLUMN face_status;
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='students' AND column_name='face_embedding_mock') THEN
      ALTER TABLE students DROP COLUMN face_embedding_mock;
    END IF;

    UPDATE notifications SET title=REPLACE(REPLACE(title,'Face registration','Student profile'),'Face profile','Student profile'),
      message=REPLACE(REPLACE(message,'face profile','student profile'),'face-based check-in','attendance check-in')
      WHERE title LIKE '%Face%' OR message LIKE '%face%';

    INSERT INTO schema_migrations(version) VALUES ('001_totp_attendance');
  END IF;
END//
CALL migrate_attendease_totp_001()//
DROP PROCEDURE migrate_attendease_totp_001//
DELIMITER ;
