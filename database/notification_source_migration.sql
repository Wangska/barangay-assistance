ALTER TABLE notifications ADD COLUMN announcement_id INT NULL DEFAULT NULL,
 ADD INDEX idx_notification_announcement (user_id,announcement_id);
UPDATE notifications n SET n.announcement_id=(
 SELECT MAX(a.announcement_id) FROM announcements a
 WHERE a.created_at<=n.created_at AND n.title=CASE WHEN a.is_emergency=1 THEN 'Emergency Announcement' ELSE 'New Announcement' END
 AND BINARY n.message=BINARY CONCAT(TRIM(a.title),CHAR(10),TRIM(a.body))
) WHERE n.title IN ('New Announcement','Emergency Announcement');