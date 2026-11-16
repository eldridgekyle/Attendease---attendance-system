CREATE DATABASE IF NOT EXISTS attendease_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE attendease_db;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS attendance_sessions;
DROP TABLE IF EXISTS attendance_logs;
DROP TABLE IF EXISTS enrollments;
DROP TABLE IF EXISTS students;
DROP TABLE IF EXISTS classes;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','teacher','student') NOT NULL,
  avatar_url VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_users_role (role)
) ENGINE=InnoDB;

CREATE TABLE classes (
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

CREATE TABLE students (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL UNIQUE,
  student_number VARCHAR(32) NOT NULL UNIQUE,
  program_year VARCHAR(80) NOT NULL,
  section VARCHAR(40) NOT NULL,
  face_status ENUM('Registered','Pending') NOT NULL DEFAULT 'Pending',
  face_embedding_mock VARCHAR(255) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_students_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE enrollments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id INT UNSIGNED NOT NULL,
  class_id INT UNSIGNED NOT NULL,
  CONSTRAINT fk_enrollments_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_enrollments_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  UNIQUE KEY uq_enrollment (student_id,class_id)
) ENGINE=InnoDB;

CREATE TABLE attendance_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id INT UNSIGNED NOT NULL,
  class_id INT UNSIGNED NOT NULL,
  attendance_date DATE NOT NULL,
  time_in TIME NULL,
  time_out TIME NULL,
  status ENUM('Present','Late','Absent','Excused') NOT NULL DEFAULT 'Present',
  verification_method ENUM('Facial Scan','Manual Override') NOT NULL DEFAULT 'Facial Scan',
  remarks VARCHAR(500) NULL,
  CONSTRAINT fk_attendance_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_attendance_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  INDEX idx_attendance_date (attendance_date),
  INDEX idx_attendance_status (status),
  INDEX idx_attendance_class_date (class_id,attendance_date),
  UNIQUE KEY uq_student_class_day (student_id,class_id,attendance_date)
) ENGINE=InnoDB;

CREATE TABLE attendance_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  class_id INT UNSIGNED NOT NULL,
  created_by INT UNSIGNED NOT NULL,
  pin_hash VARCHAR(255) NOT NULL,
  qr_token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_attendance_sessions_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  CONSTRAINT fk_attendance_sessions_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_attendance_sessions_expiry (expires_at)
) ENGINE=InnoDB;

CREATE TABLE notifications (
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

-- Demo accounts use the password "password". Change these credentials before deployment.
INSERT INTO users (id,full_name,email,password_hash,role,avatar_url,created_at) VALUES
(1,'Maya Santos','admin@attendease.test','$2y$10$92IXUNpkjpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.','admin',NULL,NOW()-INTERVAL 180 DAY),
(2,'Daniel Reyes','daniel.reyes@attendease.test','$2y$10$92IXUNpkjpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.','teacher',NULL,NOW()-INTERVAL 150 DAY),
(3,'Sofia Lim','sofia.lim@attendease.test','$2y$10$92IXUNpkjpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.','teacher',NULL,NOW()-INTERVAL 145 DAY),
(4,'Alyssa Cruz','alyssa.cruz@attendease.test','$2y$10$92IXUNpkjpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.','student',NULL,NOW()-INTERVAL 120 DAY),
(5,'Marco Villanueva','marco.v@attendease.test','$2y$10$92IXUNpkjpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.','student',NULL,NOW()-INTERVAL 118 DAY),
(6,'Bianca Flores','bianca.f@attendease.test','$2y$10$92IXUNpkjpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.','student',NULL,NOW()-INTERVAL 116 DAY),
(7,'Joaquin Herrera','joaquin.h@attendease.test','$2y$10$92IXUNpkjpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.','student',NULL,NOW()-INTERVAL 114 DAY),
(8,'Nina Bautista','nina.b@attendease.test','$2y$10$92IXUNpkjpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.','student',NULL,NOW()-INTERVAL 112 DAY),
(9,'Gabriel Tan','gabriel.t@attendease.test','$2y$10$92IXUNpkjpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.','student',NULL,NOW()-INTERVAL 110 DAY),
(10,'Camille Dizon','camille.d@attendease.test','$2y$10$92IXUNpkjpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.','student',NULL,NOW()-INTERVAL 108 DAY),
(11,'Rafael Mendoza','rafael.m@attendease.test','$2y$10$92IXUNpkjpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.','student',NULL,NOW()-INTERVAL 106 DAY),
(12,'Isabel Chua','isabel.c@attendease.test','$2y$10$92IXUNpkjpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.','student',NULL,NOW()-INTERVAL 104 DAY),
(13,'Enzo Navarro','enzo.n@attendease.test','$2y$10$92IXUNpkjpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.','student',NULL,NOW()-INTERVAL 102 DAY),
(14,'Lara Aquino','lara.a@attendease.test','$2y$10$92IXUNpkjpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.','student',NULL,NOW()-INTERVAL 100 DAY),
(15,'Theo Garcia','theo.g@attendease.test','$2y$10$92IXUNpkjpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.','student',NULL,NOW()-INTERVAL 98 DAY),
(16,'Mika Ramos','mika.r@attendease.test','$2y$10$92IXUNpkjpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.','student',NULL,NOW()-INTERVAL 96 DAY),
(17,'Paolo de Leon','paolo.d@attendease.test','$2y$10$92IXUNpkjpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.','student',NULL,NOW()-INTERVAL 94 DAY),
(18,'Zoe Castillo','zoe.c@attendease.test','$2y$10$92IXUNpkjpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.','student',NULL,NOW()-INTERVAL 92 DAY);

INSERT INTO classes (id,class_code,subject_name,teacher_id,schedule_day,start_time,end_time,room) VALUES
(1,'CS101','Introduction to Programming',2,'Monday','08:00:00','09:30:00','Lab 204'),
(2,'IT204','Database Management',2,'Tuesday','10:00:00','11:30:00','Room 301'),
(3,'MTH120','Applied Statistics',3,'Wednesday','09:00:00','10:30:00','Room 215'),
(4,'WEB210','Web Systems and Technologies',3,'Thursday','13:00:00','14:30:00','Innovation Lab'),
(5,'NET150','Fundamentals of Networking',2,'Friday','08:30:00','10:00:00','Lab 108');

INSERT INTO students (id,user_id,student_number,program_year,section,face_status,face_embedding_mock) VALUES
(1,4,'2024-00101','BSIT · Year 2','IT-2A','Registered','demo-face-001'),(2,5,'2024-00102','BSIT · Year 2','IT-2A','Registered','demo-face-002'),
(3,6,'2024-00103','BSIT · Year 2','IT-2A','Pending',NULL),(4,7,'2024-00104','BSIT · Year 2','IT-2A','Registered','demo-face-004'),
(5,8,'2024-00105','BSIT · Year 2','IT-2A','Registered','demo-face-005'),(6,9,'2024-00106','BSIT · Year 2','IT-2A','Pending',NULL),
(7,10,'2024-00107','BSIT · Year 2','IT-2A','Registered','demo-face-007'),(8,11,'2024-00108','BSIT · Year 2','IT-2A','Registered','demo-face-008'),
(9,12,'2024-00109','BSIT · Year 2','IT-2A','Registered','demo-face-009'),(10,13,'2024-00110','BSIT · Year 2','IT-2A','Pending',NULL),
(11,14,'2024-00111','BSIT · Year 2','IT-2A','Registered','demo-face-011'),(12,15,'2024-00112','BSIT · Year 2','IT-2A','Registered','demo-face-012'),
(13,16,'2024-00113','BSIT · Year 2','IT-2A','Pending',NULL),(14,17,'2024-00114','BSIT · Year 2','IT-2A','Registered','demo-face-014'),
(15,18,'2024-00115','BSIT · Year 2','IT-2A','Registered','demo-face-015');

INSERT INTO enrollments (student_id,class_id)
SELECT s.id,c.id FROM students s CROSS JOIN classes c;

-- Each class gets one recent record set on its scheduled weekday: 75 rows total.
INSERT INTO attendance_logs (student_id,class_id,attendance_date,time_in,time_out,status,verification_method,remarks)
SELECT s.id,c.id,
  CURDATE()-INTERVAL MOD(WEEKDAY(CURDATE())-(FIELD(c.schedule_day,'Monday','Tuesday','Wednesday','Thursday','Friday')-1)+7,7) DAY,
  CASE WHEN MOD(s.id+c.id,13)=0 THEN NULL ELSE ADDTIME(c.start_time,SEC_TO_TIME(MOD(s.id*7+c.id*11,1500))) END,
  CASE WHEN MOD(s.id+c.id,13)=0 THEN NULL ELSE ADDTIME(c.end_time,'00:04:00') END,
  CASE WHEN MOD(s.id+c.id,13)=0 THEN 'Absent' WHEN MOD(s.id*3+c.id,9)=0 THEN 'Late' WHEN MOD(s.id,29)=0 THEN 'Excused' ELSE 'Present' END,
  CASE WHEN MOD(s.id,5)=0 THEN 'Manual Override' ELSE 'Facial Scan' END,
  CASE WHEN MOD(s.id+c.id,13)=0 THEN 'No check-in recorded' WHEN MOD(s.id*3+c.id,9)=0 THEN 'Arrived after class start' ELSE NULL END
FROM students s CROSS JOIN classes c;

INSERT INTO notifications (user_id,title,message,type,is_read,created_at) VALUES
(1,'Weekly attendance report is ready','Your weekly attendance summary for all five classes is ready to review.','info',0,NOW()-INTERVAL 18 MINUTE),
(1,'Face registration needs review','Three students still need to complete face registration.','alert',0,NOW()-INTERVAL 2 HOUR),
(1,'New teacher account added','Sofia Lim was added to the teaching team.','success',1,NOW()-INTERVAL 1 DAY),
(2,'Class starting soon','Introduction to Programming begins in Lab 204 at 8:00 AM.','info',0,NOW()-INTERVAL 35 MINUTE),
(2,'Attendance review requested','Please review the late arrivals from yesterday’s Database Management class.','alert',0,NOW()-INTERVAL 4 HOUR),
(3,'Class roster updated','Two students were added to Web Systems and Technologies.','success',1,NOW()-INTERVAL 1 DAY),
(4,'Face profile ready','Your face profile is registered and ready for attendance check-in.','success',0,NOW()-INTERVAL 3 HOUR),
(4,'Attendance recorded','You were marked present in Introduction to Programming.','info',1,NOW()-INTERVAL 1 DAY),
(5,'Late arrival recorded','Your check-in for Database Management was recorded as late.','alert',0,NOW()-INTERVAL 1 DAY),
(6,'Complete your face profile','Register your face profile to use quick attendance check-in.','info',0,NOW()-INTERVAL 2 DAY),
(7,'Attendance streak','You have attended every class this week. Keep it up!','success',1,NOW()-INTERVAL 2 DAY),
(8,'Schedule reminder','Applied Statistics meets Wednesday at 9:00 AM in Room 215.','info',0,NOW()-INTERVAL 3 DAY),
(9,'Attendance marked excused','Your absence was updated to excused by your instructor.','success',1,NOW()-INTERVAL 4 DAY),
(10,'Complete your face profile','Register your face profile to use quick attendance check-in.','info',0,NOW()-INTERVAL 5 DAY),
(11,'Class update','Your Web Systems and Technologies room is Innovation Lab.','info',1,NOW()-INTERVAL 6 DAY),
(12,'Attendance recorded','You were marked present in Fundamentals of Networking.','success',0,NOW()-INTERVAL 7 DAY),
(13,'Complete your face profile','Register your face profile to use quick attendance check-in.','alert',0,NOW()-INTERVAL 8 DAY),
(14,'Attendance streak','You have attended every class this week. Keep it up!','success',1,NOW()-INTERVAL 9 DAY),
(15,'Attendance recorded','Your attendance records have been updated.','info',0,NOW()-INTERVAL 10 DAY),
(16,'Face profile reminder','Your face profile is still pending registration.','alert',0,NOW()-INTERVAL 11 DAY),
(17,'Attendance recorded','You were marked present in Applied Statistics.','success',1,NOW()-INTERVAL 12 DAY),
(18,'Welcome to AttendEase','Your student account is ready. Review your classes and attendance from your dashboard.','info',0,NOW()-INTERVAL 13 DAY);
