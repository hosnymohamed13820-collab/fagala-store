-- بنربط التحويل بالتوزيع والفرع عشان نفس الكمية ماتتحوّلش مرتين؛ إعادة التشغيل آمنة.
CREATE TABLE IF NOT EXISTS stock_doc_dists (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  doc_id BIGINT UNSIGNED NOT NULL,
  distribution_id BIGINT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dist_branch (distribution_id, branch_id),
  KEY idx_doc (doc_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (name) VALUES ('20261102_044_stock_doc_dists');
