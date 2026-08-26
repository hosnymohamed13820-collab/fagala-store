-- =====================================================================
--  upgrade_market_prices.sql — أسعار السوق للكتب
--  نظام الفجالة ستور المالي — 2026-08-11
-- =====================================================================
--
--  المصدر: متجر منافس شغال على WooCommerce، وبيوفّر Store API عام
--  من غير مفاتيح:  /wp-json/wc/store/v1/products?per_page=100&page=N
--  بيرجّع JSON فيه الاسم والسعر — أنضف بكتير من قص الـHTML.
--
--  ⚠️ مفيش باركود ولا SKU عندهم — الاسم بس. عشان كده فيه جدولين:
--
--    market_products  = لقطة من أسعارهم (بتتحدّث بضغطة زرار)
--    market_links     = الربط بين صنفنا ومنتجهم — **بيتعمل مرة واحدة**
--
--  فكرة الربط: النظام بيقترح المطابقة بدرجة تشابه، وانت بتأكد.
--  بعد التأكيد، المقارنة بتبقى مضبوطة ١٠٠٪ للأبد ومفيش تخمين تاني.
--
--  الربط بالـ ITEM_ID مش بالكود — نفس قاعدة book_lib.php:
--  الصنف الواحد ليه أكتر من باركود وكلهم بنفس الـ ITEM_ID.
--
--  التشغيل: phpMyAdmin → SQL → الصق ونفّذ. آمن للتكرار.
-- =====================================================================

-- لقطة أسعار المتجر الخارجي
CREATE TABLE IF NOT EXISTS market_products (
    market_id    INT           NOT NULL,          -- id المنتج عندهم
    name         VARCHAR(255)  NOT NULL,
    name_norm    VARCHAR(255)  NOT NULL DEFAULT '', -- الاسم بعد التوحيد (للمطابقة)
    price        DECIMAL(14,2) NOT NULL DEFAULT 0,
    permalink    VARCHAR(500)  NULL,
    in_stock     TINYINT(1)    NOT NULL DEFAULT 1,
    fetched_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (market_id),
    KEY idx_mp_norm  (name_norm),
    KEY idx_mp_price (price)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- الربط المؤكّد: صنف عندنا ↔ منتج عندهم
CREATE TABLE IF NOT EXISTS market_links (
    item_id      INT           NOT NULL,          -- ITEM_ID عندنا
    market_id    INT           NOT NULL,          -- id عندهم
    score        DECIMAL(5,3)  NULL,              -- درجة التشابه وقت الاقتراح (للتشخيص)
    confirmed_by INT           NULL,
    confirmed_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (item_id),
    KEY idx_ml_market (market_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- سجل عمليات السحب — عشان نعرف آخر مرة سحبنا إمتى ونحترم المهلة
CREATE TABLE IF NOT EXISTS market_fetch_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    products    INT           NOT NULL DEFAULT 0,
    status      VARCHAR(20)   NOT NULL DEFAULT 'ok',
    note        VARCHAR(255)  NULL,
    fetched_by  INT           NULL,
    fetched_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mf_at (fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- الأصناف المستبعدة صراحة (قلت إن الكتاب ده مالوش مقابل عندهم)
CREATE TABLE IF NOT EXISTS market_ignored (
    item_id     INT       NOT NULL,
    ignored_by  INT       NULL,
    ignored_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
