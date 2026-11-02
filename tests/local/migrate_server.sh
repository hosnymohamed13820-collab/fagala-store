#!/usr/bin/env bash
# =====================================================================
#  migrate_server.sh — تشغيل ملفات local/migrations/ على قاعدة الفجالة (السيرفر)
# ---------------------------------------------------------------------
#  الاستعمال:  bash tests/local/migrate_server.sh local/migrations/20261019_000_x.sql [ملف تاني…]
#              bash tests/local/migrate_server.sh --pending     ← كل المستني بالترتيب
#
#  ليه: محمد ١٩/١٠/٢٠٢٦ «اعمل كل حاجة انت اكتبها عندك قاعدة» — كلود بيشغّل
#  تعديلات القاعدة اللي تخص الموقع بنفسه قبل الرفع، بدل phpMyAdmin.
#  🔴 قاعدة الفجالة، أو إشراقة بـMIGRATE_SITE=ashraqa. مش قاعدة True في الإدارة أبداً.
#  🔴 ملفات local/migrations/ بس (اللي آخرها بيسجّل نفسه في schema_migrations).
#  الملف اللي اتسجّل قبل كده بيتعدّى. أول أمر يفشل ← وقفة (والباقي مايتنفّذش).
# =====================================================================
set -uo pipefail
cd "$(dirname "$0")/../.."

SSH_KEY="${FAGALA_SSH_KEY:-$HOME/.ssh/fagala_hostinger}"
SSH_HOST="${FAGALA_SSH_HOST:-u834909335@91.108.101.22}"
SSH_PORT="${FAGALA_SSH_PORT:-65002}"
DIR="domains/hosnimanagement.com/public_html"
# إشراقة (محمد ٢/١١: الموقعين مع بعض): MIGRATE_SITE=ashraqa bash tests/local/migrate_server.sh <ملف>
[ "${MIGRATE_SITE:-}" = "ashraqa" ] && { DIR="$DIR/ashraqa"; echo "◆ قاعدة إشراقة"; }

files=()
if [ "${1:-}" = "--pending" ]; then
    mapfile -t files < <(bash tests/local/migrations_status.sh 2>/dev/null | sed -n 's/^ *مستني: //p')
    [ ${#files[@]} -eq 0 ] && { echo "✅ مفيش حاجة مستنية"; exit 0; }
else
    files=("$@")
fi
[ ${#files[@]} -eq 0 ] && { echo "❌ الاستعمال: bash tests/local/migrate_server.sh <ملف…> | --pending"; exit 1; }

for f in "${files[@]}"; do
    case "$f" in local/migrations/*.sql) ;; *) echo "❌ $f — ملفات local/migrations/ بس"; exit 1 ;; esac
    [ -f "$f" ] || { echo "❌ $f مش موجود"; exit 1; }
    name="$(basename "$f" .sql)"
    grep -q "INSERT IGNORE INTO schema_migrations (name) VALUES ('$name')" "$f" \
        || { echo "❌ $f — آخره مابيسجّلش نفسه في schema_migrations"; exit 1; }

    echo "▶ $name"
    B64="$(base64 -w0 < "$f")"
    out="$(ssh -i "$SSH_KEY" -p "$SSH_PORT" -o BatchMode=yes -o LogLevel=ERROR "$SSH_HOST" "cd $DIR && php" <<PHP 2>&1 | grep -v '^\*\*'
<?php
require 'config.php';
\$pdo = db();
\$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// إشراقة ماكانش فيها الجدول ده لحد ٢/١١ — نفس هيكل الفجالة بالظبط
\$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (name varchar(120) NOT NULL, applied_at datetime NOT NULL DEFAULT current_timestamp(), PRIMARY KEY (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
\$name = '$name';
\$st = \$pdo->prepare('SELECT 1 FROM schema_migrations WHERE name = ?');
\$st->execute([\$name]);
if (\$st->fetchColumn()) { echo "⏭ اتشغّل قبل كده\n"; exit(0); }
// نشيل التعليقات ونقسّم على ; آخر السطر
\$sql = preg_replace('/^\s*--.*\$/m', '', base64_decode('$B64'));
\$parts = array_filter(array_map('trim', preg_split('/;\s*\$/m', \$sql)), 'strlen');
\$i = 0;
foreach (\$parts as \$q) {
    \$i++;
    try {
        \$n = \$pdo->exec(\$q);
        echo "  ✓ أمر \$i — " . (int) \$n . " صف\n";
    } catch (Throwable \$e) {
        echo "  ✗ أمر \$i فشل: " . \$e->getMessage() . "\n";
        exit(2);
    }
}
echo "✅ خلص\n";
PHP
)"
    echo "$out"
    echo "$out" | grep -q '✗\|❌' && { echo "🔴 وقفت عند $name"; exit 2; }
done
echo
bash tests/local/migrations_status.sh 2>&1 | tail -2
