-- =====================================================================
--  diag_rejected_shifts_posted.sql
--  فحص الورديات القديمة اللي حالتها rejected أو resubmitted وليها قيود في الخزينة
--  (SELECT فقط — لا يعدل ولا يحذف أي بيانات)
-- =====================================================================

SELECT 
    s.id AS shift_id,
    s.shift_name,
    b.name AS branch_name,
    s.business_date,
    s.status,
    t.name AS treasury_name,
    SUM(je.credit - je.debit) AS net_posted
FROM `u834909335_fagala`.`shifts` s
JOIN `u834909335_fagala`.`branches` b ON b.id = s.branch_id
JOIN `u834909335_fagala`.`journal_entries` je ON je.ref_type = 'shift' AND je.ref_id = s.id
JOIN `u834909335_fagala`.`treasuries` t ON t.id = je.treasury_id
WHERE s.status IN ('rejected', 'resubmitted')
GROUP BY s.id, s.shift_name, b.name, s.business_date, s.status, t.name
HAVING net_posted != 0
ORDER BY s.id DESC;
