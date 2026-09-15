#!/usr/bin/env python3
"""
فاحص أسماء الأعمدة في استعلامات SQL — الفجالة ستور
بيفحص:
  1. INSERT INTO t (c1, c2, ...)
  2. UPDATE t SET c1 = ..., c2 = ... (بجدول واحد وبدون JOIN)
  3. alias.col لما الـalias متعرّف بـ FROM t alias أو JOIN t alias
  4. استعلام SELECT فيه FROM t واحد بس من غير JOIN ولا subquery ولا UNION

مبني بناءً على حادثة ١ سبتمبر ٢٠٢٦ لما الموقع وقع بسبب طلب عمود description
من جدول custody_deficits وكان العمود الحقيقي notes.
"""

import sys
import os
import glob
import re
from pathlib import Path

# مطب ويندوز: الترمينال cp1256 بيوقع بايثون بـUnicodeEncodeError عند طباعة العربي أو الإيموجي
if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8')
if hasattr(sys.stderr, 'reconfigure'):
    sys.stderr.reconfigure(encoding='utf-8')

ROOT_DIR = Path(__file__).resolve().parent.parent

SQL_KEYWORDS = {
    'select', 'insert', 'update', 'delete', 'from', 'where', 'and', 'or', 'not',
    'in', 'is', 'null', 'like', 'between', 'exists', 'order', 'by', 'group',
    'having', 'limit', 'offset', 'asc', 'desc', 'as', 'distinct', 'all',
    'into', 'values', 'set', 'join', 'left', 'right', 'inner', 'outer', 'cross',
    'on', 'using', 'case', 'when', 'then', 'else', 'end', 'default', 'true',
    'false', 'interval', 'day', 'month', 'year', 'hour', 'minute', 'second',
    'date', 'time', 'timestamp', 'now', 'current_timestamp', 'current_date',
    'union', 'collate', 'binary', 'regexp', 'rlike', 'table', 'key', 'index',
    'primary', 'foreign', 'constraint', 'check', 'duplicate', 'lock', 'for',
    'share', 'mode', 'straight_join', 'natural', 'force', 'ignore', 'use',
    'rows', 'row', 'over', 'partition', 'window', 'with', 'recursive',
    'match', 'against', 'boolean', 'language', 'query', 'expansion',
    'char', 'varchar', 'int', 'integer', 'tinyint', 'smallint', 'mediumint',
    'bigint', 'float', 'double', 'decimal', 'numeric', 'datetime',
    'unsigned', 'zerofill', 'auto_increment', 'replace', 'show', 'describe',
    'explain', 'create', 'alter', 'drop', 'truncate', 'rename', 'database',
    'view', 'procedure', 'function', 'trigger', 'grant', 'revoke'
}

NON_ALIAS_WORDS = SQL_KEYWORDS | {
    'where', 'join', 'left', 'right', 'inner', 'outer', 'cross', 'straight_join',
    'natural', 'on', 'using', 'order', 'group', 'having', 'limit', 'offset',
    'union', 'set', 'into', 'values', 'select', 'and', 'or', 'for', 'lock',
    'window', 'partition'
}


def clean_sql_comments(sql_text):
    """إزالة التعليقات من كود SQL لبناء المخطط"""
    sql_text = re.sub(r'/\*.*?\*/', ' ', sql_text, flags=re.DOTALL)
    sql_text = re.sub(r'--[^\n]*', ' ', sql_text)
    sql_text = re.sub(r'#[^\n]*', ' ', sql_text)
    return sql_text


def parse_schema(local_dir):
    """
    يبني خريطة {جدول: set(أعمدة)} ومجموعة الـVIEWs من:
      - schema_live.sql (تصدير phpMyAdmin)
      - كل ملفات local/*.sql التانية
    """
    tables = {}
    views = set()

    schema_live_path = local_dir / 'schema_live.sql'
    if schema_live_path.exists():
        with open(schema_live_path, 'r', encoding='utf-8', errors='ignore') as f:
            schema_live_text = f.read()

        # الـVIEWs في schema_live.sql
        for m in re.finditer(r'Stand-in structure for view\s+[`\']?(\w+)[`\']?', schema_live_text, re.I):
            views.add(m.group(1).lower())
        for m in re.finditer(r'CREATE(?:.*?)\s+VIEW\s+(?:`?\w+`?\.)?`?(\w+)`?', schema_live_text, re.I):
            views.add(m.group(1).lower())

        # جداول schema_live.sql: كل سطر عمود بيبدأ بـ `  `col``
        lines = schema_live_text.splitlines()
        in_create = False
        current_tbl = None
        for line in lines:
            m_tbl = re.match(r'CREATE\s+TABLE\s+(?:`?\w+`?\.)?`(\w+)`\s*\(', line, re.I)
            if m_tbl:
                in_create = True
                current_tbl = m_tbl.group(1).lower()
                if current_tbl not in tables:
                    tables[current_tbl] = set()
                continue
            if in_create:
                if line.startswith(') ENGINE=') or line.startswith(');'):
                    in_create = False
                    current_tbl = None
                    continue
                m_col = re.match(r'^\s*`(\w+)`', line)
                if m_col:
                    tables[current_tbl].add(m_col.group(1).lower())

    # قراءة كل ملفات local/*.sql التانية
    for fpath in sorted(local_dir.glob('*.sql')):
        if fpath.name == 'schema_live.sql':
            continue
        with open(fpath, 'r', encoding='utf-8', errors='ignore') as f:
            raw_text = f.read()

        # كشف الـVIEWs
        for m in re.finditer(r'CREATE(?:.*?)\s+VIEW\s+(?:`?\w+`?\.)?`?(\w+)`?', raw_text, re.I):
            views.add(m.group(1).lower())

        clean = clean_sql_comments(raw_text)

        # CREATE TABLE [IF NOT EXISTS]
        for m in re.finditer(r'CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+([^\s(]+)\s*\((.*?)\)\s*(?:ENGINE|[;]|$)', clean, re.I | re.DOTALL):
            tbl_raw = m.group(1).strip()
            tbl_name = tbl_raw.replace('`', '').replace("'", "").split('.')[-1].strip().lower()
            body = m.group(2)
            if tbl_name not in tables:
                tables[tbl_name] = set()
            for line in body.split('\n'):
                line = line.strip()
                if not line or re.match(r'^(PRIMARY\s+KEY|UNIQUE\s+KEY|UNIQUE|KEY|INDEX|CONSTRAINT|CHECK|FOREIGN\s+KEY)\b', line, re.I):
                    continue
                m_col = re.match(r'^(?:`([a-zA-Z0-9_]+)`|([a-zA-Z0-9_]+))\s+', line)
                if m_col:
                    col = (m_col.group(1) or m_col.group(2)).lower()
                    tables[tbl_name].add(col)

        # ALTER TABLE [db.]table
        alter_matches = re.finditer(
            r'ALTER\s+TABLE\s+(?:`?([a-zA-Z0-9_]+)`?\.)?`?([a-zA-Z0-9_]+)`?\s+([^;]+?)(?=(?:ALTER\s+TABLE|CREATE\s+TABLE|;|$))',
            clean,
            re.I | re.DOTALL
        )
        for m in alter_matches:
            tbl_name = m.group(2).lower()
            body = m.group(3)
            if tbl_name not in tables:
                tables[tbl_name] = set()

            # ADD [COLUMN] [IF NOT EXISTS]
            for add_m in re.finditer(r'\bADD(?:\s+COLUMN)?(?:\s+IF\s+NOT\s+EXISTS)?\s+(?:`([a-zA-Z0-9_]+)`|([a-zA-Z0-9_]+))\s+', body, re.I):
                c = (add_m.group(1) or add_m.group(2)).lower()
                if c.upper() not in ('KEY', 'INDEX', 'UNIQUE', 'CONSTRAINT', 'PRIMARY', 'CHECK', 'FOREIGN'):
                    tables[tbl_name].add(c)

            # CHANGE [COLUMN] old new
            for chg_m in re.finditer(r'\bCHANGE(?:\s+COLUMN)?\s+(?:`([a-zA-Z0-9_]+)`|([a-zA-Z0-9_]+))\s+(?:`([a-zA-Z0-9_]+)`|([a-zA-Z0-9_]+))\b', body, re.I):
                old_c = (chg_m.group(1) or chg_m.group(2)).lower()
                new_c = (chg_m.group(3) or chg_m.group(4)).lower()
                tables[tbl_name].discard(old_c)
                tables[tbl_name].add(new_c)

            # RENAME COLUMN old TO new
            for ren_m in re.finditer(r'\bRENAME\s+COLUMN\s+(?:`([a-zA-Z0-9_]+)`|([a-zA-Z0-9_]+))\s+TO\s+(?:`([a-zA-Z0-9_]+)`|([a-zA-Z0-9_]+))\b', body, re.I):
                old_c = (ren_m.group(1) or ren_m.group(2)).lower()
                new_c = (ren_m.group(3) or ren_m.group(4)).lower()
                tables[tbl_name].discard(old_c)
                tables[tbl_name].add(new_c)

    return tables, views


HEREDOC_START = re.compile(r"<<<\s*(['\"]?)([A-Za-z_]\w*)\1\r?\n")

def extract_php_strings(src):
    """استخراج النصوص من ملف PHP مع أرقام الأسطر"""
    results = []
    i, n = 0, len(src)
    in_php = False
    line = 1

    while i < n:
        ch = src[i]
        if ch == '\n':
            line += 1
            i += 1
            continue

        if not in_php:
            if src.startswith('<?php', i) or src.startswith('<?=', i):
                in_php = True
                i += 5 if src.startswith('<?php', i) else 3
                continue
            i += 1
            continue

        # داخل PHP
        if src.startswith('?>', i):
            in_php = False
            i += 2
            continue

        # التعليقات
        if src.startswith('//', i) or ch == '#':
            j = src.find('\n', i)
            if j < 0:
                break
            seg = src[i:j]
            if '?>' in seg:
                after = seg.split('?>', 1)[1].strip()
                if after == '':
                    in_php = False
            line += 1
            i = j + 1
            continue

        if src.startswith('/*', i):
            j = src.find('*/', i + 2)
            if j < 0:
                break
            line += src.count('\n', i, j + 2)
            i = j + 2
            continue

        # النصوص
        if ch in ('"', "'"):
            q = ch
            start_line = line
            j = i + 1
            while j < n:
                c = src[j]
                if c == '\\':
                    if j + 1 < n and src[j+1] == '\n':
                        line += 1
                    j += 2
                    continue
                if c == q:
                    break
                if c == '\n':
                    line += 1
                j += 1
            if j >= n:
                break
            results.append((src[i + 1:j], start_line))
            i = j + 1
            continue

        # heredoc / nowdoc
        if ch == '<' and src.startswith('<<<', i):
            m = HEREDOC_START.match(src, i)
            if m:
                start_line = line
                tag = m.group(2)
                search_start = m.end()
                line += src.count('\n', i, search_start)
                end = re.search(r'^\s*' + re.escape(tag) + r';?\r?$', src[search_start:], re.M)
                if end:
                    content = src[search_start:search_start + end.start()]
                    line += src.count('\n', search_start, search_start + end.end())
                    results.append((content, start_line))
                    i = search_start + end.end()
                    continue
                else:
                    i = search_start
                    continue

        i += 1

    return results


def mask_string_literals(sql):
    """إخفاء السترنجات في SQL بمسافات عشان متنأثرش على الفحص"""
    chars = list(sql)
    n = len(chars)
    i = 0
    while i < n:
        c = chars[i]
        if c in ("'", '"'):
            q = c
            start = i
            i += 1
            while i < n:
                if chars[i] == '\\':
                    i += 2
                    continue
                if chars[i] == q:
                    break
                i += 1
            end = i
            for k in range(start, min(end + 1, n)):
                chars[k] = ' '
        i += 1
    return ''.join(chars)


def mask_function_calls(text):
    """إخفاء استدعاءات الدوال IDENT(...) بمسافات"""
    pattern = re.compile(r'\b[a-zA-Z0-9_]+\s*\(')
    while True:
        m = pattern.search(text)
        if not m:
            break
        open_pos = m.end() - 1
        depth = 1
        pos = open_pos + 1
        n = len(text)
        while pos < n and depth > 0:
            if text[pos] == '(':
                depth += 1
            elif text[pos] == ')':
                depth -= 1
            pos += 1
        text = text[:m.start()] + ' ' * (pos - m.start()) + text[pos:]
    return text


def load_allow_list(allow_file_path):
    """تحميل ملف السماح tests/check_columns_allow.txt"""
    allowed = set()
    if not allow_file_path.exists():
        return allowed
    with open(allow_file_path, 'r', encoding='utf-8', errors='ignore') as f:
        for line in f:
            line = line.split('#')[0].strip()
            if not line:
                continue
            parts = line.split()
            if len(parts) >= 2:
                file_key = os.path.basename(parts[0])
                tbl_col = parts[1].lower()
                allowed.add((file_key, tbl_col))
    return allowed


def check_php_file(fpath, tables, views):
    with open(fpath, 'r', encoding='utf-8', errors='ignore') as fp:
        content = fp.read()

    php_strings = extract_php_strings(content)
    errors = []
    warnings = []
    queries_count = 0

    for sql_raw, line_num in php_strings:
        # البحث عن استعلامات SQL المؤكدة
        if not re.search(r'\b(SELECT\s+.+?\s+FROM|INSERT\s+INTO|UPDATE\s+.+?\s+SET|DELETE\s+FROM)\b', sql_raw, re.I | re.DOTALL):
            continue

        queries_count += 1
        sql_masked = mask_string_literals(sql_raw)

        # -------------------------------------------------------------
        # ١) INSERT INTO t (c1, c2, ...)
        # -------------------------------------------------------------
        for m in re.finditer(r'\b(?:INSERT|REPLACE)\s+(?:LOW_PRIORITY\s+|DELAYED\s+|HIGH_PRIORITY\s+|IGNORE\s+)*INTO\s+([^\s(]+)\s*\(([^)]+)\)', sql_masked, re.I):
            tbl_raw = m.group(1).strip()
            cols_raw = m.group(2).strip()
            if '$' in tbl_raw:
                continue
            tbl = tbl_raw.replace('`', '').split('.')[-1].strip().lower()
            if tbl in views:
                continue
            if tbl not in tables:
                warnings.append((fpath, line_num, tbl))
                continue

            for c_raw in cols_raw.split(','):
                c = c_raw.strip().strip('`').strip()
                if not c or '$' in c:
                    continue
                if c.lower() not in tables[tbl]:
                    errors.append((fpath, line_num, tbl, c))

        # -------------------------------------------------------------
        # ٢) UPDATE t SET c1 = ..., c2 = ... (بجدول واحد من غير JOIN)
        # -------------------------------------------------------------
        if re.search(r'\bUPDATE\b', sql_masked, re.I) and not re.search(r'\bJOIN\b', sql_masked, re.I):
            m_up = re.search(
                r'\bUPDATE\s+(?:LOW_PRIORITY\s+|IGNORE\s+)*([^\s,]+)\s+SET\s+(.+?)(?=\s+\bWHERE\b|\s+\bORDER\s+BY\b|\s+\bLIMIT\b|;|\Z)',
                sql_masked,
                re.I | re.DOTALL
            )
            if m_up:
                tbl_raw = m_up.group(1).strip()
                set_body = m_up.group(2)
                if ',' not in tbl_raw and '$' not in tbl_raw:
                    tbl = tbl_raw.replace('`', '').split('.')[-1].strip().lower()
                    if tbl not in views:
                        if tbl not in tables:
                            warnings.append((fpath, line_num, tbl))
                        else:
                            # تقسيم خانات SET على المستوى 0
                            depth = 0
                            chunk_start = 0
                            chunks = []
                            for idx, ch in enumerate(set_body):
                                if ch == '(':
                                    depth += 1
                                elif ch == ')':
                                    depth -= 1
                                elif ch == ',' and depth == 0:
                                    chunks.append(set_body[chunk_start:idx])
                                    chunk_start = idx + 1
                            chunks.append(set_body[chunk_start:])

                            for chunk in chunks:
                                eq_pos = chunk.find('=')
                                if eq_pos > 0:
                                    lhs = chunk[:eq_pos].strip()
                                    col = lhs.split('.')[-1].strip().strip('`').strip()
                                    if col and '$' not in col:
                                        if col.lower() not in tables[tbl]:
                                            errors.append((fpath, line_num, tbl, col))

        # -------------------------------------------------------------
        # ٣) alias.col لما الـalias متعرّف بـ FROM t alias أو JOIN t alias
        # -------------------------------------------------------------
        aliases = {}
        for m in re.finditer(r'\bFROM\s+([a-zA-Z0-9_`.]+)(?:\s+(?:AS\s+)?([a-zA-Z0-9_`]+))?', sql_masked, re.I):
            t_raw = m.group(1)
            if t_raw.startswith('('):
                continue
            t_name = t_raw.replace('`', '').split('.')[-1].strip().lower()
            a_cand = m.group(2)
            if a_cand:
                a_name = a_cand.replace('`', '').strip().lower()
                if a_name not in NON_ALIAS_WORDS:
                    aliases[a_name] = t_name

        for m in re.finditer(r'\b(?:(?:LEFT|RIGHT|INNER|OUTER|CROSS|STRAIGHT_JOIN|NATURAL)\s+)?JOIN\s+([a-zA-Z0-9_`.]+)(?:\s+(?:AS\s+)?([a-zA-Z0-9_`]+))?', sql_masked, re.I):
            t_raw = m.group(1)
            if t_raw.startswith('('):
                continue
            t_name = t_raw.replace('`', '').split('.')[-1].strip().lower()
            a_cand = m.group(2)
            if a_cand:
                a_name = a_cand.replace('`', '').strip().lower()
                if a_name not in NON_ALIAS_WORDS:
                    aliases[a_name] = t_name

        if aliases:
            for m in re.finditer(r'\b([a-zA-Z0-9_`]+)\.([a-zA-Z0-9_`*]+)\b', sql_masked):
                a_raw = m.group(1).replace('`', '').lower()
                c_raw = m.group(2).replace('`', '')
                if c_raw == '*' or '$' in c_raw or '$' in a_raw:
                    continue
                if a_raw in aliases:
                    target_tbl = aliases[a_raw]
                    if '$' in target_tbl or target_tbl in views:
                        continue
                    if target_tbl not in tables:
                        warnings.append((fpath, line_num, target_tbl))
                        continue
                    if c_raw.lower() not in tables[target_tbl]:
                        errors.append((fpath, line_num, target_tbl, c_raw))

        # -------------------------------------------------------------
        # ٤) استعلام SELECT بـ FROM t واحد بس من غير JOIN ولا subquery ولا UNION
        # -------------------------------------------------------------
        if re.search(r'^\s*SELECT\b', sql_masked, re.I) and not re.search(r'\bJOIN\b', sql_masked, re.I) and not re.search(r'\bUNION\b', sql_masked, re.I) and not re.search(r'\(\s*SELECT\b', sql_masked, re.I):
            from_matches = list(re.finditer(r'\bFROM\b', sql_masked, re.I))
            if len(from_matches) == 1:
                from_pos = from_matches[0].start()
                after_from = sql_masked[from_matches[0].end():].strip()
                m_tbl = re.match(r'^(?:`?([a-zA-Z0-9_]+)`?\.)?`?([a-zA-Z0-9_$]+)`?(?:\s+(?:AS\s+)?([a-zA-Z0-9_`]+))?', after_from, re.I)
                if m_tbl:
                    tbl_raw = m_tbl.group(2)
                    alias_cand = m_tbl.group(3)
                    tbl_alias = None
                    if alias_cand and alias_cand.lower() not in NON_ALIAS_WORDS:
                        tbl_alias = alias_cand.replace('`', '').lower()

                    if '$' not in tbl_raw:
                        tbl = tbl_raw.replace('`', '').strip().lower()
                        tbl_end = m_tbl.end()
                        rest_after_tbl = after_from[tbl_end:].strip()
                        if not rest_after_tbl.startswith(','):
                            if tbl not in views:
                                if tbl not in tables:
                                    warnings.append((fpath, line_num, tbl))
                                else:
                                    # قايمة SELECT
                                    select_part = sql_masked[:from_pos]
                                    m_sel = re.match(r'^\s*SELECT\s+(?:(?:DISTINCT|ALL|HIGH_PRIORITY|SQL_CALC_FOUND_ROWS)\s+)*(.+)', select_part, re.I | re.DOTALL)
                                    select_aliases = set()
                                    raw_cols = []
                                    if m_sel:
                                        sel_body = m_sel.group(1).strip()
                                        depth = 0
                                        chunk_start = 0
                                        sel_items = []
                                        for idx, ch in enumerate(sel_body):
                                            if ch == '(':
                                                depth += 1
                                            elif ch == ')':
                                                depth -= 1
                                            elif ch == ',' and depth == 0:
                                                sel_items.append(sel_body[chunk_start:idx].strip())
                                                chunk_start = idx + 1
                                        sel_items.append(sel_body[chunk_start:].strip())

                                        for item in sel_items:
                                            m_as = re.search(r'\bAS\s+([a-zA-Z0-9_`]+)', item, re.I)
                                            if m_as:
                                                alias_name = m_as.group(1).replace('`', '').lower()
                                                select_aliases.add(alias_name)
                                                expr = item[:m_as.start()].strip()
                                            else:
                                                expr = item

                                            if '(' not in expr and not re.match(r'^\s*[\d\']', expr):
                                                col = expr.split('.')[-1].strip().strip('`').strip()
                                                if col and col != '*' and '$' not in col and col.lower() not in SQL_KEYWORDS:
                                                    raw_cols.append(col)

                                    for col in raw_cols:
                                        if col.lower() not in tables[tbl]:
                                            errors.append((fpath, line_num, tbl, col))

                                    # فحص WHERE, GROUP BY, ORDER BY
                                    rest_masked = mask_function_calls(rest_after_tbl)
                                    clauses_to_check = []
                                    m_where = re.search(r'\bWHERE\b\s+(.+?)(?=\s+\bGROUP\s+BY\b|\s+\bHAVING\b|\s+\bORDER\s+BY\b|\s+\bLIMIT\b|;|\Z)', rest_masked, re.I | re.DOTALL)
                                    if m_where:
                                        clauses_to_check.append(('WHERE', m_where.group(1)))
                                    m_grp = re.search(r'\bGROUP\s+BY\b\s+(.+?)(?=\s+\bHAVING\b|\s+\bORDER\s+BY\b|\s+\bLIMIT\b|;|\Z)', rest_masked, re.I | re.DOTALL)
                                    if m_grp:
                                        clauses_to_check.append(('GROUP BY', m_grp.group(1)))
                                    m_ord = re.search(r'\bORDER\s+BY\b\s+(.+?)(?=\s+\bLIMIT\b|;|\Z)', rest_masked, re.I | re.DOTALL)
                                    if m_ord:
                                        clauses_to_check.append(('ORDER BY', m_ord.group(1)))

                                    for c_name, c_text in clauses_to_check:
                                        c_clean = re.sub(r'\?|:[a-zA-Z0-9_]+', ' ', c_text)
                                        c_clean = re.sub(r'\b\d+(?:\.\d+)?\b', ' ', c_clean)
                                        for token_m in re.finditer(r'\b(?:([a-zA-Z0-9_`]+)\.)?([a-zA-Z0-9_`$]+)\b', c_clean):
                                            prefix = token_m.group(1)
                                            ident = token_m.group(2)
                                            if prefix:
                                                p = prefix.replace('`', '').lower()
                                                if p != tbl and (tbl_alias is None or p != tbl_alias):
                                                    continue
                                            w = ident.replace('`', '').lower()
                                            if '$' in w or not w or w in SQL_KEYWORDS or w in select_aliases:
                                                continue
                                            if w == tbl or (tbl_alias and w == tbl_alias):
                                                continue
                                            if w not in tables[tbl]:
                                                errors.append((fpath, line_num, tbl, ident))

    return errors, warnings, queries_count


def main():
    local_dir = ROOT_DIR / 'local'
    allow_file = ROOT_DIR / 'tests' / 'check_columns_allow.txt'

    tables, views = parse_schema(local_dir)
    allowed = load_allow_list(allow_file)

    # تحديد الملفات المطلوبة
    if len(sys.argv) > 1:
        target_files = []
        for arg in sys.argv[1:]:
            expanded = glob.glob(arg)
            if expanded:
                target_files.extend(expanded)
            else:
                target_files.append(arg)
    else:
        target_files = sorted(glob.glob(str(ROOT_DIR / 'site' / '*.php')) + glob.glob(str(ROOT_DIR / 'site' / 'includes' / '*.php')))

    all_errors = []
    all_warnings = []
    total_queries = 0
    seen_errors = set()
    seen_warnings = set()

    for fpath in target_files:
        errs, warns, q_count = check_php_file(str(fpath), tables, views)
        total_queries += q_count

        # تصفية التحذيرات
        for w in warns:
            w_key = (os.path.basename(w[0]), w[1], w[2])
            if w_key not in seen_warnings:
                seen_warnings.add(w_key)
                all_warnings.append(w)

        # تصفية الأخطاء واستثناء ما في ملف السماح
        for e in errs:
            file_key = os.path.basename(e[0])
            tbl_col = f"{e[2].lower()}.{e[3].lower()}"
            if (file_key, tbl_col) in allowed:
                continue
            err_key = (file_key, e[1], tbl_col)
            if err_key not in seen_errors:
                seen_errors.add(err_key)
                all_errors.append(e)

    # طباعة التحذيرات إن وُجدت
    for fpath, line_num, tbl in all_warnings:
        norm_path = Path(fpath).as_posix()
        try:
            norm_path = Path(fpath).relative_to(ROOT_DIR).as_posix()
        except ValueError:
            pass
        print(f"⚠️ {norm_path}:{line_num}  جدول غير معروف: {tbl}")

    # طباعة الأخطاء إن وُجدت
    for fpath, line_num, tbl, col in all_errors:
        norm_path = Path(fpath).as_posix()
        try:
            norm_path = Path(fpath).relative_to(ROOT_DIR).as_posix()
        except ValueError:
            pass
        print(f"❌ {norm_path}:{line_num}  {tbl}.{col} — مش موجود")

    if all_errors:
        print(f"\n❌ إجمالي الأخطاء: {len(all_errors)}")
        sys.exit(1)
    else:
        print(f"✅ تم فحص {total_queries} استعلام في {len(target_files)} ملف — مفيش أخطاء في أسماء الأعمدة")
        sys.exit(0)


if __name__ == '__main__':
    main()
