CREATE DATABASE IF NOT EXISTS barangay_assistance_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE barangay_assistance_system;

-- This schema is intentionally non-destructive. It is safe to run on an
-- existing database, but existing installations must also run the migrations.
CREATE TABLE IF NOT EXISTS roles (
  role_id INT AUTO_INCREMENT PRIMARY KEY,
  role_name VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
  user_id INT AUTO_INCREMENT PRIMARY KEY,
  role_id INT NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  phone_number VARCHAR(30) NOT NULL,
  profile_photo VARCHAR(255) NULL,
  password_hash VARCHAR(255) NOT NULL,
  status ENUM('Pending','Active','Inactive','Rejected') NOT NULL DEFAULT 'Pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_users_role_status (role_id, status),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(role_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS senior_citizens (
  senior_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL UNIQUE,
  first_name VARCHAR(80) NOT NULL,
  middle_name VARCHAR(80),
  last_name VARCHAR(80) NOT NULL,
  birthdate DATE NOT NULL,
  gender VARCHAR(20) NOT NULL,
  region VARCHAR(120) NOT NULL,
  province VARCHAR(120) NOT NULL,
  city_municipality VARCHAR(120) NOT NULL,
  barangay VARCHAR(120) NOT NULL DEFAULT '',
  address TEXT NOT NULL,
  place_of_birth VARCHAR(180) NOT NULL,
  civil_status VARCHAR(30) NOT NULL,
  blood_type VARCHAR(5) NOT NULL,
  religion VARCHAR(120) NOT NULL,
  education VARCHAR(80) NOT NULL,
  telephone VARCHAR(30),
  gsis_no VARCHAR(60),
  sss_no VARCHAR(60),
  tin_no VARCHAR(60),
  philhealth_no VARCHAR(60),
  employment_status VARCHAR(40) NOT NULL,
  classification VARCHAR(40) NOT NULL,
  monthly_pension DECIMAL(12,2) NULL,
  emergency_contact_name VARCHAR(150),
  emergency_contact_phone VARCHAR(30),
  valid_id_path VARCHAR(255),
  medical_notes TEXT,
  terms_accepted_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_seniors_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS assistance_requests (
  request_id INT AUTO_INCREMENT PRIMARY KEY,
  senior_id INT NOT NULL,
  request_type VARCHAR(100) NOT NULL,
  description TEXT NOT NULL,
  supporting_document VARCHAR(255) NULL,
  status ENUM('Pending','Under Review','Approved','Rejected','Scheduled','Completed') NOT NULL DEFAULT 'Pending',
  schedule_date DATETIME NULL,
  remarks TEXT,
  processed_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_assistance_status (status),
  INDEX idx_requests_senior_status_created (senior_id, status, created_at),
  CONSTRAINT fk_requests_senior FOREIGN KEY (senior_id) REFERENCES senior_citizens(senior_id) ON DELETE CASCADE,
  CONSTRAINT fk_requests_processor FOREIGN KEY (processed_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS announcements (
  announcement_id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(180) NOT NULL,
  body TEXT NOT NULL,
  audience ENUM('All','Senior Citizen') NOT NULL DEFAULT 'All',
  is_emergency TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_announcements_audience (audience),
  CONSTRAINT fk_announcements_user FOREIGN KEY (created_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS events (
  event_id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(180) NOT NULL,
  description TEXT NOT NULL,
  event_date DATETIME NOT NULL,
  venue VARCHAR(180) NOT NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_events_date (event_date),
  CONSTRAINT fk_events_user FOREIGN KEY (created_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
  notification_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  title VARCHAR(160) NOT NULL,
  message TEXT NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  announced_at DATETIME NULL DEFAULT NULL,
  notified_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  application_user_id INT NULL DEFAULT NULL,
  assistance_request_id INT NULL DEFAULT NULL,
  announcement_id INT NULL DEFAULT NULL,
  INDEX idx_notification_announcement (user_id,announcement_id),
  INDEX idx_notifications_user_read_created (user_id, is_read, created_at),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sms_logs (
  sms_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  phone_number VARCHAR(30) NOT NULL,
  message TEXT NOT NULL,
  status VARCHAR(140) NOT NULL,
  sent_at DATETIME NOT NULL,
  INDEX idx_sms_sent_at (sent_at),
  CONSTRAINT fk_sms_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
  audit_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(180) NOT NULL,
  entity VARCHAR(80) NOT NULL,
  entity_id INT NULL,
  ip_address VARCHAR(45) NOT NULL,
  user_agent VARCHAR(250) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_created (created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reports (
  report_id INT AUTO_INCREMENT PRIMARY KEY,
  generated_by INT NOT NULL,
  report_type VARCHAR(100) NOT NULL,
  parameters JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_reports_user FOREIGN KEY (generated_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(100) PRIMARY KEY,
  setting_value TEXT NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Required reference data. This does not create user accounts.
INSERT IGNORE INTO roles (role_id, role_name) VALUES
  (1, 'Administrator'), (2, 'Barangay Staff'), (3, 'Senior Citizen');
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('barangay_name', 'Barangay');

CREATE TABLE IF NOT EXISTS application_events (
 event_id INT AUTO_INCREMENT PRIMARY KEY,
 user_id INT NOT NULL,
 request_id INT NULL,
 status VARCHAR(60) NOT NULL,
 remarks TEXT NOT NULL,
 actor_id INT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_application_history (user_id, request_id, event_id),
 FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
 FOREIGN KEY (request_id) REFERENCES assistance_requests(request_id) ON DELETE CASCADE,
 FOREIGN KEY (actor_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS application_requirements (
 requirement_id INT AUTO_INCREMENT PRIMARY KEY,
 user_id INT NOT NULL,
 label VARCHAR(150) NOT NULL,
 status ENUM('Missing','Submitted','Accepted','Needs replacement') NOT NULL DEFAULT 'Missing',
 file_path VARCHAR(255) NULL,
 remarks TEXT NULL,
 reviewed_by INT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY user_requirement (user_id,label),
 FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
 FOREIGN KEY (reviewed_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS assistance_distributions (
 distribution_id INT AUTO_INCREMENT PRIMARY KEY,
 request_id INT NOT NULL UNIQUE,
 item VARCHAR(180) NOT NULL,
 quantity DECIMAL(12,2) NOT NULL,
 unit VARCHAR(40) NOT NULL,
 amount DECIMAL(12,2) NULL,
 released_at DATETIME NOT NULL,
 released_by INT NOT NULL,
 received_by VARCHAR(150) NOT NULL,
 remarks TEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (request_id) REFERENCES assistance_requests(request_id),
 FOREIGN KEY (released_by) REFERENCES users(user_id)
) ENGINE=InnoDB;
INSERT IGNORE INTO application_requirements (user_id,label,status,file_path)
 SELECT user_id,'Valid identification',IF(valid_id_path IS NULL OR valid_id_path='', 'Missing','Submitted'),valid_id_path FROM senior_citizens;
INSERT INTO application_events (user_id,status,remarks,created_at)
 SELECT u.user_id,'Submitted','Registration submitted.',u.created_at FROM users u JOIN senior_citizens s ON s.user_id=u.user_id
 WHERE NOT EXISTS(SELECT 1 FROM application_events e WHERE e.user_id=u.user_id AND e.request_id IS NULL);
INSERT INTO application_events (user_id,request_id,status,remarks,created_at)
 SELECT s.user_id,ar.request_id,'Submitted','Assistance request submitted.',ar.created_at FROM assistance_requests ar JOIN senior_citizens s ON s.senior_id=ar.senior_id
 WHERE NOT EXISTS(SELECT 1 FROM application_events e WHERE e.request_id=ar.request_id);
CREATE TABLE IF NOT EXISTS password_reset_codes (
  user_id INT PRIMARY KEY,
  code_hash VARCHAR(255) NOT NULL,
  expires_at BIGINT NOT NULL,
  attempts INT NOT NULL DEFAULT 0,
  sent_at BIGINT NOT NULL,
  window_started_at BIGINT NOT NULL,
  send_count INT NOT NULL DEFAULT 1,
  CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS registration_email_codes (
 email VARCHAR(150) PRIMARY KEY,
 code_hash VARCHAR(255) NOT NULL DEFAULT '',
 browser_hash CHAR(64) NOT NULL DEFAULT '',
 expires_at BIGINT NOT NULL DEFAULT 0,
 attempts INT NOT NULL DEFAULT 0,
 sent_at BIGINT NOT NULL DEFAULT 0,
 window_started_at BIGINT NOT NULL DEFAULT 0,
 send_count INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;
