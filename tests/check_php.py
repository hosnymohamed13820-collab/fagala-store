#!/usr/bin/env python3
"""
فاحص بنية PHP بسيط — بيشتغل من غير مفسّر PHP.

بيتخطى النصوص والتعليقات (لأن قوس جوه string مش قوس حقيقي)، وبيتأكد من:
  1. توازن { } ( ) [ ] في كود PHP بس
  2. توازن <?php ... ?>
  3. تطابق if/foreach/while/for + endif/endforeach/... (صيغة النقطتين)
"""
import sys, re

PAIRS = {'}': '{', ')': '(', ']': '['}
ALT_OPEN  = ('if', 'foreach', 'for', 'while', 'switch')
ALT_CLOSE = {'endif': 'if', 'endforeach': 'foreach', 'endfor': 'for',
             'endwhile': 'while', 'endswitch': 'switch'}


def scan(path):
    src = open(path, encoding='utf-8').read()
    errs = []
    i, n = 0, len(src)
    in_php = False
    stack = []        # أقواس
    alt = []          # صيغة النقطتين
    line = 1
    open_tags = 0

    while i < n:
        ch = src[i]
        if ch == '\n':
            line += 1

        if not in_php:
            if src.startswith('<?php', i) or src.startswith('<?=', i):
                in_php = True
                open_tags += 1
                i += 5 if src.startswith('<?php', i) else 3
                continue
            i += 1
            continue

        # داخل PHP
        if src.startswith('?>', i):
            in_php = False
            open_tags -= 1
            i += 2
            continue

        # التعليقات
        if src.startswith('//', i) or ch == '#':
            j = src.find('\n', i)
            j = n if j < 0 else j
            # ⚠️ ?> جوه تعليق // بيقفل وسم PHP فعلاً — المحلل بيشوفه
            #    قبل ما يعتبر السطر تعليق. ده بق صامت وقاتل:
            #    الباقي بيتطبع كنص والمتغيرات عمرها ما تتعرّف.
            #    (وقع فعلاً في treasury.php يوم 2026-08-23.)
            seg = src[i:j]
            if '?>' in seg:
                # نفرّق بين حالتين:
                #   <?php else: // categories ?>   ← مقصود، الوسم بيتقفل
                #                                     والسطر بيخلص عنده
                #   // <select ...><?= $x ?></select>  ← بق، فيه كود بعده
                after = seg.split('?>', 1)[1].strip()
                if after == '':
                    in_php = False            # الوسم اتقفل فعلاً
                    open_tags -= 1
                else:
                    errs.append(f'سطر {line}: ?> جوه تعليق // وبعده كود '
                                f'— الوسم بيتقفل والباقي بيتطبع كنص')
            i = j
            continue
        if src.startswith('/*', i):
            j = src.find('*/', i + 2)
            if j < 0:
                errs.append(f'سطر {line}: تعليق /* مش مقفول')
                break
            line += src.count('\n', i, j)
            i = j + 2
            continue

        # النصوص
        if ch in ('"', "'"):
            q, j = ch, i + 1
            while j < n:
                if src[j] == '\\':
                    j += 2
                    continue
                if src[j] == q:
                    break
                if src[j] == '\n':
                    line += 1
                j += 1
            if j >= n:
                errs.append(f'سطر {line}: نص {q} مش مقفول')
                break
            i = j + 1
            continue

        # heredoc / nowdoc
        m = re.match(r"<<<\s*(['\"]?)([A-Za-z_]\w*)\1\r?\n", src[i:])
        if m:
            tag = m.group(2)
            end = re.search(r'^\s*' + tag + r'\b', src[i + m.end():], re.M)
            if not end:
                errs.append(f'سطر {line}: heredoc {tag} مش مقفول')
                break
            seg = src[i:i + m.end() + end.end()]
            line += seg.count('\n')
            i += m.end() + end.end()
            continue

        # الأقواس
        if ch in '{([':
            stack.append((ch, line))
            i += 1
            continue
        if ch in '})]':
            if not stack:
                errs.append(f'سطر {line}: قوس زيادة {ch}')
            elif stack[-1][0] != PAIRS[ch]:
                o, ol = stack[-1]
                errs.append(f'سطر {line}: {ch} مش متطابق مع {o} (سطر {ol})')
                stack.pop()
            else:
                stack.pop()
            i += 1
            continue

        # صيغة النقطتين
        w = re.match(r'[A-Za-z_]\w*', src[i:])
        if w:
            word = w.group(0)
            low = word.lower()
            if low in ALT_CLOSE:
                if not alt:
                    errs.append(f'سطر {line}: {low} من غير فتح')
                elif alt[-1][0] != ALT_CLOSE[low]:
                    errs.append(f'سطر {line}: {low} والمفتوح {alt[-1][0]} (سطر {alt[-1][1]})')
                    alt.pop()
                else:
                    alt.pop()
            elif low in ALT_OPEN:
                # نشوف: بعد قوس الشرط، جه ":" ولا "{"؟
                j = i + len(word)
                while j < n and src[j] in ' \t\r\n':
                    j += 1
                if j < n and src[j] == '(':
                    d, k = 0, j
                    while k < n:
                        if src[k] == '(':
                            d += 1
                        elif src[k] == ')':
                            d -= 1
                            if d == 0:
                                break
                        k += 1
                    k += 1
                    while k < n and src[k] in ' \t\r\n':
                        k += 1
                    if k < n and src[k] == ':':
                        alt.append((low, line))
                elif low == 'switch':
                    pass
            i += len(word)
            continue

        i += 1

    for o, ol in stack:
        errs.append(f'سطر {ol}: {o} مفتوح ومش مقفول')
    for a, al in alt:
        errs.append(f'سطر {al}: {a}: مفتوح ومش مقفول (ناقص end{a})')
    # وسم <?php واحد مفتوح في الآخر = **سليم**، ده الشكل الموصى بيه
    # لملفات PHP الخالصة (من غير ?> عشان ما تطلعش مسافات بعد الملف).
    # الخطأ الحقيقي هو ?> زيادة (سالب) أو أكتر من وسم مفتوح.
    if open_tags < 0:
        errs.append(f'?> زيادة ({-open_tags})')
    elif open_tags > 1:
        errs.append(f'{open_tags} وسم <?php مفتوح ومش مقفول')
    return errs


ok = True
for p in sys.argv[1:]:
    e = scan(p)
    if e:
        ok = False
        print(f'❌ {p}')
        for x in e:
            print('   ', x)
    else:
        print(f'✅ {p} — البنية سليمة')
sys.exit(0 if ok else 1)
