ALTER TABLE invoice_distribution
  ADD COLUMN hq_guard_off    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = حارس رصيد الإدارة متشال عن الفاتورة دي (الأونر من الإعدادات)',
  ADD COLUMN hq_guard_off_by INT UNSIGNED NULL,
  ADD COLUMN hq_guard_off_at DATETIME     NULL;
