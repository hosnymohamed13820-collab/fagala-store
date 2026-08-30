import re, io, json

BS = chr(92)
css = io.open('tabler.css', encoding='utf-8').read()
pat = re.compile(r'\.ti-([a-z0-9-]+):before\s*\{\s*content:\s*"' + BS + BS + r'([0-9a-f]{4})"')
m = dict(pat.findall(css))
print("ايقونات في الخط الكامل:", len(m))

want = ['x', 'star', 'star-filled', 'book', 'bookmark', 'ban', 'moon', 'clipboard',
        'tag', 'alert-circle', 'check', 'shopping-cart', 'circle-x', 'forbid', 'sun',
        'key', 'paperclip', 'confetti', 'file-text', 'antenna-bars-5', 'zzz',
        'device-desktop', 'device-mobile-share', 'arrow-up', 'settings']
for k in want:
    print("  ti-%-20s -> %s" % (k, m.get(k, 'مش موجودة')))

json.dump(m, io.open('map.json', 'w', encoding='utf-8'))
print("اتحفظت map.json")
