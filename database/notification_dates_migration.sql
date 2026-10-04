-- Existing announcement notifications stored the announcement date as created_at.
-- Their actual delivery time cannot be recovered, so leave notified_at NULL.
ALTER TABLE notifications
  ADD COLUMN announced_at DATETIME NULL DEFAULT NULL AFTER created_at,
  ADD COLUMN notified_at DATETIME NULL DEFAULT NULL AFTER announced_at;

UPDATE notifications n
SET n.announced_at = (
  SELECT MAX(a.created_at) FROM announcements a
  WHERE a.created_at <= n.created_at
    AND n.title = CASE WHEN a.is_emergency=1 THEN 'Emergency Announcement' ELSE 'New Announcement' END
    AND BINARY n.message = BINARY CONCAT(TRIM(a.title), CHAR(10), TRIM(a.body))
)
WHERE n.title IN ('New Announcement', 'Emergency Announcement');

UPDATE notifications SET notified_at=created_at
WHERE title NOT IN ('New Announcement', 'Emergency Announcement');

ALTER TABLE notifications MODIFY notified_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP;