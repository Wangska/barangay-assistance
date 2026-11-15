ALTER TABLE assistance_requests
  ADD COLUMN schedule_end DATETIME NULL AFTER schedule_date;
