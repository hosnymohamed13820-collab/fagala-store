-- بريف ٢٠٢: نربط قيد صرف السلفة القديمة برقم السلفة لو المطابقة مؤكدة واحد لواحد.
-- آمن للتكرار: بنختار القيود اللي ref_id فيها فاضي بس، ومبنغيّرش أي مبلغ أو رصيد.
DROP TEMPORARY TABLE IF EXISTS tmp_advance_ref_pairs;
DROP TEMPORARY TABLE IF EXISTS tmp_advance_ref_journal_counts;
DROP TEMPORARY TABLE IF EXISTS tmp_advance_ref_advance_counts;
DROP TEMPORARY TABLE IF EXISTS tmp_advance_ref_used;

CREATE TEMPORARY TABLE tmp_advance_ref_used AS
SELECT DISTINCT ref_id AS advance_id FROM journal_entries
WHERE ref_type = 'advance' AND ref_id IS NOT NULL;
CREATE INDEX idx_tmp_advance_ref_used ON tmp_advance_ref_used (advance_id);

CREATE TEMPORARY TABLE tmp_advance_ref_pairs AS
SELECT je.id AS journal_id, ea.id AS advance_id
FROM journal_entries je
JOIN employees e ON je.description = CONCAT('سلفة للموظف: ', e.full_name)
JOIN employee_advances ea ON ea.employee_id = e.id
    AND ea.amount = je.debit AND ea.advance_date = je.entry_date
LEFT JOIN tmp_advance_ref_used used_advance ON used_advance.advance_id = ea.id
WHERE je.ref_type = 'advance' AND je.ref_id IS NULL
    AND used_advance.advance_id IS NULL;

CREATE INDEX idx_tmp_advance_ref_journal ON tmp_advance_ref_pairs (journal_id);
CREATE INDEX idx_tmp_advance_ref_advance ON tmp_advance_ref_pairs (advance_id);

CREATE TEMPORARY TABLE tmp_advance_ref_journal_counts AS
SELECT journal_id, COUNT(*) AS candidate_count
FROM tmp_advance_ref_pairs GROUP BY journal_id;

CREATE TEMPORARY TABLE tmp_advance_ref_advance_counts AS
SELECT advance_id, COUNT(*) AS candidate_count
FROM tmp_advance_ref_pairs GROUP BY advance_id;

UPDATE journal_entries je
JOIN tmp_advance_ref_pairs p ON p.journal_id = je.id
JOIN tmp_advance_ref_journal_counts jc ON jc.journal_id = p.journal_id
JOIN tmp_advance_ref_advance_counts ac ON ac.advance_id = p.advance_id
SET je.ref_id = p.advance_id
WHERE je.ref_type = 'advance' AND je.ref_id IS NULL
    AND jc.candidate_count = 1 AND ac.candidate_count = 1;

DROP TEMPORARY TABLE tmp_advance_ref_advance_counts;
DROP TEMPORARY TABLE tmp_advance_ref_journal_counts;
DROP TEMPORARY TABLE tmp_advance_ref_pairs;
DROP TEMPORARY TABLE tmp_advance_ref_used;

INSERT IGNORE INTO schema_migrations (name) VALUES ('20261025_001_advance_journal_ref');
