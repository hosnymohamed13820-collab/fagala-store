-- =====================================================================
--  upgrade_branch_balances.sql — أرصدة الفروع المباشرة
--  فجالة ستور · 2026-08-20
--
--  الرصيد بيوصل دلوقتي من الفرع نفسه، مش عن طريق الإدارة والترحيل.
--  COM_STO_ITEMS في الفرع هو المصدر الأصلي — الـPOS بيحدّثه لحظة البيع.
--
--  الجدول ده **جنب** hq_balances مش بدله. القديم يفضل شغّال لحد ما
--  الجديد يثبت، وساعتها تبدّل مصدر القراءة بسطر واحد وترجع في ثانية
--  لو حصلت مشكلة.
--
--  شغّل upgrade_agents.sql الأول (بيعمل جدول الوكلاء).
--  آمن للتشغيل أكتر من مرة.
-- =====================================================================

CREATE TABLE IF NOT EXISTS hq_branch_balances (
    agent_key   VARCHAR(32)     NOT NULL,   -- degla · bonjour · abc · zahraa
    item_id     INT             NOT NULL,
    sto_id      INT             NOT NULL,
    balance     DECIMAL(16,3)   NOT NULL DEFAULT 0,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,

    -- المفتاح على (item_id, sto_id): المخزن بيحدد الفرع أصلاً،
    -- و agent_key محفوظ عشان نعرف مين بعت الصف ونقدر نمسح لوكيل واحد.
    PRIMARY KEY (item_id, sto_id),
    KEY idx_agent (agent_key),
    KEY idx_item  (item_id),
    KEY idx_fresh (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  مصدر قراءة الأرصدة في الموقع.
--
--    hq      = الإدارة (السلوك القديم — بيستنى الترحيل)
--    branch  = الفروع مباشرة (لحظي)
--
--  ابدأ بـ hq. بدّلها لـ branch بعد ما تتطمّن على الوكلاء،
--  وارجّعها في ثانية لو حصلت مشكلة.
-- =====================================================================
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('balance_source', 'hq');

-- =====================================================================
--  VIEW: نفس شكل hq_balances بالظبط
--
--  ⚠️ ليه ده مهم: منطق موجود في stock_query_lib.php بيفترض إن
--     "الصنف اللي رصيده صفر مالوش صف في الجدول خالص" — لأن وكيل
--     الإدارة بيفلتر الأصفار قبل ما يبعت.
--
--  وكيل الفرع **لازم** يبعت الأصفار (عشان الصنف اللي خلص يتحدّث من
--  ٥ لـ ٠ بدل ما يفضل ٥ للأبد). فبنفلترها هنا بدل ما نعدّل ٦ استعلامات
--  ونكسر افتراض قايم.
-- =====================================================================
CREATE OR REPLACE VIEW hq_branch_balances_nz AS
SELECT item_id, sto_id, balance, agent_key, updated_at
FROM hq_branch_balances
WHERE balance <> 0;
