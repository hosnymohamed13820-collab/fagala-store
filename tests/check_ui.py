#!/usr/bin/env python3
"""يمنع أي صفحة جديدة من اختراع شكل واجهة برّه fagala.css."""
import re
import sys
from pathlib import Path

if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8')
    sys.stderr.reconfigure(encoding='utf-8')

ROOT = Path(__file__).resolve().parent.parent
SITE = ROOT / 'site'
CSS = SITE / 'assets' / 'fagala.css'
ICONS = SITE / 'assets' / 'tabler-subset.css'
LEGACY = ROOT / 'tests' / 'ui_legacy.txt'
CATS = ('hex', 'var', 'icon', 'btn', 'table', 'mono', 'control', 'component', 'override')
HELP = {
    'hex': 'استخدم توكن لون من fagala.css — شوف ui_kit.php',
    'var': 'عرّف التوكن في fagala.css أو استخدم توكن موجود — شوف ui_kit.php',
    'icon': 'استخدم أيقونة موجودة في tabler-subset.css — شوف ui_kit.php',
    'btn': 'استخدم `.btn` من fagala.css — شوف ui_kit.php',
    'table': 'استخدم `<table class="ftable">` — شوف ui_kit.php',
    'mono': 'استخدم `font-variant-numeric: tabular-nums` — شوف ui_kit.php',
    'control': 'استخدم `.finput` و`.fselect` و`.ftextarea` — شوف ui_kit.php',
    'component': 'استخدم `.fcard` أو `.ftabs` — شوف ui_kit.php',
    'override': 'شكل مكوّنات المكتبة من fagala.css بس — شوف ui_kit.php',
}

STYLE = re.compile(r'<style\b[^>]*>(.*?)</style\s*>', re.I | re.S)
STYLE_ATTR = re.compile(r'\bstyle\s*=\s*(["\'])(.*?)\1', re.I | re.S)
HEX = re.compile(r'(?<![\w-])#[0-9a-fA-F]{3,8}\b')
VAR_USE = re.compile(r'var\(\s*(--[a-z0-9-]+)', re.I)
VAR_DEF = re.compile(r'(--[a-z0-9-]+)\s*:', re.I)
ICON = re.compile(r'\bti-([a-z0-9-]+)\b')
TABLE = re.compile(r'<table\b([^>]*)>', re.I | re.S)
CLASS = re.compile(r'\bclass\s*=\s*(["\'])(.*?)\1', re.I | re.S)
RULE = re.compile(r'([^{}]+)\{([^{}]*)\}', re.S)
TAG = re.compile(r'<[a-z][^>]*>', re.I | re.S)
DECL = re.compile(r'(?:^|;)\s*([a-z-]+)\s*:\s*([^;}]*)', re.I)
UI_COMPONENTS = (
    'finput', 'fselect', 'ftextarea', 'ffield', 'fcard', 'fcard-head',
    'fcard-title', 'ftabs', 'ftab', 'ftable', 'falert', 'fkpis', 'fkpi',
    'ffilters', 'fpage-head', 'fchip',
)
SHAPE_PROP = re.compile(
    r'^(?:background(?:-[a-z-]+)?|border(?:-[a-z-]+)?|padding(?:-[a-z-]+)?|'
    r'box-shadow|font-size|font-weight|color|height|min-height)$', re.I
)
CARD_STATUS_BORDER = re.compile(r'^border-[a-z-]+-color$', re.I)
TOKEN_VALUE = re.compile(r'^var\(\s*--[a-z0-9-]+\s*\)(?:\s*!important)?$', re.I)


def selector_components(selector):
    """يرجّع مكوّنات المكتبة المذكورة بالاسم الصريح في الـselector."""
    found = set()
    for name in UI_COMPONENTS:
        if re.search(rf'\.{re.escape(name)}(?![a-z0-9_-])', selector, re.I):
            found.add(name)
    for part in selector.split(','):
        # تعريف .btn الأصلي ليه فحصه القديم؛ هنا المقصود override تحته أب.
        if re.search(r'[\s>+~]\.btn(?![a-z0-9_-])', part.strip(), re.I):
            found.add('btn')
    return found


def inline_components(attrs):
    found = set()
    for _quote, value in CLASS.findall(attrs):
        classes = set(re.findall(r'[a-zA-Z0-9_-]+', value))
        found.update(name for name in UI_COMPONENTS if name in classes)
        if 'btn' in classes:
            found.add('btn')
    return found


def has_shape(declarations, components):
    for prop, value in DECL.findall(declarations):
        if not SHAPE_PROP.match(prop):
            continue
        # شريط حالة الكارت مسموح، بشرط اللون ييجي من توكن.
        if ('fcard' in components and CARD_STATUS_BORDER.match(prop)
                and TOKEN_VALUE.match(value.strip())):
            continue
        return True
    return False


def override_help(components):
    if components == {'btn'}:
        return 'override صغير على `.btn` — اتأكد إنه لازم'
    name = sorted(components - {'btn'})[0] if components - {'btn'} else 'btn'
    return (f'الصفحة بتعيد تعريف شكل `.{name}` — الشكل من fagala.css بس؛ '
            'لو محتاج حجم تاني استخدم `.finput-sm` أو اطلب مكوّن — شوف ui_kit.php')


def css_parts(text):
    blocks = STYLE.findall(text)
    inline = [m[1] for m in STYLE_ATTR.findall(text)]
    return blocks, inline


def scan_file(path):
    text = path.read_text(encoding='utf-8', errors='ignore')
    blocks, inline = css_parts(text)
    page_css = '\n'.join(blocks + inline)
    defined = set(VAR_DEF.findall(CSS.read_text(encoding='utf-8', errors='ignore'))) | set(VAR_DEF.findall(page_css))
    icons = set(re.findall(r'\.ti-([a-z0-9-]+)', ICONS.read_text(encoding='utf-8', errors='ignore')))
    counts = {key: 0 for key in CATS}
    counts['hex'] = len(HEX.findall(page_css))
    counts['var'] = sum(1 for name in VAR_USE.findall(page_css) if name not in defined)
    counts['icon'] = sum(1 for name in ICON.findall(text) if name not in icons)
    counts['table'] = sum(1 for attrs in TABLE.findall(text)
                          if not any('ftable' in m[1].split() for m in CLASS.findall(attrs)))
    counts['mono'] = len(re.findall(r'\bmonospace\b', page_css, re.I))
    override_details = []

    for block in blocks:
        clean = re.sub(r'/\*.*?\*/', '', block, flags=re.S)
        for selector, body in RULE.findall(clean):
            components = selector_components(selector)
            if components and has_shape(body, components):
                override_details.append(components)
            for part in selector.split(','):
                sel = part.strip()
                if re.match(r'^\.btn(?=$|[:.\[#\s])', sel):
                    counts['btn'] += 1
                if re.search(r'(^|[\s>+~])(?:input|select|textarea)(?=$|[:.\[#\s])', sel, re.I):
                    counts['control'] += 1
                if re.search(r'\.[a-z0-9_-]+-(?:card|tabs)(?=$|[:.\[#\s])', sel, re.I):
                    counts['component'] += 1
    for tag in TAG.findall(text):
        style = STYLE_ATTR.search(tag)
        if not style:
            continue
        components = inline_components(tag)
        if components and has_shape(style.group(2), components):
            override_details.append(components)
    counts['override'] = len(override_details)
    return counts, override_details


def candidates(paths):
    if paths:
        return [Path(p).resolve() for p in paths]
    return sorted(p for p in SITE.rglob('*.php') if p.name != 'ui.php')


def load_legacy():
    data = {}
    if not LEGACY.exists(): return data
    for raw in LEGACY.read_text(encoding='utf-8', errors='ignore').splitlines():
        line = raw.split('#', 1)[0].strip()
        if not line: continue
        parts = line.split()
        vals = {k: int(v) for k, v in (x.split('=', 1) for x in parts[1:]) if k in CATS and v.isdigit()}
        data[parts[0]] = vals
    return data


def rel(path):
    try: return path.relative_to(SITE).as_posix()
    except ValueError: return path.name


def dump(paths):
    print('# صفحة  التصنيف=العدد — القديم مسموح يقل بس ممنوع يزيد')
    for path in candidates(paths):
        found, _details = scan_file(path)
        if any(found.values()):
            print(rel(path), ' '.join(f'{k}={found[k]}' for k in CATS if found[k]))


def main():
    args = [x for x in sys.argv[1:] if x != '--dump']
    if '--dump' in sys.argv:
        dump(args); return 0
    legacy = load_legacy() if not args else {}
    bad = []
    for path in candidates(args):
        name = rel(path)
        found, override_details = scan_file(path)
        allowed = legacy.get(name, {})
        for kind, count in found.items():
            if count > allowed.get(kind, 0):
                details = override_details if kind == 'override' else []
                bad.append((name, kind, count, allowed.get(kind, 0), details))
    for name, kind, count, allowed, details in bad:
        suffix = f' (المسموح قديمًا {allowed})' if allowed else ''
        help_text = override_help(set().union(*details)) if details else HELP[kind]
        print(f'  ❌ {name}: {count} مخالفة [{kind}]{suffix} — {help_text}')
    if bad:
        print(f'  ❌ فحص الواجهة فشل: {len(bad)} نوع مخالفة')
        return 1
    print('  ✅ مفيش صفحة زوّدت مخالفة واجهة، والصفحات الجديدة نضيفة')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
