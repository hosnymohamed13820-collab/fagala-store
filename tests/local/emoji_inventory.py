"""emoji_inventory.py — جرد كل الإيموجي الظاهر في الواجهة (الفجالة + إشراقة)، من غير التعليقات.
الاستخدام: PYTHONIOENCODING=utf-8 python tests/local/emoji_inventory.py [site|ashraqa]"""
import sys
import re,glob,os
EMOJI=re.compile('[\U0001F000-\U0001FAFF\u2600-\u26FF\u23E9-\u23FA\u2705\u274C\u2757\u2B50\u2B06\u2B07\uFE0F]')
COMMENT=re.compile(r'^\s*(//|\*|/\*|#|<!--|<\?php\s*/\*)')
n=0
pats=['site/**/*.php','site/assets/*.js','site/*.js']
only='site' if len(sys.argv)>1 and sys.argv[1] in ['site','ashraqa'] else ''
files=sorted(set(p.replace(os.sep,'/') for g in pats for p in glob.glob(g,recursive=True)))
for f in files:
    if f.endswith('config.php') or (only and not f.startswith(only+'/')): continue
    for i,l in enumerate(open(f,encoding='utf-8',errors='ignore'),1):
        if COMMENT.match(l) or not EMOJI.search(l): continue
        print(f"{f}:{i}: {l.strip()[:150]}"); n+=1
print(f"# total: {n}")
