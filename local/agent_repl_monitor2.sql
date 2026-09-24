-- بريف ٥٢: الإدارة صدّرت لحد فين · كل فرع استلم لحد فين
-- شغّله مرة واحدة على القاعدتين (الفجالة + إشراقة) من phpMyAdmin.
ALTER TABLE agent_status
  ADD COLUMN repl_last_recv       BIGINT   NULL,
  ADD COLUMN repl_recv_changed_at DATETIME NULL,
  ADD COLUMN repl_last_sent       BIGINT   NULL,
  ADD COLUMN repl_send_pending    INT      NULL,
  ADD COLUMN repl_send_age_min    INT      NULL;
