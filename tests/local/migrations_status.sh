#!/usr/bin/env bash
# =====================================================================
#  migrations_status.sh — أنهي ملف في local/migrations لسه ماتشغّلش (بريف ١٠٨)
#  الاستعمال: bash tests/local/migrations_status.sh           ← السيرفر (قراية بس عن طريق srvq.sh)
#             bash tests/local/migrations_status.sh --local   ← Laragon
# =====================================================================
set -uo pipefail
cd "$(dirname "$0")/../.."
if [ "${1:-}" = "--local" ]; then
    M="/d/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe"
    applied="$("$M" -uroot -N --default-character-set=utf8mb4 fagala -e "SELECT name FROM schema_migrations" 2>/dev/null | tr -d '\r')"
else
    applied="$(bash tests/local/srvq.sh "SELECT name FROM schema_migrations" 2>/dev/null | tail -n +2 | tr -d '\r ')"
fi
pending=0
for f in $(ls local/migrations/*.sql 2>/dev/null | sort); do
    n="$(basename "$f" .sql)"
    if ! echo "$applied" | grep -qx "$n"; then
        echo "  مستني: $f"
        pending=$((pending + 1))
    fi
done
[ "$pending" -eq 0 ] && echo "✅ مفيش حاجة مستنية" || echo "🔴 $pending ملف محتاج يتشغّل على قاعدة الفجالة بالترتيب ده"
