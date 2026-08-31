# Regenerate from the repository root:
#   python3 docs/internal/plans/rule-vocabulary/X2-directive-audit/enumeration-execution-state.py
# The TSV header explains the method and its blind spots; re-apply it by hand
# after regeneration.
#
# Selection is TRANSITIVE: a class counts as a rule when it implements
# RuleInterface or reaches AbstractRule through any chain of parents. The first
# version of this script matched a single declaration line and therefore missed
# every subclass of an intermediate abstract base (15 files) — the defect the
# plan review caught.
OUT = 'docs/internal/plans/rule-vocabulary/X2-directive-audit/enumeration-execution-state.tsv'
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

def is_rule(c):
    seen = set()
    while c and c not in seen:
        seen.add(c)
        if c not in info:
            return False
        _, par, imp = info[c]
        if c == 'AbstractRule' or 'RuleInterface' in imp:
            return True
        c = par

rule_classes = sorted(c for c in info if is_rule(c))
rows = []
for c in rule_classes:
    rel = info[c][0]
    s = files[rel]
    ctor = re.search(r'function\s+__construct\s*\(', s)
    ctor_end = -1
    if ctor:
        j = s.find('{', s.index('(', ctor.start()))
        if j != -1:
            d = 0
            for k in range(j, len(s)):
                if s[k] == '{':
                    d += 1
                elif s[k] == '}':
                    d -= 1
                    if d == 0:
                        ctor_end = k
                        break
    for m in re.finditer(r'\$this->(\w+)\s*(?:\[[^\]]*\])?\s*(=(?!=)|\.=|\+=)', s):
        if ctor and ctor_end != -1 and ctor.start() <= m.start() <= ctor_end:
            continue
        rows.append((rel, s[:m.start()].count('\n') + 1, c, m.group(1)))

with open(OUT, 'w') as f:
    f.write("file\tline\tclass\tproperty\n")
    for r in sorted(set(rows)):
        f.write("\t".join(map(str, r)) + "\n")
print("rule classes (transitive):", len(rule_classes))
print("files holding them:", len({info[c][0] for c in rule_classes}))
print("assignments outside ctor:", len(set(rows)))
