-- Adds sitio, Facebook account, and membership remarks from the barangay membership form. Safe to run more than once.
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

CALL add_senior_registration_column('sitio', 'VARCHAR(120) NULL');
CALL add_senior_registration_column('facebook_account', 'VARCHAR(150) NULL');
CALL add_senior_registration_column('membership_remarks', 'VARCHAR(255) NULL');

DROP PROCEDURE IF EXISTS add_senior_registration_column;
