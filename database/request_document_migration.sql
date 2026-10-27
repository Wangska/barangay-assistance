-- Stores the optional supporting file attached to an assistance request. Safe to run more than once.
USE barangay_assistance_system;

SET @request_document_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'assistance_requests'
    AND column_name = 'supporting_document'
);
SET @request_document_sql := IF(
  @request_document_exists = 0,
  'ALTER TABLE assistance_requests ADD COLUMN supporting_document VARCHAR(255) NULL AFTER description',
  'SELECT 1'
);
PREPARE request_document_statement FROM @request_document_sql;
EXECUTE request_document_statement;
DEALLOCATE PREPARE request_document_statement;
