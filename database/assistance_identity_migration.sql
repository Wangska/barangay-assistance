-- Stores the identification file submitted with a death assistance request. Safe to run more than once.
USE barangay_assistance_system;

DROP PROCEDURE IF EXISTS add_assistance_column;
DELIMITER $$
CREATE PROCEDURE add_assistance_column(IN column_name VARCHAR(64), IN column_definition TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'assistance_requests'
      AND column_name = column_name
  ) THEN
    SET @assistance_sql = CONCAT('ALTER TABLE assistance_requests ADD COLUMN ', column_name, ' ', column_definition);
    PREPARE assistance_statement FROM @assistance_sql;
    EXECUTE assistance_statement;
    DEALLOCATE PREPARE assistance_statement;
  END IF;
END$$
DELIMITER ;

CALL add_assistance_column('identity_document', 'VARCHAR(255) NULL');
CALL add_assistance_column('identity_label', 'VARCHAR(30) NULL');
DROP PROCEDURE IF EXISTS add_assistance_column;
