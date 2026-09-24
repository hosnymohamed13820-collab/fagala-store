#!/usr/bin/env bash
# =====================================================================
#  srvq.sh — استعلام **قراية بس** على قاعدة السيرفر الحقيقية
# ---------------------------------------------------------------------
#  الاستعمال:  bash tests/local/srvq.sh "SELECT id, name FROM suppliers WHERE is_active = 0"
#              bash tests/local/srvq.sh "SHOW COLUMNS FROM agent_status" ashraqa
#
#  ليه: كل مرة كنا بنكتب سكربت PHP مؤقت ونبعته بـssh عشان نبص على داتا
#  (٢٤ سبتمبر ٢٠٢٦ — ٦ مرات في يوم واحد).
#  🔴 SELECT / SHOW / DESCRIBE / EXPLAIN بس، وأمر واحد (مفيش «;» في النص).
#     الكتابة على السيرفر محمد بيعملها بإيده من phpMyAdmin — مش من هنا.
#  الخرج: سطر عناوين + صف لكل نتيجة، مفصولين بـ « | ». أقصى ٥٠٠ صف.
# =====================================================================
set -uo pipefail

SQL="${1:-}"; SITE="${2:-fagala}"
[ -z "$SQL" ] && { echo "❌ الاستعمال: bash tests/local/srvq.sh \"SELECT ...\" [ashraqa]"; exit 1; }

# حارس القراية: أول كلمة لازم تكون قراية، ومفيش أمر تاني بعد «;»
first="$(echo "$SQL" | sed -E 's/^[[:space:](]*//' | awk '{print toupper($1)}')"
case "$first" in
    SELECT|SHOW|DESCRIBE|DESC|EXPLAIN) ;;
    *) echo "❌ قراية بس (SELECT/SHOW/DESCRIBE/EXPLAIN) — الكتابة من phpMyAdmin"; exit 1 ;;
esac
if echo "$SQL" | sed -E 's/;[[:space:]]*$//' | grep -q ';'; then
    echo "❌ أمر واحد بس — مفيش «;» في النص"; exit 1
fi
if echo "$SQL" | grep -qiE '\b(INTO[[:space:]]+(OUT|DUMP)FILE|FOR[[:space:]]+UPDATE|LOCK[[:space:]]+IN)\b'; then
    echo "❌ ممنوع INTO OUTFILE / FOR UPDATE"; exit 1
fi

SSH_KEY="${FAGALA_SSH_KEY:-$HOME/.ssh/fagala_hostinger}"
SSH_HOST="${FAGALA_SSH_HOST:-u834909335@91.108.101.22}"
SSH_PORT="${FAGALA_SSH_PORT:-65002}"
DIR="domains/hosnimanagement.com/public_html"
[ "$SITE" = "ashraqa" ] && DIR="$DIR/ashraqa"

# الـSQL بيتبعت base64 عشان مفيش مشاكل علامات تنصيص في الطريق
B64="$(printf '%s' "$SQL" | base64 -w0)"

ssh -i "$SSH_KEY" -p "$SSH_PORT" -o BatchMode=yes -o LogLevel=ERROR "$SSH_HOST" "cd $DIR && php" <<PHP 2>&1 | grep -v '^\*\*'
<?php
require 'config.php';
\$pdo = db();
\$pdo->exec('SET SESSION TRANSACTION READ ONLY');
\$pdo->beginTransaction();
try {
    \$st = \$pdo->query(base64_decode('$B64'));
    \$n = 0;
    while (\$r = \$st->fetch(PDO::FETCH_ASSOC)) {
        if (\$n === 0) echo implode(' | ', array_keys(\$r)), "\n";
        echo implode(' | ', array_map(fn(\$v) => \$v === null ? 'NULL' : (string) \$v, \$r)), "\n";
        if (++\$n >= 500) { echo "… (وقفت عند ٥٠٠ صف)\n"; break; }
    }
    if (\$n === 0) echo "(مفيش صفوف)\n";
} catch (Throwable \$e) {
    echo "❌ ", \$e->getMessage(), "\n";
} finally {
    \$pdo->rollBack();
}
PHP
