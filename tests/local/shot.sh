#!/usr/bin/env bash
# =====================================================================
#  shot.sh — صورة لصفحة من نسخة Laragon (من غير ما تفتح متصفح)
# ---------------------------------------------------------------------
#  الفحوصات التانية بتقرا الكود؛ دي بتشوف الصفحة. اتعملت ٢٣ سبتمبر
#  بعد رقم «الفرق المجمع» في cashier.php اللي طلع مش في النص ومحدش
#  شافه غير محمد على الموقع الحقيقي.
#
#  الاستعمال:
#     bash tests/local/shot.sh cashier.php                 ← فاتح + ليلي، أونر
#     bash tests/local/shot.sh cashier.php ashraqa light
#     SHOT_W=390 bash tests/local/shot.sh cashier.php      ← عرض موبايل
#
#  الصور بتتحفظ في $SHOT_DIR (افتراضي ${TMPDIR:-/tmp}/fagala_shots).
#  🔴 اعمل sync_to_laragon.sh الأول لو الملف اتعدّل.
#  🔴 مابيلمسش الموقع ولا القاعدة غير القراية اللي الصفحة نفسها بتعملها.
# =====================================================================
set -uo pipefail
cd "$(dirname "$0")/../.."

PAGE="${1:-}"; WHO="${2:-owner}"; MODES="${3:-light dark}"
[ -z "$PAGE" ] && { echo "الاستعمال: bash tests/local/shot.sh <page.php> [owner|ashraqa] [light|dark]"; exit 1; }
PHP="${PHP_BIN:-/d/php-8.5.10-nts-Win32-vs17-x64/php.exe}"
WWW="${LARAGON_WWW:-D:/laragon/www}"
OUT="${SHOT_DIR:-${TMPDIR:-/tmp}/fagala_shots}"; mkdir -p "$OUT"
W="${SHOT_W:-1440}"; H="${SHOT_H:-1100}"
BROWSER=""
for b in "/c/Program Files (x86)/Microsoft/Edge/Application/msedge.exe" \
         "/c/Program Files/Microsoft/Edge/Application/msedge.exe" \
         "/c/Program Files (x86)/Google/Chrome/Application/chrome.exe" \
         "/c/Program Files/Google/Chrome/Application/chrome.exe"; do
    [ -x "$b" ] && { BROWSER="$b"; break; }
done
[ -z "$BROWSER" ] && { echo "❌ مفيش Edge ولا Chrome"; exit 1; }

HTML="$("$PHP" tests/local/run_page.php "$PAGE" "$WHO" "" 2>&1)"
if echo "$HTML" | grep -qE "Fatal error|Parse error"; then
    echo "❌ الصفحة وقعت:"; echo "$HTML" | grep -E "Fatal error|Parse error" | head -3; exit 1
fi

name="${PAGE%.php}"
for mode in $MODES; do
    f="$WWW/_shot_${name}_${mode}.html"
    # الوضع بيتحدد من localStorage قبل أي ستايل — بنثبّته قبل سكربت الهيدر
    printf '%s' "$HTML" | sed "0,/<head>/s//<head><script>try{localStorage.setItem('fagala-theme','$mode')}catch(e){}<\/script>/" > "$f"
    png="$OUT/${name}_${mode}_${W}.png"
    timeout 60 "$BROWSER" --headless=new --disable-gpu --hide-scrollbars --window-size="$W,$H" \
        --virtual-time-budget=3000 --screenshot="$png" "file:///${f#/}" >/dev/null 2>&1
    rm -f "$f"
    [ -s "$png" ] && echo "📸 $png" || echo "❌ الصورة ماتعملتش ($mode)"
done
