#!/usr/bin/env bash
# =====================================================================
#  فحوصات ما قبل التسليم — الفجالة ستور
#  شغّلها قبل ما ترفع أي حاجة، وقبل ما أي وكيل يوريك كوده.
#
#  دي الفحوصات اللي مسكت في جلسة 2026-08-23:
#    $sumWallet  · متغير مستخدم ومش معرّف
#    $mainTrId   · ?> جوه تعليق // بيقفل وسم PHP
#    $prov       · عدد ? في الاستعلام ≠ عدد القيم
#  التلاتة كانوا هيوصلوا للسيرفر لولا الفحص.
# =====================================================================
set -u
cd "$(dirname "$0")/.." || exit 1
FAIL=0

echo "== بنية PHP =="
python3 tests/check_php.py site/*.php site/includes/*.php || FAIL=1

echo
echo "== بنية PowerShell =="
if compgen -G "local/*.ps1" > /dev/null; then
    python3 tests/check_ps1.py local/*.ps1 || FAIL=1
fi

echo
echo "== الجافاسكربت =="
if command -v node > /dev/null; then
    for f in site/assets/*.js site/sw.js; do
        [ -f "$f" ] || continue
        node --check "$f" > /dev/null 2>&1 && echo "  ✅ $f" || { echo "  ❌ $f"; FAIL=1; }
    done
else
    echo "  (node مش متاح — اتخطى)"
fi

echo
echo "== ترميز ملفات باورشيل =="
# القاعدة مش «ASCII خالص» — دي كانت تقريب غلط أول مرة.
# باورشيل بيقرا الملف كـANSI لو مفيش BOM. يعني:
#   عربي + BOM      = تمام (وكلاء السكانر شغّالين كده)
#   عربي بدون BOM   = الحروف بتتكسر
#   ASCII بدون BOM  = تمام
for f in local/*.ps1; do
    [ -f "$f" ] || continue
    if LC_ALL=C grep -q '[^ -~	]' "$f"; then
        if head -c3 "$f" | grep -q $'\xef\xbb\xbf'; then
            echo "  ✅ $f (عربي + BOM)"
        else
            echo "  ❌ $f فيه عربي من غير BOM — الحروف هتتكسر"; FAIL=1
        fi
    else
        echo "  ✅ $f (ASCII)"
    fi
done

echo
echo "== منطق تحليل الموسم =="
if command -v php > /dev/null; then
    php tests/analysis/test_analysis.php > /tmp/an1.log 2>&1 \
        && tail -1 /tmp/an1.log || { cat /tmp/an1.log; FAIL=1; }
    php tests/analysis/test_render.php > /tmp/an2.log 2>&1 \
        && tail -1 /tmp/an2.log || { cat /tmp/an2.log; FAIL=1; }
else
    echo "  (php مش متاح — اتخطى. التنصيب: apt install php-cli php-mbstring php-sqlite3 php-xml)"
fi

echo
[ $FAIL -eq 0 ] && echo "✅ كل الفحوصات عدّت" || echo "❌ فيه فحص فشل — ما ترفعش"
exit $FAIL
