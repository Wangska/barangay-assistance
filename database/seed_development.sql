-- Development-only sample data. Do not import this file in production.
USE barangay_assistance_system;

INSERT IGNORE INTO roles (role_id, role_name) VALUES
  (1, 'Administrator'), (2, 'Barangay Staff'), (3, 'Senior Citizen');

INSERT IGNORE INTO users (role_id, full_name, email, phone_number, password_hash, status)
SELECT role_id, 'System Administrator', 'admin@barangay.test', '09170000001', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Active'
FROM roles WHERE role_name = 'Administrator';
INSERT IGNORE INTO users (role_id, full_name, email, phone_number, password_hash, status)
SELECT role_id, 'Barangay Staff', 'staff@barangay.test', '09170000002', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Active'
FROM roles WHERE role_name = 'Barangay Staff';
INSERT IGNORE INTO users (role_id, full_name, email, phone_number, password_hash, status)
SELECT role_id, 'Maria Santos', 'senior@barangay.test', '09170000003', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Active'
FROM roles WHERE role_name = 'Senior Citizen';

INSERT INTO senior_citizens (user_id, first_name, last_name, birthdate, gender, region, province, city_municipality, address, place_of_birth, civil_status, blood_type, religion, education, employment_status, classification, emergency_contact_name, emergency_contact_phone, medical_notes, terms_accepted_at)
SELECT u.user_id, 'Maria', 'Santos', '1954-04-12', 'Female', 'Region IV-A', 'Laguna', 'Sample Municipality', 'Purok 1, Barangay Sample', 'Sample Municipality', 'Widowed', 'O+', 'Roman Catholic', 'Elementary', 'Retired', 'Pensioner', 'Ana Santos', '09170000005', 'Hypertension maintenance medicine.', NOW()
FROM users u
WHERE u.email = 'senior@barangay.test'
  AND NOT EXISTS (SELECT 1 FROM senior_citizens s WHERE s.user_id = u.user_id);

INSERT INTO assistance_requests (senior_id, request_type, description, status)
SELECT s.senior_id, 'Medical Assistance', 'Requesting medicine support for monthly maintenance.', 'Pending'
FROM senior_citizens s JOIN users u ON u.user_id = s.user_id
WHERE u.email = 'senior@barangay.test'
  AND NOT EXISTS (SELECT 1 FROM assistance_requests ar WHERE ar.senior_id = s.senior_id);

INSERT INTO announcements (title, body, audience, is_emergency, created_by)
SELECT 'Health Mission', 'Free checkup for senior citizens this Friday at the barangay hall.', 'All', 0, user_id
FROM users WHERE email = 'admin@barangay.test'
  AND NOT EXISTS (SELECT 1 FROM announcements WHERE title = 'Health Mission');

INSERT INTO events (title, description, event_date, venue, created_by)
SELECT 'Senior Citizens Assembly', 'Quarterly meeting and benefits orientation.', '2026-07-05 09:00:00', 'Barangay Hall', user_id
FROM users WHERE email = 'admin@barangay.test'
  AND NOT EXISTS (SELECT 1 FROM events WHERE title = 'Senior Citizens Assembly');

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('barangay_name', 'Barangay Sample');
