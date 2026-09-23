#!/usr/bin/env bash
# =====================================================================
#  bump_cache.sh — يزوّد أرقام الكاش في الموقعين مرة واحدة
# ---------------------------------------------------------------------
#  بعد أي تعديل JS/CSS لازم يتزوّد ?v= بتاع الملف في كل صفحة بتناديه
#  + VERSION في sw.js + sw.js?v= (٤ أماكن). بالإيد كان بيقع فيها:
#  موقع اتحدّث والتاني لأ، أو VERSION اتزوّد و?v= لأ.
#
#  الاستعمال:
#     bash tests/local/bump_cache.sh "وصف قصير" assets/fagala.css assets/app.js
#     bash tests/local/bump_cache.sh "وصف قصير"            ← sw بس
#
#  - كل ملف: الرقم الجديد = أكبر رقم ?v= موجود له في site/ وashraqa/ + 1،
#    ويتكتب في **كل** مكان بيناديه في الموقعين (فالقديم المنسي بيتلم كمان).
#  - sw.js: VERSION + 1 في الموقعين، والوصف بيتضاف في أول التعليق،
#    وكل sw.js?v= بيبقى نفس الرقم.
#  🔴 مابينسخش أي ملف بين الموقعين — النسخ بقايمة البريف بس.
# =====================================================================
set -euo pipefail
cd "$(dirname "$0")/../.."

MSG="${1:-}"
[ -z "$MSG" ] && { echo "❌ لازم وصف: bash tests/local/bump_cache.sh \"وصف\" [assets/x.css ...]"; exit 1; }
shift

php_files() { grep -rl --include=*.php -- "$1" site ashraqa 2>/dev/null || true; }

for asset in "$@"; do
    base="${asset#./}"
    files="$(php_files "$base?v=")"
    [ -z "$files" ] && { echo "❌ مفيش صفحة بتنادي $base?v="; exit 1; }
    max="$(grep -rhoE --include=*.php -- "$(printf '%s' "$base" | sed 's/[.]/\\./g')\?v=[0-9]+" site ashraqa | grep -oE '[0-9]+$' | sort -n | tail -1)"
    new=$((max + 1))
    ASSET="$base" NEW="$new" perl -pi -e 's/\Q$ENV{ASSET}\E\?v=\d+/$ENV{ASSET}?v=$ENV{NEW}/g' $files
    echo "✅ $base  v=$max → v=$new  ($(echo "$files" | wc -l) ملف)"
done

# ----- sw.js -----
cur_site="$(grep -oE "const VERSION = 'v[0-9]+'" site/sw.js | grep -oE '[0-9]+')"
cur_ash="$(grep -oE "const VERSION = 'v[0-9]+'" ashraqa/sw.js | grep -oE '[0-9]+')"
if [ "$cur_site" != "$cur_ash" ]; then
    echo "❌ VERSION مختلف: site=v$cur_site ashraqa=v$cur_ash — ظبطهم بإيدك الأول"; exit 1
fi
new=$((cur_site + 1))
for f in site/sw.js ashraqa/sw.js; do
    # بايتات زي ما هي (من غير -CSD) — وإلا الوصف العربي بيتكتب مكسّر
    OLD="$cur_site" NEW="$new" MSG="$MSG" perl -pi -e \
        's/const VERSION = \x27v$ENV{OLD}\x27; \/\/ /const VERSION = \x27v$ENV{NEW}\x27; \/\/ v$ENV{NEW}: $ENV{MSG} | /' "$f"
done
swfiles="$(php_files "sw.js?v=")"
NEW="$new" perl -pi -e 's/sw\.js\?v=\d+/sw.js?v=$ENV{NEW}/g' $swfiles
echo "✅ sw.js  v$cur_site → v$new  (VERSION في الموقعين + $(echo "$swfiles" | wc -l) ملف بينادي sw.js)"

# ----- تأكيد -----
bad=0
for f in site/sw.js ashraqa/sw.js; do
    grep -q "const VERSION = 'v$new'; // v$new: " "$f" || { echo "❌ $f مااتحدّثش"; bad=1; }
done
others="$(grep -rhoE --include=*.php 'sw\.js\?v=[0-9]+' site ashraqa | sort -u)"
[ "$others" = "sw.js?v=$new" ] || { echo "❌ sw.js?v= مش موحّد: $others"; bad=1; }
[ $bad -eq 0 ] && echo "✅ كله متسق"
exit $bad
