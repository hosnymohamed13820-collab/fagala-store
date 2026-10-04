#!/usr/bin/env bash
# =====================================================================
#  migrations_manifest.sh — بيكتب site/migrations_manifest.php من local/migrations/*.sql
#  (بريف ١٠٨). الملف ده بيترفع مع الموقع، وصفحة «المراقبة» بتقارنه بجدول
#  schema_migrations عشان تقول لمحمد أنهي ملف مستني يتشغّل.
#  الاستعمال: bash tests/local/migrations_manifest.sh
# =====================================================================
set -euo pipefail
cd "$(dirname "$0")/../.."
OUT="site/migrations_manifest.php"
{
    echo "<?php"
    echo "// بيتولّد من tests/local/migrations_manifest.sh — متعدّلوش بإيدك."
    echo "return ["
    for f in $(ls local/migrations/*.sql 2>/dev/null | sort); do
        echo "    '$(basename "$f" .sql)',"
    done
    echo "];"
} > "$OUT"
echo "✅ $OUT — $(grep -c "^    '" "$OUT") ملف"
