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
 WHERE NOT EXISTS(SELECT 1 FROM application_events e WHERE e.request_id=ar.request_id);ALTER TABLE assistance_requests MODIFY status ENUM('Pending','Under Review','Approved','Rejected','Scheduled','Completed') NOT NULL DEFAULT 'Pending';
