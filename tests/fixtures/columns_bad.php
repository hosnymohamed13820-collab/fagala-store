<?php
// ملف اختبار لفحص أسماء الأعمدة — بيحتوي على الحالات الست المطلوبة

// ١) لازم يتمسك (description مش موجود في custody_deficits)
$q1 = "SELECT id, amount, description FROM custody_deficits WHERE id = ?";

// ٢) لازم يعدّي (notes موجود في custody_deficits)
$q2 = "SELECT id, amount, notes FROM custody_deficits WHERE id = ?";

// ٣) لازم يتمسك fake_col (مش موجود في br_invoices)
$q3 = "INSERT INTO br_invoices (agent_key, sth_id, suffix, fake_col) VALUES (?,?,?,?)";

// ٤) لازم يتمسك l.bogus بس (agent_key موجود في br_invoices)
$q4 = "SELECT h.agent_key, l.bogus FROM br_invoice_lines l JOIN br_invoices h ON h.sth_id = l.sth_id";

// ٥) لازم يعدّي (n و q ألياس معرّفين في SELECT)
$q5 = "SELECT COUNT(*) AS n, SUM(quantity) AS q FROM br_invoice_lines ORDER BY n";

// ٦) لازم يتجاهل (اسم الجدول فيه متغير PHP)
$q6 = "SELECT * FROM {$tbl} WHERE x = 1";
