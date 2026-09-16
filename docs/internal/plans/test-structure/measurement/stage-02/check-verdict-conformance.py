#!/usr/bin/env python3
"""Stage 02's Definition of Done, checked against the verdict tables.

Three conditions, one exit code: no `repo-control` path is still under
`tests/`, no `product-test` path left it, and no `mixed` file still declares a
method its verdict names as a control. The population is the audit's
`controls-verdict.tsv` plus the triage of the files that audit never examined,
with the two corrections argued in `two-witness-adjudication.md`.

This is a one-off conformance check, not a tracked guard. Stage 02 adds no
guards: a guard that models "what is a control" instead of measuring it is the
defect this campaign exists to remove.

Its refusal was obtained before its green was believed — restoring a moved
control under `tests/`, and restoring a departed method to a split file, are
each named by exit 1.
"""
import os, re, glob, sys
lines=open('docs/internal/plans/test-structure/measurement/controls-verdict.tsv').read().splitlines()
hdr=lines[0].split('\t'); rows=[dict(zip(hdr,l.split('\t',4))) for l in lines[1:]]
tri=[]
for f in glob.glob('docs/internal/plans/test-structure/measurement/stage-02/triage/batch-*.tsv'):
    for l in open(f).read().splitlines()[1:]:
        c=l.split('\t')
        if c[1] in ('repo-control','mixed'): tri.append({'path':c[0],'class':c[1],'scope':c[3]})
corr={'tests/Analysis/Evidence/Measurement/Integration/Identity/RatchetKeyGrammarTest.php':'repo-control'}
fail=[]
allr=rows+tri
for r in allr:
    cls=corr.get(r['path'], r['class'])
    if cls=='repo-control' and os.path.exists(r['path']): fail.append(('control still in tests/',r['path']))
    if cls=='product-test' and not os.path.exists(r['path']): fail.append(('product-test vanished',r['path']))
    if cls=='mixed' and os.path.exists(r['path']):
        m=set(re.findall(r'public function (it\w+)', open(r['path']).read()))
        left={x.strip() for x in r['scope'].split(',') if x.strip()} & m
        if left: fail.append(('control method still in tests/',r['path']+' -> '+','.join(sorted(left))))
for k,v in fail: print(f"[{k}] {v}")
sys.exit(1 if fail else 0)
