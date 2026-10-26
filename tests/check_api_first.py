#!/usr/bin/env python3
"""
check_api_first.py — أي صفحة جديدة لازم تبقى API-first (RULES قاعدة ٩).

«صفحة» = أي `site/*.php` فيه `includes/header.php` — ومش `*_api.php` ولا
`*_lib.php` ولا `config.php`. لو الصفحة فيها SQL (`->prepare(` / `->query(` /
`->exec(`) يبقى مخالفة: المفروض `_lib.php` + `_api.php` + صفحة عرض بس.

الصفحات القديمة اللي لسه فيها SQL مسموحة **بعددها الحالي** في
`tests/api_first_legacy.txt`:
  - صفحة مش في القايمة وفيها SQL          → فشل
  - صفحة في القايمة وعددها زاد            → فشل
  - صفحة في القايمة وعددها نقص (أو اتشالت) → تحذير «حدّث api_first_legacy.txt»

محمد (٢٦ أكتوبر ٢٠٢٦): «اتأكد إن أي صفحة جديدة تتعمل بالطريقة الجديدة».
القاعدة كانت مكتوبة بس محدش بيمسكها أوتوماتيك.

لتوليد القايمة من الكود الحالي:
    python tests/check_api_first.py --dump > tests/api_first_legacy.txt
"""

import re
import sys
from pathlib import Path

# مطب ويندوز: الترمينال cp1256 بيوقع بايثون بـUnicodeEncodeError عند طباعة العربي
if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8')
if hasattr(sys.stderr, 'reconfigure'):
    sys.stderr.reconfigure(encoding='utf-8')

ROOT_DIR = Path(__file__).resolve().parent.parent
SITE_DIR = ROOT_DIR / 'site'
LEGACY_FILE = ROOT_DIR / 'tests' / 'api_first_legacy.txt'

# أي `$pdo->prepare(` أو `db()->query(` أو `mysqli->exec(`
SQL = re.compile(r'->\s*(?:prepare|query|exec)\s*\(')

# الصفحة بتتقري من header — الكومنت اللي بيذكر الملف مش استدعاء
HEADER = re.compile(
    r'^\s*(?:require|require_once|include|include_once)\b[^;]*includes/header\.php'
)

# زي check_no_emoji.py: التعليقات مستثناة — شرح الاستعلام مش استعلام
COMMENT = re.compile(r'^\s*(?://|\*|/\*|#|<!--)')

# ملفات مش صفحات: الـendpoint والـlib والسر
NOT_A_PAGE = ('_api.php', '_lib.php', 'config.php')


def is_page(name):
    return not name.endswith(NOT_A_PAGE)


def count_queries(path):
    """عدد الاستعلامات في الملف (سطور التعليق مستثناة)."""
    text = path.read_text(encoding='utf-8', errors='ignore')
    n = 0
    for line in text.splitlines():
        if COMMENT.match(line):
            continue
        n += len(SQL.findall(line))
    return n


def scan():
    """{اسم الصفحة: عدد الاستعلامات} لكل صفحة فيها header."""
    pages = {}
    for path in sorted(SITE_DIR.glob('*.php')):
        if not is_page(path.name):
            continue
        text = path.read_text(encoding='utf-8', errors='ignore')
        if not any(HEADER.match(line) for line in text.splitlines()):
            continue
        pages[path.name] = count_queries(path)
    return pages


def load_legacy():
    """{اسم الصفحة: العدد المسموح} من tests/api_first_legacy.txt."""
    legacy = {}
    if not LEGACY_FILE.exists():
        return legacy
    for line in LEGACY_FILE.read_text(encoding='utf-8', errors='ignore').splitlines():
        line = line.split('#')[0].strip()
        if not line:
            continue
        parts = line.split()
        if len(parts) < 2 or not parts[1].isdigit():
            continue
        legacy[parts[0]] = int(parts[1])
    return legacy


def dump():
    """القايمة من الكود الحالي — بيتحط في api_first_legacy.txt."""
    print('# tests/api_first_legacy.txt')
    print('# صفحات قديمة لسه فيها SQL — ممنوع يزيد عددها وممنوع صفحة جديدة تدخل.')
    print('# الصيغة: page.php  عدد-الاستعلامات  # ملاحظة')
    print('# اتطلّع بـ: python tests/check_api_first.py --dump')
    print()
    for name, n in scan().items():
        if n:
            print(f'{name}\t{n}')


def main():
    pages = scan()
    legacy = load_legacy()

    bad = []
    stale = []

    for name, n in pages.items():
        allowed = legacy.get(name, 0)
        if n == 0:
            if name in legacy:
                stale.append((name, allowed, 0))
            continue
        if name not in legacy:
            bad.append((name, n, None))
        elif n > allowed:
            bad.append((name, n, allowed))

    # صفحات في القايمة مابقتش موجودة أو مابقهاش header
    for name, allowed in legacy.items():
        if name not in pages:
            stale.append((name, allowed, 0))

    for name, n, allowed in sorted(bad):
        msg = (f'  ❌ الصفحة {name} فيها {n} استعلام — '
               f'أي صفحة جديدة = _lib + _api + الصفحة (RULES قاعدة ٩)')
        if allowed is not None:
            msg += f' — كان مسموح {allowed} بس'
        print(msg)

    for name, allowed, now in sorted(stale):
        print(f'  ⚠️ {name}: العدد بقى {now} (كان {allowed}) '
              f'— حدّث api_first_legacy.txt (python tests/check_api_first.py --dump)')

    if bad:
        print(f'  ❌ {len(bad)} صفحة مخالفة لقاعدة ٩ (API-first)')
        return 1

    if not legacy and any(n for n in pages.values()):
        print('  ⚠️ مفيش tests/api_first_legacy.txt — كل صفحة فيها SQL = مخالفة')

    total = sum(1 for n in pages.values() if n)
    print(f'  ✅ {len(pages)} صفحة — مفيش SQL في صفحة جديدة '
          f'({total} صفحة قديمة مسموحة مؤقتاً)')
    return 0


if __name__ == '__main__':
    if '--dump' in sys.argv[1:]:
        dump()
        sys.exit(0)
    sys.exit(main())
