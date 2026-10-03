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

# 🔴 مطب ويندوز: الترمينال بترميز cp1256، وبايثون بيقع بـUnicodeEncodeError أول ما
#    يطبع ✅ أو عربي. الفحص نفسه بيبقى عدّى، الطباعة هي اللي بتفشّله وانت فاكر
#    إن الكود غلط. لازم يبقى على مستوى السكربت كله مش على أمر واحد.
export PYTHONIOENCODING=utf-8

# php مش دايماً في الـPATH على ويندوز. الترتيب: PHP_BIN من البيئة ← اللي في
# الـPATH ← الأماكن المعروفة. اختبارات التحليل بتشغّل php تاني من جوّه،
# وبتستعمل PHP_BINARY يعني نفس النسخة دي بالظبط.
find_php() {
    [ -n "${PHP_BIN:-}" ] && [ -x "$PHP_BIN" ] && { echo "$PHP_BIN"; return; }
    command -v php > /dev/null 2>&1 && { echo php; return; }
    for d in /c/php /c/tools/php /c/xampp/php /c/laragon/bin/php/* /c/laragon/bin/php \
             /d/laragon/bin/php/* /d/laragon/bin/php \
             /d/php /d/php-*/ /d/php*/ /e/php /e/php-*/ \
             "$HOME"/Downloads/php-*/ /c/Users/*/Downloads/php-*/; do
        [ -x "$d/php.exe" ] && { echo "$d/php.exe"; return; }
    done
}
PHP_BIN="$(find_php)"

echo "== بنية PHP =="
# فيه نسختين من نفس الفاحص: check_php.py الأصلية، و check_php.mjs مطابقة ليها بـNode.
#
# 🔴 ساعات python3 اللي في الـPATH بيبقى stub من مايكروسوفت ستور بيرجع فاضي من
#    غير ما يفشل، عشان كده بنجرّبه بأمر حقيقي الأول.
#
# الفحص ده مسك ٣ بقات كانت هتوصل السيرفر يوم 2026-08-27 — قوسين ناقصين وقّعوا
# صفحة كاملة، وعمود مش موجود في جدول. فمينفعش يتخطى في صمت.
if command -v python3 > /dev/null 2>&1 && python3 -c "" > /dev/null 2>&1; then
    python3 tests/check_php.py site/*.php site/includes/*.php || FAIL=1
elif command -v node > /dev/null 2>&1; then
    echo "  (python3 مش شغال — بنستخدم نسخة Node)"
    node tests/check_php.mjs site/*.php site/includes/*.php || FAIL=1
else
    echo "  ❌ لا python3 ولا node — بنية PHP ما اتفحصتش. ما ترفعش."
    FAIL=1
fi

echo
echo "== مفيش إيموجي في الواجهة (السطور الجديدة بس) =="
PY=""
for c in python3 python; do command -v $c > /dev/null 2>&1 && $c -c "" > /dev/null 2>&1 && { PY=$c; break; }; done
if [ -n "$PY" ]; then
    PYTHONIOENCODING=utf-8 $PY tests/check_no_emoji.py || FAIL=1
else
    echo "  ⚠️ مفيش بايثون — فحص الإيموجي ما اتعملش"
fi

echo
echo "== بنية PHP — إشراقة =="
if command -v node > /dev/null 2>&1; then
    node tests/check_php.mjs ashraqa/*.php ashraqa/includes/*.php || FAIL=1
else
    echo "  (node مش متاح — اتخطى)"
fi

echo
echo "== بنية PowerShell =="
# 🔴 وكلاء المزامنة السبعة في local/agents/ (من ٢٤ سبتمبر — كانوا في Bruch Agents/ و local/)
#    والطابعة والسكانر في local/
#    الفحص كان بيشوف local/ بس، فوكلاء الفروع كانوا بيعدّوا من غير
#    أي فحص بنية — وده بان يوم ١١ سبتمبر ٢٠٢٦ لما اتعدّلوا الأربعة
#    في رن واحد.
PS1_FILES=()
for f in local/*.ps1 local/agents/*.ps1; do
    [ -f "$f" ] && PS1_FILES+=("$f")
done
if [ ${#PS1_FILES[@]} -gt 0 ]; then
    python3 tests/check_ps1.py "${PS1_FILES[@]}" || FAIL=1
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
echo "== الجافاسكربت اللي جوّه صفحات PHP =="
# 🔴 الفحص اللي فوق بيشوف ملفات .js بس. الجافاسكربت المكتوب جوّه بلوك
#    <script> في صفحة PHP كان بيعدّي من غير أي فحص — ويوم ١٢ سبتمبر ٢٠٢٦
#    ده وقّع صفحتين كاملتين (`review.php` و`custody.php`): سطر جديد خام
#    جوّه سترنج بعلامة مفردة = SyntaxError = **بلوك <script> كله ميت**،
#    والزراير مابتعملش حاجة من غير ولا رسالة خطأ.
if command -v node > /dev/null 2>&1; then
    node tests/check_inline_js.mjs site/*.php site/includes/*.php \
                                   ashraqa/*.php ashraqa/includes/*.php || FAIL=1
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
for f in "${PS1_FILES[@]}"; do
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
echo "== أسماء الأعمدة في SQL =="
# حادثة ١ سبتمبر: الموقع وقع لما استعلام طلب description من custody_deficits
# والعمود الحقيقي notes. الفحص ده بيمسك أي عمود مش موجود قبل ما يوصل السيرفر.
python3 tests/check_columns.py || FAIL=1

echo
echo "== منطق تحليل الموسم =="
if [ -n "$PHP_BIN" ]; then
    LOG="${TMPDIR:-/tmp}"
    "$PHP_BIN" tests/analysis/test_analysis.php > "$LOG/an1.log" 2>&1         && tail -1 "$LOG/an1.log" || { cat "$LOG/an1.log"; FAIL=1; }
    "$PHP_BIN" tests/analysis/test_render.php > "$LOG/an2.log" 2>&1         && tail -1 "$LOG/an2.log" || { cat "$LOG/an2.log"; FAIL=1; }
else
    echo "  ❌ php مش ملقي — منطق التحليل ما اتفحصش."
    echo "     حط المسار الكامل في PHP_BIN، أو ضيف فولدر php.exe للـPATH."
    FAIL=1
fi

echo
echo "== اختبارات منطق الفلوس =="
if [ -f "/d/laragon/www/config.php" ]; then
    bash tests/local/sync_to_laragon.sh > /dev/null 2>&1 || {
        echo "  ❌ فشلت مزامنة الكود إلى Laragon قبل الاختبارات"
        FAIL=1
    }
fi

if [ -n "$PHP_BIN" ]; then
    LOG="${TMPDIR:-/tmp}"
    "$PHP_BIN" tests/finance/test_salary.php > "$LOG/sal.log" 2>&1         && tail -1 "$LOG/sal.log" || { cat "$LOG/sal.log"; FAIL=1; }
    "$PHP_BIN" tests/finance/test_distribution.php > "$LOG/dist.log" 2>&1 && tail -1 "$LOG/dist.log" || { cat "$LOG/dist.log"; FAIL=1; }
    "$PHP_BIN" tests/finance/test_custody.php > "$LOG/cust.log" 2>&1      && tail -1 "$LOG/cust.log" || { cat "$LOG/cust.log"; FAIL=1; }
else
    echo "  ❌ php مش ملقي — اختبارات منطق الفلوس ما اتفحصتش."
    echo "     حط المسار الكامل في PHP_BIN، أو ضيف فولدر php.exe للـPATH."
    FAIL=1
fi

echo
[ $FAIL -eq 0 ] && echo "✅ كل الفحوصات عدّت" || echo "❌ فيه فحص فشل — ما ترفعش"
exit $FAIL
