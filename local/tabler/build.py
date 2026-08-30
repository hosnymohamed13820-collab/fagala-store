"""
build.py — يبني subset جديد لخط Tabler لنظام الفجالة ستور.

بياخد: قايمة الأيقونات المطلوبة → بيطلع tabler-subset.woff2 + .css
المصدر: Tabler Icons 3.0.0 (نفس إصدار الـsubset القديم — اتأكدنا من
ti-check = ea5e في الاتنين).
"""
import io, json, re, subprocess, sys, os

MAP = json.load(io.open('map.json', encoding='utf-8'))
OLD_CSS = 'D:/fagala_store/site/assets/tabler-subset.css'

# (١) اللي موجود دلوقتي — مايتشالش ولا واحد
old = sorted(set(re.findall(r'\.ti-([a-z0-9-]+):before', io.open(OLD_CSS, encoding='utf-8').read())))

# (٢) أيقونات مستعملة في الكود والخط مافيهوش شكلها (مربع فاضي عند المستخدم)
broken = ['arrows-sort', 'bookmark', 'copy', 'discount-2', 'printer', 'wand']

# (٣) الجديدة — بدل الإيموجيز
new = [
    'x',                 # ✗ ❌ ✕
    'star',              # ★
    'ban',               # 🚫 ⛔
    'book',              # 📚
    'key',               # 🔑
    'paperclip',         # 📎
    'clipboard',         # 📋
    'zzz',               # 💤
    'confetti',          # 🎉
    'moon',              # 🌙
    'arrow-up',          # ⬆
    'device-desktop',    # 🖥
    'antenna-bars-5',    # 📡
    'alert-circle',      # تنبيه أخف من المثلث
]

want = sorted(set(old) | set(broken) | set(new))

missing = [n for n in want if n not in MAP]
if missing:
    sys.exit('مش موجودة في الخط الكامل: ' + ', '.join(missing))

codes = sorted({MAP[n] for n in want})
print('أيقونات: %d قديمة + %d مكسورة + %d جديدة = %d فريدة (%d كود)'
      % (len(old), len(broken), len(new), len(want), len(codes)))

# (٤) الـsubset نفسه
unicodes = ','.join('U+' + c for c in codes)
cmd = [sys.executable, '-m', 'fontTools.subset', 'tabler.woff2',
       '--unicodes=' + unicodes,
       '--flavor=woff2',
       '--output-file=tabler-subset.woff2',
       '--no-hinting', '--desubroutinize',
       '--layout-features=', '--name-IDs=', '--notdef-outline']
r = subprocess.run(cmd, capture_output=True, text=True)
if r.returncode != 0:
    sys.exit('فشل الـsubset:\n' + r.stdout + r.stderr)
print('الخط اتعمل:', os.path.getsize('tabler-subset.woff2'), 'بايت')

# (٥) الـCSS بنفس شكل الملف القديم
lines = ['.ti-%s:before { content: "%s%s"; }' % (n, chr(92), MAP[n]) for n in want]
css = '''/* =====================================================================
 *  tabler-subset.css - ايقونات نظام الفجالة ستور
 *
 *  Tabler Icons 3.0.0 - MIT License
 *  https://github.com/tabler/tabler-icons/blob/master/LICENSE
 *
 *  ده subset فيه الـ%d ايقونة المستخدمة في النظام بس، بدل الخط الكامل
 *  (4595 ايقونة / 716KB) اللي كان بيتحمّل من jsdelivr مع كل صفحة.
 *
 *  ---------------------------------------------------------------
 *  لو ضفت ايقونة جديدة في اي صفحة:
 *    الايقونة مش هتظهر (هتبان مربع فاضي) لان الخط مافيهوش الشكل بتاعها.
 *    الحل: قولي على اسمها واعملك الخط من تاني.
 *  الايقونات المتاحة دلوقتي مكتوبة تحت بالترتيب.
 *  ---------------------------------------------------------------
 * ===================================================================== */

@font-face {
    font-family: "tabler-icons";
    font-style: normal;
    font-weight: 400;
    font-display: swap;
    src: url("tabler-subset.woff2") format("woff2");
}

.ti {
    font-family: "tabler-icons" !important;
    speak: none;
    font-style: normal;
    font-weight: normal;
    font-variant: normal;
    text-transform: none;
    line-height: 1;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

/* دوران — مستعملة مع ti-loader. مش ايقونة، خاصية حركة */
.ti-spin { display: inline-block; animation: tiSpin 0.9s linear infinite; }
@keyframes tiSpin { to { transform: rotate(360deg); } }

%s
''' % (len(want), '\n'.join(lines))

io.open('tabler-subset.css', 'w', encoding='utf-8', newline='\n').write(css)
print('الـCSS اتعمل:', os.path.getsize('tabler-subset.css'), 'بايت')
