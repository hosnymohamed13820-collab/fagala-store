<?php
/**
 * price_lib.php — استعلام أسعار الأصناف بالباركود
 * نظام الفجالة ستور المالي
 *
 * بيبحث في SQL Server: الباركود (ITEM_CODES.IC_CODE) → اسم الصنف + سعر البيع.
 * السعر = CIP_PRICE المرتبط بالباركود (عبر CIP_ID) — سعر الكاشير.
 *
 * ============================================================
 *  إعداد جداول/أعمدة SQL Server للأصناف (مبنية على بنية نظامك)
 * ============================================================
 */
require_once __DIR__ . '/config.php';
const PRICE_PREFIX      = SQLSRV_PREFIX;   // من config.php (اسم قاعدة الفرع)
const PRICE_CODES_TABLE = PRICE_PREFIX . 'ITEM_CODES';      // جدول الباركود والأسماء
const PRICE_PRICE_TABLE = PRICE_PREFIX . 'COM_ITEM_PRICE';  // جدول الأسعار

// أعمدة ITEM_CODES
const PC_CODE     = 'IC_CODE';     // الباركود
const PC_ITEM_ID  = 'ITEM_ID';     // كود الصنف الداخلي
const PC_NAME     = 'IC_NAME1';    // اسم الصنف
const PC_CIP_ID   = 'CIP_ID';      // ربط بالسعر

// أعمدة COM_ITEM_PRICE
const PP_CIP_ID   = 'CIP_ID';      // معرّف السعر
const PP_PRICE    = 'CIP_PRICE';   // السعر (سعر الكاشير)

require_once __DIR__ . '/config.php';

/**
 * البحث عن صنف بالباركود.
 * بترجّع ['code','item_id','name','price'] أو null لو مش موجود.
 */
function lookup_price_by_barcode(string $barcode): ?array
{
    $barcode = trim($barcode);
    if ($barcode === '') {
        return null;
    }

    // ١) النسخة المحلية الأول (hq_items) — بتشتغل من غير نت وأسرع
    $localReady = false;
    try {
        $st = db()->prepare(
            'SELECT barcode AS code, item_id, name, sale_price AS price
             FROM hq_items WHERE barcode = ? LIMIT 1'
        );
        $st->execute([$barcode]);
        $local = $st->fetch();
        if ($local) {
            return [
                'code'    => (string) $local['code'],
                'item_id' => (string) $local['item_id'],
                'name'    => (string) ($local['name'] ?? ''),
                'price'   => $local['price'] !== null ? (float) $local['price'] : null,
            ];
        }
        // مش لقيناه — بس هل النسخة المحلية أصلاً فيها داتا؟
        $localReady = hq_items_ready();
    } catch (Throwable $ex) {
        // الجدول لسه ما اتعملش — نكمّل على الجسر
    }

    // لو النسخة المحلية جاهزة (فيها داتا) والصنف مش فيها → يبقى مش موجود
    // خالص. منروحش للجسر عشان ما نعلّقش 8 ثواني لو الجسر واقف.
    if ($localReady) {
        return null;
    }

    // ٢) النسخة المحلية فاضية → نسأل الجسر (لو النت شغال)
    $sql = "SELECT TOP 1
                c." . PC_CODE . "    AS code,
                c." . PC_ITEM_ID . " AS item_id,
                c." . PC_NAME . "    AS name,
                p." . PP_PRICE . "   AS price
            FROM " . PRICE_CODES_TABLE . " c
            LEFT JOIN " . PRICE_PRICE_TABLE . " p ON p." . PP_CIP_ID . " = c." . PC_CIP_ID . "
            WHERE c." . PC_CODE . " = ?";
    $rows = sqlsrv_query($sql, [$barcode]);
    $row = $rows[0] ?? null;

    if (!$row) {
        return null;
    }
    return [
        'code'    => (string) $row['code'],
        'item_id' => (string) $row['item_id'],
        'name'    => sqlsrv_fix_encoding((string) $row['name']),
        'price'   => $row['price'] !== null ? (float) $row['price'] : null,
    ];
}

/**
 * هل النسخة المحلية للأصناف فيها داتا؟
 * لو أيوه، مش محتاجين نرجع للجسر لما صنف مش موجود.
 * النتيجة بتتخزّن مرة واحدة في الريكوست.
 */
function hq_items_ready(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $ready = (bool) db()->query('SELECT 1 FROM hq_items LIMIT 1')->fetchColumn();
    } catch (Throwable $ex) {
        $ready = false;
    }
    return $ready;
}

/**
 * بحث جزئي بالاسم أو الباركود (لو الكاشير مش متأكد من الباركود كامل).
 * بترجّع قائمة (أقصى 20 نتيجة).
 */
function search_items(string $term): array
{
    $term = trim($term);
    if ($term === '') {
        return [];
    }

    // ١) النسخة المحلية الأول (hq_items) — بحث سريع من غير نت
    try {
        $like = '%' . $term . '%';
        $st = db()->prepare(
            'SELECT barcode AS code, item_id, name, sale_price AS price
             FROM hq_items
             WHERE barcode LIKE ? OR name LIKE ?
             ORDER BY name LIMIT 20'
        );
        $st->execute([$like, $like]);
        $localRows = $st->fetchAll();
        if ($localRows) {
            $out = [];
            foreach ($localRows as $row) {
                $out[] = [
                    'code'  => (string) $row['code'],
                    'name'  => (string) ($row['name'] ?? ''),
                    'price' => $row['price'] !== null ? (float) $row['price'] : null,
                ];
            }
            return $out;
        }
        // مفيش نتيجة — لو النسخة المحلية جاهزة، يبقى مفيش نتيجة خالص،
        // منروحش للجسر عشان ما نعلّقش لو واقف.
        if (hq_items_ready()) {
            return [];
        }
    } catch (Throwable $ex) {
        // الجدول لسه ما اتعملش — نكمّل على الجسر
    }

    // ٢) مفيش نتيجة محلي → نسأل الجسر (لو النت شغال)
    $like = '%' . $term . '%';
    $sql = "SELECT TOP 20
                c." . PC_CODE . "    AS code,
                c." . PC_ITEM_ID . " AS item_id,
                c." . PC_NAME . "    AS name,
                p." . PP_PRICE . "   AS price
            FROM " . PRICE_CODES_TABLE . " c
            LEFT JOIN " . PRICE_PRICE_TABLE . " p ON p." . PP_CIP_ID . " = c." . PC_CIP_ID . "
            WHERE c." . PC_CODE . " LIKE ? OR c." . PC_NAME . " LIKE ?
            ORDER BY c." . PC_NAME;
    $out = [];
    foreach (sqlsrv_query($sql, [$like, $like]) as $row) {
        $out[] = [
            'code'  => (string) $row['code'],
            'name'  => sqlsrv_fix_encoding((string) $row['name']),
            'price' => $row['price'] !== null ? (float) $row['price'] : null,
        ];
    }
    return $out;
}
