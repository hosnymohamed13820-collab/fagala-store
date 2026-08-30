import io, re, sys
from fontTools.ttLib import TTFont

css = io.open('tabler-subset.css', encoding='utf-8').read()
pairs = re.findall(r'\.ti-([a-z0-9-]+):before\s*\{\s*content:\s*"' + chr(92) + chr(92) + r'([0-9a-f]{4})"', css)
print('كلاسات في الـCSS:', len(pairs))

f = TTFont('tabler-subset.woff2')
cmap = f.getBestCmap()
print('أكواد في الخط:', len(cmap))

bad = [(n, c) for n, c in pairs if int(c, 16) not in cmap]
if bad:
    print('🔴 كلاسات مالهاش شكل في الخط:')
    for n, c in bad:
        print('   ti-%s (%s)' % (n, c))
    sys.exit(1)

print('كل كلاس ليه شكل في الخط ✓')

# اتأكد إن الأشكال مش فاضية (glyph فيه نقط فعلاً)
glyf = f.get('glyf')
empty = []
for n, c in pairs:
    gname = cmap[int(c, 16)]
    if glyf is not None:
        g = glyf[gname]
        if g.numberOfContours == 0:
            empty.append(n)
if empty:
    print('⚠️ أشكال فاضية:', ', '.join(empty))
else:
    print('مفيش شكل فاضي ✓')
