<?php
/**
 * item_units_lib.php — دوال حساب القطع ومعاملات الوحدات (بريف ٢١٤)
 * نظام الفجالة ستور المالي
 *
 * المكتبة المشتركة لحساب الكمية بالقطعة دايماً بين البريفات ٢١٢ و٢١٣ و٢١٤ و٢١٥ و٢١٧ و٢١٨.
 * الأولوية: معامل السطر > معامل الكود > 1 مع unknown.
 */
declare(strict_types=1);

/**
 * حساب عدد القطع لمعامل السطر أو الكود.
 *
 * @param float $qty الكمية المكتوبة
 * @param float|null $lineFactor معامل الوحدة الموجود في السطر (لو أكبر من صفر)
 * @param float|null $codeFactor معامل الوحدة المسجل للكود في hq_codes (لو أكبر من صفر)
 * @return array{pieces: float, factor: float, source: 'line'|'code'|'unknown'}
 */
function item_line_pieces(float $qty, ?float $lineFactor, ?float $codeFactor): array
{
    if ($lineFactor !== null && (float) $lineFactor > 0.0) {
        $factor = (float) $lineFactor;
        $source = 'line';
    } elseif ($codeFactor !== null && (float) $codeFactor > 0.0) {
        $factor = (float) $codeFactor;
        $source = 'code';
    } else {
        $factor = 1.0;
        $source = 'unknown';
    }

    $pieces = round($qty * $factor, 3);

    return [
        'pieces' => $pieces,
        'factor' => $factor,
        'source' => $source,
    ];
}

/**
 * جلب معاملات الوحدات لمجموعة أكواد في استعلام واحد.
 *
 * @param PDO $pdo
 * @param string[] $codes
 * @return array<string, array{item_id: int, unit_factor: float, code_type: int}>
 */
function item_code_factors(PDO $pdo, array $codes): array
{
    // تنضيف وتصفية الأكواد غير الفاضية والمكررة
    $cleaned = [];
    foreach ($codes as $c) {
        $trimmed = trim((string) $c);
        if ($trimmed !== '') {
            $cleaned[$trimmed] = true;
        }
    }

    if (empty($cleaned)) {
        return [];
    }

    $uniqueCodes = array_keys($cleaned);
    $placeholders = implode(',', array_fill(0, count($uniqueCodes), '?'));

    $stmt = $pdo->prepare(
        "SELECT code, item_id, unit_factor, code_type
           FROM hq_codes
          WHERE code IN ($placeholders)"
    );
    $stmt->execute($uniqueCodes);

    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $code = (string) $row['code'];
        $out[$code] = [
            'item_id'     => (int) $row['item_id'],
            'unit_factor' => (float) ($row['unit_factor'] ?? 1.0),
            'code_type'   => (int) ($row['code_type'] ?? 1),
        ];
    }

    return $out;
}
