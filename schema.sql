CREATE DATABASE IF NOT EXISTS attendease_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE attendease_db;
CREATE TABLE IF NOT EXISTS users (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(100) NOT NULL,
 email VARCHAR(100) NOT NULL UNIQUE,
 password VARCHAR(255) NOT NULL,
 role ENUM('admin','teacher','student') NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS students (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NOT NULL,
 student_id_no VARCHAR(50) NOT NULL UNIQUE,
 course_year VARCHAR(50) NOT NULL,
 face_data_status ENUM('Registered','Pending') NOT NULL DEFAULT 'Pending',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_students_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS attendance_logs (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 student_id INT UNSIGNED NOT NULL,
 time_in DATETIME NULL,
 time_out DATETIME NULL,
 status ENUM('Present','Late','Absent') NOT NULL DEFAULT 'Present',
 `date` DATE NOT NULL,
 CONSTRAINT fk_attendance_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
 INDEX idx_attendance_date (`date`), INDEX idx_attendance_status (status)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS notifications (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NOT NULL,
 message TEXT NOT NULL,
 is_read BOOLEAN NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
 INDEX idx_notifications_user_read (user_id,is_read)
) ENGINE=InnoDB;
