#!/usr/bin/env bash
# =====================================================================
#  land_check.sh — الفحوصات الثابتة بعد ما وكيل يخلّص بريف (قبل الكوميت)
# ---------------------------------------------------------------------
#  الاستعمال:  bash tests/local/land_check.sh 45            ← + run_all
#              bash tests/local/land_check.sh 45 --no-gate
#              bash tests/local/land_check.sh 45 --restore  ← رجّع ملفات site/ الناقصة من Laragon
#
#  ١) git status مطابق لبلوك «المتوقع بالحرف» في .briefs/briefN.md؟
#  ٢) ملف في ashraqa/ اتنسخ فوقه نسخة site/ وهو كان مختلف؟ (حصلت في ٤٤)
#  ٣) أرقام الكاش متسقة في الموقعين؟ (VERSION · sw.js?v= · كل asset?v=)
#  ٤) tests/run_all.sh
#  🔴 مابيعملش كوميت ولا رفع. الكوميت بمسارات البريف بس.
# =====================================================================
set -uo pipefail
cd "$(dirname "$0")/../.."

N="${1:-}"; GATE=1; RESTORE=0
[ "${2:-}" = "--no-gate" ] && GATE=0
[ "${2:-}" = "--restore" ] && RESTORE=1
LARAGON="${LARAGON_WWW:-/d/laragon/www}"
BRIEF=".briefs/brief$N.md"
[ -z "$N" ] || [ ! -f "$BRIEF" ] && { echo "❌ الاستعمال: bash tests/local/land_check.sh <رقم البريف>"; exit 1; }
FAIL=0

echo "═══ ١) الملفات: المتوقع vs الموجود ═══"
EXP="$(awk '/المتوقع \*\*بالحرف/{f=1;next} f&&/^```/{n++; if(n==2)exit; next} f&&n==1' "$BRIEF" \
      | sed -E 's/^ ?([MDA?]{1,2}) +"?([^"]+)"?$/\1 \2/' | grep -v '^$' | sort)"
ACT="$(git status --porcelain | sed -E 's/^ ?([MDA?]{1,2}) +"?([^"]+)"?$/\1 \2/' | sort)"
if [ -z "$EXP" ]; then
    echo "  ⚠️ مالقيتش بلوك «المتوقع بالحرف» في $BRIEF"
elif [ "$EXP" = "$ACT" ]; then
    echo "  ✅ مطابق ($(echo "$ACT" | wc -l) ملف)"
else
    echo "  ❌ مختلف:"
    diff <(echo "$EXP") <(echo "$ACT") | grep '^[<>]' | sed 's/^</  ناقص:/; s/^>/  زيادة:/'
    FAIL=1

    # 🔴 حصلت مرتين (٥٢ و٥٣): الوكيل خلص وملفات site/ رجعت زي HEAD (Reject في لوحته؟)
    #    بس نسخته لسه في Laragon لأنه عمل sync قبل الاختبار.
    MISSING="$(diff <(echo "$EXP") <(echo "$ACT") | grep '^< ' | sed 's/^< //')"
    while read -r st path; do
        [ -z "$path" ] && continue
        case "$path" in
            site/*)
                rel="${path#site/}"
                if [ -f "$LARAGON/$rel" ] && ! cmp -s "$LARAGON/$rel" "$path" 2>/dev/null; then
                    if [ $RESTORE -eq 1 ]; then
                        cp "$LARAGON/$rel" "$path" && echo "  ↺ $path ← من Laragon (راجع الديف)"
                    else
                        echo "  💡 $path — Laragon فيه نسخة مختلفة. رجّعها بـ: --restore"
                    fi
                fi ;;
            ashraqa/*) echo "  ✋ $path — مش في Laragon، اعمل تعديله بإيدك" ;;
            *)         echo "  ✋ $path — اكتبه تاني من البريف" ;;
        esac
    done <<< "$MISSING"
fi

echo; echo "═══ ٢) ملفات إشراقة اللي بقت نسخة من الفجالة ═══"
found=0
for p in $(git status --porcelain | awk '{print $2}' | grep '^ashraqa/'); do
    rel="${p#ashraqa/}"
    [ -f "site/$rel" ] || continue
    git cat-file -e "HEAD:site/$rel" 2>/dev/null && git cat-file -e "HEAD:ashraqa/$rel" 2>/dev/null || continue
    if ! diff -q <(git show "HEAD:site/$rel") <(git show "HEAD:ashraqa/$rel") >/dev/null \
       && diff -q "site/$rel" "ashraqa/$rel" >/dev/null; then
        # كانوا مختلفين في HEAD وبقوا متطابقين. آخر كوميت كانوا فيه متطابقين؟
        last_same=""
        for c in $(git log --format=%h -n 15 -- "site/$rel" "ashraqa/$rel"); do
            if diff -q <(git show "$c:site/$rel" 2>/dev/null) <(git show "$c:ashraqa/$rel" 2>/dev/null) >/dev/null; then last_same="$c"; break; fi
        done
        n="$(diff <(git show "HEAD:site/$rel") <(git show "HEAD:ashraqa/$rel") | grep -c '^[<>]')"
        if [ -n "$last_same" ]; then
            echo "  ⚠️ $rel — مختلفين في HEAD بـ$n سطر، بس كانوا متطابقين في $last_same ← غالباً فرق من بريف لمس site بس. راجع"
        else
            echo "  ❌ $rel — كانوا مختلفين دايماً ($n سطر) وبقوا نسخة واحدة ← اتنسخ فوق إشراقة"
            FAIL=1
        fi
        found=1
    fi
done
[ $found -eq 0 ] && echo "  ✅ مفيش"

echo; echo "═══ ٣) أرقام الكاش ═══"
vs="$(grep -oE "const VERSION = 'v[0-9]+'" site/sw.js | grep -oE '[0-9]+')"
[ -f site/includes/site_profile.php ] || { echo "site profile missing"; FAIL=1; }
sw="$(grep -rhoE --include=*.php 'sw\.js\?v=[0-9]+' site | sort -u | tr '\n' ' ')"
[ "$sw" = "sw.js?v=$vs " ] || { echo "sw.js version mismatch: $sw"; FAIL=1; }
while read -r a; do
    vals="$(grep -rhoE --include=*.php "$(printf '%s' "$a" | sed 's/[.]/\\./g')\?v=[0-9]+" site | sort -u | tr '\n' ' ')"
    [ "$(echo "$vals" | wc -w)" -gt 1 ] && echo "  ⚠️ $a مش موحّد: $vals"
done < <(grep -rhoE --include=*.php 'assets/[A-Za-z0-9_./-]+\.(css|js)\?v=' site | sed 's/?v=$//' | sort -u)
changed_assets="$(git status --porcelain | awk '{print $2}' | grep -E '^site/assets/.*\.(css|js)$' || true)"
if [ -n "$changed_assets" ] && git diff --quiet -- site/sw.js; then
    echo "  ❌ فيه CSS/JS اتغيّر و sw.js مااتلمسش ← bash tests/local/bump_cache.sh \"وصف\" <الملفات>"
    FAIL=1
fi

if [ $GATE -eq 1 ]; then
    echo; echo "═══ ٤) run_all ═══"
    export PYTHONIOENCODING=utf-8
    bash tests/run_all.sh > "${TMPDIR:-/tmp}/land_gate.log" 2>&1 && echo "  ✅ كل الفحوصات عدّت" \
        || { grep -E '❌|Error|error' "${TMPDIR:-/tmp}/land_gate.log" | head -15; FAIL=1; }
fi

echo
[ $FAIL -eq 0 ] && echo "✅ جاهز للكوميت — بمسارات البريف بس" || echo "❌ فيه حاجة محتاجة تتشاف قبل الكوميت"
exit $FAIL
