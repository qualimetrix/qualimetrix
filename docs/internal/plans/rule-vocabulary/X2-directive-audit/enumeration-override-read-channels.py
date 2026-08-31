# Regenerate from the repository root:
#   python3 docs/internal/plans/rule-vocabulary/X2-directive-audit/enumeration-override-read-channels.py
# The TSV header explains the method and its blind spots; re-apply it by hand
# after regeneration.
#
# Five patterns, not four: AbstractRule offers TWO inherited paths to the read
# (getEffectiveOptions and getEffectiveSeverity, the latter delegating to the
# former), and the first version of this script knew only the first — so the
# consumer surface was undercounted by five files.
OUT = 'docs/internal/plans/rule-vocabulary/X2-directive-audit/enumeration-override-read-channels.tsv'
import os, re
from collections import Counter

root = os.getcwd()
patterns = {
    'ctx-read': re.compile(r'->getThresholdOverride\s*\('),
    'ctx-property': re.compile(r'->thresholdOverrides\b'),
    'options-apply': re.compile(r'->with(?:Vo)?Override\s*\('),
    'helper-options': re.compile(r'getEffectiveOptions\s*\('),
    'helper-severity': re.compile(r'getEffectiveSeverity\s*\('),
}
rows = []
for dp, dn, fns in os.walk(os.path.join(root, 'src')):
    for fn in fns:
        if not fn.endswith('.php'):
            continue
        p = os.path.join(dp, fn)
        rel = os.path.relpath(p, root)
        for i, line in enumerate(open(p, encoding='utf-8', errors='replace').read().split('\n'), 1):
            for name, pat in patterns.items():
                if pat.search(line):
                    rows.append((name, rel, str(i), line.strip()[:140]))

with open(OUT, 'w') as f:
    f.write("channel\tfile\tline\tsource\n")
    for r in sorted(rows):
        f.write("\t".join(r) + "\n")

for name, n in Counter(r[0] for r in rows).most_common():
    print(f"  {n:3d} {name}  ({len({r[1] for r in rows if r[0]==name})} files)")
print("total", len(rows))
