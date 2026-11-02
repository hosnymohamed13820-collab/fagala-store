#!/usr/bin/env python3
"""
check_no_emoji.py — مفيش إيموجي في الواجهة.

قرار محمد (٣ أكتوبر ٢٠٢٦): الويب كله من غير إيموجي. أي علامة في الواجهة
تبقى أيقونة `ti-*` من الـsubset. الصفحة الجديدة بتاعة تحليل المبيعات
طلعت بـ«الأرباح 🔒» لأن القاعدة ماكانتش مكتوبة في أي حتة.

بيفحص **السطور الجديدة بس** (الفرق عن HEAD + الملفات اللي لسه مش في جيت)
عشان القديم (~٢٠٠ واحد) مايوقّعش الفحص لحد ما يتنضّف في بريف لوحده.

مسموح: ✓ ✕ ✗ · ⇦ — دي علامات كتابة مش إيموجي.
التعليقات مستثناة (🔴 في التعليق تحذير للمبرمج ومابيظهرش لحد).
"""
import re
import subprocess
import sys

EMOJI = re.compile(
    '[\U0001F000-\U0001FAFF'   # الصور والوشوش والرموز
    '☀-⛿'            # ☀ ⚠ ⛔ …
    '⏩-⏺'            # ⏳ ⏰ ⏸ …
    '✅❌❗⭐⬆⬇️]'
)
CSV = re.compile(r'text/csv|fputcsv\(')  # التصدير إكسيل بـSimpleXlsx (قرار محمد ٣/١٠)
COMMENT = re.compile(r'^\s*(//|\*|/\*|#|<!--|<\?php\s*/\*)')


def git(*args):
    return subprocess.run(['git', *args], capture_output=True, text=True,
                          encoding='utf-8', errors='ignore').stdout


def main():
    bad = []

    # ١) السطور المضافة في الملفات المتتبّعة
    cur = None
    for line in git('diff', '-U0', 'HEAD', '--', 'site/').splitlines():
        if line.startswith('+++ '):
            cur = line[6:] if line.startswith('+++ b/') else None
        elif line.startswith('+') and cur and not line.startswith('+++'):
            text = line[1:]
            if not COMMENT.match(text) and EMOJI.search(text):
                bad.append((cur, text.strip()))
            if not COMMENT.match(text) and CSV.search(text):
                bad.append((cur, 'CSV — استخدم SimpleXlsx: ' + text.strip()))

    # ٢) الملفات الجديدة كلها
    for f in git('ls-files', '--others', '--exclude-standard', 'site/').splitlines():
        if not f.endswith(('.php', '.js', '.css', '.html')) or f.endswith('config.php'):
            continue
        with open(f, encoding='utf-8', errors='ignore') as fh:
            for n, text in enumerate(fh, 1):
                if not COMMENT.match(text) and EMOJI.search(text):
                    bad.append((f'{f}:{n}', text.strip()))
                if not COMMENT.match(text) and CSV.search(text):
                    bad.append((f'{f}:{n}', 'CSV — استخدم SimpleXlsx: ' + text.strip()))

    if bad:
        print(f'  ❌ مخالفة لقواعد الواجهة ({len(bad)}) — إيموجي ← أيقونة ti-* · CSV ← SimpleXlsx:')
        for where, text in bad[:20]:
            print(f'     {where}: {text[:100]}')
        return 1
    print('  ✅ مفيش إيموجي جديد')
    return 0


if __name__ == '__main__':
    sys.exit(main())
