#!/usr/bin/env bash
# =====================================================================
#  نسخ الكود من site/ لنسخة Laragon المحلية — قبل أي اختبار محلي.
#
#  D:\laragon\www نسخة **منفصلة** (مش رابط) — بتقدم لوحدها لما site/
#  يتعدّل. من غير السكربت ده بتختبر على كود قديم وانت فاكره الجديد.
#
#  🔴 config.php **مابيتنسخش أبداً**:
#     site/config.php وهمي (~30 بايت) للاختبارات، واللي في www الحقيقي
#     (~16 كيلو) متظبط على القاعدة المحلية. نسخ الوهمي فوقه = النسخة
#     المحلية كلها بتقف. نفس الغلطة اللي وقّعت الموقع الحقيقي مرتين (RULES §٨).
#
#  • بينسخ الملفات اللي في git بس (`git ls-files site/`) — config.php
#    مش في git أصلاً، والاستثناء الصريح تحت حزام أمان تاني.
#  • **مابيمسحش** أي ملف في www.
#  • مابيلمسش القاعدة.
# =====================================================================
set -eu
cd "$(dirname "$0")/../.."

DST="${LARAGON_WWW:-/d/laragon/www}"

if [ ! -f "$DST/config.php" ]; then
    echo "❌ مفيش $DST/config.php — مش هكمل (المسار غلط؟)"; exit 1
fi
# حزام أمان: لو config.php اللي هناك صغير يبقى اتدعس قبل كده
size=$(wc -c < "$DST/config.php")
if [ "$size" -lt 1000 ]; then
    echo "❌ $DST/config.php حجمه $size بايت بس — شكله اتدعس بالوهمي. مش هكمل."; exit 1
fi

n=0
while IFS= read -r f; do
    rel="${f#site/}"
    case "$rel" in
        config.php|config.php.*) continue ;;        # 🔴 عمره ما يتنسخ
        uploads/*|data/*|downloads/*) continue ;;   # ملفات المستخدمين المحلية
    esac
    if ! cmp -s "$f" "$DST/$rel" 2>/dev/null; then
        mkdir -p "$(dirname "$DST/$rel")"
        cp "$f" "$DST/$rel"
        echo "  ↻ $rel"
        n=$((n + 1))
    fi
done < <(git ls-files site/)

echo "✅ $n ملف اتحدّث · config.php ما اتلمسش ($(wc -c < "$DST/config.php") بايت)"
