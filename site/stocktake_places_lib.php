<?php
/**
 * stocktake_places_lib.php — أقسام وأرفف الجرد (بريف ١٤٩)
 * نظام الفجالة ستور المالي
 *
 * الاستعلامات كانت مكتوبة جوّه stocktake_places.php. اتقلت هنا زي ما هي
 * عشان الـAPI (stocktake_places_api.php) ينادي نفس الدوال، والشاشة تفضل
 * بنفس السلوك بالحرف. الأماكن دي بتظهر كقائمة في محضر الجرد بدل الكتابة الحرة.
 *
 * الجدول بيتعمل لوحده أول مرة (stp_ready) — الصفحة كانت بتعمله في أولها.
 */
declare(strict_types=1);

/**
 * إنشاء الجدول تلقائياً لو مش موجود (بدل ما تشغّل SQL يدوي).
 *
 * static عشان الاستعلام يروح مرة واحدة في الريكوست مهما اتنده من مكانين.
 */
function stp_ready(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS `stocktake_places` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(255) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (PDOException $ex) {
        // حساب «مشاهدة بس» (is_owner بتحوّله للقراية بس) ممنوع عليه DDL —
        // MySQL بيرمي 1792 «READ ONLY transaction». الجدول موجود أصلاً،
        // فبنكمّل عادي والقراية بتشتغل. أي خطأ تاني بيطلع زي ما هو.
        if ((int) ($ex->errorInfo[1] ?? 0) !== 1792) {
            throw $ex;
        }
    }
    $done = true;
}

/**
 * كل الأماكن بترتيب العرض.
 *
 * الأنواع بتتحوّل هنا مرة واحدة (رقم/بول) — الشاشة والـAPI بياخدوا نفس الشكل.
 *
 * @return array<int, array{id:int, name:string, sort_order:int, is_active:bool, created_at:string}>
 */
function stp_list(): array
{
    $rows = db()->query('SELECT * FROM stocktake_places ORDER BY sort_order, id')->fetchAll();
    return array_map(static function (array $r): array {
        return [
            'id'         => (int) $r['id'],
            'name'       => (string) $r['name'],
            'sort_order' => (int) $r['sort_order'],
            'is_active'  => (bool) (int) $r['is_active'],
            'created_at' => (string) $r['created_at'],
        ];
    }, $rows);
}

/**
 * إضافة أماكن — سطر لكل مكان (الفاضي بيتشال).
 *
 * بيرجّع عدد اللي اتضاف. الترتيب بيكمّل من آخر sort_order.
 * استعلام واحد لكل السطور: مفيش insert جوّه حلقة (RULES §٢).
 */
function stp_add(string $text): int
{
    $names = [];
    foreach (preg_split('/\r\n|\r|\n/', trim($text)) ?: [] as $ln) {
        $ln = trim($ln);
        if ($ln === '') {
            continue;
        }
        $names[] = $ln;
    }
    if (!$names) {
        return 0;
    }

    $pdo = db();
    $max = (int) $pdo->query('SELECT COALESCE(MAX(sort_order),0) FROM stocktake_places')->fetchColumn();

    $placeholders = [];
    $params = [];
    foreach ($names as $nm) {
        $placeholders[] = '(?, ?)';
        $params[] = $nm;
        $params[] = ++$max;
    }

    $sql = 'INSERT INTO stocktake_places (name, sort_order) VALUES ' . implode(', ', $placeholders);
    $pdo->prepare($sql)->execute($params);

    return count($names);
}

/**
 * حذف مكان واحد. id = 0 معناها «مفيش مكان» — نفس سلوك الصفحة (مفيش حذف).
 */
function stp_delete(int $id): void
{
    if ($id <= 0) {
        return;
    }
    db()->prepare('DELETE FROM stocktake_places WHERE id=?')->execute([$id]);
}

/**
 * مسح كل الأماكن دفعة واحدة.
 */
function stp_delete_all(): void
{
    db()->exec('DELETE FROM stocktake_places');
}

/**
 * إخفاء/تفعيل مكان (بتقلب is_active).
 */
function stp_toggle(int $id): void
{
    if ($id <= 0) {
        return;
    }
    db()->prepare('UPDATE stocktake_places SET is_active=1-is_active WHERE id=?')->execute([$id]);
}
