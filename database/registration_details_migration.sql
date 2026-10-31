-- Adds the OSCA and barangay membership details collected on registration. Safe to run more than once.
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

CALL add_senior_registration_column('application_status', 'VARCHAR(20) NULL');
CALL add_senior_registration_column('nationality', 'VARCHAR(80) NULL');
CALL add_senior_registration_column('occupation', 'VARCHAR(120) NULL');
CALL add_senior_registration_column('monthly_income', 'DECIMAL(12,2) NULL');
CALL add_senior_registration_column('is_pensioner', 'VARCHAR(3) NULL');
CALL add_senior_registration_column('pension_source', 'VARCHAR(180) NULL');
CALL add_senior_registration_column('lgu_assistance', 'VARCHAR(3) NULL');
CALL add_senior_registration_column('lgu_assistance_type', 'VARCHAR(180) NULL');
CALL add_senior_registration_column('other_financial_support', 'VARCHAR(3) NULL');
CALL add_senior_registration_column('other_support_details', 'VARCHAR(255) NULL');
CALL add_senior_registration_column('dependent_children', 'SMALLINT NULL');
CALL add_senior_registration_column('living_type', 'VARCHAR(80) NULL');
CALL add_senior_registration_column('beneficiary_name', 'VARCHAR(150) NULL');
CALL add_senior_registration_column('beneficiary_address', 'VARCHAR(255) NULL');
CALL add_senior_registration_column('emergency_contact_address', 'VARCHAR(255) NULL');
CALL add_senior_registration_column('emergency_relationship', 'VARCHAR(80) NULL');

DROP PROCEDURE IF EXISTS add_senior_registration_column;
