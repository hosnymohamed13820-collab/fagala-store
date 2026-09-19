#!/usr/bin/env bash
# =====================================================================
#  deploy.sh — رفع الملفات المتغيّرة للسيرفر بأمر واحد (SSH)
#
#  بيرفع **الموقعين**:
#    site/     →  /domains/hosnimanagement.com/public_html
#    ashraqa/  →  /domains/hosnimanagement.com/public_html/ashraqa
#
#  ليه مش WinSCP زي الأول:
#    ١٩ سبتمبر ٢٠٢٦ الرفع وقف على شهادة TLS مش معروفة (*.hstgr.io) —
#    WinSCP سأل سؤال تفاعلي والسكربت مات ومفيش حاجة اترفعت. الـSSH
#    مربوط بمفتاح، مافيش فيه سؤال، وأسرع.
#
#  ليه مش زرار Synchronize في WinSCP (السبب الأصلي، لسه قايم):
#    • config.php الوهمي ٣٠ بايت بيكتب فوق الحقيقي والموقع يقف
#      (**حصل ٣٠ أغسطس ٢٠٢٦** — RULES §٨).
#    • ولو «مسح الملفات» متعلّم، فولدر ashraqa/ بيتمسح بالكامل.
#
#  🔴 الفرق المهم عن النسخة القديمة: المقارنة بقت بـ**بصمة الملف على
#     السيرفر نفسه**، مش بنسخة `public_html/` المحلية. النسخة المحلية
#     بتقدم من غير ما حد ياخد باله (كانت متأخرة ٣ أسابيع في إشراقة)،
#     وساعتها السكربت يا بيرفع ملفات مالهاش لزمة يا بيفوّت ملف متغيّر.
#
#  الضمانات:
#    ١) 🔴 مستحيل يرفع config.php — مستثنى من القايمة وفيه فحص تاني بعدها
#    ٢) 🔴 مابيمسحش أي حاجة على السيرفر — رفع بس
#    ٣) بيشغّل tests/run_all.sh الأول — لو فشل مابيرفعش
#    ٤) بعد الرفع بيقارن البصمات تاني — الملف اللي وصل ناقص بيتمسك
#    ٥) بيشغّل `php -l` على السيرفر لكل ملف PHP اترفع
#    ٦) 🔴 بيقرا **سجل أخطاء الموقع** (logs/errors.log.php) للموقعين
#       ويعرض اللي حصل في آخر ٢٤ ساعة. مش error_log بتاع PHP —
#       الاستضافة مابتكتبش واحد أصلاً.
#
#  الاستعمال:
#    bash tests/local/deploy.sh                 ← عرض بس (الموقعين)
#    bash tests/local/deploy.sh --yes           ← رفع (الموقعين)
#    bash tests/local/deploy.sh --yes fagala    ← موقع واحد
#    bash tests/local/deploy.sh --yes ashraqa
#    bash tests/local/deploy.sh --errors        ← سجل الأخطاء بس (من غير رفع)
#
#  متطلبات: مفتاح ~/.ssh/fagala_hostinger
# =====================================================================
set -u
cd "$(dirname "$0")/../.."

SSH_KEY="${FAGALA_SSH_KEY:-$HOME/.ssh/fagala_hostinger}"
SSH_HOST="${FAGALA_SSH_HOST:-u834909335@91.108.101.22}"
SSH_PORT="${FAGALA_SSH_PORT:-65002}"
BASE="domains/hosnimanagement.com/public_html"
SNAP="public_html"          # نسخة مرجعية محلية — بتتحدّث بعد الرفع

SSH=(ssh -i "$SSH_KEY" -p "$SSH_PORT" -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o LogLevel=ERROR)

GO=0; ONLY=""; ERRORS_ONLY=0
for a in "$@"; do
    case "$a" in
        --yes)            GO=1 ;;
        --errors)         ERRORS_ONLY=1 ;;
        fagala|site)      ONLY="fagala" ;;
        ashraqa)          ONLY="ashraqa" ;;
        *) echo "❌ خيار مش معروف: $a"; exit 1 ;;
    esac
done

# آخر ٢٤ ساعة — المدى اللي بيبيّن «فيه حاجة اتكسرت ومحدش واخد باله»
SINCE="$(date -d '24 hours ago' '+%Y-%m-%d %H:%M:%S' 2>/dev/null || true)"
# run_all.sh وقارئ السجل الاتنين محتاجينه — بيتعرّف هنا عشان كل المسارات
export PHP_BIN="${PHP_BIN:-/d/php-8.5.10-nts-Win32-vs17-x64/php.exe}"

[ -f "$SSH_KEY" ] || { echo "❌ مفيش مفتاح SSH في $SSH_KEY"; exit 1; }

# ---------------------------------------------------------------------
#  تحذير لو فيه شغل نصّ كمّل
#  🔴 السكربت بيرفع **الملفات اللي على الجهاز**، مش آخر كوميت. لو أجي
#     (أو أي حد) لسه شغال، الرفع هياخد شغله وهو نصّه. حصل قرب يحصل
#     ١٩ سبتمبر ٢٠٢٦ — بريف ٢١ كان شغال وإحنا بنرفع بريف ٢٠.
# ---------------------------------------------------------------------
DIRTY="$(git status --porcelain 2>/dev/null)"
if [ -n "$DIRTY" ]; then
    echo "⚠️  فيه ملفات مش متكوميتة — الرفع بياخد اللي على الجهاز مش آخر كوميت:"
    echo "$DIRTY" | sed 's/^/     /'
    echo "     🔴 اتأكد إن مفيش شغل لسه شغّال قبل ما تكمّل."
    echo
fi

# ---------------------------------------------------------------------
#  ١) قايمة المرشحين لكل موقع
# ---------------------------------------------------------------------
list_candidates() {           # $1 = فولدر المصدر (site | ashraqa)
    local src="$1" f rel
    while IFS= read -r f; do
        rel="${f#"$src"/}"
        case "$rel" in
            config.php|config.php.*)            continue ;;   # 🔴 عمره ما يترفع
            uploads/*|data/*|downloads/*|logs/*) continue ;;   # بتاعة السيرفر
        esac
        printf '%s\n' "$rel"
    done < <(git ls-files --cached --others --exclude-standard "$src/") | sort -u
}

# ---------------------------------------------------------------------
#  ٢) مين مختلف فعلاً عن السيرفر — بصمة السيرفر هي المرجع
#     نداء SSH **واحد** لكل موقع مهما كان عدد الملفات.
# ---------------------------------------------------------------------
diff_vs_server() {            # $1 = المصدر · $2 = المسار على السيرفر
    local src="$1" remote="$2" cand="$3" out="$4"
    local remote_sums local_sums

    remote_sums="$(mktemp)"; local_sums="$(mktemp)"

    # بصمات السيرفر: الملف اللي مش موجود بيطلع بصمة فاضية وبيتحسب «جديد»
    # shellcheck disable=SC2016
    "${SSH[@]}" "$SSH_HOST" "cd '$remote' 2>/dev/null && xargs -d '\n' -r md5sum -- 2>/dev/null" \
        < "$cand" > "$remote_sums" 2>/dev/null

    (cd "$src" && xargs -d '\n' -r md5sum -- < "$cand" 2>/dev/null) > "$local_sums"

    # قارن: نطبع اللي بصمته مختلفة أو مش موجودة على السيرفر
    # 🔴 الصيغتين مختلفتين: md5sum على ويندوز بيطلع «بصمة *اسم» (وضع
    #    ثنائي) وعلى السيرفر «بصمة  اسم» (مسافتين). لازم نفصل على أول
    #    مسافة ونشيل النجمة، مش نفصل على مسافتين.
    awk '
        function fname(l,   p, f) { p = index(l, " "); f = substr(l, p+1); sub(/^[ *]+/, "", f); return f }
        function fhash(l,   p)    { p = index(l, " "); return substr(l, 1, p-1) }
        NR==FNR { sum[fname($0)] = fhash($0); next }
        { f = fname($0); if (sum[f] != fhash($0)) print f }
    ' "$remote_sums" "$local_sums" | sort > "$out"

    rm -f "$remote_sums" "$local_sums"
}

# ---------------------------------------------------------------------
#  سجل أخطاء الموقع (logs/errors.log.php) — الموقعين
#
#  🔴 ده **مش** error_log بتاع PHP. الاستضافة مابتكتبش واحد أصلاً
#     (اتأكدنا ٢٠ سبتمبر — مفيش الملف خالص)، فالفحص القديم اللي كان
#     بيعمل tail عليه كان بيقول «فاضي» على طول ويطمّنك وهو مش شايف حاجة.
#     السجل الحقيقي بيكتبه `errlog_lib.php` جوه `logs/errors.log.php`.
#
#  ليه ده جوه الرفع: غلطة قاتلة في إشراقة فضلت شغالة **٦٧ مرة على مدى
#  تلات أيام** والإشعارات واقفة، ومحدش عرف غير لما اتفتح السجل بالصدفة.
# ---------------------------------------------------------------------
report_errors() {             # $1 = من امتى (فاضي = كل السجل)
    local since="${1:-}" s sub dir n
    echo; echo "═══ سجل الأخطاء (آخر ٢٤ ساعة) ═══"
    for s in "${SITES[@]}"; do
        sub=""; [ "$s" = "ashraqa" ] && sub="ashraqa/"
        dir="$SNAP/${sub}logs"
        mkdir -p "$dir"
        scp -q -i "$SSH_KEY" -P "$SSH_PORT" -o LogLevel=ERROR \
            "$SSH_HOST:$BASE/${sub}logs/errors.log.php" "$dir/" 2>/dev/null || true
        if [ ! -f "$dir/errors.log.php" ]; then
            echo "  ⚠️  $s — مالقيتش سجل (logs/errors.log.php)"
            continue
        fi
        out="$("$PHP_BIN" tests/local/read_server_errors.php "$dir" 6 "$since" 2>&1)"
        if printf '%s' "$out" | grep -q '^✅'; then
            echo "  ✅ $s — مفيش أخطاء جديدة"
        else
            n="$(printf '%s' "$out" | sed -n 's/.*غلطات مختلفة: \([0-9]*\).*/\1/p' | head -1)"
            echo "  🔴 $s — ${n:-؟} غلطة:"
            printf '%s\n' "$out" | sed '1,2d' | sed 's/^/     /'
        fi
    done
}

# ---------------------------------------------------------------------
#  ٣) حزام الأمان — config.php عمره ما يعدّي
# ---------------------------------------------------------------------
guard_config() {              # $1 = ملف القايمة
    if grep -qE '(^|/)config\.php' "$1"; then
        echo "❌🔴 config.php في القايمة — وقفت. ده مايحصلش أبداً، شوف السكربت."
        exit 1
    fi
}

# ---------------------------------------------------------------------
#  بناء القوايم
# ---------------------------------------------------------------------
declare -A SRC_OF REMOTE_OF LIST_OF
SITES=()
[ "$ONLY" = "ashraqa" ] || SITES+=("fagala")
[ "$ONLY" = "fagala" ]  || SITES+=("ashraqa")

SRC_OF[fagala]="site";      REMOTE_OF[fagala]="$BASE"
SRC_OF[ashraqa]="ashraqa";  REMOTE_OF[ashraqa]="$BASE/ashraqa"

# `--errors` لوحدها: اقرا السجل وامشي — من غير أي مقارنة ولا رفع
if [ "$ERRORS_ONLY" -eq 1 ]; then
    report_errors "$SINCE"
    exit 0
fi

TOTAL=0
for s in "${SITES[@]}"; do
    src="${SRC_OF[$s]}"
    [ -d "$src" ] || { echo "❌ مفيش فولدر $src/"; exit 1; }
    cand="$(mktemp)"; lst="$(mktemp)"
    list_candidates "$src" > "$cand"
    guard_config "$cand"
    diff_vs_server "$src" "${REMOTE_OF[$s]}" "$cand" "$lst"
    guard_config "$lst"
    LIST_OF[$s]="$lst"
    n=$(wc -l < "$lst" | tr -d ' ')
    TOTAL=$(( TOTAL + n ))
    rm -f "$cand"

    echo "═══ $s ($n) ═══"
    if [ "$n" -gt 0 ]; then sed 's/^/  ↻  /' "$lst"; else echo "  ✅ مطابق للسيرفر"; fi
done

echo "  🔒 config.php مستثنى · مفيش مسح على السيرفر"

if [ "$TOTAL" -eq 0 ]; then echo; echo "✅ مفيش حاجة تترفع."; exit 0; fi

if [ "$GO" -ne 1 ]; then
    echo; echo "ده عرض بس. للرفع:  bash tests/local/deploy.sh --yes"
    exit 0
fi

# ---------------------------------------------------------------------
#  ٤) الفحوصات قبل الرفع
# ---------------------------------------------------------------------
echo; echo "═══ الفحوصات ═══"
export PYTHONIOENCODING=utf-8
# ⚠️ run_all.sh من غير php بيفشل — وده بيمنع الرفع وانت فاكر إن الكود غلط.
#    PHP_BIN متعرّف فوق مرة واحدة لكل المسارات.
if ! bash tests/run_all.sh > /tmp/deploy_checks.log 2>&1; then
    tail -20 /tmp/deploy_checks.log
    echo "❌ فيه فحص فشل — مارفعتش حاجة."; exit 1
fi
echo "  ✅ كل الفحوصات عدّت"

# ---------------------------------------------------------------------
#  ٥) الرفع — tar عبر SSH: اتصال واحد لكل موقع مهما كان العدد
#     (scp بيفتح اتصال لكل ملف — ٣٢ ملف كانوا بياخدوا دقايق)
#     🔴 tar هنا **بيفك بس** — مفيش --delete ولا أي مسح.
# ---------------------------------------------------------------------
echo; echo "═══ الرفع ═══"
for s in "${SITES[@]}"; do
    lst="${LIST_OF[$s]}"
    n=$(wc -l < "$lst" | tr -d ' ')
    [ "$n" -gt 0 ] || continue
    if ! tar -czf - -C "${SRC_OF[$s]}" -T "$lst" \
         | "${SSH[@]}" "$SSH_HOST" "mkdir -p '${REMOTE_OF[$s]}' && tar -xzf - -C '${REMOTE_OF[$s]}'"; then
        echo "❌ الرفع وقف في $s — 🔴 شوف وصل إيه قبل ما تعيد."; exit 1
    fi
    echo "  ⬆️  $s — $n ملف"
done

# ---------------------------------------------------------------------
#  ٦) التأكيد — البصمة تاني بعد الرفع
# ---------------------------------------------------------------------
echo; echo "═══ التأكيد ═══"
BAD=0
for s in "${SITES[@]}"; do
    lst="${LIST_OF[$s]}"
    n=$(wc -l < "$lst" | tr -d ' ')
    [ "$n" -gt 0 ] || continue
    still="$(mktemp)"
    diff_vs_server "${SRC_OF[$s]}" "${REMOTE_OF[$s]}" "$lst" "$still"
    m=$(wc -l < "$still" | tr -d ' ')
    if [ "$m" -eq 0 ]; then
        echo "  ✅ $s — $n ملف مطابقين بالبصمة"
    else
        echo "  🔴 $s — $m ملف مش مطابق:"; sed 's/^/     /' "$still"; BAD=1
    fi
    rm -f "$still"
done
[ "$BAD" -eq 0 ] || { echo "❌ فيه ملف مش مطابق."; exit 1; }

# ---------------------------------------------------------------------
#  ٧) فحص بنية PHP على السيرفر + سجل الأخطاء
# ---------------------------------------------------------------------
echo; echo "═══ على السيرفر ═══"
for s in "${SITES[@]}"; do
    lst="${LIST_OF[$s]}"
    php_files="$(grep '\.php$' "$lst" || true)"
    [ -n "$php_files" ] || continue
    bad="$(printf '%s\n' "$php_files" \
        | "${SSH[@]}" "$SSH_HOST" "cd '${REMOTE_OF[$s]}' && while IFS= read -r f; do php -l \"\$f\" >/dev/null 2>&1 || echo \"\$f\"; done")"
    if [ -n "$bad" ]; then
        echo "  🔴 $s — ملفات فيها خطأ بنية:"; printf '%s\n' "$bad" | sed 's/^/     /'; BAD=1
    else
        echo "  ✅ $s — كل ملفات PHP سليمة"
    fi
done
[ "$BAD" -eq 0 ] || { echo "❌ فيه ملف مكسور على السيرفر — صلّحه فوراً."; exit 1; }

report_errors "$SINCE"

# ---------------------------------------------------------------------
#  ٨) تحديث النسخة المرجعية المحلية (public_html/) — مرجع للقراءة بس
# ---------------------------------------------------------------------
for s in "${SITES[@]}"; do
    lst="${LIST_OF[$s]}"; src="${SRC_OF[$s]}"
    sub=""; [ "$s" = "ashraqa" ] && sub="ashraqa/"
    while IFS= read -r rel; do
        [ -n "$rel" ] || continue
        mkdir -p "$SNAP/$sub$(dirname "$rel")"
        cp "$src/$rel" "$SNAP/$sub$rel"
    done < "$lst"
    rm -f "$lst"
done

echo; echo "✅ $TOTAL ملف اترفعوا واتأكدوا."
