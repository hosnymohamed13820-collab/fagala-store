#!/usr/bin/env bash
# =====================================================================
#  run_audit.sh — المشغّل الرئيسي للمراجعة العميقة (بريف ١٨٢ — الجزء د)
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

TIMESTAMP="$(date +%Y-%m-%d_%H%M%S)"
OUT_DIR="$REPO_DIR/tests/audit/out/$TIMESTAMP"
mkdir -p "$OUT_DIR"

echo "========================================================="
echo "   DEEP AUDIT RUNNER (Brief 182)"
echo "   Start Time: $(date)"
echo "   Output: $OUT_DIR"
echo "========================================================="

START_SEC=$(date +%s)

# تزامن الكود مع Laragon
echo ""
echo ">>> [خطوة ٠] مزامنة الكود مع Laragon (www_ds2)..."
LARAGON_WWW="${LARAGON_WWW_BASH:-/d/laragon/www_ds2}" "$REPO_DIR/tests/local/sync_to_laragon.sh"

export LARAGON_WWW="${LARAGON_WWW:-D:/laragon/www_ds2}"

# الجزء أ: الزاحف
echo ""
echo ">>> [خطوة ١] تشغيل الجزء أ: زاحف الموقع وفاحص الـ APIs..."
"$PHP_BIN" "$SCRIPT_DIR/crawl.php" "$OUT_DIR"

# الجزء ب: فحص المتصفح
echo ""
echo ">>> [خطوة ٢] تشغيل الجزء ب: فحص المتصفح (Edge Headless + CDP)..."
"$NODE_BIN" "$SCRIPT_DIR/browser_audit.mjs" "$OUT_DIR"

# الجزء ج: الفحص الثابت
echo ""
echo ">>> [خطوة ٣] تشغيل الجزء ج: الفحص الثابت الشامل..."
"$PHP_BIN" "$SCRIPT_DIR/static_audit.php" "$OUT_DIR"

# تجميع النتائج
echo ""
echo ">>> [خطوة ٤] تجميع النتائج وإعداد التقرير النهائي..."
"$PHP_BIN" "$SCRIPT_DIR/aggregate.php" "$OUT_DIR"

END_SEC=$(date +%s)
TOTAL_MIN=$(( (END_SEC - START_SEC) / 60 ))
TOTAL_SEC=$(( (END_SEC - START_SEC) % 60 ))

echo ""
echo "========================================================="
echo "   اكتملت المراجعة العميقة بنجاح!"
echo "   إجمالي الوقت المستغرق: ${TOTAL_MIN} دقيقة و ${TOTAL_SEC} ثانية"
echo "   الملفات في: $OUT_DIR"
echo "========================================================="
