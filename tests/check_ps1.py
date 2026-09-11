#!/usr/bin/env python3
"""
فاحص بنية PowerShell - بيشتغل من غير باورشيل.

بيتخطى التعليقات والنصوص والـ here-strings، وبيتأكد من:
  1. توازن { } ( ) [ ]
  2. here-strings (@" ... "@) مقفولة صح والقافلة أول السطر
  3. الـ backtick (`) للاستمرار مافيهوش مسافة وراه (أشهر بق صامت)
  4. كل exit مسبوق بنبضة (تحليل مسارات الخروج)
"""
import sys, re

PAIRS = {'}': '{', ')': '(', ']': '['}


def blank(seg):
    """بيمسح النص بس بيسيب الأسطر الجديدة عشان الترقيم مايزحلقش."""
    return ''.join('\n' if ch == '\n' else ' ' for ch in seg)


def strip_code(src):
    """بيرجع الكود من غير تعليقات/نصوص، مع الحفاظ على أرقام السطور."""
    out = []
    i, n = 0, len(src)
    line = 1
    errs = []
    while i < n:
        ch = src[i]

        # here-string  @" ... "@   /   @' ... '@
        m = re.match(r"@(['\"])\r?\n", src[i:])
        if m:
            q = m.group(1)
            close = re.search(r'^' + q + r'@', src[i + m.end():], re.M)
            if not close:
                errs.append(f'سطر {line}: here-string @{q} مش مقفول')
                break
            seg = src[i:i + m.end() + close.end()]
            line += seg.count('\n')
            out.append(blank(seg))
            i += m.end() + close.end()
            continue

        # تعليق بلوك <# ... #>
        if src.startswith('<#', i):
            j = src.find('#>', i + 2)
            if j < 0:
                errs.append(f'سطر {line}: تعليق <# مش مقفول')
                break
            line += src.count('\n', i, j)
            out.append(blank(src[i:j + 2]))
            i = j + 2
            continue

        # تعليق سطر
        if ch == '#':
            j = src.find('\n', i)
            j = n if j < 0 else j
            out.append(' ' * (j - i))
            i = j
            continue

        # نص
        if ch in ('"', "'"):
            q, j = ch, i + 1
            while j < n:
                if q == '"' and src[j] == '`':
                    j += 2
                    continue
                if src[j] == q:
                    if j + 1 < n and src[j + 1] == q:   # '' escape
                        j += 2
                        continue
                    break
                if src[j] == '\n':
                    line += 1
                j += 1
            if j >= n:
                errs.append(f'سطر {line}: نص {q} مش مقفول')
                break
            out.append(blank(src[i:j + 1]))
            i = j + 1
            continue

        if ch == '\n':
            line += 1
        out.append(ch)
        i += 1
    return ''.join(out), errs


def check(path):
    src = open(path, encoding='utf-8').read()
    code, errs = strip_code(src)

    # 1) الأقواس
    stack, line = [], 1
    for ch in code:
        if ch == '\n':
            line += 1
        elif ch in '{([':
            stack.append((ch, line))
        elif ch in '})]':
            if not stack:
                errs.append(f'سطر {line}: قوس زيادة {ch}')
            elif stack[-1][0] != PAIRS[ch]:
                errs.append(f'سطر {line}: {ch} مش متطابق مع {stack[-1][0]} (سطر {stack[-1][1]})')
                stack.pop()
            else:
                stack.pop()
    for o, ol in stack:
        errs.append(f'سطر {ol}: {o} مفتوح ومش مقفول')

    # 2) backtick متبوع بمسافة = استمرار مكسور (صامت وقاتل)
    for idx, ln in enumerate(src.split('\n'), 1):
        if re.search(r'`[ \t]+$', ln):
            errs.append(f'سطر {idx}: backtick وراه مسافة - سطر الاستمرار مكسور')

    return errs


def exit_paths(path):
    """
    كل exit في الكود الفعلي لازم تكون النبضة اتبعتت على المسار بتاعه.

    القاعدة كانت مكتوبة باسم دالة (`function Stop-Agent`) بدل ما تكون
    بالمعنى، فوكلاء الفروع اللي دالتهم اسمها `Stop-Run` طلعوا إنذارات
    كاذبة (١١ سبتمبر ٢٠٢٦). دلوقتي بنشوف **النبضة نفسها**، فأي اسم
    للدالة بيعدّي.

    ومسموح كمان بـ`# no-beat:` جنب الـexit للحالات اللي السكوت فيها
    **مقصود** — زي الخروج لما فيه جولة تانية شغالة: هي اللي هتبعت
    النبضة، ولو بعتنا واحدة كمان يبقى النظام بيشوف نبضتين لجولة واحدة.
    الماركر لازم يتكتب بإيد ومعاه السبب، فمش هيتحط بالغلط.
    """
    src = open(path, encoding='utf-8').read()
    code, _ = strip_code(src)
    lines = code.split('\n')
    raw = src.split('\n')
    bad = []
    for i, ln in enumerate(lines, 1):
        if re.search(r'\bexit\b', ln):
            # مسموح (١): النبضة اتبعتت في نفس المسار قبل الـexit
            ctx = '\n'.join(lines[max(0, i - 25):i])
            if 'Send-Beat' in ctx or 'function Stop-Agent' in ctx:
                continue
            # مسموح (٢): سكوت مقصود ومعلّم بإيد
            # نافذة ٦ سطور: الوسم بيتكتب فوق الـWrite-Log اللي قبل
            # الـexit، والسبب بيكمّل على سطر أو اتنين تحته.
            near = '\n'.join(raw[max(0, i - 6):i])
            if 'no-beat:' in near:
                continue
            bad.append((i, raw[i - 1].strip()))
    return bad


# فحص النبضة لوكلاء المزامنة بس. السكربتات التانية (تركيب، تصدير
# سكيما) مالهاش نبضة أصلاً، وكانت بتطلع إنذارات كاذبة.
def needs_beat(path, src):
    return 'Send-Beat' in src or 'sync_beat' in src


ok = True
for p in sys.argv[1:]:
    print(f'--- {p} ---')
    e = check(p)
    if e:
        ok = False
        for x in e:
            print('  ❌', x)
    else:
        print('  ✅ البنية سليمة (أقواس · here-strings · نصوص · backticks)')

    src = open(p, encoding='utf-8').read()
    if not needs_beat(p, src):
        print('  — (مش وكيل مزامنة، فحص النبضة اتخطى)')
        continue

    bad = exit_paths(p)
    if bad:
        ok = False
        print('  ❌ exit من غير نبضة:')
        for l, t in bad:
            print(f'      سطر {l}: {t}')
    else:
        print('  ✅ مفيش أي exit بيهرب من غير نبضة')

sys.exit(0 if ok else 1)
