#!/usr/bin/env python3
"""Stage 02's Definition of Done, checked against the verdict tables.

Four conditions, one exit code: no `repo-control` path is still under
`tests/`, no `product-test` path left it, no `mixed` file still declares a
method its verdict names as a control, and everything that departed `tests/`
(a whole `repo-control` file, or a `mixed` file's control methods) actually
arrived somewhere under `governance/`. The population is the audit's
`controls-verdict.tsv` plus the triage of the files that audit never examined,
with the two corrections argued in `two-witness-adjudication.md`.

This is a one-off conformance check, not a tracked guard. Stage 02 adds no
guards: a guard that models "what is a control" instead of measuring it is the
defect this campaign exists to remove.

Its refusal was obtained before its green was believed — restoring a moved
control under `tests/`, restoring a departed method to a split file, deleting
a control instead of moving it, and leaving a `mixed` row's vanished path
unresolved, are each named by exit 1.
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
# two-witness-adjudication.md's second correction: DirectiveAuditReportReadingTest
# moves four methods, not the three the TSV's `scope` column names —
# `itKeepsTheMeasuredMeaningOfEveryVerdictKnownToday` joins its three siblings.
scope_corr={'tests/Unit/RuleVocabulary/DirectiveAuditReportReadingTest.php':{'itKeepsTheMeasuredMeaningOfEveryVerdictKnownToday'}}

# A `mixed` row's `path` names the file BEFORE this stage's moves. Two shapes
# make that path stop existing while the verdict stays `mixed` (the remaining
# half is still there, just not at `path`):
#
# 1. `corr` above: the row's own class corrects to `repo-control` — the whole
#    file departed, nothing "renamed" remains under `tests/` to check.
# 2. This map: the stage's naming convention (round-2 review, `claude-07`)
#    renames the remaining half when the old name described the departed
#    control rather than what the file asserts now. The row stays `mixed`,
#    but the survivor lives at a different path — record where, or this
#    branch has nothing to check and silently passes, which is the exact
#    defect round 2 found (`claude-01`).
#
# A `mixed` path missing from BOTH `corr` and this map is refused, not
# skipped: an unresolved disappearance means either a departed control was
# deleted outright instead of moved, or a renamed remainder was never
# recorded here.
remainder_renamed={
    'tests/Analysis/Evidence/ComputedMetrics/Integration/BenchmarkConsumersCoverageTest.php':
        'tests/Analysis/Evidence/ComputedMetrics/Integration/BenchmarkCoverageRefusalTest.php',
    'tests/Analysis/Policy/Architecture/Integration/ModularArchitectureGovernanceIntegrationTest.php':
        'tests/Analysis/Policy/Architecture/Integration/ModularArchitectureGeneratorRefusalTest.php',
    'tests/Analysis/Policy/Architecture/Unit/LayersValidatorMembershipRefusalGuardTest.php':
        'tests/Analysis/Policy/Architecture/Unit/LayersValidatorEmptyMembershipRefusalTest.php',
}

# Arrival side: every departed unit (a whole `repo-control` file, or a
# `mixed` row's control methods) must be found somewhere under `governance/`.
# Checked by name only — by file basename for a whole file, by method name
# for a control method — not by path, so a further rename inside
# `governance/` does not itself redden this. That is weaker than the
# departure checks above, which pin an exact path; it is still strictly more
# than the zero arrival used to get.
gov_basenames=set()
gov_methods=set()
for f in glob.glob('governance/**/*.php', recursive=True):
    gov_basenames.add(os.path.basename(f))
    gov_methods |= set(re.findall(r'public function (it\w+)', open(f).read()))

# One `repo-control` row does not arrive as a single same-named file: per
# `docs/internal/plans/test-structure/measurement/stage-02/decisions.md`
# §4, its fourteen methods split four ways by subject, none of the four
# destinations named `DocumentationConsistencyTest`. A basename check alone
# would call this a silent deletion; name the split instead of weakening the
# predicate for every other row.
repo_control_split_into={
    'tests/System/DocumentationConsistency/Integration/DocumentationConsistencyTest.php': [
        'governance/RuleDeclaration/DocumentationRuleSurfaceTest.php',
        'governance/RatchetArtifact/BaselineCountPublicationTest.php',
        'governance/PlanningRecords/PlanningRecordIsolationTest.php',
        'governance/DocumentationCensus/RegisteredFormatterDocumentationTest.php',
    ],
}

fail=[]
allr=rows+tri
for r in allr:
    cls=corr.get(r['path'], r['class'])
    if cls=='repo-control':
        if os.path.exists(r['path']): fail.append(('control still in tests/',r['path']))
        if r['path'] in repo_control_split_into:
            missing=[p for p in repo_control_split_into[r['path']] if not os.path.exists(p)]
            if missing: fail.append(('split destination vanished',r['path']+' -> '+','.join(missing)))
        elif os.path.basename(r['path']) not in gov_basenames:
            fail.append(('control did not arrive under governance/',r['path']))
    if cls=='product-test' and not os.path.exists(r['path']): fail.append(('product-test vanished',r['path']))
    if cls=='mixed':
        target=remainder_renamed.get(r['path'], r['path'])
        if not os.path.exists(target):
            if r['path'] in remainder_renamed:
                fail.append(('renamed remainder vanished',r['path']+' -> '+target))
            else:
                fail.append(('mixed path vanished, remainder unnamed',r['path']))
            continue
        m=set(re.findall(r'public function (it\w+)', open(target).read()))
        scope={x.strip() for x in r['scope'].split(',') if x.strip()} | scope_corr.get(r['path'], set())
        left=scope & m
        if left: fail.append(('control method still in tests/',target+' -> '+','.join(sorted(left))))
        missing=scope - gov_methods
        if missing: fail.append(('control method did not arrive under governance/',r['path']+' -> '+','.join(sorted(missing))))
for k,v in fail: print(f"[{k}] {v}")
sys.exit(1 if fail else 0)
