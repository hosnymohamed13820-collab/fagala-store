-- =====================================================================
--  upgrade_book_swap_suppliers.sql — 2026-08-09
--  تبديل كميات بشري <-> الاسلامية للناشرين الاربعة
--
--  السبب: ملف الاكسيل كان اعمدته (بشري / علام / مورد غير معروف)
--  واتربطوا بالترتيب 1 2 3. اتضح ان الناشرين دول مقلوبين:
--      سلاح التلميذ · المعاصر · الامتحان · الاضواء
--  الباقي زي ما هو - مايتلمسش.
--
--  المورد 2 (العلم) مابيتغيرش خالص.
--
--  ⚠️ خد باك اب للجدول قبل التشغيل (السكربت بياخد واحد لوحده كمان).
--  ⚠️ شغّل القسم (1) الاول واقرا النتيجة قبل ما تشغّل (3).
-- =====================================================================

-- ---------------------------------------------------------------------
--  (1) معاينة - شغّل ده لوحده الاول
--      لازم يطلع 181 كتاب، وكل كتاب عنده صف لبشري وصف للاسلامية.
-- ---------------------------------------------------------------------
SELECT
    c.publisher                              AS `الناشر`,
    COUNT(*)                                 AS `عدد الكتب`,
    SUM(l1.book_id IS NOT NULL)              AS `عنده صف بشري`,
    SUM(l3.book_id IS NOT NULL)              AS `عنده صف الاسلامية`
FROM book_catalog c
LEFT JOIN book_order_lines l1 ON l1.book_id = c.id AND l1.supplier_id = 1
LEFT JOIN book_order_lines l3 ON l3.book_id = c.id AND l3.supplier_id = 3
WHERE c.publisher IN ('سلاح التلميذ', 'المعاصر', 'الامتحان', 'الأضواء', 'الاضواء')
GROUP BY c.publisher
ORDER BY COUNT(*) DESC;

-- عينة قبل التبديل (اول 10 كتب) - صوّرها عشان تقارن بعدين
SELECT c.code, c.name, c.publisher,
       l1.req_qty AS `بشري قبل`, l3.req_qty AS `الاسلامية قبل`
FROM book_catalog c
LEFT JOIN book_order_lines l1 ON l1.book_id = c.id AND l1.supplier_id = 1
LEFT JOIN book_order_lines l3 ON l3.book_id = c.id AND l3.supplier_id = 3
WHERE c.publisher IN ('سلاح التلميذ', 'المعاصر', 'الامتحان', 'الأضواء', 'الاضواء')
ORDER BY c.publisher, c.name
LIMIT 10;

-- ---------------------------------------------------------------------
--  (2) باك اب - نسخة كاملة من الجدول قبل اي تعديل
--      الرجوع: DELETE FROM book_order_lines;
--               INSERT INTO book_order_lines SELECT * FROM book_order_lines_bak_20260809;
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS book_order_lines_bak_20260809;
CREATE TABLE book_order_lines_bak_20260809 AS SELECT * FROM book_order_lines;

-- ---------------------------------------------------------------------
--  (3) التبديل - تلات خطوات في ترانزاكشن واحدة
--
--  ليه تلات خطوات مش UPDATE واحد بـ CASE؟
--  المفتاح الاساسي هو (book_id, supplier_id). لو حوّلنا صف بشري
--  للاسلامية والصف التاني لسه مكانه، المفتاح يتصادم والابديت يفشل.
--  فبنفضّي رقم 1 الاول في رقم مؤقت (-1)، نحرّك 3 مكانه، وبعدين
--  نرجّع المؤقت لـ 3. الرقم -1 مستحيل يكون موجود (كل الـ id موجب).
-- ---------------------------------------------------------------------
START TRANSACTION;

-- الكتب المستهدفة في جدول مؤقت (اسرع من تكرار الـ JOIN تلات مرات)
DROP TEMPORARY TABLE IF EXISTS tmp_swap_books;
CREATE TEMPORARY TABLE tmp_swap_books (book_id INT PRIMARY KEY) ENGINE=MEMORY;
INSERT INTO tmp_swap_books (book_id)
SELECT id FROM book_catalog
WHERE publisher IN ('سلاح التلميذ', 'المعاصر', 'الامتحان', 'الأضواء', 'الاضواء');

-- 3-أ) بشري -> مؤقت
UPDATE book_order_lines l
JOIN tmp_swap_books t ON t.book_id = l.book_id
SET l.supplier_id = -1
WHERE l.supplier_id = 1;

-- 3-ب) الاسلامية -> بشري
UPDATE book_order_lines l
JOIN tmp_swap_books t ON t.book_id = l.book_id
SET l.supplier_id = 1
WHERE l.supplier_id = 3;

-- 3-ج) مؤقت -> الاسلامية
UPDATE book_order_lines l
JOIN tmp_swap_books t ON t.book_id = l.book_id
SET l.supplier_id = 3
WHERE l.supplier_id = -1;

DROP TEMPORARY TABLE IF EXISTS tmp_swap_books;

COMMIT;

-- ---------------------------------------------------------------------
--  (4) تأكيد بعد التشغيل
--      لازم يطلع صفر صفوف بمورد سالب، ونفس الـ 10 كتب بالارقام مقلوبة.
-- ---------------------------------------------------------------------
SELECT COUNT(*) AS `صفوف عالقة (لازم صفر)` FROM book_order_lines WHERE supplier_id < 1;

SELECT c.code, c.name, c.publisher,
       l1.req_qty AS `بشري بعد`, l3.req_qty AS `الاسلامية بعد`
FROM book_catalog c
LEFT JOIN book_order_lines l1 ON l1.book_id = c.id AND l1.supplier_id = 1
LEFT JOIN book_order_lines l3 ON l3.book_id = c.id AND l3.supplier_id = 3
WHERE c.publisher IN ('سلاح التلميذ', 'المعاصر', 'الامتحان', 'الأضواء', 'الاضواء')
ORDER BY c.publisher, c.name
LIMIT 10;

-- الاجمالي لكل مورد قبل/بعد - المفروض الرقمين اتبادلوا
SELECT s.name AS `المورد`, SUM(l.req_qty) AS `اجمالي الكميات`
FROM book_order_lines l
JOIN book_suppliers s ON s.id = l.supplier_id
GROUP BY s.id, s.name ORDER BY s.sort_order;

-- =====================================================================
--  خلاص. لما تتطمّن على الارقام امسح الباك اب:
--      DROP TABLE book_order_lines_bak_20260809;
-- =====================================================================
