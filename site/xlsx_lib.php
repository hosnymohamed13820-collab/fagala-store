<?php
/**
 * xlsx_lib.php — مولّد ملفات إكسيل (.xlsx) من غير أي مكتبات خارجية
 * نظام الفجالة ستور المالي
 *
 * ملف الإكسيل أصلاً ZIP جواه ملفات XML، وPHP فيه ZipArchive جاهز.
 *
 * المهم هنا: بنحدد **نوع العمود** قبل ما نكتب أي بيانات —
 * فعمود كود الصنف بيبقى نص فعلاً، مش رقم متحوّل لصيغة علمية.
 *
 * الاستخدام:
 *   $x = new SimpleXlsx('الفاتورة');
 *   $x->setColumnTypes(['n','s','s','n']);   // s = نص · n = رقم
 *   $x->addRow(['م','كود','اسم','كمية'], true);   // true = صف عناوين
 *   $x->addRow([1, '00515', 'صنف', 12]);
 *   $x->download('invoice.xlsx');
 */

final class SimpleXlsx
{
    private string $sheetName;
    private array  $rows = [];          // [ [cells...], isHeader ]
    private array  $colTypes = [];      // ['s'|'n', ...]
    private array  $sharedStrings = [];
    private array  $sharedIndex = [];
    private int    $sharedCount = 0;

    public function __construct(string $sheetName = 'Sheet1')
    {
        // إكسيل بيرفض أسماء فيها محارف معيّنة أو أطول من 31
        $clean = preg_replace('/[\\\\\/\?\*\[\]:]/u', '-', $sheetName);
        $this->sheetName = mb_substr($clean ?: 'Sheet1', 0, 31);
    }

    /** تحديد نوع كل عمود: 's' نص · 'n' رقم. لازم قبل addRow. */
    public function setColumnTypes(array $types): void
    {
        $this->colTypes = array_values($types);
    }

    public function addRow(array $cells, bool $header = false): void
    {
        $this->rows[] = [$cells, $header];
    }

    /** صف فاضي للفصل بين الأقسام. */
    public function addBlank(): void
    {
        $this->rows[] = [[], false];
    }

    // ===== الداخلي =====

    private function esc(string $v): string
    {
        // إكسيل بيرفض محارف التحكم
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v);
        return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function sharedId(string $v): int
    {
        if (isset($this->sharedIndex[$v])) return $this->sharedIndex[$v];
        $id = count($this->sharedStrings);
        $this->sharedStrings[] = $v;
        $this->sharedIndex[$v] = $id;
        return $id;
    }

    /** رقم العمود → حرف (1=A, 27=AA) */
    private function colName(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $m = ($n - 1) % 26;
            $s = chr(65 + $m) . $s;
            $n = (int) (($n - $m - 1) / 26);
        }
        return $s;
    }

    private function buildSheet(): string
    {
        $maxCols = 1;
        foreach ($this->rows as [$cells, $h]) $maxCols = max($maxCols, count($cells));

        // ===== تعريف الأعمدة =====
        // ده اللي بيخلي العمود نص قبل أي بيانات: style=1 مربوط بصيغة "@" (Text)
        $cols = '<cols>';
        for ($c = 1; $c <= $maxCols; $c++) {
            $type = $this->colTypes[$c - 1] ?? 'n';
            $w    = $type === 's' ? 18 : 13;
            $st   = $type === 's' ? ' style="1"' : '';
            $cols .= '<col min="' . $c . '" max="' . $c . '" width="' . $w . '" customWidth="1"' . $st . '/>';
        }
        $cols .= '</cols>';

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
             . '<sheetViews><sheetView rightToLeft="1" workbookViewId="0"/></sheetViews>'
             . $cols . '<sheetData>';

        $r = 0;
        foreach ($this->rows as [$cells, $isHeader]) {
            $r++;
            if (!$cells) { $xml .= '<row r="' . $r . '"/>'; continue; }

            $xml .= '<row r="' . $r . '">';
            $c = 0;
            foreach ($cells as $val) {
                $c++;
                $ref  = $this->colName($c) . $r;
                $type = $this->colTypes[$c - 1] ?? 'n';

                if ($val === null || $val === '') {
                    // خلية فاضية — بس بتحتفظ بستايل العمود
                    $st = $isHeader ? ' s="2"' : ($type === 's' ? ' s="1"' : '');
                    $xml .= '<c r="' . $ref . '"' . $st . '/>';
                    continue;
                }

                $asText = $isHeader || $type === 's' || !is_numeric($val);

                if ($asText) {
                    $id = $this->sharedId((string) $val);
                    $st = $isHeader ? ' s="2"' : ($type === 's' ? ' s="1"' : '');
                    $xml .= '<c r="' . $ref . '"' . $st . ' t="s"><v>' . $id . '</v></c>';
                } else {
                    $xml .= '<c r="' . $ref . '"><v>' . (0 + $val) . '</v></c>';
                }
            }
            $xml .= '</row>';
        }

        return $xml . '</sheetData></worksheet>';
    }

    private function buildShared(): string
    {
        $n = count($this->sharedStrings);
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
           . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $n . '" uniqueCount="' . $n . '">';
        foreach ($this->sharedStrings as $s) {
            $x .= '<si><t xml:space="preserve">' . $this->esc($s) . '</t></si>';
        }
        return $x . '</sst>';
    }

    private function buildStyles(): string
    {
        // numFmtId 49 = "@" يعني Text — ده أساس تحويل العمود لنص
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
             . '<numFmts count="1"><numFmt numFmtId="164" formatCode="@"/></numFmts>'
             . '<fonts count="2">'
             .   '<font><sz val="11"/><name val="Calibri"/></font>'
             .   '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
             . '</fonts>'
             . '<fills count="3">'
             .   '<fill><patternFill patternType="none"/></fill>'
             .   '<fill><patternFill patternType="gray125"/></fill>'
             .   '<fill><patternFill patternType="solid"><fgColor rgb="FF16233D"/><bgColor indexed="64"/></patternFill></fill>'
             . '</fills>'
             . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
             . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
             . '<cellXfs count="3">'
             .   '<xf numFmtId="0"   fontId="0" fillId="0" borderId="0" xfId="0"/>'
             .   '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
             .   '<xf numFmtId="0"   fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1">'
             .     '<alignment horizontal="center" vertical="center"/></xf>'
             . '</cellXfs>'
             . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
             . '</styleSheet>';
    }

    /** بناء الملف وإرساله للمتصفح للتحميل. */
    public function download(string $filename): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('تعذّر إنشاء ملف الإكسيل.');
        }

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
          . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
          . '<Default Extension="xml" ContentType="application/xml"/>'
          . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
          . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
          . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
          . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
          . '</Types>');

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
          . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
          . '</Relationships>');

        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
          . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
          . '<sheets><sheet name="' . $this->esc($this->sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
          . '</workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
          . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
          . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
          . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
          . '</Relationships>');

        $sheet = $this->buildSheet();               // لازم الأول: بيملّي sharedStrings
        $zip->addFromString('xl/sharedStrings.xml', $this->buildShared());
        $zip->addFromString('xl/styles.xml',        $this->buildStyles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);

        $zip->close();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tmp));
        header('Pragma: no-cache');
        header('Expires: 0');
        readfile($tmp);
        @unlink($tmp);
    }
}
