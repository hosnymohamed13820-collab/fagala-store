ALTER TABLE rabt_incidents ADD COLUMN reminded_at DATETIME NULL AFTER last_seen;
INSERT IGNORE INTO schema_migrations (name) VALUES ('20261012_001_rabt_reminded');

