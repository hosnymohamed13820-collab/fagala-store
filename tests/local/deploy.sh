#!/usr/bin/env bash
# =====================================================================
#  deploy.sh — رفع الملفات المتغيّرة للسيرفر بأمر واحد (عن طريق WinSCP)
#
#  ليه مش زرار Synchronize في WinSCP:
#    الجلسة المحفوظة مالهاش أي استثناء (FileMask = *.*).
#    • site/config.php وهمي ٣٠ بايت — Synchronize بيكتبه فوق الحقيقي
#      والموقع كله بيقف. **حصل ٣٠ أغسطس ٢٠٢٦** (RULES §٨).
#    • ولو «مسح الملفات» متعلّم، فولدر ashraqa/ (نظام إشراقة كله) بيتمسح
#      لأنه مش موجود في site/.
#
#  السكربت ده:
#    ١) بيحسب الملفات اللي **محتواها** مختلف عن public_html/ (نسخة الميرورينج)
#    ٢) 🔴 مستحيل يرفع config.php — مش في القايمة أصلاً، وفيه فحص صريح كمان
#    ٣) 🔴 مابيمسحش أي حاجة على السيرفر — put بس
#    ٤) بيشغّل tests/run_all.sh الأول — لو فشل مابيرفعش
#    ٥) بيرفع **الملفات الجديدة الأول**: auth.php بيعمل require لـerrlog_lib.php؛
#       لو auth.php وصل قبله الموقع كله بيقف لحد ما التاني يوصل
#    ٦) بعد الرفع بينزّل نفس الملفات تاني في public_html/ ويقارنها — لو
#       ملف وصل ناقص بيقول
#
#  الاستعمال:
#    bash tests/local/deploy.sh          ← يعرض القايمة بس (من غير رفع)
#    bash tests/local/deploy.sh --yes    ← يرفع
# =====================================================================
set -u
cd "$(dirname "$0")/../.."

WINSCP="/c/Program Files (x86)/WinSCP/WinSCP.com"
# الجلسة: **Site محفوظ** في WinSCP مش Workspace.
# ⚠️ اتجرّب ١٥ سبتمبر ٢٠٢٦: `open "My Workspace/0000"` بيطلّع
#    «Host "My Workspace" does not exist» — السكربتات مابتفتحش جلسات الـWorkspace.
#    لازم Site متسجّل بالاسم ده (Login → New Site → Save as → fagala-ftp،
#    وعلّم Save password).
SESSION="${WINSCP_SITE:-fagala-ftp}"
REMOTE="/domains/hosnimanagement.com/public_html"
SNAP="public_html"                                  # نسخة الميرورينج

GO=0; [ "${1:-}" = "--yes" ] && GO=1

[ -x "$WINSCP" ] || { echo "❌ مش لاقي WinSCP.com في $WINSCP"; exit 1; }
[ -d "$SNAP" ]   || { echo "❌ مفيش $SNAP/ — اعمل ميرورينج الأول"; exit 1; }

# اتأكد إن الـSite موجود في WinSCP قبل أي حاجة (من غير اتصال)
SITE_KEY="HKCU:\\Software\\Martin Prikryl\\WinSCP 2\\Sessions\\"
if ! powershell -NoProfile -Command "exit [int](-not (Test-Path ('$SITE_KEY' + [uri]::EscapeDataString('$SESSION'))))" 2>/dev/null; then
    echo "❌ مفيش Site اسمه «$SESSION» في WinSCP."
    echo "   افتح WinSCP → New Site → نفس بيانات السيرفر → Save → الاسم: $SESSION → علّم Save password"
    [ "${1:-}" = "--yes" ] && exit 1
fi

# ---------- ١) القايمة ----------
NEW=(); CHANGED=()
while IFS= read -r f; do
    rel="${f#site/}"
    case "$rel" in
        config.php|config.php.*)            continue ;;   # 🔴 عمره ما يترفع
        uploads/*|data/*|downloads/*|logs/*) continue ;;   # بتاعة السيرفر
    esac
    if [ ! -f "$SNAP/$rel" ]; then
        NEW+=("$rel")
    elif ! cmp -s "$f" "$SNAP/$rel"; then
        CHANGED+=("$rel")
    fi
done < <(git ls-files --cached --others --exclude-standard site/)

# حزام أمان تاني — لو config.php دخل القايمة بأي طريقة
for x in "${NEW[@]:-}" "${CHANGED[@]:-}"; do
    case "$(basename "$x")" in config.php|config.php.*)
        echo "❌🔴 config.php في القايمة — وقفت. ده مايحصلش أبداً، شوف السكربت."; exit 1 ;;
    esac
done

total=$(( ${#NEW[@]} + ${#CHANGED[@]} ))
if [ "$total" -eq 0 ]; then
    echo "✅ مفيش حاجة تترفع — site/ مطابق لـ$SNAP/"
    echo "   (لو رفعت بإيدك من غير ميرورينج، $SNAP/ ممكن يكون قديم)"
    exit 0
fi

echo "═══ هيترفع ($total) ═══"
for x in "${NEW[@]:-}";     do [ -n "$x" ] && echo "  🆕 $x"; done
for x in "${CHANGED[@]:-}"; do [ -n "$x" ] && echo "  ↻  $x"; done
echo "  🔒 config.php مستثنى · مفيش مسح على السيرفر"

if [ "$GO" -ne 1 ]; then
    echo; echo "ده عرض بس. للرفع:  bash tests/local/deploy.sh --yes"
    exit 0
fi

# ---------- ٢) الفحوصات قبل الرفع ----------
echo; echo "═══ الفحوصات ═══"
export PYTHONIOENCODING=utf-8
# run_all.sh من غير php بيفشل — وده بيمنع الرفع وانت فاكر إن الكود غلط
export PHP_BIN="${PHP_BIN:-/d/php-8.5.10-nts-Win32-vs17-x64/php.exe}"
if ! bash tests/run_all.sh > /tmp/deploy_checks.log 2>&1; then
    tail -20 /tmp/deploy_checks.log
    echo "❌ فيه فحص فشل — مارفعتش حاجة."; exit 1
fi
echo "  ✅ كل الفحوصات عدّت"

# ---------- ٣) سكربت WinSCP ----------
winpath() { cygpath -w "$1"; }
SCRIPT="$(mktemp -t deploy_XXXX.txt)"
{
    echo "option batch abort"
    echo "option confirm off"
    echo "open \"$SESSION\""
    # الجداد الأول (auth.php بيعتمد على errlog_lib.php)
    for x in "${NEW[@]:-}" "${CHANGED[@]:-}"; do
        [ -n "$x" ] || continue
        echo "put -nopreservetime \"$(winpath "site/$x")\" \"$REMOTE/$x\""
    done
    # نزّلهم تاني في النسخة المحلية — ده اللي بيأكّد إنهم وصلوا كاملين
    for x in "${NEW[@]:-}" "${CHANGED[@]:-}"; do
        [ -n "$x" ] || continue
        mkdir -p "$SNAP/$(dirname "$x")"
        echo "get \"$REMOTE/$x\" \"$(winpath "$SNAP/$x")\""
    done
    echo "exit"
} > "$SCRIPT"

echo; echo "═══ الرفع ═══"
if ! "$WINSCP" /script="$(winpath "$SCRIPT")" /log="$(winpath /tmp/deploy_winscp.log)"; then
    rm -f "$SCRIPT"
    echo "❌ WinSCP وقف في النص. اللوج: /tmp/deploy_winscp.log"
    echo "   🔴 شوف اتنزّل إيه وماتنزلش إيه قبل ما تعيد."
    exit 1
fi
rm -f "$SCRIPT"

# ---------- ٤) التأكيد ----------
echo; echo "═══ التأكيد ═══"
bad=0
for x in "${NEW[@]:-}" "${CHANGED[@]:-}"; do
    [ -n "$x" ] || continue
    if cmp -s "site/$x" "$SNAP/$x"; then echo "  ✅ $x"; else echo "  🔴 $x — اللي على السيرفر مش مطابق"; bad=1; fi
done
[ "$bad" -eq 0 ] && echo "✅ $total ملف اترفعوا واتأكد إنهم وصلوا كاملين" || { echo "❌ فيه ملف مش مطابق"; exit 1; }
