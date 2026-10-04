-- One-time, irreversible cleanup for installations that previously supported
-- the Resident role. Take and verify a database backup before running this.
USE barangay_assistance_system;

-- Deleting Resident users cascades to their old resident profiles, concerns,
-- and notifications. SMS and audit records are retained with user_id set NULL.
DELETE u
FROM users u
JOIN roles r ON r.role_id = u.role_id
WHERE r.role_name = 'Resident';

-- Remove the retired role, then the tables used only by the retired workflow.
DELETE FROM roles WHERE role_name = 'Resident';
DROP TABLE IF EXISTS community_concerns;
DROP TABLE IF EXISTS residents;
