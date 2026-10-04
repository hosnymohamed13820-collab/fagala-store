#!/usr/bin/env bash
# =====================================================================
#  check_migrations.sh — فحص ملفات local/migrations (بريف ١٠٨)
#  ١) المانيفست مطابق للملفات  ٢) آخر سطر بيسجّل الملف باسمه  ٣) مفيش اسم قاعدة
# =====================================================================
set -u
cd "$(dirname "$0")/.." || exit 1
FAIL=0
MAN="site/migrations_manifest.php"

want="$(ls local/migrations/*.sql 2>/dev/null | sort | xargs -n1 basename 2>/dev/null | sed 's/\.sql$//')"
have="$(grep -oE "^    '[^']+'" "$MAN" 2>/dev/null | tr -d " '")"
if [ "$want" != "$have" ]; then
    echo "  ❌ المانيفست قديم — شغّل: bash tests/local/migrations_manifest.sh"
    FAIL=1
fi

for f in local/migrations/*.sql; do
    [ -f "$f" ] || continue
    n="$(basename "$f" .sql)"
    last="$(grep -v '^[[:space:]]*$' "$f" | tail -1 | tr -d '\r')"
    if ! echo "$last" | grep -qiE "INSERT IGNORE INTO \`?schema_migrations\`? .*'$n'"; then
        echo "  ❌ $f — آخر سطر لازم: INSERT IGNORE INTO schema_migrations (name) VALUES ('$n');"
        FAIL=1
    fi
    if grep -q "u834909335" "$f"; then
        echo "  ❌ $f — فيه اسم القاعدة. شيله (الملف بيتشغّل على القاعدة المفتوحة)"
        FAIL=1
    fi
done

[ "$FAIL" -eq 0 ] && echo "  ✅ ملفات القاعدة ($(echo "$want" | grep -c .)) والمانيفست مظبوطين"
exit "$FAIL"
