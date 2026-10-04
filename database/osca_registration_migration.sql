-- Safe, repeatable migration for databases created before the expanded
-- senior-registration form. Back up the database before any schema change.
USE barangay_assistance_system;

DELIMITER //
DROP PROCEDURE IF EXISTS add_column_if_missing//
CREATE PROCEDURE add_column_if_missing(IN p_column_name VARCHAR(64), IN p_column_definition TEXT)
BEGIN
  DECLARE column_exists INT DEFAULT 0;
  SELECT COUNT(*) INTO column_exists
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'senior_citizens'
    AND column_name = p_column_name;
  IF column_exists = 0 THEN
    SET @migration_sql = CONCAT('ALTER TABLE senior_citizens ADD COLUMN ', p_column_definition);
    PREPARE migration_statement FROM @migration_sql;
    EXECUTE migration_statement;
    DEALLOCATE PREPARE migration_statement;
  END IF;
END//

DROP PROCEDURE IF EXISTS add_index_if_missing//
CREATE PROCEDURE add_index_if_missing(IN table_name_param VARCHAR(64), IN index_name_param VARCHAR(64), IN index_definition TEXT)
BEGIN
  DECLARE index_exists INT DEFAULT 0;
  SELECT COUNT(*) INTO index_exists
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = table_name_param
    AND index_name = index_name_param;
  IF index_exists = 0 THEN
    SET @migration_sql = CONCAT('ALTER TABLE `', table_name_param, '` ADD INDEX `', index_name_param, '` ', index_definition);
    PREPARE migration_statement FROM @migration_sql;
    EXECUTE migration_statement;
    DEALLOCATE PREPARE migration_statement;
  END IF;
END//
DELIMITER ;

CALL add_column_if_missing('first_name', '`first_name` VARCHAR(80) NULL AFTER `user_id`');
CALL add_column_if_missing('middle_name', '`middle_name` VARCHAR(80) NULL AFTER `first_name`');
CALL add_column_if_missing('last_name', '`last_name` VARCHAR(80) NULL AFTER `middle_name`');
CALL add_column_if_missing('region', '`region` VARCHAR(120) NULL AFTER `gender`');
CALL add_column_if_missing('province', '`province` VARCHAR(120) NULL AFTER `region`');
CALL add_column_if_missing('city_municipality', '`city_municipality` VARCHAR(120) NULL AFTER `province`');
CALL add_column_if_missing('place_of_birth', '`place_of_birth` VARCHAR(180) NULL AFTER `address`');
CALL add_column_if_missing('civil_status', '`civil_status` VARCHAR(30) NULL AFTER `place_of_birth`');
CALL add_column_if_missing('blood_type', '`blood_type` VARCHAR(5) NULL AFTER `civil_status`');
CALL add_column_if_missing('religion', '`religion` VARCHAR(120) NULL AFTER `blood_type`');
CALL add_column_if_missing('education', '`education` VARCHAR(80) NULL AFTER `religion`');
CALL add_column_if_missing('telephone', '`telephone` VARCHAR(30) NULL AFTER `education`');
CALL add_column_if_missing('gsis_no', '`gsis_no` VARCHAR(60) NULL AFTER `telephone`');
CALL add_column_if_missing('sss_no', '`sss_no` VARCHAR(60) NULL AFTER `gsis_no`');
CALL add_column_if_missing('tin_no', '`tin_no` VARCHAR(60) NULL AFTER `sss_no`');
CALL add_column_if_missing('philhealth_no', '`philhealth_no` VARCHAR(60) NULL AFTER `tin_no`');
CALL add_column_if_missing('employment_status', '`employment_status` VARCHAR(40) NULL AFTER `philhealth_no`');
CALL add_column_if_missing('classification', '`classification` VARCHAR(40) NULL AFTER `employment_status`');
CALL add_column_if_missing('monthly_pension', '`monthly_pension` DECIMAL(12,2) NULL AFTER `classification`');
CALL add_column_if_missing('terms_accepted_at', '`terms_accepted_at` DATETIME NULL AFTER `medical_notes`');

UPDATE senior_citizens s
JOIN users u ON u.user_id = s.user_id
SET s.first_name = COALESCE(NULLIF(s.first_name, ''), SUBSTRING_INDEX(u.full_name, ' ', 1)),
    s.last_name = COALESCE(NULLIF(s.last_name, ''), SUBSTRING_INDEX(u.full_name, ' ', -1)),
    s.region = COALESCE(NULLIF(s.region, ''), 'Not provided'),
    s.province = COALESCE(NULLIF(s.province, ''), 'Not provided'),
    s.city_municipality = COALESCE(NULLIF(s.city_municipality, ''), 'Not provided'),
    s.place_of_birth = COALESCE(NULLIF(s.place_of_birth, ''), 'Not provided'),
    s.civil_status = COALESCE(NULLIF(s.civil_status, ''), 'Not provided'),
    s.blood_type = COALESCE(NULLIF(s.blood_type, ''), 'N/A'),
    s.religion = COALESCE(NULLIF(s.religion, ''), 'Not provided'),
    s.education = COALESCE(NULLIF(s.education, ''), 'Not provided'),
    s.employment_status = COALESCE(NULLIF(s.employment_status, ''), 'Not provided'),
    s.classification = COALESCE(NULLIF(s.classification, ''), 'Not provided');

CALL add_index_if_missing('assistance_requests', 'idx_requests_senior_status_created', '(senior_id, status, created_at)');
CALL add_index_if_missing('notifications', 'idx_notifications_user_read_created', '(user_id, is_read, created_at)');

DROP PROCEDURE IF EXISTS add_column_if_missing;
DROP PROCEDURE IF EXISTS add_index_if_missing;
