-- علامات مستقلة لكل رسالة. الإنشاء قابل لإعادة التشغيل ولا يمس بيانات الرسائل.
CREATE TABLE IF NOT EXISTS sms_marks (
    sms_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    note TEXT NULL,
    color VARCHAR(20) NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_sms_marks_sms FOREIGN KEY (sms_id) REFERENCES sms_inbox(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
