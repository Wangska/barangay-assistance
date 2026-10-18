-- Adds the optional account photo used by the administrator profile. Safe to run more than once.
USE barangay_assistance_system;

SET @profile_photo_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'users'
    AND column_name = 'profile_photo'
);
SET @profile_photo_sql := IF(
  @profile_photo_exists = 0,
  'ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) NULL AFTER phone_number',
  'SELECT 1'
);
PREPARE profile_photo_statement FROM @profile_photo_sql;
EXECUTE profile_photo_statement;
DEALLOCATE PREPARE profile_photo_statement;
