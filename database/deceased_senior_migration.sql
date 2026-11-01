-- Records when an administrator marks a senior deceased after a death assistance request. Safe to run more than once.
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

CALL add_senior_registration_column('is_deceased', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL add_senior_registration_column('deceased_at', 'DATETIME NULL');

DROP PROCEDURE IF EXISTS add_senior_registration_column;
