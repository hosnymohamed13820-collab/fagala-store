<?php
/**
 * xlsx_read_lib.php — قارئ ملفات إكسيل (.xlsx) بدون أي مكتبات خارجية
 * نظام الفجالة ستور المالي
 *
 * بيعتمد على ZipArchive و SimpleXML المدمجين في PHP.
 * بيحل مشكلة الفجوات (Gaps) في ملفات الإكسيل بالاعتماد على خاصية r="C5"
 * حتى لا يحدث أي إزاحة في الأعمدة تؤثر على ساعات العمل والرواتب.
 */

if (!defined('XLSX_READ_LIB_INCLUDED')) {
    define('XLSX_READ_LIB_INCLUDED', true);
}

/**
 * تحويل رمز العمود الحرفي (مثل A أو C أو AA) إلى فهرس رقمي يبدأ من 0.
 * A -> 0, B -> 1, Z -> 25, AA -> 26, U -> 20
 */
function xlsx_col_to_index(string $col): int
{
    $col = strtoupper(trim($col));
    $len = strlen($col);
    $idx = 0;
    for ($i = 0; $i < $len; $i++) {
        $c = ord($col[$i]);
        if ($c >= 65 && $c <= 90) {
            $idx = $idx * 26 + ($c - 64);
        }
    }
    return max(0, $idx - 1);
}

/**
 * قراءة صفوف ملف إكسيل (.xlsx) وإرجاعها كمصفوفة ثنائية الأبعاد من النصوص.
 *
 * @param string $filePath المسار المطلق أو النسبي لملف الإكسيل
 * @return array<int, array<int, string>> مصفوفة الصفوف (كل صف هو مصفوفة نصوص)
 * @throws RuntimeException في حال وجود خطأ في فتح الملف أو قراءة أوراق العمل
 */
function read_xlsx_rows(string $filePath): array
{
    if (!file_exists($filePath) || !is_readable($filePath)) {
        throw new RuntimeException('الملف غير موجود أو يتعذر قراءته.');
    }

    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('امتداد ZipArchive غير مفعل في خادم PHP.');
    }

    $zip = new ZipArchive();
    $status = $zip->open($filePath);
    if ($status !== true) {
        throw new RuntimeException('الملف المرفوع ليس ملف إكسيل مضغوط صالح (.xlsx). تأكد من سلامة الملف.');
    }

    // 1) قراءة جدول النصوص المشتركة sharedStrings.xml
    $sharedStrings = [];
    $sstXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sstXml !== false) {
        // تنظيف الـ namespaces لتسهيل الوصول السريع للعناصر
        $sstXmlClean = preg_replace('/\s+xmlns(:[a-zA-Z0-9]+)?="[^"]*"/', '', $sstXml);
        $sst = @simplexml_load_string($sstXmlClean);
        if ($sst && isset($sst->si)) {
            foreach ($sst->si as $si) {
                if (isset($si->t)) {
                    $sharedStrings[] = (string) $si->t;
                } elseif (isset($si->r)) {
                    $str = '';
                    foreach ($si->r as $r) {
                        $str .= (string) ($r->t ?? '');
                    }
                    $sharedStrings[] = $str;
                } else {
                    $sharedStrings[] = (string) $si;
                }
            }
        }
    }

    // 2) تحديد وقراءة ورقة العمل الأولى sheet1.xml
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        // بحث مرن عن أول ورقة عمل في حال اختلاف التسمية أو حالة الأحرف
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name && preg_match('#^xl/worksheets/sheet\d+\.xml$#i', $name)) {
                $sheetXml = $zip->getFromIndex($i);
                break;
            }
        }
    }

    $zip->close();

    if ($sheetXml === false) {
        throw new RuntimeException('الملف لا يحتوي على ورقة عمل صالحة (sheet1.xml).');
    }

    $sheetXmlClean = preg_replace('/\s+xmlns(:[a-zA-Z0-9]+)?="[^"]*"/', '', $sheetXml);
    $sheet = @simplexml_load_string($sheetXmlClean);
    if (!$sheet || !isset($sheet->sheetData)) {
        throw new RuntimeException('تعذر قراءة بيانات ورقة العمل من ملف الإكسيل.');
    }

    $rows = [];
    $maxColsAcrossSheet = 0;
    $sequentialRow = 1;

    // 3) تفكيك الصفوف والخلايا مع احترام رمز الخلية r لمنع زحلقة الأعمدة
    foreach ($sheet->sheetData->row as $rowNode) {
        $realRowNum = $sequentialRow;
        $rRowAttr = (string) ($rowNode['r'] ?? '');
        if ($rRowAttr !== '' && is_numeric($rRowAttr)) {
            $realRowNum = (int) $rRowAttr;
        }

        $rowMap = [];
        $maxColInRow = -1;
        $nextSequentialCol = 0;

        if (isset($rowNode->c)) {
            foreach ($rowNode->c as $cell) {
                $colIdx = $nextSequentialCol;
                $rAttr = (string) ($cell['r'] ?? '');

                if ($rAttr !== '' && preg_match('/^([A-Z]+)(\d+)$/i', $rAttr, $matches)) {
                    $colLetters = strtoupper($matches[1]);
                    $colIdx = xlsx_col_to_index($colLetters);
                }

                $type = (string) ($cell['t'] ?? '');
                $val = '';

                if ($type === 's') {
                    // نص من جدول النصوص المشتركة
                    $sIndex = (int) ($cell->v ?? 0);
                    $val = $sharedStrings[$sIndex] ?? '';
                } elseif ($type === 'inlineStr') {
                    // نص مضمن مباشر
                    if (isset($cell->is->t)) {
                        $val = (string) $cell->is->t;
                    } elseif (isset($cell->is->r)) {
                        $t = '';
                        foreach ($cell->is->r as $r) {
                            $t .= (string) ($r->t ?? '');
                        }
                        $val = $t;
                    }
                } elseif ($type === 'str') {
                    // نص مباشر ناتج عن معادلة أو قيمة نصية
                    $val = (string) ($cell->v ?? '');
                } elseif ($type === 'b') {
                    // قيمة منطقية
                    $vStr = (string) ($cell->v ?? '');
                    $val = ($vStr === '1' || strtolower($vStr) === 'true') ? '1' : '0';
                } else {
                    // أرقام وتواريخ وقيم افتراضية
                    $val = isset($cell->v) ? (string) $cell->v : '';
                }

                $rowMap[$colIdx] = $val;
                if ($colIdx > $maxColInRow) {
                    $maxColInRow = $colIdx;
                }
                $nextSequentialCol = $colIdx + 1;
            }
        }

        // بناء مصفوفة الصف وملء الخلايا غير الموجودة (الفجوات) بنصوص فارغة
        $rowCells = [];
        for ($c = 0; $c <= $maxColInRow; $c++) {
            $rowCells[$c] = $rowMap[$c] ?? '';
        }

        $rows[] = [
            'row'   => $realRowNum,
            'cells' => $rowCells,
        ];

        if (count($rowCells) > $maxColsAcrossSheet) {
            $maxColsAcrossSheet = count($rowCells);
        }
        $sequentialRow = $realRowNum + 1;
    }

    // تسوية أطوال جميع الصفوف لضمان توحيد عدد الأعمدة وتفادي undefined index
    if ($maxColsAcrossSheet > 0) {
        foreach ($rows as &$r) {
            $curLen = count($r['cells']);
            if ($curLen < $maxColsAcrossSheet) {
                $r['cells'] = array_pad($r['cells'], $maxColsAcrossSheet, '');
            }
        }
        unset($r);
    }

    return $rows;
}
