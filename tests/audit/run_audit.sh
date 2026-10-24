#!/usr/bin/env bash
# =====================================================================
#  run_audit.sh — المشغّل الرئيسي للمراجعة العميقة (بريف ١٨٢ب)
#  نظام الفجالة ستور المالي
# =====================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"

PHP_BIN="${PHP_BIN:-/d/laragon/bin/php/php-8.3.33-Win32-vs16-x64/php.exe}"
if [ ! -f "$PHP_BIN" ]; then
    PHP_BIN="$(command -v php || true)"
fi

NODE_BIN="$(command -v node || true)"
if [ -z "$NODE_BIN" ]; then
    echo "❌ node مش موجود في PATH"
    exit 1
fi

MYSQL_BIN="${MYSQL_BIN:-/d/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe}"
MYSQLDUMP_BIN="${MYSQLDUMP_BIN:-/d/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysqldump.exe}"

TIMESTAMP="$(date +%Y-%m-%d_%H%M%S)"
OUT_DIR="$REPO_DIR/tests/audit/out/$TIMESTAMP"
mkdir -p "$OUT_DIR"

echo "========================================================="
echo "   DEEP AUDIT RUNNER — SAFE ISOLATED SUITE (Brief 182b)"
echo "   Start Time: $(date)"
echo "   Output: $OUT_DIR"
echo "========================================================="

START_SEC=$(date +%s)

# -------------------------------------------------------------
# [خطوة ٠.أ] التحقق من قاعدة fagala الأصلية وحساب بصمتها قبل أي شيء
# -------------------------------------------------------------
echo ""
echo ">>> [خطوة ٠.أ] حساب بصمة قاعدة fagala الأصلية قبل الفحص..."
"$MYSQL_BIN" -u root -e "CHECKSUM TABLE fagala.push_queue, fagala.notifications, fagala.invoice_problems;" | tee "$OUT_DIR/fagala_checksum_before.txt"

# -------------------------------------------------------------
# [خطوة ٠.ب] إنشاء قاعدة الاختبار المعزولة fagala_audit
# -------------------------------------------------------------
echo ""
echo ">>> [خطوة ٠.ب] إنشاء نسخة القاعدة المعزولة (fagala_audit)..."
"$MYSQL_BIN" -u root -e "DROP DATABASE IF EXISTS fagala_audit; CREATE DATABASE fagala_audit;"
"$MYSQLDUMP_BIN" --skip-lock-tables --default-character-set=utf8mb4 -u root fagala | "$MYSQL_BIN" --default-character-set=utf8mb4 -u root fagala_audit

# حساب بصمة جميع جداول fagala_audit قبل الزحف
"$MYSQL_BIN" -u root -N -e "
SELECT CONCAT('CHECKSUM TABLE fagala_audit.', table_name, ';')
FROM information_schema.tables WHERE table_schema='fagala_audit';
" | "$MYSQL_BIN" -u root > "$OUT_DIR/audit_all_tables_checksum_before.txt"

# -------------------------------------------------------------
# [خطوة ٠.ج] إعداد بيئة D:/laragon/www_audit وتجهيز config.php
# -------------------------------------------------------------
echo ""
echo ">>> [خطوة ٠.ج] تجهيز مجلد D:/laragon/www_audit وربطه بقاعدة fagala_audit..."
WWW_AUDIT_BASH="/d/laragon/www_audit"
mkdir -p "$WWW_AUDIT_BASH"

# نسخ ملفات الريبو إلى www_audit
while IFS= read -r f; do
    rel="${f#site/}"
    case "$rel" in
        config.php|config.php.*|uploads/*|data/*|downloads/*) continue ;;
    esac
    mkdir -p "$(dirname "$WWW_AUDIT_BASH/$rel")"
    cp -f "$REPO_DIR/$f" "$WWW_AUDIT_BASH/$rel"
done < <(git -C "$REPO_DIR" ls-files --cached --others --exclude-standard site/)

# مسح أي ملفات .php في www_audit مش موجودة في git (مثل bridge.php)
for f in "$WWW_AUDIT_BASH"/*.php; do
    [ -f "$f" ] || continue
    base="$(basename "$f")"
    [ "$base" = "config.php" ] && continue
    if ! git -C "$REPO_DIR" ls-files --error-unmatch "site/$base" >/dev/null 2>&1; then
        rm -f "$f"
        echo "  - مسح ملف غير موجود في الريبو: $base"
    fi
done

# نسخ config.php من www_ds2 وتعديل DB_NAME فقط بـ sed بدون طباعته
cp -f /d/laragon/www_ds2/config.php "$WWW_AUDIT_BASH/config.php"
sed -i "s/define('DB_NAME', 'fagala')/define('DB_NAME', 'fagala_audit')/g" "$WWW_AUDIT_BASH/config.php"

export LARAGON_WWW="D:/laragon/www_audit"
export LARAGON_WWW_BASH="/d/laragon/www_audit"

# -------------------------------------------------------------
# [خطوة ٠.د] تشغيل الاختبار الذاتي selftest للفحوصات الـ ١١
# -------------------------------------------------------------
echo ""
echo ">>> [خطوة ٠.د] تشغيل الاختبار الذاتي للفحوصات الـ ١١ (selftest.php)..."
"$PHP_BIN" "$SCRIPT_DIR/selftest.php" | tee "$OUT_DIR/selftest_output.txt"

# -------------------------------------------------------------
# الجزء أ: الزاحف وفاحص الـ APIs
# -------------------------------------------------------------
echo ""
echo ">>> [خطوة ١] تشغيل الجزء أ: زاحف الموقع وفاحص الـ APIs على www_audit..."
"$PHP_BIN" "$SCRIPT_DIR/crawl.php" "$OUT_DIR"

# -------------------------------------------------------------
# الجزء ب: فحص المتصفح (Edge Headless)
# -------------------------------------------------------------
echo ""
echo ">>> [خطوة ٢] تشغيل الجزء ب: فحص المتصفح عبر خادم محلي حقيقي..."
"$NODE_BIN" "$SCRIPT_DIR/browser_audit.mjs" "$OUT_DIR"

# -------------------------------------------------------------
# الجزء ب.٢: زاحف النقرات (Edge Headless + CDP Click Audit - بريف ٢٠١)
# -------------------------------------------------------------
echo ""
echo ">>> [خطوة ٢.ب] تشغيل زاحف النقرات وفحص الأزرار الحية (click_audit.mjs)..."
"$NODE_BIN" "$SCRIPT_DIR/click_audit.mjs" "$OUT_DIR"

# -------------------------------------------------------------
# الجزء ج: الفحص الثابت الشامل
# -------------------------------------------------------------
echo ""
echo ">>> [خطوة ٣] تشغيل الجزء ج: الفحص الثابت الشامل لكود site/..."
"$PHP_BIN" "$SCRIPT_DIR/static_audit.php" "$OUT_DIR"

# -------------------------------------------------------------
# الجزء د: تجميع النتائج وإعداد التقرير
# -------------------------------------------------------------
echo ""
echo ">>> [خطوة ٤] تجميع النتائج وإعداد ملخص المراجعة..."
"$PHP_BIN" "$SCRIPT_DIR/aggregate.php" "$OUT_DIR"

# -------------------------------------------------------------
# [خطوة ختامية] التحقق من الجداول المعدلة في fagala_audit وبصمة fagala
# -------------------------------------------------------------
echo ""
echo ">>> [خطوة ختامية] حساب الجداول التي تغيرت في fagala_audit والتحقق من سلامة fagala الأصلية..."
"$MYSQL_BIN" -u root -N -e "
SELECT CONCAT('CHECKSUM TABLE fagala_audit.', table_name, ';')
FROM information_schema.tables WHERE table_schema='fagala_audit';
" | "$MYSQL_BIN" -u root > "$OUT_DIR/audit_all_tables_checksum_after.txt"

# مقارنة التغييرات في fagala_audit
diff -u "$OUT_DIR/audit_all_tables_checksum_before.txt" "$OUT_DIR/audit_all_tables_checksum_after.txt" > "$OUT_DIR/db_modified_tables.diff" || true

# التحقق من بصمة fagala الأصلية
"$MYSQL_BIN" -u root -e "CHECKSUM TABLE fagala.push_queue, fagala.notifications, fagala.invoice_problems;" | tee "$OUT_DIR/fagala_checksum_after.txt"

END_SEC=$(date +%s)
TOTAL_MIN=$(( (END_SEC - START_SEC) / 60 ))
TOTAL_SEC=$(( (END_SEC - START_SEC) % 60 ))

echo ""
echo "========================================================="
echo "   اكتملت المراجعة العميقة الآمنة بنجاح!"
echo "   إجمالي الوقت المستغرق: ${TOTAL_MIN} دقيقة و ${TOTAL_SEC} ثانية"
echo "   الملفات في: $OUT_DIR"
echo "========================================================="
