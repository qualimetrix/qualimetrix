# Regenerate from the repository root:
#   python3 docs/internal/plans/rule-vocabulary/X2-directive-audit/enumeration-rule-collaborators.py
# The TSV header explains the method and its blind spots; re-apply it by hand
# after regeneration.
#
# Selection is TRANSITIVE, like enumeration-execution-state.py: a class counts
# as a rule when it implements RuleInterface or reaches AbstractRule through any
# chain of parents. Configuration validators are selected by their interface.
OUT = 'docs/internal/plans/rule-vocabulary/X2-directive-audit/enumeration-rule-collaborators.tsv'
import os, re

root = os.getcwd()
files = {}
for dp, dn, fns in os.walk(os.path.join(root, 'src')):
    for fn in fns:
        if fn.endswith('.php'):
            p = os.path.join(dp, fn)
            files[os.path.relpath(p, root)] = open(p, encoding='utf-8', errors='replace').read()

info = {}
for f, s in files.items():
    for m in re.finditer(r'\b(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)((?:\s+extends\s+\w+)?(?:\s+implements\s+[^{]+)?)', s):
        tail = m.group(2)
        par = re.search(r'extends\s+(\w+)', tail)
        imp = re.search(r'implements\s+([^{]+)', tail)
        info[m.group(1)] = (f, par.group(1) if par else None,
                            [x.strip() for x in imp.group(1).split(',')] if imp else [])

def kind_of(c):
    seen = set()
    head = c
    while c and c not in seen:
        seen.add(c)
        if c not in info:
            return None
        _, par, imp = info[c]
        if c == 'AbstractRule' or 'RuleInterface' in imp:
            return 'rule'
        if 'ConfigurationValidatorInterface' in imp:
            return 'validator'
        c = par
    return None

rows = []
for c in sorted(info):
    kind = kind_of(c)
    if kind is None:
        continue
    rel = info[c][0]
    s = files[rel]
    m = re.search(r'function\s+__construct\s*\((.*?)\)\s*\{', s, re.S)
    if not m:
        rows.append((kind, c, rel, '(no constructor)'))
        continue
    for t in re.finditer(r'(?:private|protected|public)?\s*(?:readonly\s+)?\??([A-Z]\w+)\s+\$\w+', m.group(1)):
        rows.append((kind, c, rel, t.group(1)))

with open(OUT, 'w') as f:
    f.write("kind\tclass\tfile\tcollaborator\n")
    for r in sorted(set(rows)):
        f.write("\t".join(r) + "\n")

from collections import Counter
print("classes:", len({r[1] for r in rows}))
print("without own constructor:", len({r[1] for r in rows if r[3] == '(no constructor)'}))
for t, n in Counter(r[3] for r in set(rows) if r[3] != '(no constructor)').most_common():
    print(f"  {n:3d} {t}")
