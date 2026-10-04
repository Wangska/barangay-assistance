-- Adds the registration barangay column. Safe to run more than once.
USE barangay_assistance_system;

SET @barangay_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'senior_citizens'
    AND column_name = 'barangay'
);
SET @barangay_sql := IF(
  @barangay_exists = 0,
  'ALTER TABLE senior_citizens ADD COLUMN barangay VARCHAR(120) NOT NULL DEFAULT '''' AFTER city_municipality',
  'SELECT 1'
);
PREPARE barangay_statement FROM @barangay_sql;
EXECUTE barangay_statement;
DEALLOCATE PREPARE barangay_statement;
