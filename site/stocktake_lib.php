<?php
/**
 * stocktake_lib.php — منطق موديول الجرد
 * نظام الفجالة ستور المالي
 *
 * - يقرا بيانات الصنف بالكود من SQL Server (ITEM_CODES + الأسعار).
 * - يحدد الكود الأساسي (IC_CODE_TYPE=0) والفرعي عبر ITEM_ID.
 * - يحوّل الكمية للوحدة الأساسية: base_qty = count_qty * (unit_code / unit_base).
 * - رصيد السيستم من ملف الرصيد المرفوع (stocktake_balances).
 *
 * ============================================================
 *  أعمدة ITEM_CODES في SQL Server
 * ============================================================
 */
// بادئة الجداول في SQL Server (اسم القاعدة + schema) — بتتعرّف في config.php.
// *** لتغيير الفرع: غيّر SQLSRV_DB في config.php مرة واحدة، وكل النظام يتظبط ***
require_once __DIR__ . '/config.php';
const STK_PREFIX      = SQLSRV_PREFIX;   // من config.php (اسم قاعدة الفرع)
const STK_CODES_TABLE = STK_PREFIX . 'ITEM_CODES';
const STK_IC_CODE     = 'IC_CODE';        // الباركود
const STK_ITEM_ID     = 'ITEM_ID';        // الكود الداخلي (الرابط بين الأكواد)
const STK_NAME        = 'IC_NAME1';       // الاسم
const STK_CODE_TYPE   = 'IC_CODE_TYPE';   // 0=أساسي، 1=فرعي
const STK_UNIT_TYPE   = 'IC_UNIT_FACTOR'; // معامل الوحدة الحقيقي (1=قطعة، 12=علبة...)
const STK_CIP_ID      = 'CIP_ID';         // ربط بالسعر
const STK_PRICE_TABLE = STK_PREFIX . 'COM_ITEM_PRICE';
const STK_PP_CIP_ID   = 'CIP_ID';
const STK_PP_PRICE    = 'CIP_PRICE';
// جدول التكلفة (سعر التكلفة بالوحدة الأساسية)
const STK_COST_TABLE  = STK_PREFIX . 'COM_ITEMS';
const STK_COST_ITEMID = 'ITEM_ID';
const STK_COST_COL    = 'CI_AVR_COST';    // متوسط التكلفة بالوحدة الأساسية

require_once __DIR__ . '/config.php';

/**
 * جلب بيانات صنف بالكود من SQL Server.
 *
 * بيرجّع:
 *   item_id, entered_code, code_type, unit (للكود المُدخل),
 *   base_code, base_unit (وحدة الأساسي), name, price
 * أو null لو الكود مش موجود.
 */
function stk_lookup_code(string $code): ?array
{
    $code = trim($code);
    if ($code === '') return null;

    // ١) النسخة المحلية الأول (hq_codes) — بتشتغل من غير نت
    try {
        $st = db()->prepare(
            'SELECT item_id, code, name, code_type, unit_factor, price, cost
             FROM hq_codes WHERE code = ? LIMIT 1'
        );
        $st->execute([$code]);
        $local = $st->fetch();
        if ($local) {
            $itemId = (string) $local['item_id'];
            $unit = (float) $local['unit_factor']; if ($unit <= 0) $unit = 1;
            // الكود الأساسي لنفس الصنف
            $baseCode = $code; $baseUnit = $unit;
            if ($itemId !== '' && $itemId !== '0') {
                $bs = db()->prepare(
                    'SELECT code, unit_factor FROM hq_codes
                     WHERE item_id = ? AND code_type = 0 LIMIT 1'
                );
                $bs->execute([$itemId]);
                $b = $bs->fetch();
                if ($b) {
                    $baseCode = (string) $b['code'];
                    $baseUnit = (float) $b['unit_factor']; if ($baseUnit <= 0) $baseUnit = 1;
                }
            }
            return [
                'item_id'      => $itemId,
                'entered_code' => (string) $local['code'],
                'code_type'    => (int) $local['code_type'],
                'unit'         => $unit,
                'base_code'    => $baseCode,
                'base_unit'    => $baseUnit,
                'name'         => (string) ($local['name'] ?? ''),
                'price'        => $local['price'] !== null ? (float) $local['price'] : null,
                'cost'         => $local['cost']  !== null ? (float) $local['cost']  : null,
            ];
        }
        if (stk_codes_ready()) return null;
    } catch (Throwable $ex) {
        // نكمّل على الجسر
    }

    // ٢) الجسر (لو النسخة المحلية فاضية)
    $sql = "SELECT TOP 1 c." . STK_ITEM_ID . " AS item_id, c." . STK_IC_CODE . " AS code,
                   c." . STK_CODE_TYPE . " AS code_type, c." . STK_UNIT_TYPE . " AS unit_type,
                   c." . STK_NAME . " AS name, p." . STK_PP_PRICE . " AS price,
                   ci." . STK_COST_COL . " AS cost
            FROM " . STK_CODES_TABLE . " c
            LEFT JOIN " . STK_PRICE_TABLE . " p ON p." . STK_PP_CIP_ID . " = c." . STK_CIP_ID . "
            LEFT JOIN " . STK_COST_TABLE . " ci ON ci." . STK_COST_ITEMID . " = c." . STK_ITEM_ID . "
            WHERE c." . STK_IC_CODE . " = ?";
    $rows = sqlsrv_query($sql, [$code]);
    $row = $rows[0] ?? null;
    if (!$row) return null;

    $itemId = (string) $row['item_id'];
    $unit = (float) $row['unit_type'];
    if ($unit <= 0) $unit = 1;

    $baseCode = $code; $baseUnit = $unit;
    if ($itemId !== '' && $itemId !== '0') {
        $brows = sqlsrv_query(
            "SELECT TOP 1 " . STK_IC_CODE . " AS base_code, " . STK_UNIT_TYPE . " AS base_unit
             FROM " . STK_CODES_TABLE . "
             WHERE " . STK_ITEM_ID . " = ? AND " . STK_CODE_TYPE . " = 0",
            [$itemId]
        );
        $brow = $brows[0] ?? null;
        if ($brow) {
            $baseCode = (string) $brow['base_code'];
            $baseUnit = (float) $brow['base_unit'];
            if ($baseUnit <= 0) $baseUnit = 1;
        }
    }

    return [
        'item_id'      => $itemId,
        'entered_code' => (string) $row['code'],
        'code_type'    => (int) $row['code_type'],
        'unit'         => $unit,
        'base_code'    => $baseCode,
        'base_unit'    => $baseUnit,
        'name'         => sqlsrv_fix_encoding((string) $row['name']),
        'price'        => $row['price'] !== null ? (float) $row['price'] : null,
        'cost'         => isset($row['cost']) && $row['cost'] !== null ? (float) $row['cost'] : null,
    ];
}

/**
 * جدول الأكواد المحلي فيه داتا؟
 */
function stk_codes_ready(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $ready = (bool) db()->query('SELECT 1 FROM hq_codes LIMIT 1')->fetchColumn();
    } catch (Throwable $ex) {
        $ready = false;
    }
    return $ready;
}

/**
 * جلب كل الأكواد المربوطة بكود واحد (نفس ITEM_ID).
 * أداة استعلام مستقلة عن الجرد: تعرف الأكواد المرتبطة بالصنف.
 *
 * بيرجّع ['item_id'=>..., 'name'=>..., 'codes'=>[ ['code','unit','is_base','price'], ... ]]
 * أو null لو الكود مش موجود.
 */
function stk_linked_codes(string $code): ?array
{
    $code = trim($code);
    if ($code === '') return null;

    // ١) النسخة المحلية الأول (hq_codes)
    try {
        $h = db()->prepare('SELECT item_id, name FROM hq_codes WHERE code = ? LIMIT 1');
        $h->execute([$code]);
        $head = $h->fetch();
        if ($head) {
            $itemId = (string) $head['item_id'];
            $name   = (string) ($head['name'] ?? '');
            if ($itemId === '' || $itemId === '0') {
                return ['item_id' => $itemId, 'name' => $name, 'codes' => [[
                    'code' => $code, 'unit' => 1.0, 'is_base' => true, 'price' => null,
                ]]];
            }
            $cs = db()->prepare(
                'SELECT code, unit_factor, code_type, price FROM hq_codes
                 WHERE item_id = ? ORDER BY code_type, unit_factor'
            );
            $cs->execute([$itemId]);
            $codes = [];
            foreach ($cs->fetchAll() as $row) {
                $u = (float) $row['unit_factor']; if ($u <= 0) $u = 1;
                $codes[] = [
                    'code'    => (string) $row['code'],
                    'unit'    => $u,
                    'is_base' => (int) $row['code_type'] === 0,
                    'price'   => $row['price'] !== null ? (float) $row['price'] : null,
                ];
            }
            return ['item_id' => $itemId, 'name' => $name, 'codes' => $codes];
        }
        if (stk_codes_ready()) return null;
    } catch (Throwable $ex) {
        // نكمّل على الجسر
    }

    // ٢) الجسر (لو النسخة المحلية فاضية)
    $r = sqlsrv_query(
        "SELECT TOP 1 " . STK_ITEM_ID . " AS item_id, " . STK_NAME . " AS name
         FROM " . STK_CODES_TABLE . " WHERE " . STK_IC_CODE . " = ?",
        [$code]
    );
    $head = $r[0] ?? null;
    if (!$head) return null;

    $itemId = (string) $head['item_id'];
    $name   = sqlsrv_fix_encoding((string) $head['name']);
    if ($itemId === '' || $itemId === '0') {
        return ['item_id' => $itemId, 'name' => $name, 'codes' => [[
            'code' => $code, 'unit' => 1.0, 'is_base' => true, 'price' => null,
        ]]];
    }

    $rows = sqlsrv_query(
        "SELECT c." . STK_IC_CODE . " AS code, c." . STK_UNIT_TYPE . " AS unit_type,
                c." . STK_CODE_TYPE . " AS code_type, p." . STK_PP_PRICE . " AS price
         FROM " . STK_CODES_TABLE . " c
         LEFT JOIN " . STK_PRICE_TABLE . " p ON p." . STK_PP_CIP_ID . " = c." . STK_CIP_ID . "
         WHERE c." . STK_ITEM_ID . " = ?
         ORDER BY c." . STK_CODE_TYPE . ", c." . STK_UNIT_TYPE,
        [$itemId]
    );

    $codes = [];
    foreach ($rows as $row) {
        $u = (float) $row['unit_type']; if ($u <= 0) $u = 1;
        $codes[] = [
            'code'    => (string) $row['code'],
            'unit'    => $u,
            'is_base' => (int) $row['code_type'] === 0,
            'price'   => $row['price'] !== null ? (float) $row['price'] : null,
        ];
    }

    return ['item_id' => $itemId, 'name' => $name, 'codes' => $codes];
}

/**
 * تحويل الكمية المُدخلة (بوحدة الكود) إلى الوحدة الأساسية.
 * base_qty = count_qty * (unit_code / unit_base)
 */
function stk_to_base(float $countQty, float $unitCode, float $unitBase): float
{
    if ($unitBase <= 0) $unitBase = 1;
    if ($unitCode <= 0) $unitCode = 1;
    return $countQty * ($unitCode / $unitBase);
}

/**
 * رصيد السيستم لصنف من ملف الرصيد المرفوع للجلسة (بالوحدة الأساسية).
 * بيدوّر بالكود الأساسي أو المُدخل.
 */
function stk_system_qty(int $sessionId, string $baseCode, string $enteredCode): ?array
{
    $pdo = db();
    foreach ([$baseCode, $enteredCode] as $c) {
        if ($c === '') continue;
        $st = $pdo->prepare(
            'SELECT system_qty, unit, item_name, sale_price, cost_price
             FROM stocktake_balances WHERE session_id=? AND item_code=? LIMIT 1'
        );
        $st->execute([$sessionId, $c]);
        $row = $st->fetch();
        if ($row) return $row;
    }
    return null;
}

/**
 * إضافة/تجميع سطر جرد في محضر.
 *
 * المنطق:
 *  - بيجيب بيانات الكود من SQL Server.
 *  - بيحوّل الكمية للوحدة الأساسية.
 *  - لو فيه سطر بنفس item_id في نفس المحضر → بيجمّع عليه (base_qty)
 *    ويسجّل في review_log، ويرجّع ['merged'=>true,'line_id'=>السطر الأصلي].
 *  - غير كده → سطر جديد.
 *
 * @return array ['merged'=>bool, 'line_id'=>int, 'name'=>..., 'item_id'=>...]
 */
function stk_add_line(int $sheetId, string $code, float $countQty, ?int $sessionId = null): array
{
    if ($countQty < 0) throw new InvalidArgumentException('الكمية لا يمكن أن تكون سالبة.');
    // حماية من لخبطة السكانر: كمية ضخمة غير منطقية (السكانر ضرب الكود في خانة الكمية)
    if ($countQty > 100000) {
        throw new InvalidArgumentException('الكمية كبيرة بشكل غير منطقي (' . $countQty . '). يبدو أن السكانر أدخل الكود في خانة الكمية. تأكد وحاول تاني.');
    }

    $info = stk_lookup_code($code);
    if (!$info) {
        throw new RuntimeException('الكود غير موجود في بيانات الأصناف: ' . $code);
    }

    $pdo = db();

    // الجلسة (لجلب رصيد السيستم)
    if ($sessionId === null) {
        $s = $pdo->prepare('SELECT session_id FROM stocktake_sheets WHERE id=?');
        $s->execute([$sheetId]);
        $sessionId = (int) $s->fetchColumn();
    }

    $baseQty = stk_to_base($countQty, $info['unit'], $info['base_unit']);

    // رصيد السيستم
    // السعر والتكلفة من SQL Server (أولوية)، والملف بديل
    $systemQty = null; $sysName = null; $salePrice = $info['price']; $costPrice = $info['cost'] ?? null;
    if ($sessionId) {
        $sysRow = stk_system_qty($sessionId, $info['base_code'], $info['entered_code']);
        if ($sysRow) {
            $systemQty = (float) $sysRow['system_qty'];
            $sysName   = $sysRow['item_name'] ?: null;
            if ($sysRow['sale_price'] !== null) $salePrice = (float) $sysRow['sale_price'];
            // التكلفة: لو مفيش من SQL Server، خدها من الملف
            if ($costPrice === null && $sysRow['cost_price'] !== null) $costPrice = (float) $sysRow['cost_price'];
        }
    }
    $name = $info['name'] ?: $sysName ?: $info['entered_code'];

    $pdo->beginTransaction();
    try {
        // هل فيه سطر بنفس item_id في المحضر؟ (تجميع)
        $merged = false; $lineId = 0;
        if ($info['item_id'] !== '' && $info['item_id'] !== '0') {
            $ex = $pdo->prepare('SELECT id, base_qty, review_log FROM stocktake_lines WHERE sheet_id=? AND item_id=? LIMIT 1 FOR UPDATE');
            $ex->execute([$sheetId, $info['item_id']]);
            $existing = $ex->fetch();
            if ($existing) {
                // تجميع على السطر الأول
                $lineId = (int) $existing['id'];
                $newBase = (float) $existing['base_qty'] + $baseQty;
                $log = (string) ($existing['review_log'] ?? '');
                $log = ($log === '' ? '=' . rtrim(rtrim(number_format((float)$existing['base_qty'],3,'.',''),'0'),'.') : $log)
                     . '+' . rtrim(rtrim(number_format($baseQty,3,'.',''),'0'),'.');
                $pdo->prepare('UPDATE stocktake_lines SET base_qty=?, review_log=? WHERE id=?')
                    ->execute([$newBase, $log, $lineId]);
                $merged = true;
            }
        }

        if (!$merged) {
            $pdo->prepare(
                'INSERT INTO stocktake_lines
                    (sheet_id, item_id, entered_code, base_code, item_name, unit, unit_factor,
                     count_qty, base_qty, system_qty, sale_price, cost_price)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $sheetId, $info['item_id'] ?: null, $info['entered_code'], $info['base_code'],
                $name, $info['unit'] ? (string)$info['unit'] : null, $info['unit'],
                $countQty, $baseQty, $systemQty, $salePrice, $costPrice,
            ]);
            $lineId = (int) $pdo->lastInsertId();
        }

        $pdo->commit();
        return ['merged' => $merged, 'line_id' => $lineId, 'name' => $name, 'item_id' => $info['item_id']];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * رفع ملف الرصيد (TAB-separated) لجلسة.
 * الأعمدة: مخزن | الرصيد | كود الصنف | الوحدة | اسم الصنف | آخر سعر بيع | ... | آخر سعر شراء
 * (الأعمدة بالترتيب ده من ملف السيستم).
 *
 * @return int عدد السطور المستوردة
 */
function stk_import_balance(int $sessionId, string $filePath): int
{
    if (!is_readable($filePath)) {
        throw new RuntimeException('تعذّر قراءة الملف.');
    }
    $pdo = db();
    $fh = fopen($filePath, 'r');
    if (!$fh) throw new RuntimeException('تعذّر فتح الملف.');

    // تجاهل الهيدر (أول سطر)
    $first = fgets($fh);
    // إزالة BOM لو موجود
    $count = 0;
    $pdo->beginTransaction();
    try {
        // امسح رصيد سابق لنفس الجلسة
        $pdo->prepare('DELETE FROM stocktake_balances WHERE session_id=?')->execute([$sessionId]);
        $ins = $pdo->prepare(
            'INSERT INTO stocktake_balances (session_id, item_code, system_qty, unit, item_name, sale_price, cost_price)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        while (($line = fgets($fh)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') continue;
            $cols = explode("\t", $line);
            // 0=مخزن 1=الرصيد 2=كود 3=وحدة 4=اسم 5=سعر بيع ... 8=سعر شراء
            $code = trim($cols[2] ?? '');
            if ($code === '') continue;
            $qty   = (float) str_replace(',', '', $cols[1] ?? '0');
            $unit  = trim($cols[3] ?? '');
            $name  = trim($cols[4] ?? '');
            $sale  = isset($cols[5]) && $cols[5] !== '' ? (float) str_replace(',', '', $cols[5]) : null;
            $cost  = isset($cols[8]) && $cols[8] !== '' ? (float) str_replace(',', '', $cols[8]) : null;
            $ins->execute([$sessionId, $code, $qty, $unit ?: null, $name ?: null, $sale, $cost]);
            $count++;
        }
        $pdo->prepare('UPDATE stocktake_sessions SET has_balance=1 WHERE id=?')->execute([$sessionId]);
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fclose($fh);
        throw $ex;
    }
    fclose($fh);
    return $count;
}

/**
 * الجلسة النشطة الحالية (أو null).
 */
function stk_active_session(): ?array
{
    $row = db()->query("SELECT * FROM stocktake_sessions WHERE status='active' ORDER BY id DESC LIMIT 1")->fetch();
    return $row ?: null;
}

/* =====================================================================
 *  دوال المراجعة (المرحلة 2)
 * ===================================================================== */

/**
 * بدء مراجعة محضر: يحوّله لحالة reviewing ويسجّل المراجع.
 */
function stk_start_review(int $sheetId, int $reviewerId): void
{
    $pdo = db();
    $st = $pdo->prepare('SELECT status FROM stocktake_sheets WHERE id=?');
    $st->execute([$sheetId]);
    $status = $st->fetchColumn();
    if ($status === false) throw new RuntimeException('المحضر غير موجود.');
    if ($status === 'done') throw new RuntimeException('المحضر منتهي بالفعل.');
    $pdo->prepare('UPDATE stocktake_sheets SET status="reviewing", reviewed_by=?, reviewed_at=NOW() WHERE id=?')
        ->execute([$reviewerId, $sheetId]);
}

/**
 * حفظ كمية مراجعة لصنف (review_qty) بالوحدة الأساسية.
 * بيستخدم نفس منطق الكود: لو المراجع ضرب كود فرعي، نحوّله للأساسي.
 *
 * @return array ['line_id'=>int, 'review_qty'=>float, 'orig'=>float, 'state'=>'less'|'more'|'equal']
 */
function stk_set_review_qty(int $lineId, float $reviewCountQty, string $code = ''): array
{
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM stocktake_lines WHERE id=? FOR UPDATE');
    // FOR UPDATE داخل transaction
    $pdo->beginTransaction();
    try {
        $st->execute([$lineId]);
        $line = $st->fetch();
        if (!$line) throw new RuntimeException('السطر غير موجود.');

        // لو اتبعت كود، نحوّل الكمية بالوحدة؛ غير كده الكمية بالأساسي مباشرة
        $reviewBase = $reviewCountQty;
        if ($code !== '') {
            $info = stk_lookup_code($code);
            if ($info) {
                $reviewBase = stk_to_base($reviewCountQty, $info['unit'], $info['base_unit']);
            }
        }

        $orig = (float) $line['base_qty'];
        $state = abs($reviewBase - $orig) < 0.0001 ? 'equal' : ($reviewBase < $orig ? 'less' : 'more');

        $pdo->prepare('UPDATE stocktake_lines SET review_qty=? WHERE id=?')->execute([$reviewBase, $lineId]);
        $pdo->commit();
        return ['line_id'=>$lineId, 'review_qty'=>$reviewBase, 'orig'=>$orig, 'state'=>$state];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * إدخال جرد المراجع بالكود (المراجع بيعمل محضر تاني كامل).
 * - بيجيب الصنف بالكود، يحوّل للأساسي.
 * - لو الصنف موجود في المحضر (نفس item_id): يجمّع على review_qty.
 * - لو مش موجود (المراجع لقى صنف مكنش متجرد): ينشئ سطر جديد بـ base_qty=0
 *   و review_qty=القيمة (صنف زيادة، هيظهر في المقارنة).
 *
 * @return array ['merged'=>bool, 'line_id'=>int, 'name'=>..., 'new_item'=>bool]
 */
function stk_add_review(int $sheetId, string $code, float $countQty): array
{
    if ($countQty < 0) throw new InvalidArgumentException('الكمية لا يمكن أن تكون سالبة.');
    if ($countQty > 100000) {
        throw new InvalidArgumentException('الكمية كبيرة بشكل غير منطقي (' . $countQty . '). يبدو أن السكانر أدخل الكود في خانة الكمية.');
    }
    $info = stk_lookup_code($code);
    if (!$info) throw new RuntimeException('الكود غير موجود في بيانات الأصناف: ' . $code);

    $pdo = db();
    $reviewBase = stk_to_base($countQty, $info['unit'], $info['base_unit']);

    $pdo->beginTransaction();
    try {
        // دوّر على سطر بنفس item_id
        $existing = null;
        if ($info['item_id'] !== '' && $info['item_id'] !== '0') {
            $ex = $pdo->prepare('SELECT id, review_qty FROM stocktake_lines WHERE sheet_id=? AND item_id=? LIMIT 1 FOR UPDATE');
            $ex->execute([$sheetId, $info['item_id']]);
            $existing = $ex->fetch();
        }

        if ($existing) {
            // جمّع على review_qty الموجودة
            $newReview = (float)($existing['review_qty'] ?? 0) + $reviewBase;
            $pdo->prepare('UPDATE stocktake_lines SET review_qty=? WHERE id=?')
                ->execute([$newReview, $existing['id']]);
            $lineId = (int) $existing['id']; $newItem = false;
        } else {
            // صنف جديد لقاه المراجع (مكنش في الأصلي): base_qty=0
            $pdo->prepare(
                'INSERT INTO stocktake_lines
                    (sheet_id, item_id, entered_code, base_code, item_name, unit, unit_factor,
                     count_qty, base_qty, review_qty)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, ?)'
            )->execute([
                $sheetId, $info['item_id'] ?: null, $info['entered_code'], $info['base_code'],
                $info['name'], $info['unit'] ? (string)$info['unit'] : null, $info['unit'], $reviewBase,
            ]);
            $lineId = (int) $pdo->lastInsertId(); $newItem = true;
        }

        $pdo->commit();
        return ['merged'=>!$newItem, 'line_id'=>$lineId, 'name'=>$info['name'], 'new_item'=>$newItem];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * تعديل الكمية النهائية المتفق عليها لصنف (final_qty) بالأساسي.
 * بيسجّل في review_log.
 */
function stk_set_final_qty(int $lineId, float $finalQty, ?int $userId = null): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT review_log FROM stocktake_lines WHERE id=? FOR UPDATE');
        $st->execute([$lineId]);
        $row = $st->fetch();
        if (!$row) throw new RuntimeException('السطر غير موجود.');
        $log = (string)($row['review_log'] ?? '');
        $log .= ($log ? ' | ' : '') . 'نهائي:' . rtrim(rtrim(number_format($finalQty,3,'.',''),'0'),'.');
        $pdo->prepare('UPDATE stocktake_lines SET final_qty=?, review_log=? WHERE id=?')
            ->execute([$finalQty, $log, $lineId]);
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * حالة مقارنة المحضر: بيرجّع لكل سطر حالته اللونية.
 *  - 'less'  (أحمر): المراجع لقى أقل من الجرد الأصلي.
 *  - 'more'  (أصفر): المراجع لقى أكثر.
 *  - 'equal' (أخضر): مطابق.
 * والكمية المرجعية = review_qty لو موجودة، وإلا base_qty.
 *
 * @return array ['lines'=>[...], 'all_green'=>bool, 'has_diff'=>bool]
 */
function stk_compare_sheet(int $sheetId): array
{
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM stocktake_lines WHERE sheet_id=?');
    $st->execute([$sheetId]);
    $rows = $st->fetchAll();

    $out = []; $allGreen = true; $hasDiff = false;
    foreach ($rows as $r) {
        $orig = (float) $r['base_qty'];
        // review_qty=NULL يعني المراجع ماضربش الصنف
        $reviewed = $r['review_qty'] !== null;
        $rev  = $reviewed ? (float) $r['review_qty'] : 0.0;
        if (abs($rev - $orig) < 0.0001) {
            $state = 'equal';
        } elseif ($rev < $orig) {
            $state = 'less'; $allGreen = false; $hasDiff = true;  // المراجع لقى أقل (أو مالقهوش)
        } else {
            $state = 'more'; $allGreen = false; $hasDiff = true;  // المراجع لقى أكثر (أو صنف زيادة)
        }
        $r['_state'] = $state;
        $r['_review'] = $rev;
        $r['_reviewed'] = $reviewed;
        $out[] = $r;
    }
    // ترتيب: الأحمر فوق، الأصفر النص، الأخضر تحت
    $order = ['less'=>0, 'more'=>1, 'equal'=>2];
    usort($out, fn($a,$b) => $order[$a['_state']] <=> $order[$b['_state']]);

    return ['lines'=>$out, 'all_green'=>$allGreen, 'has_diff'=>$hasDiff];
}

/**
 * إنهاء المحضر: يثبّت الكميات النهائية ويحوّله read-only (done).
 * النهائية = final_qty لو موجودة، وإلا review_qty، وإلا base_qty.
 */
function stk_finalize_sheet(int $sheetId, ?int $userId = null): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM stocktake_lines WHERE sheet_id=? FOR UPDATE');
        $st->execute([$sheetId]);
        $rows = $st->fetchAll();
        $upd = $pdo->prepare('UPDATE stocktake_lines SET final_qty=? WHERE id=?');
        foreach ($rows as $r) {
            if ($r['final_qty'] !== null) continue; // متعدّل بالفعل
            $final = $r['review_qty'] !== null ? (float)$r['review_qty'] : (float)$r['base_qty'];
            $upd->execute([$final, (int)$r['id']]);
        }
        $pdo->prepare('UPDATE stocktake_sheets SET status="done", done_at=NOW() WHERE id=?')->execute([$sheetId]);
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * إرجاع محضر منتهي (done) للمراجعة تاني (reviewing).
 * للأونر فقط — لو حد دخل الانتهاء بالغلط.
 * بتسيب القيم زي ما هي (final_qty و review_qty محفوظين) عشان الأونر يكمّل.
 */
function stk_reopen_review(int $sheetId): void
{
    $pdo = db();
    $st = $pdo->prepare("SELECT status FROM stocktake_sheets WHERE id=?");
    $st->execute([$sheetId]);
    $status = $st->fetchColumn();
    if ($status !== 'done') {
        throw new RuntimeException('المحضر مش منتهي عشان نرجّعه.');
    }
    $pdo->prepare("UPDATE stocktake_sheets SET status='reviewing', done_at=NULL WHERE id=?")->execute([$sheetId]);
}

/**
 * هل اليوزر يقدر يراجع/يعدّل المحضر؟
 * (الأونر، المراجع، صاحب المحضر فقط)
 */
function stk_can_review(array $sheet, array $user): bool
{
    if (!empty($user['is_owner']) || ($user['role_name'] ?? '') === 'owner') return true;
    $uid = (int) $user['id'];
    return $uid === (int)($sheet['counted_by'] ?? 0) || $uid === (int)($sheet['reviewed_by'] ?? 0);
}

/**
 * هل اليوزر يقدر يضيف/يحذف أصناف في الجرد؟
 * (الأونر، أو صاحب المحضر فقط — مش المراجع)
 */
function stk_can_edit(array $sheet, array $user): bool
{
    if (!empty($user['is_owner']) || ($user['role_name'] ?? '') === 'owner') return true;
    return (int)$user['id'] === (int)($sheet['counted_by'] ?? 0);
}

/* =====================================================================
 *  دوال التقارير (المرحلة 3)
 * ===================================================================== */

/**
 * بناء محتوى تقرير TEXT لمحضر واحد.
 * كل سطر: كود الصنف <TAB> الكمية النهائية (بالأساسي) <TAB> سعر التكلفة.
 * بدون هيدر. الكمية النهائية = final_qty أو review_qty أو base_qty.
 */
function stk_report_sheet(int $sheetId): string
{
    $pdo = db();
    $st = $pdo->prepare('SELECT base_code, entered_code, base_qty, review_qty, final_qty, cost_price FROM stocktake_lines WHERE sheet_id=? ORDER BY id');
    $st->execute([$sheetId]);
    $rows = $st->fetchAll();

    $lines = [];
    foreach ($rows as $r) {
        $code = $r['base_code'] ?: $r['entered_code'];
        if ($code === '' || $code === null) continue;
        // الكمية: النهائية ثم المراجع ثم الجرد الأصلي
        $qty = $r['final_qty'] !== null ? (float)$r['final_qty']
             : ($r['review_qty'] !== null ? (float)$r['review_qty'] : (float)$r['base_qty']);
        if (abs($qty) < 0.0001) continue; // تجاهل الصفر
        $qtyOut = rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
        if ($qtyOut === '') $qtyOut = '0';
        // سعر التكلفة: لو مفقود (صنف دخّله المراجع) نجيبه من SQL Server بالكود
        $costVal = $r['cost_price'] !== null ? (float)$r['cost_price'] : stk_cost_for_code((string)$code);
        $cost = $costVal !== null ? number_format($costVal, 2, '.', '') : '';
        $lines[] = $code . "\t" . $qtyOut . "\t" . $cost;
    }
    return implode("\r\n", $lines) . (count($lines) ? "\r\n" : '');
}

/**
 * جلب سعر التكلفة لكود من SQL Server (للأصناف اللي مالهاش تكلفة محفوظة).
 * بترجّع cache عشان متكررش الاستعلام لنفس الكود.
 */
function stk_cost_for_code(string $code): ?float
{
    static $cache = [];
    $code = trim($code);
    if ($code === '') return null;
    if (array_key_exists($code, $cache)) return $cache[$code];
    try {
        $rows = sqlsrv_query(
            "SELECT TOP 1 ci." . STK_COST_COL . " AS cost
             FROM " . STK_CODES_TABLE . " c
             LEFT JOIN " . STK_COST_TABLE . " ci ON ci." . STK_COST_ITEMID . " = c." . STK_ITEM_ID . "
             WHERE c." . STK_IC_CODE . " = ?",
            [$code]
        );
        $cost = isset($rows[0]['cost']) && $rows[0]['cost'] !== null ? (float)$rows[0]['cost'] : null;
    } catch (Throwable $ex) {
        $cost = null;
    }
    $cache[$code] = $cost;
    return $cost;
}

/**
 * تقرير مجمّع لكل المحاضر المنتهية (done) في جلسة.
 * بيجمّع نفس الصنف (نفس الكود الأساسي) عبر كل المحاضر.
 * كل سطر: كود الصنف <TAB> الكمية الإجمالية <TAB> سعر التكلفة.
 */
function stk_report_session(int $sessionId): string
{
    $pdo = db();
    $st = $pdo->prepare(
        "SELECT l.base_code, l.entered_code, l.cost_price,
                COALESCE(l.final_qty, l.review_qty, l.base_qty) AS qty
         FROM stocktake_lines l
         JOIN stocktake_sheets s ON s.id = l.sheet_id
         WHERE s.session_id = ? AND s.status = 'done'"
    );
    $st->execute([$sessionId]);

    $totals = []; $costs = [];
    foreach ($st as $r) {
        $code = $r['base_code'] ?: $r['entered_code'];
        if ($code === '' || $code === null) continue;
        $totals[$code] = ($totals[$code] ?? 0) + (float)$r['qty'];
        if ($r['cost_price'] !== null) $costs[$code] = (float)$r['cost_price'];
    }

    $lines = [];
    foreach ($totals as $code => $qty) {
        if (abs($qty) < 0.0001) continue;
        $qtyOut = rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
        if ($qtyOut === '') $qtyOut = '0';
        // التكلفة: المحفوظة، وإلا من SQL Server بالكود
        $costVal = $costs[$code] ?? stk_cost_for_code((string)$code);
        $cost = $costVal !== null ? number_format($costVal, 2, '.', '') : '';
        $lines[] = $code . "\t" . $qtyOut . "\t" . $cost;
    }
    return implode("\r\n", $lines) . (count($lines) ? "\r\n" : '');
}

/**
 * حذف سطر من محضر (طالما المحضر مفتوح).
 */
function stk_delete_line(int $lineId, int $sheetId): void
{
    $pdo = db();
    // تأكد إن المحضر مش منتهي (يسمح بالحذف في open و reviewing)
    $st = $pdo->prepare('SELECT status FROM stocktake_sheets WHERE id=?');
    $st->execute([$sheetId]);
    $status = $st->fetchColumn();
    if ($status === 'done') {
        throw new RuntimeException('لا يمكن الحذف بعد إنهاء المحضر. ارجعه للمراجعة أولاً.');
    }
    $pdo->prepare('DELETE FROM stocktake_lines WHERE id=? AND sheet_id=?')->execute([$lineId, $sheetId]);
}

/**
 * مسح محضر جرد كامل (بكل أصنافه).
 * - للأونر فقط (يُتحقق في الصفحة).
 * - مسموح في حالة open أو reviewing فقط، مش done.
 */
function stk_delete_sheet(int $sheetId): void
{
    $pdo = db();
    $st = $pdo->prepare('SELECT status FROM stocktake_sheets WHERE id=?');
    $st->execute([$sheetId]);
    $status = $st->fetchColumn();
    if ($status === false) {
        throw new RuntimeException('المحضر غير موجود.');
    }
    if ($status === 'done') {
        throw new RuntimeException('لا يمكن مسح محضر منتهي. ارجعه للمراجعة أولاً لو لازم.');
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM stocktake_lines WHERE sheet_id=?')->execute([$sheetId]);
        $pdo->prepare('DELETE FROM stocktake_sheets WHERE id=?')->execute([$sheetId]);
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * بحث عن أصناف بالاسم (للجرد). بيرجّع قائمة [code, name, unit].
 * بيدوّر في IC_NAME1 بالكلمة.
 */
function stk_search_by_name(string $term, int $limit = 50): array
{
    $term = trim($term);
    if (mb_strlen($term) < 2) return [];

    $limit = max(1, $limit);
    $words = array_slice(preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 4);
    if (!$words) return [];

    $out = []; $seen = [];

    // ١) النسخة المحلية الأول، وكل كلمة لازم تكون موجودة في الاسم بأي ترتيب.
    try {
        $where = implode(' AND ', array_fill(0, count($words), 'name LIKE ?'));
        $st = db()->prepare(
            'SELECT code, name, code_type, unit_factor, price, cost
             FROM hq_codes WHERE ' . $where . '
             ORDER BY code_type ASC, name ASC LIMIT ?'
        );
        $param = 1;
        foreach ($words as $word) {
            $st->bindValue($param++, '%' . $word . '%', PDO::PARAM_STR);
        }
        $st->bindValue($param, $limit, PDO::PARAM_INT);
        $st->execute();
        foreach ($st->fetchAll() as $r) {
            $code = (string) $r['code'];
            if (isset($seen[$code])) continue;
            $seen[$code] = true;
            $unit = (float) $r['unit_factor'];
            if ($unit <= 0) $unit = 1;
            $out[] = [
                'code' => $code,
                'name' => (string) ($r['name'] ?? ''),
                'unit' => $unit,
                'sale' => $r['price'] !== null ? (float) $r['price'] : null,
                'cost' => $r['cost'] !== null ? (float) $r['cost'] : null,
            ];
        }
        if ($out) return $out;
    } catch (Throwable $ex) {
        error_log('stk_search_by_name local: ' . $ex->getMessage());
    }

    // ٢) الجسر fallback لو المحلي مرجّعش نتائج.
    $bridgeWhere = implode(
        ' AND ',
        array_fill(0, count($words), 'c.' . STK_NAME . ' LIKE ?')
    );
    $sql = "SELECT TOP $limit c." . STK_IC_CODE . " AS code, c." . STK_NAME . " AS name,
                   c." . STK_UNIT_TYPE . " AS unit, c." . STK_CODE_TYPE . " AS code_type,
                   p." . STK_PP_PRICE . " AS sale_price, ci." . STK_COST_COL . " AS cost_price
            FROM " . STK_CODES_TABLE . " c
            LEFT JOIN " . STK_PRICE_TABLE . " p ON p." . STK_PP_CIP_ID . " = c." . STK_CIP_ID . "
            LEFT JOIN " . STK_COST_TABLE . " ci ON ci." . STK_COST_ITEMID . " = c." . STK_ITEM_ID . "
            WHERE $bridgeWhere
            ORDER BY c." . STK_CODE_TYPE . " ASC";
    try {
        $params = array_map(static fn(string $word): string => '%' . $word . '%', $words);
        foreach (sqlsrv_query($sql, $params) as $r) {
            $code = (string) $r['code'];
            if (isset($seen[$code])) continue;
            $seen[$code] = true;
            $unit = (float) $r['unit'];
            if ($unit <= 0) $unit = 1;
            $out[] = [
                'code' => $code,
                'name' => sqlsrv_fix_encoding((string) $r['name']),
                'unit' => $unit,
                'sale' => $r['sale_price'] !== null ? (float) $r['sale_price'] : null,
                'cost' => $r['cost_price'] !== null ? (float) $r['cost_price'] : null,
            ];
            if (count($out) >= $limit) break;
        }
    } catch (Throwable $ex) {
        error_log('stk_search_by_name bridge: ' . $ex->getMessage());
    }
    return $out;
}

/**
 * بحث عن صنف داخل كل محاضر جلسة (بالكود أو الاسم).
 * بيرجّع كل سطر لقاه (متكرر لو في أكتر من محضر) مع رقم المحضر واسم القائم بالجرد.
 * النتيجة مرتبة بالاسم ثم رقم المحضر.
 */
/**
 * بحث عن المحاضر اللي مكانها/قسمها بيحتوي كلمة معيّنة (زي "كشاكيل").
 * بيرجّع قائمة المحاضر (مش السطور) مع عدد الأصناف وحالة كل محضر.
 */
function stk_search_sheets_by_place(int $sessionId, string $term): array
{
    $term = trim($term);
    if ($term === '') return [];
    $pdo = db();
    $st = $pdo->prepare(
        "SELECT s.id, s.sheet_no, s.place_note, s.status AS sheet_status,
                u.full_name AS counter_name, r.full_name AS reviewer_name,
                (SELECT COUNT(*) FROM stocktake_lines l WHERE l.sheet_id = s.id) AS lines_count
         FROM stocktake_sheets s
         LEFT JOIN users u ON u.id = s.counted_by
         LEFT JOIN users r ON r.id = s.reviewed_by
         WHERE s.session_id = ?
           AND s.place_note LIKE ?
         ORDER BY s.sheet_no"
    );
    $st->execute([$sessionId, '%' . $term . '%']);
    return $st->fetchAll();
}

function stk_search_in_sheets(int $sessionId, string $term): array
{
    $term = trim($term);
    if ($term === '') return [];
    $pdo = db();
    // البحث بالكود بالظبط (مطابقة تامة) — مش LIKE
    // عشان لو ضربت كود 21 يطلّع الصنف بكود 21 بس، مش كل الأكواد اللي فيها 21.
    $st = $pdo->prepare(
        "SELECT l.entered_code, l.base_code, l.item_name, l.unit,
                l.base_qty, l.review_qty, l.final_qty,
                s.sheet_no, s.status AS sheet_status, s.place_note,
                u.full_name AS counter_name
         FROM stocktake_lines l
         JOIN stocktake_sheets s ON s.id = l.sheet_id
         LEFT JOIN users u ON u.id = s.counted_by
         WHERE s.session_id = ?
           AND (l.entered_code = ? OR l.base_code = ?)
         ORDER BY s.sheet_no"
    );
    $st->execute([$sessionId, $term, $term]);
    return $st->fetchAll();
}

/* =====================================================================
 *  دوال أرشيف الجرد (للقراءة في التقارير)
 * ===================================================================== */

/**
 * كل جلسات الجرد (للاختيار في التقارير).
 */
function stk_all_sessions(): array
{
    return db()->query(
        "SELECT s.id, s.status, s.started_at, s.closed_at,
                u.full_name AS starter_name,
                (SELECT COUNT(*) FROM stocktake_sheets WHERE session_id=s.id) AS sheets_count
         FROM stocktake_sessions s
         LEFT JOIN users u ON u.id = s.started_by
         ORDER BY s.id DESC"
    )->fetchAll();
}

/**
 * محاضر جلسة معيّنة (للاختيار في التقارير).
 */
function stk_session_sheets(int $sessionId): array
{
    $st = db()->prepare(
        "SELECT sh.id, sh.sheet_no, sh.status, sh.place_note, sh.created_at,
                b.name AS branch_name, u.full_name AS counter_name, r.full_name AS reviewer_name,
                (SELECT COUNT(*) FROM stocktake_lines WHERE sheet_id=sh.id) AS items_count
         FROM stocktake_sheets sh
         LEFT JOIN branches b ON b.id=sh.branch_id
         LEFT JOIN users u ON u.id=sh.counted_by
         LEFT JOIN users r ON r.id=sh.reviewed_by
         WHERE sh.session_id=? ORDER BY sh.sheet_no"
    );
    $st->execute([$sessionId]);
    return $st->fetchAll();
}

/**
 * أصناف محاضر معيّنة (للقراءة فقط في التقارير).
 * بتاخد مصفوفة معرّفات محاضر، وبترجّع كل السطور مع رقم المحضر واسم القائم بالجرد.
 */
function stk_lines_for_sheets(array $sheetIds): array
{
    $sheetIds = array_values(array_filter(array_map('intval', $sheetIds)));
    if (!$sheetIds) return [];
    $place = implode(',', array_fill(0, count($sheetIds), '?'));
    $st = db()->prepare(
        "SELECT l.*, s.sheet_no, s.status AS sheet_status, s.place_note,
                u.full_name AS counter_name, r.full_name AS reviewer_name
         FROM stocktake_lines l
         JOIN stocktake_sheets s ON s.id = l.sheet_id
         LEFT JOIN users u ON u.id = s.counted_by
         LEFT JOIN users r ON r.id = s.reviewed_by
         WHERE l.sheet_id IN ($place)
         ORDER BY s.sheet_no, l.id"
    );
    $st->execute($sheetIds);
    return $st->fetchAll();
}

/**
 * تصدير بيانات لملف Excel حقيقي (.xlsx) من غير مكتبات خارجية.
 * العمود B (index 1) يتعمل نص (string) إجبارياً، الباقي حسب نوعه.
 * @param array $headers أسماء الأعمدة
 * @param array $rows صفوف البيانات
 * @param string $filename اسم الملف
 */
/**
 * تصدير xlsx مع دعم ألوان الخلايا.
 * @param array $headers رؤوس الأعمدة
 * @param array $rows صفوف البيانات
 * @param string $filename اسم الملف
 * @param array $cellFills خريطة ألوان: ["rowIndex:colIndex" => "green"|"red"|"redtext"]
 *        rowIndex يبدأ من 0 لأول صف بيانات (بعد الهيدر).
 */
function stk_export_xlsx(array $headers, array $rows, string $filename, array $cellFills = []): void
{
    // لازم إكستنشن zip مفعّل (xlsx = ملف ZIP)
    if (!class_exists('ZipArchive')) {
        if (ob_get_length()) ob_end_clean();
        header('Content-Type: text/html; charset=utf-8');
        echo '<div style="font-family:sans-serif;direction:rtl;padding:40px;text-align:center;">'
           . '<h2 style="color:var(--neg);">تصدير Excel غير متاح</h2>'
           . '<p style="font-size:16px;line-height:1.8;color:var(--ink-600);">إكستنشن <strong>zip</strong> مش مفعّل في PHP على الجهاز ده.<br>'
           . 'فعّله من <code>php.ini</code> (شيل ; من <code>extension=zip</code>) وأعد تشغيل Apache.<br>'
           . 'أو استخدم زر <strong>تصدير CSV</strong> اللي بيشتغل من غير أي إعداد.</p>'
           . '<a href="javascript:history.back()" style="display:inline-block;margin-top:16px;padding:10px 24px;background:var(--fill-strong);color:var(--fill-strong-fg);border-radius:8px;text-decoration:none;">رجوع</a>'
           . '</div>';
        exit;
    }

    // ===== أنماط الخلايا (styles.xml) =====
    // style 0 = عادي، 1 = هيدر، 2 = أخضر فاتح، 3 = أحمر فاتح، 4 = نص أحمر غامق (للأسماء)
    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="3">'
        . '<font><sz val="11"/><name val="Calibri"/></font>'                                  // 0 عادي
        . '<font><b/><sz val="11"/><color rgb="FF1E1B4B"/><name val="Calibri"/></font>'        // 1 هيدر bold
        . '<font><b/><sz val="11"/><color rgb="FFC0392B"/><name val="Calibri"/></font>'        // 2 أحمر غامق
        . '</fonts>'
        . '<fills count="5">'
        . '<fill><patternFill patternType="none"/></fill>'                                     // 0
        . '<fill><patternFill patternType="gray125"/></fill>'                                  // 1 (محجوز)
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFD6F5E3"/></patternFill></fill>' // 2 أخضر فاتح
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFDE0E0"/></patternFill></fill>' // 3 أحمر فاتح
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFEEEAFB"/></patternFill></fill>' // 4 هيدر بنفسجي
        . '</fills>'
        . '<borders count="1"><border/></borders>'
        . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
        . '<cellXfs count="6">'
        . '<xf fontId="0" fillId="0"/>'                                  // 0 عادي
        . '<xf fontId="1" fillId="4" applyFill="1" applyFont="1"/>'      // 1 هيدر
        . '<xf fontId="0" fillId="2" applyFill="1"/>'                    // 2 خلفية خضرا
        . '<xf fontId="0" fillId="3" applyFill="1"/>'                    // 3 خلفية حمرا
        . '<xf fontId="2" fillId="3" applyFill="1" applyFont="1"/>'      // 4 نص أحمر + خلفية حمرا
        . '<xf fontId="2" fillId="0" applyFont="1"/>'                    // 5 نص أحمر فقط
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    $styleMap = ['green'=>2, 'red'=>3, 'redfill_redtext'=>4, 'redtext'=>5];

    // بناء بيانات الخلايا
    $allRows = array_merge([$headers], $rows);
    $sheetData = '';
    $rowNum = 0;
    foreach ($allRows as $rIdx => $row) {
        $rowNum++;
        $isHeader = ($rIdx === 0);
        $dataRowIdx = $rIdx - 1; // index صف البيانات (بدون الهيدر)
        $sheetData .= '<row r="' . $rowNum . '">';
        $colIdx = 0;
        foreach ($row as $val) {
            $colLetter = stk_xlsx_col($colIdx);
            $ref = $colLetter . $rowNum;
            // تحديد الستايل
            $sIdx = 0;
            if ($isHeader) {
                $sIdx = 1;
            } else {
                $key = $dataRowIdx . ':' . $colIdx;
                if (isset($cellFills[$key]) && isset($styleMap[$cellFills[$key]])) {
                    $sIdx = $styleMap[$cellFills[$key]];
                }
            }
            $sAttr = $sIdx > 0 ? ' s="' . $sIdx . '"' : '';
            $isText = ($colIdx === 1) || !is_numeric($val) || $val === '';
            if ($val === null || $val === '') {
                $sheetData .= '<c r="' . $ref . '"' . $sAttr . '/>';
            } elseif ($isText) {
                $esc = htmlspecialchars((string)$val, ENT_QUOTES | ENT_XML1, 'UTF-8');
                $sheetData .= '<c r="' . $ref . '"' . $sAttr . ' t="inlineStr"><is><t xml:space="preserve">' . $esc . '</t></is></c>';
            } else {
                $sheetData .= '<c r="' . $ref . '"' . $sAttr . '><v>' . $val . '</v></c>';
            }
            $colIdx++;
        }
        $sheetData .= '</row>';
    }

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>' . $sheetData . '</sheetData></worksheet>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';

    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="الجرد" sheetId="1" r:id="rId1"/></sheets></workbook>';

    $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    // بناء ملف ZIP (xlsx)
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
    $zip->addFromString('xl/styles.xml', $stylesXml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
}

/**
 * تحويل رقم عمود (0-based) لحرف Excel (A, B, ... Z, AA, ...).
 */
function stk_xlsx_col(int $idx): string
{
    $letters = '';
    $idx++;
    while ($idx > 0) {
        $rem = ($idx - 1) % 26;
        $letters = chr(65 + $rem) . $letters;
        $idx = intdiv($idx - 1, 26);
    }
    return $letters;
}

/**
 * بيانات المحضر وتفاصيله (لصفحة الجرد والـAPI).
 */
function stk_get_sheet(int $sheetId): ?array
{
    $st = db()->prepare(
        "SELECT sh.*, b.name AS branch_name, u.full_name AS counter_name, r.full_name AS reviewer_name
         FROM stocktake_sheets sh
         LEFT JOIN branches b ON b.id=sh.branch_id
         LEFT JOIN users u ON u.id=sh.counted_by
         LEFT JOIN users r ON r.id=sh.reviewed_by
         WHERE sh.id=?"
    );
    $st->execute([$sheetId]);
    return $st->fetch() ?: null;
}

/**
 * سطور محضر الجرد (مرتبة من الأحدث للأقدم).
 */
function stk_sheet_lines(int $sheetId): array
{
    $st = db()->prepare('SELECT * FROM stocktake_lines WHERE sheet_id=? ORDER BY id DESC');
    $st->execute([$sheetId]);
    return $st->fetchAll();
}

/**
 * قائمة المحاضر لجلسة الجرد النشطة (مرتبة برقم المحضر تنازلياً).
 */
function stk_active_sheets(int $sessionId): array
{
    $st = db()->prepare(
        "SELECT sh.*, b.name AS branch_name, u.full_name AS counter_name, r.full_name AS reviewer_name,
                (SELECT COUNT(*) FROM stocktake_lines WHERE sheet_id=sh.id) AS items_count
         FROM stocktake_sheets sh
         LEFT JOIN branches b ON b.id=sh.branch_id
         LEFT JOIN users u ON u.id=sh.counted_by
         LEFT JOIN users r ON r.id=sh.reviewed_by
         WHERE sh.session_id=? ORDER BY sh.sheet_no DESC"
    );
    $st->execute([$sessionId]);
    return $st->fetchAll();
}

/**
 * تجميع المحاضر حسب القسم (العرض الشجري للأقسام).
 */
function stk_group_sheets(array $sheets): array
{
    $sheetGroups = [];
    foreach ($sheets as $shRow) {
        $note = trim($shRow['place_note'] ?? '');
        if ($note === '') {
            $section = $shRow['branch_name'] ?? 'بدون قسم';
        } else {
            $parts = preg_split('/\s*[-–]\s*/u', $note, 2);
            $section = trim($parts[0]) !== '' ? trim($parts[0]) : $note;
        }
        if (!isset($sheetGroups[$section])) $sheetGroups[$section] = [];
        $sheetGroups[$section][] = $shRow;
    }
    return $sheetGroups;
}

/**
 * قائمة الفروع النشطة للجرد.
 */
function stk_branches_list(): array
{
    return db()->query('SELECT id, name FROM branches WHERE is_active=1 ORDER BY name')->fetchAll();
}

/**
 * قائمة المستخدمين النشطين (للأونر).
 */
function stk_users_list(): array
{
    return db()->query('SELECT id, full_name FROM users WHERE is_active=1 ORDER BY full_name')->fetchAll();
}

/**
 * قائمة أماكن الجرد (الأقسام والأرفف).
 */
function stk_places_list(): array
{
    try {
        return db()->query('SELECT name FROM stocktake_places WHERE is_active=1 ORDER BY sort_order, id')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $ex) {
        return [];
    }
}

/**
 * جلب سطر المحضر الخام من جدول stocktake_sheets بدون JOIN (للتحقق من الصلاحيات).
 */
function stk_load_sheet_row(int $sheetId): ?array
{
    $st = db()->prepare('SELECT * FROM stocktake_sheets WHERE id=?');
    $st->execute([$sheetId]);
    return $st->fetch() ?: null;
}

/**
 * إنشاء محضر جرد جديد مع حماية ضد تكرار نفس المكان في نفس الجلسة.
 *
 * @return array{sheet_id:int, duplicate:bool}
 */
function stk_create_sheet(int $sessionId, ?int $branchId, string $place, int $userId): array
{
    $pdo = db();
    $place = trim($place);
    if ($place !== '') {
        $normPlace = preg_replace('/\s+/u', ' ', $place);
        $dupPlace = $pdo->prepare(
            "SELECT id FROM stocktake_sheets
             WHERE session_id=? AND REPLACE(REPLACE(place_note,'  ',' '),'  ',' ')=? AND status IN ('open','reviewing')
             ORDER BY id DESC LIMIT 1"
        );
        $dupPlace->execute([$sessionId, $normPlace]);
        $dupId = (int) $dupPlace->fetchColumn();
        if ($dupId > 0) {
            return ['sheet_id' => $dupId, 'duplicate' => true];
        }
    }

    $maxNo = (int) $pdo->query('SELECT COALESCE(MAX(sheet_no),0) FROM stocktake_sheets WHERE session_id=' . $sessionId)->fetchColumn();
    $st = $pdo->prepare(
        'INSERT INTO stocktake_sheets (session_id, sheet_no, branch_id, place_note, counted_by, status)
         VALUES (?, ?, ?, ?, ?, "open")'
    );
    $st->execute([$sessionId, $maxNo + 1, $branchId ?: null, $place !== '' ? $place : null, $userId]);
    $newId = (int) $pdo->lastInsertId();
    return ['sheet_id' => $newId, 'duplicate' => false];
}

/**
 * تعديل كمية المراجع لصنف في محضر الجرد مع حراسة ضد كود السكانر والكميات الفلكية.
 */
function stk_update_review_qty(int $sheetId, int $lineId, float $qty): float
{
    $pdo = db();
    $ln = $pdo->prepare('SELECT unit, entered_code, base_qty FROM stocktake_lines WHERE id=? AND sheet_id=?');
    $ln->execute([$lineId, $sheetId]);
    $row = $ln->fetch();
    if (!$row) {
        throw new RuntimeException('السطر غير موجود.');
    }
    $unit = (float)($row['unit'] ?? 1);
    if ($unit <= 0) $unit = 1;

    // حماية ضد الكود المضروب في خانة الكمية
    $enteredCode = (string) ($row['entered_code'] ?? '');
    if ($enteredCode !== '' && abs($qty - (float)$enteredCode) < 0.0001 && $qty > 100) {
        throw new InvalidArgumentException('يبدو أن السكانر أدخل الكود في خانة الكمية. اكتب الكمية بيدك.');
    }
    // كمية غير منطقية (فلكية أو سالبة)
    if ($qty > 100000 || $qty < 0) {
        throw new InvalidArgumentException('الكمية كبيرة بشكل غير منطقي (' . $qty . ').');
    }

    $reviewBase = $qty * $unit;
    $pdo->prepare('UPDATE stocktake_lines SET review_qty=? WHERE id=? AND sheet_id=?')
        ->execute([$reviewBase, $lineId, $sheetId]);
    return $reviewBase;
}

/**
 * إنهاء جلسة جرد نشطة. للأونر فقط.
 */
function stk_end_session(int $sessionId, int $userId): void
{
    db()->prepare('UPDATE stocktake_sessions SET status="closed", closed_by=?, closed_at=NOW() WHERE id=?')
        ->execute([$userId, $sessionId]);
}

/**
 * إعادة فتح آخر جلسة جرد مقفولة. للأونر فقط.
 * يرفض لو فيه جلسة نشطة بالفعل أو مفيش جلسة مقفولة.
 */
function stk_reopen_session(): int
{
    $pdo = db();
    $last = $pdo->query("SELECT id FROM stocktake_sessions WHERE status='closed' ORDER BY id DESC LIMIT 1")->fetchColumn();
    if (!$last) {
        throw new RuntimeException('مفيش جرد مقفول نقدر نرجّعه.');
    }
    $act = (int)$pdo->query("SELECT COUNT(*) FROM stocktake_sessions WHERE status='active'")->fetchColumn();
    if ($act > 0) {
        throw new RuntimeException('فيه جلسة جرد نشطة بالفعل. اقفلها الأول.');
    }
    $pdo->prepare("UPDATE stocktake_sessions SET status='active', closed_by=NULL, closed_at=NULL WHERE id=?")->execute([(int)$last]);
    return (int)$last;
}

/**
 * إعادة تعيين القائم بالجرد أو المراجع لمحضر. للأونر فقط.
 * الحقول المسموحة: counted_by أو reviewed_by فقط.
 */
function stk_reassign_sheet(int $sheetId, string $field, ?int $userId): void
{
    if (!in_array($field, ['counted_by', 'reviewed_by'], true)) {
        throw new InvalidArgumentException('حقل غير صالح.');
    }
    db()->prepare("UPDATE stocktake_sheets SET {$field}=? WHERE id=?")->execute([$userId, $sheetId]);
}

/**
 * تغيير مكان محضر الجرد. للأونر فقط.
 */
function stk_change_sheet_place(int $sheetId, string $place): void
{
    $place = trim($place);
    if ($place === '') {
        throw new InvalidArgumentException('اكتب المكان.');
    }
    db()->prepare('UPDATE stocktake_sheets SET place_note=? WHERE id=?')->execute([$place, $sheetId]);
}
