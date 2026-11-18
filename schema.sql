-- Fresh AttendEase development database. Existing databases must use migrations/001_totp_attendance.sql.
-- This file is safe to rerun: it never drops tables or overwrites seed data.
CREATE DATABASE IF NOT EXISTS attendease_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE attendease_db;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','teacher','student') NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  avatar_url VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_users_role (role)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS classes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  class_code VARCHAR(24) NOT NULL UNIQUE,
  subject_name VARCHAR(140) NOT NULL,
  teacher_id INT UNSIGNED NOT NULL,
  schedule_day ENUM('Monday','Tuesday','Wednesday','Thursday','Friday') NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  room VARCHAR(40) NOT NULL,
  CONSTRAINT fk_classes_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_classes_day_time (schedule_day,start_time)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS students (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL UNIQUE,
  student_number VARCHAR(32) NOT NULL UNIQUE,
  program_year VARCHAR(80) NOT NULL,
  section VARCHAR(40) NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_students_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS enrollments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id INT UNSIGNED NOT NULL,
  class_id INT UNSIGNED NOT NULL,
  CONSTRAINT fk_enrollments_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_enrollments_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  UNIQUE KEY uq_enrollment (student_id,class_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attendance_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  class_id INT UNSIGNED NOT NULL,
  created_by INT UNSIGNED NOT NULL,
  secret_ciphertext VARCHAR(512) NULL,
  expires_at DATETIME NOT NULL,
  ended_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_attendance_sessions_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  CONSTRAINT fk_attendance_sessions_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_attendance_sessions_expiry (expires_at),
  INDEX idx_attendance_sessions_active_class (class_id,ended_at,expires_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attendance_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id INT UNSIGNED NOT NULL,
  class_id INT UNSIGNED NOT NULL,
  session_id BIGINT UNSIGNED NULL,
  attendance_date DATE NOT NULL,
  time_in TIME NULL,
  time_out TIME NULL,
  status ENUM('Present','Late','Absent','Excused') NOT NULL DEFAULT 'Present',
  verification_method ENUM('TOTP','Manual Override','Legacy') NOT NULL DEFAULT 'Legacy',
  remarks VARCHAR(500) NULL,
  CONSTRAINT fk_attendance_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_attendance_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  CONSTRAINT fk_attendance_log_session FOREIGN KEY (session_id) REFERENCES attendance_sessions(id) ON DELETE SET NULL,
  INDEX idx_attendance_date (attendance_date),
  INDEX idx_attendance_status (status),
  INDEX idx_attendance_class_date (class_id,attendance_date),
  INDEX idx_attendance_session (session_id),
  UNIQUE KEY uq_attendance_session_student (student_id,session_id)
) ENGINE=InnoDB;

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

CREATE TABLE IF NOT EXISTS notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  message TEXT NOT NULL,
  type ENUM('alert','info','success') NOT NULL DEFAULT 'info',
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_notifications_user_read (user_id,is_read,created_at)
) ENGINE=InnoDB;

-- Development accounts use the password "password". Change them before deployment.
INSERT IGNORE INTO users (id,full_name,email,password_hash,role,avatar_url,created_at) VALUES
(1,'Maya Santos','admin@attendease.test','$2y$12$HcWuEYNek6nPwaS7XQcFWunI6Y03No93kgKX5ce2RBcuCfvkndne.','admin',NULL,NOW()-INTERVAL 180 DAY),
(2,'Daniel Reyes','daniel.reyes@attendease.test','$2y$12$HcWuEYNek6nPwaS7XQcFWunI6Y03No93kgKX5ce2RBcuCfvkndne.','teacher',NULL,NOW()-INTERVAL 150 DAY),
(3,'Sofia Lim','sofia.lim@attendease.test','$2y$12$HcWuEYNek6nPwaS7XQcFWunI6Y03No93kgKX5ce2RBcuCfvkndne.','teacher',NULL,NOW()-INTERVAL 145 DAY),
(4,'Alyssa Cruz','alyssa.cruz@attendease.test','$2y$12$HcWuEYNek6nPwaS7XQcFWunI6Y03No93kgKX5ce2RBcuCfvkndne.','student',NULL,NOW()-INTERVAL 120 DAY),
(5,'Marco Villanueva','marco.v@attendease.test','$2y$12$HcWuEYNek6nPwaS7XQcFWunI6Y03No93kgKX5ce2RBcuCfvkndne.','student',NULL,NOW()-INTERVAL 118 DAY),
(6,'Bianca Flores','bianca.f@attendease.test','$2y$12$HcWuEYNek6nPwaS7XQcFWunI6Y03No93kgKX5ce2RBcuCfvkndne.','student',NULL,NOW()-INTERVAL 116 DAY),
(7,'Joaquin Herrera','joaquin.h@attendease.test','$2y$12$HcWuEYNek6nPwaS7XQcFWunI6Y03No93kgKX5ce2RBcuCfvkndne.','student',NULL,NOW()-INTERVAL 114 DAY),
(8,'Nina Bautista','nina.b@attendease.test','$2y$12$HcWuEYNek6nPwaS7XQcFWunI6Y03No93kgKX5ce2RBcuCfvkndne.','student',NULL,NOW()-INTERVAL 112 DAY),
(9,'Gabriel Tan','gabriel.t@attendease.test','$2y$12$HcWuEYNek6nPwaS7XQcFWunI6Y03No93kgKX5ce2RBcuCfvkndne.','student',NULL,NOW()-INTERVAL 110 DAY),
(10,'Camille Dizon','camille.d@attendease.test','$2y$12$HcWuEYNek6nPwaS7XQcFWunI6Y03No93kgKX5ce2RBcuCfvkndne.','student',NULL,NOW()-INTERVAL 108 DAY),
(11,'Rafael Mendoza','rafael.m@attendease.test','$2y$12$HcWuEYNek6nPwaS7XQcFWunI6Y03No93kgKX5ce2RBcuCfvkndne.','student',NULL,NOW()-INTERVAL 106 DAY),
(12,'Isabel Chua','isabel.c@attendease.test','$2y$12$HcWuEYNek6nPwaS7XQcFWunI6Y03No93kgKX5ce2RBcuCfvkndne.','student',NULL,NOW()-INTERVAL 104 DAY),
(13,'Enzo Navarro','enzo.n@attendease.test','$2y$12$HcWuEYNek6nPwaS7XQcFWunI6Y03No93kgKX5ce2RBcuCfvkndne.','student',NULL,NOW()-INTERVAL 102 DAY),
(14,'Lara Aquino','lara.a@attendease.test','$2y$12$HcWuEYNek6nPwaS7XQcFWunI6Y03No93kgKX5ce2RBcuCfvkndne.','student',NULL,NOW()-INTERVAL 100 DAY),
(15,'Theo Garcia','theo.g@attendease.test','$2y$12$HcWuEYNek6nPwaS7XQcFWunI6Y03No93kgKX5ce2RBcuCfvkndne.','student',NULL,NOW()-INTERVAL 98 DAY),
(16,'Mika Ramos','mika.r@attendease.test','$2y$12$HcWuEYNek6nPwaS7XQcFWunI6Y03No93kgKX5ce2RBcuCfvkndne.','student',NULL,NOW()-INTERVAL 96 DAY),
(17,'Paolo de Leon','paolo.d@attendease.test','$2y$12$HcWuEYNek6nPwaS7XQcFWunI6Y03No93kgKX5ce2RBcuCfvkndne.','student',NULL,NOW()-INTERVAL 94 DAY),
(18,'Zoe Castillo','zoe.c@attendease.test','$2y$12$HcWuEYNek6nPwaS7XQcFWunI6Y03No93kgKX5ce2RBcuCfvkndne.','student',NULL,NOW()-INTERVAL 92 DAY);

INSERT IGNORE INTO classes (id,class_code,subject_name,teacher_id,schedule_day,start_time,end_time,room) VALUES
(1,'CS101','Introduction to Programming',2,'Monday','08:00:00','09:30:00','Lab 204'),
(2,'IT204','Database Management',2,'Tuesday','10:00:00','11:30:00','Room 301'),
(3,'MTH120','Applied Statistics',3,'Wednesday','09:00:00','10:30:00','Room 215'),
(4,'WEB210','Web Systems and Technologies',3,'Thursday','13:00:00','14:30:00','Innovation Lab'),
(5,'NET150','Fundamentals of Networking',2,'Friday','08:30:00','10:00:00','Lab 108');

INSERT IGNORE INTO students (id,user_id,student_number,program_year,section) VALUES
(1,4,'2024-00101','BSIT · Year 2','IT-2A'),(2,5,'2024-00102','BSIT · Year 2','IT-2A'),
(3,6,'2024-00103','BSIT · Year 2','IT-2A'),(4,7,'2024-00104','BSIT · Year 2','IT-2A'),
(5,8,'2024-00105','BSIT · Year 2','IT-2A'),(6,9,'2024-00106','BSIT · Year 2','IT-2A'),
(7,10,'2024-00107','BSIT · Year 2','IT-2A'),(8,11,'2024-00108','BSIT · Year 2','IT-2A'),
(9,12,'2024-00109','BSIT · Year 2','IT-2A'),(10,13,'2024-00110','BSIT · Year 2','IT-2A'),
(11,14,'2024-00111','BSIT · Year 2','IT-2A'),(12,15,'2024-00112','BSIT · Year 2','IT-2A'),
(13,16,'2024-00113','BSIT · Year 2','IT-2A'),(14,17,'2024-00114','BSIT · Year 2','IT-2A'),
(15,18,'2024-00115','BSIT · Year 2','IT-2A');

INSERT IGNORE INTO enrollments (student_id,class_id)
SELECT s.id,c.id FROM students s CROSS JOIN classes c;

-- Each class receives one recent sample record per student; legacy sample history is retained on reruns.
INSERT INTO attendance_logs (student_id,class_id,attendance_date,time_in,time_out,status,verification_method,remarks)
SELECT s.id,c.id,
  CURDATE()-INTERVAL MOD(WEEKDAY(CURDATE())-(FIELD(c.schedule_day,'Monday','Tuesday','Wednesday','Thursday','Friday')-1)+7,7) DAY,
  CASE WHEN MOD(s.id+c.id,13)=0 THEN NULL ELSE ADDTIME(c.start_time,SEC_TO_TIME(MOD(s.id*7+c.id*11,1500))) END,
  CASE WHEN MOD(s.id+c.id,13)=0 THEN NULL ELSE ADDTIME(c.end_time,'00:04:00') END,
  CASE WHEN MOD(s.id+c.id,13)=0 THEN 'Absent' WHEN MOD(s.id*3+c.id,9)=0 THEN 'Late' WHEN MOD(s.id,29)=0 THEN 'Excused' ELSE 'Present' END,
  CASE WHEN MOD(s.id,5)=0 THEN 'Manual Override' ELSE 'Legacy' END,
  CASE WHEN MOD(s.id+c.id,13)=0 THEN 'No check-in recorded' WHEN MOD(s.id*3+c.id,9)=0 THEN 'Arrived after class start' ELSE NULL END
FROM students s CROSS JOIN classes c
WHERE NOT EXISTS (
  SELECT 1 FROM attendance_logs existing
  WHERE existing.student_id=s.id AND existing.class_id=c.id AND existing.session_id IS NULL
    AND existing.attendance_date=CURDATE()-INTERVAL MOD(WEEKDAY(CURDATE())-(FIELD(c.schedule_day,'Monday','Tuesday','Wednesday','Thursday','Friday')-1)+7,7) DAY
);

INSERT IGNORE INTO notifications (id,user_id,title,message,type,is_read,created_at) VALUES
(1,1,'Weekly attendance report is ready','Your weekly attendance summary for all five classes is ready to review.','info',0,NOW()-INTERVAL 18 MINUTE),
(2,1,'Student profiles need review','Three student profiles need review.','alert',0,NOW()-INTERVAL 2 HOUR),
(3,1,'New instructor account added','Sofia Lim was added to the teaching team.','success',1,NOW()-INTERVAL 1 DAY),
(4,2,'Class starting soon','Introduction to Programming begins in Lab 204 at 8:00 AM.','info',0,NOW()-INTERVAL 35 MINUTE),
(5,2,'Attendance review requested','Please review the late arrivals from yesterday’s Database Management class.','alert',0,NOW()-INTERVAL 4 HOUR),
(6,3,'Class roster updated','Two students were added to Web Systems and Technologies.','success',1,NOW()-INTERVAL 1 DAY),
(7,4,'Attendance check-in ready','Your student profile is ready to use attendance check-in.','success',0,NOW()-INTERVAL 3 HOUR),
(8,4,'Attendance recorded','You were marked present in Introduction to Programming.','info',1,NOW()-INTERVAL 1 DAY),
(9,5,'Late arrival recorded','Your check-in for Database Management was recorded as late.','alert',0,NOW()-INTERVAL 1 DAY),
(10,6,'Review your student profile','Your student profile is ready for attendance check-in.','info',0,NOW()-INTERVAL 2 DAY),
(11,7,'Attendance streak','You have attended every class this week. Keep it up!','success',1,NOW()-INTERVAL 2 DAY),
(12,8,'Schedule reminder','Applied Statistics meets Wednesday at 9:00 AM in Room 215.','info',0,NOW()-INTERVAL 3 DAY),
(13,9,'Attendance marked excused','Your absence was updated to excused by your instructor.','success',1,NOW()-INTERVAL 4 DAY),
(14,10,'Review your student profile','Your student profile is ready for attendance check-in.','info',0,NOW()-INTERVAL 5 DAY),
(15,11,'Class update','Your Web Systems and Technologies room is Innovation Lab.','info',1,NOW()-INTERVAL 6 DAY),
(16,12,'Attendance recorded','Your attendance in Fundamentals of Networking was recorded.','success',0,NOW()-INTERVAL 7 DAY),
(17,13,'Review your student profile','Your student profile is ready for attendance check-in.','alert',0,NOW()-INTERVAL 8 DAY),
(18,14,'Attendance streak','You have attended every class this week. Keep it up!','success',1,NOW()-INTERVAL 9 DAY),
(19,15,'Attendance recorded','Your attendance records have been updated.','info',0,NOW()-INTERVAL 10 DAY),
(20,16,'Student profile reminder','Your student profile is ready for attendance check-in.','alert',0,NOW()-INTERVAL 11 DAY),
(21,17,'Attendance recorded','You were marked present in Applied Statistics.','success',1,NOW()-INTERVAL 12 DAY),
(22,18,'Welcome to AttendEase','Your student account is ready. Review your classes and attendance from your dashboard.','info',0,NOW()-INTERVAL 13 DAY);
