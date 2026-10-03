#!/usr/bin/env bash
# =====================================================================
#  daily_backup.sh — نسخة احتياطية يومية من السيرفر لجهاز محمد
# ---------------------------------------------------------------------
#  طلب محمد ٣ أكتوبر ٢٠٢٦: «لو حصل حاجة نرجّع الداتا، أو الاستضافة وقعت
#  نرفع على استضافة تانية في نفس اليوم». قبلها ماكانش فيه أي باك أب
#  أوتوماتيك — نسخة يدوية واحدة على D: من بعد صرف المرتبات.
#
#  بيسحب:
#    db_fagala.sql.gz    القاعدة كاملة (mysqldump --single-transaction)
#    db_ashraqa.sql.gz   قاعدة إشراقة
#    config_uploads.tar.gz   config.php للموقعين + صور المهام (uploads/tasks).
#                            الكود نفسه مش هنا — في الجيت، وبيترفع بـdeploy.sh.
#
#  🔴 النسخة فيها config.php = باسوردات القاعدة. المكان بره الريبو عن قصد
#     (D:\fagala_backups) — متنقلهوش جوه المشروع ومتبعتهوش لحد.
#  🔴 الباسورد مابيطلعش في أي خرج ولا لوج: PHP على السيرفر بيكتبه في ملف
#     مؤقت 0600 لـmysqldump ويمسحه.
#
#  الاحتفاظ: آخر ٣٠ يوم + يوم ١ من كل شهر للأبد.
#  التشغيل: Task Scheduler كل يوم ٣ الفجر، ولو الجهاز كان مقفول بيتشغّل
#  أول ما يفتح (install_task.ps1). أو بإيدك: bash local/backup/daily_backup.sh
# =====================================================================
set -uo pipefail

DEST_ROOT="${FAGALA_BACKUP_DIR:-/d/fagala_backups}"
SSH_KEY="${FAGALA_SSH_KEY:-$HOME/.ssh/fagala_hostinger}"
SSH_HOST="${FAGALA_SSH_HOST:-u834909335@91.108.101.22}"
SSH_PORT="${FAGALA_SSH_PORT:-65002}"
BASE="domains/hosnimanagement.com/public_html"
KEEP_DAYS=30

DAY="$(date +%Y-%m-%d)"
DEST="$DEST_ROOT/$DAY"
LOG="$DEST_ROOT/backup.log"
mkdir -p "$DEST"

log() { echo "$(date '+%Y-%m-%d %H:%M:%S') $*" | tee -a "$LOG"; }
SSH=(ssh -i "$SSH_KEY" -p "$SSH_PORT" -o BatchMode=yes -o ConnectTimeout=30 -o LogLevel=ERROR "$SSH_HOST")
FAIL=0

# ---------- القاعدة ----------
# الاستضافة قافلة exec/passthru في PHP — فـPHP بيكتب ملف الدخول المؤقت (0600)
# ويطبع مكانه واسم القاعدة بس، والشِل هو اللي بيشغّل mysqldump ويمسح الملف.
# 🔴 --defaults-file مش --defaults-extra-file: فيه ~/.my.cnf على السيرفر بيتقري بعد
#    الـextra وبيرجّع يوزر الفجالة، فإشراقة كانت بتطلع «Access denied» (٣ أكتوبر).
# ob_start قبل config.php: لو طبع أي حاجة (مسافة، BOM) كانت هتلخبط السطر ده.
dump_db() {
    local name="$1" dir="$2" out="$DEST/db_$1.sql.gz"
    "${SSH[@]}" "bash -s -- '$dir'" > "$out.part" 2>> "$LOG" <<'SH'
cd "$1" || exit 90
# مفيش process substitution على الاستضافة (/dev/fd مش موجود) — فـ$(…)
out=$(php -r '
ob_start(); require "config.php"; ob_end_clean();
$f = tempnam(sys_get_temp_dir(), "bk"); chmod($f, 0600);
file_put_contents($f, "[client]
user=" . DB_USER . "
password=\"" . addcslashes(DB_PASS, "\"\\") . "\"
host=" . DB_HOST . "
");
echo $f, " ", DB_NAME;')
f="${out%% *}"; db="${out#* }"
# الملف فيه الباسورد — يتمسح مهما حصل (أول تجربة سابت ٦ ملفات في /tmp)
trap 'rm -f "$f"' EXIT
[ -n "${f:-}" ] && [ -n "${db:-}" ] || exit 91
mysqldump --defaults-file="$f" --single-transaction --quick --routines --triggers     --no-tablespaces --default-character-set=utf8mb4 "$db" | gzip -6
rc=${PIPESTATUS[0]}
exit $rc
SH
    local rc=$?
    # الفحص: الملف سليم + mysqldump وصل لآخره + عدد جداول معقول
    if [ $rc -ne 0 ] || ! gzip -t "$out.part" 2>/dev/null; then
        log "❌ $name: الملف بايظ أو اتقطع (rc=$rc)"; rm -f "$out.part"; FAIL=1; return
    fi
    if ! gzip -dc "$out.part" | tail -c 300 | grep -q "Dump completed"; then
        log "❌ $name: الـdump ماوصلش لآخره"; FAIL=1
    fi
    local tables; tables=$(gzip -dc "$out.part" | grep -c "^CREATE TABLE")
    if [ "$tables" -lt 10 ]; then
        log "❌ $name: $tables جدول بس — في حاجة غلط"; FAIL=1
    fi
    mv "$out.part" "$out"
    log "✅ $name: $tables جدول · $(du -h "$out" | cut -f1)"
}

# ---------- اللي على السيرفر ومش في الجيت ----------
# محمد: «ماتسحبش ملفات الموقع — الكود عندنا ونرفعه في أي وقت، الداتا هي المشكلة».
# بس دول مش في الجيت، ومن غيرهم الموقع مايشتغلش على استضافة تانية:
#   config.php للموقعين (بيانات القاعدة + دوال الموقع) · صور المهام اللي اترفعت.
dump_extras() {
    local out="$DEST/config_uploads.tar.gz"
    "${SSH[@]}" "cd $BASE && tar czf - config.php ashraqa/config.php \$(ls -d uploads/tasks 2>/dev/null)" > "$out.part" 2>> "$LOG"
    if [ $? -ne 0 ] || ! gzip -t "$out.part" 2>/dev/null; then
        log "❌ config والصور: الأرشيف بايظ"; rm -f "$out.part"; FAIL=1; return
    fi
    local list; list="$(tar tzf "$out.part" 2>/dev/null)"
    if ! grep -qx "config.php" <<< "$list" || ! grep -qx "ashraqa/config.php" <<< "$list"; then
        log "❌ config.php ناقص من الأرشيف"; FAIL=1
    fi
    mv "$out.part" "$out"
    log "✅ config + صور المهام: $(grep -c . <<< "$list") ملف · $(du -h "$out" | cut -f1)"
}

log "— بداية الباك أب ($DAY)"
dump_db fagala "$BASE"
dump_db ashraqa "$BASE/ashraqa"
dump_extras

# ---------- الاحتفاظ ----------
# بنمسح بس فولدرات بشكل تاريخ (YYYY-MM-DD) أقدم من ٣٠ يوم، ومش يوم ١ في الشهر.
cutoff="$(date -d "-$KEEP_DAYS days" +%Y-%m-%d)"
for d in "$DEST_ROOT"/????-??-??; do
    [ -d "$d" ] || continue
    n="$(basename "$d")"
    [[ "$n" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] || continue
    [[ "$n" == *-01 ]] && continue
    [[ "$n" < "$cutoff" ]] && { rm -rf "$d"; log "🧹 اتمسح $n"; }
done

if [ $FAIL -eq 0 ]; then
    date '+%Y-%m-%d %H:%M:%S' > "$DEST_ROOT/LAST_OK"
    log "✅ خلص"
else
    log "❌ خلص بأخطاء — النسخة دي مش مضمونة"
    exit 1
fi
