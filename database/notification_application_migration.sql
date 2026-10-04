ALTER TABLE notifications
  ADD COLUMN application_user_id INT NULL DEFAULT NULL,
  ADD COLUMN assistance_request_id INT NULL DEFAULT NULL;
UPDATE notifications n JOIN users u ON LOCATE(CONCAT('(Account #', u.user_id, ').'), n.message)>0
SET n.application_user_id=u.user_id
WHERE n.title='New Senior Registration';
UPDATE notifications n JOIN assistance_requests ar ON LOCATE(CONCAT('submitted request #', ar.request_id, ' for '), n.message)>0
SET n.assistance_request_id=ar.request_id
WHERE n.title='New Assistance Application';