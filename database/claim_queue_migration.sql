-- Safe to run more than once on a database that already has the earlier tables.
CREATE TABLE IF NOT EXISTS claim_queues (
  queue_id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL,
  queue_type ENUM('Release','Appointment') NOT NULL,
  queue_date DATE NOT NULL,
  queue_number INT NOT NULL,
  queue_code VARCHAR(20) NOT NULL,
  claimed_at DATETIME NULL,
  claimed_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_claim_queue_code (queue_code),
  UNIQUE KEY uq_claim_queue_slot (queue_type, queue_date, queue_number),
  UNIQUE KEY uq_claim_queue_request_day (request_id, queue_type, queue_date),
  CONSTRAINT fk_claim_queue_request FOREIGN KEY (request_id) REFERENCES assistance_requests(request_id) ON DELETE CASCADE,
  CONSTRAINT fk_claim_queue_officer FOREIGN KEY (claimed_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO roles (role_id, role_name) VALUES (4, 'Barangay Officer');

INSERT IGNORE INTO users (role_id, full_name, email, phone_number, password_hash, status)
SELECT role_id, 'Barangay Officer', 'officer@barangay.test', '09170000004', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Active'
FROM roles WHERE role_name = 'Barangay Officer';
