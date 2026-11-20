-- Adds idempotent session finalization, correction audit, and password reset support.
-- Run after 001_totp_attendance.sql. Existing records are retained.
CREATE TABLE IF NOT EXISTS schema_migrations (
  version VARCHAR(64) NOT NULL PRIMARY KEY,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

DELIMITER //
DROP PROCEDURE IF EXISTS migrate_attendease_workflows_002//
CREATE PROCEDURE migrate_attendease_workflows_002()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM schema_migrations WHERE version='002_workflows') THEN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='auth_version') THEN
      ALTER TABLE users ADD COLUMN auth_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER is_active;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='attendance_sessions' AND column_name='finalized_at') THEN
      ALTER TABLE attendance_sessions ADD COLUMN finalized_at DATETIME NULL AFTER ended_at;
    END IF;

    ALTER TABLE attendance_logs MODIFY verification_method ENUM('TOTP','Manual Override','System','Legacy') NOT NULL DEFAULT 'Legacy';

    CREATE TABLE IF NOT EXISTS instructors (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL UNIQUE,
      employee_number VARCHAR(32) NOT NULL UNIQUE,
      department VARCHAR(120) NOT NULL,
      CONSTRAINT fk_instructors_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;

    CREATE TABLE IF NOT EXISTS password_resets (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      token_hash CHAR(64) NOT NULL UNIQUE,
      expires_at DATETIME NOT NULL,
      used_at DATETIME NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      INDEX idx_password_reset_expiry (expires_at,used_at)
    ) ENGINE=InnoDB;

    CREATE TABLE IF NOT EXISTS password_reset_attempts (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      email_hash CHAR(64) NOT NULL,
      ip_hash CHAR(64) NOT NULL,
      attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_password_reset_attempt_window (ip_hash,attempted_at),
      INDEX idx_password_reset_email_window (email_hash,attempted_at)
    ) ENGINE=InnoDB;

    CREATE TABLE IF NOT EXISTS attendance_audit_logs (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      attendance_log_id INT UNSIGNED NOT NULL,
      actor_user_id INT UNSIGNED NULL,
      previous_status ENUM('Present','Late','Absent','Excused') NOT NULL,
      new_status ENUM('Present','Late','Absent','Excused') NOT NULL,
      reason VARCHAR(500) NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      CONSTRAINT fk_attendance_audit_record FOREIGN KEY (attendance_log_id) REFERENCES attendance_logs(id) ON DELETE RESTRICT,
      CONSTRAINT fk_attendance_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
      INDEX idx_attendance_audit_record_time (attendance_log_id,created_at)
    ) ENGINE=InnoDB;

    CREATE TABLE IF NOT EXISTS admin_audit_logs (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      actor_user_id INT UNSIGNED NULL,
      action VARCHAR(60) NOT NULL,
      entity_type VARCHAR(40) NOT NULL,
      entity_id BIGINT UNSIGNED NULL,
      details TEXT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      CONSTRAINT fk_admin_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
      INDEX idx_admin_audit_entity_time (entity_type,entity_id,created_at),
      INDEX idx_admin_audit_actor_time (actor_user_id,created_at)
    ) ENGINE=InnoDB;

    INSERT INTO schema_migrations(version) VALUES ('002_workflows');
  END IF;
END//
CALL migrate_attendease_workflows_002()//
DROP PROCEDURE migrate_attendease_workflows_002//
DELIMITER ;
