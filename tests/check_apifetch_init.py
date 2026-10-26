#!/usr/bin/env python3
"""
check_apifetch_init.py — ممنوع نداء apiFetch وقت قراية السكربت.

المشكلة (بريف ٢٣٥ — ٢٧ أكتوبر ٢٠٢٦):
    `assets/app.js` فيه `window.apiFetch` وبيتحمّل في `includes/footer.php`
    **آخر** الصفحة. أي صفحة بتنادي `apiFetch` (أو دالة بتستدعيه) في سطر
    بره أي دالة، النداء بيحصل قبل ما `app.js` يوصل ←
    `ReferenceError: apiFetch is not defined` والصفحة بتفتح فاضية.
    حصلت في `item_master.php` و`item_classes.php` و`item_prices.php`.

الفحص ده ساكن (مش محتاج Laragon ولا متصفح):
  1. بياخد كل بلوك `<script>` inline **قبل** `includes/footer.php` في `site/*.php`.
  2. بيعرف أي دالة معرّفة في نفس البلوك بتستدعي `apiFetch` (fetchy).
  3. بيدوّر على أي نداء لـ`apiFetch` أو لدالة fetchy **على المستوى الأعلى**
     (بره أي دالة/arrow/مستمع حدث). الـIIFE مستثناة — بتتنفّذ فوراً بردو.
  4. لو النداء جوه `DOMContentLoaded` أو أي مستمع حدث تاني = تمام.

الحل الصح: لفّ النداء في
    document.addEventListener('DOMContentLoaded', () => { ... });

الاستعمال:  python tests/check_apifetch_init.py
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

SCRIPT = re.compile(r'<script\b([^>]*)>(.*?)</script>', re.S | re.I)
FOOTER = re.compile(r'includes/footer\.php')
IDENT = re.compile(r'[A-Za-z_$][\w$]*')

# الـendpoint والـlib مش صفحات — الـJS بتاعهم (لو فيه) مش بيتحمّل مع صفحة
NOT_A_PAGE = ('_api.php', '_lib.php', 'config.php')

# الكلمات اللي لو قبل `(` يبقى `{` اللي بعدها **بلوك** مش جسم دالة
BLOCK_KEYWORDS = {'if', 'for', 'while', 'switch', 'catch', 'with', 'do', 'else', 'try', 'finally'}

BODY_NAME_PATTERNS = [
    # function loadCategories() {
    re.compile(r'function\s*\*?\s*([A-Za-z_$][\w$]*)\s*\([^()]*\)\s*$'),
    # const x = async function() {
    re.compile(r'([A-Za-z_$][\w$]*)\s*=\s*(?:async\s+)?function\s*\*?\s*[A-Za-z_$]*\s*\([^()]*\)\s*$'),
    # const x = async () => {
    re.compile(r'([A-Za-z_$][\w$]*)\s*=\s*(?:async\s+)?\([^()]*\)\s*=>\s*$'),
    # const x = async v => {
    re.compile(r'([A-Za-z_$][\w$]*)\s*=\s*(?:async\s+)?[A-Za-z_$][\w$]*\s*=>\s*$'),
]


def strip_php(src):
    """مكان الـPHP بيتحوّل مسافات — عشان الـtokenizer مايتلخبطش في تنصيص PHP."""
    def blank(m):
        return re.sub(r'[^\n]', ' ', m.group(0))
    return re.sub(r'<\?(?:php|=).*?\?>', blank, src, flags=re.S)


def skip_string(js, i):
    """i على علامة تنصيص — يرجّع أول موضع بعد التنصيص."""
    q = js[i]
    i += 1
    n = len(js)
    while i < n:
        c = js[i]
        if c == '\\':
            i += 2
        elif c == q:
            return i + 1
        elif c == '\n':          # سترنج مقطوع — بنقف عند السطر
            return i
        else:
            i += 1
    return n


def skip_template(js, i):
    """i على backtick — يرجّع أول موضع بعد القالب (مع ${...})."""
    i += 1
    n = len(js)
    while i < n:
        c = js[i]
        if c == '\\':
            i += 2
        elif c == '`':
            return i + 1
        elif c == '$' and i + 1 < n and js[i + 1] == '{':
            i = skip_code_until_brace(js, i + 2)
        else:
            i += 1
    return n


def skip_code_until_brace(js, i):
    """جوّه ${ ... } — بيرجّع أول موضع بعد القوس المقفول."""
    depth = 1
    n = len(js)
    while i < n:
        c = js[i]
        if c in '"\'':
            i = skip_string(js, i)
        elif c == '`':
            i = skip_template(js, i)
        elif c == '/' and i + 1 < n and js[i + 1] == '/':
            j = js.find('\n', i)
            i = n if j < 0 else j
        elif c == '/' and i + 1 < n and js[i + 1] == '*':
            j = js.find('*/', i + 2)
            i = n if j < 0 else j + 2
        elif c == '{':
            depth += 1
            i += 1
        elif c == '}':
            depth -= 1
            i += 1
            if depth == 0:
                return i
        else:
            i += 1
    return n


def is_regex_start(js, i):
    """هل `/` دي بداية regex ولا قسمة؟"""
    j = i - 1
    while j >= 0 and js[j] in ' \t\r\n':
        j -= 1
    if j < 0:
        return True
    c = js[j]
    if c in '(,=:[!&|?{};+-*%~^<>':
        return True
    m = re.search(r'([A-Za-z_$][\w$]*)\s*$', js[max(0, j - 20):j + 1])
    return bool(m) and m.group(1) in {
        'return', 'typeof', 'case', 'in', 'of', 'new', 'delete', 'void', 'do', 'else', 'yield', 'await'
    }


def skip_regex(js, i):
    i += 1
    n = len(js)
    while i < n:
        c = js[i]
        if c == '\\':
            i += 2
        elif c == '/':
            i += 1
            while i < n and js[i].isalpha():
                i += 1
            return i
        elif c == '\n':
            return i
        else:
            i += 1
    return n


def body_name(js, pos):
    """اسم الدالة اللي جسمها بيبدأ عند pos — أو None."""
    window = js[max(0, pos - 400):pos]
    for pat in BODY_NAME_PATTERNS:
        m = pat.search(window)
        if m:
            return m.group(1)
    return None


def scan_block(js):
    """
    بيحلّل بلوك <script> واحد.
    يرجّع (bodies, calls):
        bodies = [{id, name, start, end, depth, iife}]
        calls  = [(name, pos, [ids الدوال الحاضنة])]
    """
    n = len(js)
    bodies = []
    bodies_by_id = {}
    calls = []

    brace_stack = []          # [body_id or None]  — None = بلوك عادي (if/for/…)
    paren_stack = []          # [(كلمة قبل القوس, الحرف اللي قبله)]
    last_closed = None        # (كلمة, حرف) لآخر قوس مقفول
    arrow_stack = []          # [(paren_depth, brace_depth)] لـarrow بجسم تعبير
    i = 0

    def enclosing():
        return [b for b in brace_stack if b is not None]

    while i < n:
        c = js[i]

        if c in '"\'':
            i = skip_string(js, i)
            continue
        if c == '`':
            i = skip_template(js, i)
            continue

        if c == '/':
            nxt = js[i + 1] if i + 1 < n else ''
            if nxt == '/':
                j = js.find('\n', i)
                i = n if j < 0 else j
                continue
            if nxt == '*':
                j = js.find('*/', i + 2)
                i = n if j < 0 else j + 2
                continue
            if is_regex_start(js, i):
                i = skip_regex(js, i)
                continue

        if c == '(':
            j = i - 1
            while j >= 0 and js[j] in ' \t\r\n':
                j -= 1
            m = re.search(r'([A-Za-z_$][\w$]*)$', js[max(0, j - 60):j + 1])
            word = m.group(1) if m else ''
            paren_stack.append((word, js[j] if j >= 0 else ''))
            i += 1
            continue

        if c == ')':
            last_closed = paren_stack.pop() if paren_stack else None
            # arrow بجسم تعبير جوّه أقواس خلص
            while arrow_stack and arrow_stack[-1][0] > len(paren_stack):
                arrow_stack.pop()
            i += 1
            continue

        if c == '=' and i + 1 < n and js[i + 1] == '>':
            k = i + 2
            while k < n and js[k] in ' \t\r\n':
                k += 1
            if k >= n or js[k] != '{':
                arrow_stack.append((len(paren_stack), len(brace_stack)))
            i += 2
            continue

        if c == '{':
            is_func = False
            if last_closed is not None:
                prev_word, _prev_char = last_closed
                # `if (…) {` و`for (…) {` بلوك — أي قوس تاني بعده `{` = جسم دالة
                if prev_word not in BLOCK_KEYWORDS:
                    is_func = True
            j = i - 1
            while j >= 0 and js[j] in ' \t\r\n':
                j -= 1
            if j >= 0 and js[j] == '>':
                # => {
                is_func = True
            if is_func:
                body = {
                    'id': len(bodies),
                    'name': body_name(js, i),
                    'start': i,
                    'end': n,
                    'depth': len([b for b in brace_stack if b is not None]) + 1,
                    'iife': False,
                }
                bodies.append(body)
                bodies_by_id[body['id']] = body
                brace_stack.append(body['id'])
            else:
                brace_stack.append(None)
            last_closed = None
            i += 1
            continue

        if c == '}':
            popped = brace_stack.pop() if brace_stack else None
            if popped is not None:
                body = bodies_by_id[popped]
                body['end'] = i
                # IIFE: })(…)  أو  })()
                k = i + 1
                while k < n and js[k] in ' \t\r\n':
                    k += 1
                if k < n and js[k] == ')':
                    k += 1
                    while k < n and js[k] in ' \t\r\n':
                        k += 1
                    if k < n and js[k] == '(':
                        body['iife'] = True
                elif k < n and js[k] == '(':
                    body['iife'] = True
            while arrow_stack and arrow_stack[-1][1] > len(brace_stack):
                arrow_stack.pop()
            last_closed = None
            i += 1
            continue

        if c in ',;':
            while arrow_stack and arrow_stack[-1][0] >= len(paren_stack) \
                    and arrow_stack[-1][1] == len(brace_stack):
                arrow_stack.pop()
            last_closed = None
            i += 1
            continue

        if c.isalpha() or c in '_$':
            m = IDENT.match(js, i)
            word = m.group(0)
            k = m.end()
            while k < n and js[k] in ' \t\r\n':
                k += 1
            if k < n and js[k] == '(':
                # مش تعريف دالة
                p = i - 1
                while p >= 0 and js[p] in ' \t\r\n':
                    p -= 1
                decl = False
                m2 = re.search(r'(function|\*)$', js[max(0, p - 12):p + 1])
                if m2:
                    decl = True
                if not decl:
                    calls.append((word, i, enclosing(), len(arrow_stack)))
            last_closed = None
            i = m.end()
            continue

        if c not in ' \t\r\n':
            last_closed = None
        i += 1

    return bodies, calls


def footer_limit(raw):
    """آخر موضع لـfooter.php — الصفحة ممكن تكون فيها فوتر بدري في فرع خروج."""
    hits = list(FOOTER.finditer(raw))
    return hits[-1].start() if hits else len(raw)


def analyze_page(path):
    """[(سطر, اسم النداء, السبب)] — النداءات اللي بتحصل وقت قراية السكربت."""
    raw = path.read_text(encoding='utf-8', errors='ignore')
    head = strip_php(raw[:footer_limit(raw)])

    hits = []
    for m in SCRIPT.finditer(head):
        if 'src=' in m.group(1).lower():
            continue
        js = m.group(2)
        base = m.start(2)
        bodies, calls = scan_block(js)

        # الدوال اللي بتنادي apiFetch مباشرة (مش جوّه دالة جواها)
        fetchy = set()
        for name, pos, stack, arrows in calls:
            if name != 'apiFetch' or not stack or arrows:
                continue
            body = bodies[stack[-1]]
            if body['depth'] == len(stack) and body['name']:
                fetchy.add(body['name'])

        for name, pos, stack, arrows in calls:
            if name != 'apiFetch' and name not in fetchy:
                continue
            if any(not bodies[bid]['iife'] for bid in stack):
                continue                      # جوّه دالة حقيقية أو مستمع حدث
            if arrows:
                continue                      # arrow بجسم تعبير — مش بيتنفّذ دلوقتي
            line = head.count('\n', 0, base + pos) + 1
            hits.append((line, name))
    return hits


def main():
    pages = sorted(SITE_DIR.glob('*.php'))
    checked = 0
    failed = 0

    for path in pages:
        if path.name.endswith(NOT_A_PAGE):
            continue
        raw = path.read_text(encoding='utf-8', errors='ignore')
        if 'apiFetch' not in raw:
            continue
        checked += 1
        hits = analyze_page(path)
        if not hits:
            continue
        failed += 1
        for line, name in hits:
            print(f'  ❌ site/{path.name}:{line} — {name}() بيتنده وقت قراية السكربت')
        print(f'     الـ`app.js` بيتحمّل في الفوتر بعد السكربت ده ← '
              f'«apiFetch is not defined». لفّ النداء في DOMContentLoaded.')

    if failed:
        print(f'  ❌ {failed} صفحة بتنادي apiFetch قبل تحميل app.js')
        return 1

    print(f'  ✅ {checked} صفحة فيها apiFetch — كل النداءات بعد DOMContentLoaded')
    return 0


if __name__ == '__main__':
    sys.exit(main())
