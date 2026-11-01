-- Adds pension and financial details, the death-assistance setting, and assistance allocations. Safe to run more than once.
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

CALL add_assistance_column('pension_status', 'VARCHAR(20) NULL');
CALL add_assistance_column('pension_provider', 'VARCHAR(20) NULL');
CALL add_assistance_column('monthly_pension_amount', 'DECIMAL(12,2) NULL');
CALL add_assistance_column('financial_status', 'VARCHAR(30) NULL');
DROP PROCEDURE IF EXISTS add_assistance_column;

CREATE TABLE IF NOT EXISTS assistance_allocations (
  allocation_id INT AUTO_INCREMENT PRIMARY KEY,
  label VARCHAR(180) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  notes TEXT NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_allocations_user FOREIGN KEY (created_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
  ('death_assistance_amount', '2500'),
  ('senior_allocation_note', 'The 1% to 2% share of barangay income for senior assistance is unconfirmed and is not used in budget calculations.');
