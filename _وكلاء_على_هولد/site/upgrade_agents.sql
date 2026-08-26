-- =====================================================================
--  upgrade_agents.sql — مراقبة وكلاء المزامنة
--  فجالة ستور · 2026-08-20
--
--  الفكرة: كل وكيل بيبعت "نبضة" كل دورة حتى لو مفيش داتا اتغيّرت.
--  من غير النبضة، سكوت الوكيل بيبقى غامض — مش هنعرف الجهاز واقف
--  ولا الوكيل واقف ولا مفيش بيع جديد.
--
--    نبضة موجودة + مفيش داتا  = شغّال عادي، مفيش حركة  ✅
--    مفيش نبضة                 = الجهاز أو الوكيل واقف  ⚠️
--
--  آمن للتشغيل أكتر من مرة (IF NOT EXISTS / INSERT IGNORE).
-- =====================================================================

CREATE TABLE IF NOT EXISTS agent_status (
    agent_key     VARCHAR(32)  NOT NULL,
    label         VARCHAR(64)  NOT NULL,
    sort_order    INT          NOT NULL DEFAULT 0,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,

    -- آخر نبضة: الوكيل رفع إيده وقال "أنا هنا"
    last_beat_at  DATETIME     NULL,
    -- آخر مرة بعت داتا فعلاً (غير النبضة)
    last_data_at  DATETIME     NULL,
    last_rows     INT          NOT NULL DEFAULT 0,
    -- آخر خطأ بعته الوكيل عن نفسه
    last_error    VARCHAR(255) NULL,
    last_error_at DATETIME     NULL,
    agent_version VARCHAR(24)  NULL,
    host_name     VARCHAR(64)  NULL,

    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (agent_key),
    KEY idx_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- الوكلاء الستة. الأسامي من السكرين شوتس (2026-08-20).
INSERT IGNORE INTO agent_status (agent_key, label, sort_order) VALUES
    ('hq',      'الإدارة',  1),
    ('degla',   'دجلة',     2),
    ('bonjour', 'بونجور',   3),
    ('abc',     'ABC',      4),
    ('zahraa',  'الفجالة',  5),
    ('ashraqa', 'إشراقة',   6);

-- =====================================================================
--  الإعدادات
--
--  ⚠️ ليه 20 و 60 مش 120 دقيقة:
--     الوكيل بيشتغل كل دقيقتين. ساعتين سكوت = 60 دورة فاشلة، والأرصدة
--     تفضل غلط طول الوقت ده وحد بياخد قرارات عليها.
--       20 دقيقة = 10 دورات فاتت — مش صدفة، بس ممكن نت متقطع → أصفر
--       60 دقيقة = مشكلة مؤكدة، لازم حد يتحرك                → أحمر
--
--  ⚠️ نافذة المراقبة بتعدّي نص الليل (10ص → 12:30ص) عشان الفروع
--     بتقفل 12. الكود بيتعامل مع العبور ده.
-- =====================================================================
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('agent_warn_min',   '20'),
    ('agent_alert_min',  '60'),
    ('agent_watch_from', '10:00'),
    ('agent_watch_to',   '00:30'),
    ('agent_watcher_id', '0');
