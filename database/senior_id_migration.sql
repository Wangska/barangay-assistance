-- Digital senior ID number and issue date. Safe to run more than once.
USE barangay_assistance_system;

DROP PROCEDURE IF EXISTS add_senior_registration_column;
DELIMITER $$
CREATE PROCEDURE add_senior_registration_column(IN column_name VARCHAR(64), IN column_definition TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'senior_citizens'
      AND column_name = column_name
  ) THEN
    SET @registration_sql = CONCAT('ALTER TABLE senior_citizens ADD COLUMN ', column_name, ' ', column_definition);
    PREPARE registration_statement FROM @registration_sql;
    EXECUTE registration_statement;
    DEALLOCATE PREPARE registration_statement;
  END IF;
END$$
DELIMITER ;

CALL add_senior_registration_column('id_control_number', 'VARCHAR(20) NULL');
CALL add_senior_registration_column('id_issued_at', 'DATE NULL');
DROP PROCEDURE IF EXISTS add_senior_registration_column;

SET @has_control_index = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'senior_citizens'
    AND index_name = 'uq_senior_control_number'
);
SET @add_control_index = IF(@has_control_index = 0, 'ALTER TABLE senior_citizens ADD UNIQUE KEY uq_senior_control_number (id_control_number)', 'SELECT 1');
PREPARE control_index_statement FROM @add_control_index;
EXECUTE control_index_statement;
DEALLOCATE PREPARE control_index_statement;

UPDATE senior_citizens s
JOIN users u ON u.user_id = s.user_id
SET s.id_control_number = LPAD(s.senior_id, 6, '0'),
    s.id_issued_at = DATE(COALESCE(
      (SELECT MAX(e.created_at) FROM application_events e WHERE e.user_id = s.user_id AND e.request_id IS NULL AND e.status = 'Approved'),
      s.created_at
    ))
WHERE u.status = 'Active' AND s.id_control_number IS NULL;

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
  ('osca_head_name', ''),
  ('punong_barangay_name', '');
