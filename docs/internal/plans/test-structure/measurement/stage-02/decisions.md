# Stage 02 — the five forks, decided

The owner delegated all five. Each decision states what it buys and what it
costs, so review can disagree with the reasoning rather than guess it.

## 1. Population — relocate the known set, triage the rest in parallel

The 81-path census is incomplete (`census-completeness.md`). Two readings were
open: move the known 59 and open re-derivation separately, or triage first and
move once.

**Chosen: both, on separate tracks.** Packages relocate the known set
sequentially; a read-only triage of the 327 suspects runs alongside and folds in
as the last package.

What makes this safe rather than a hedge: the expensive half of a package is
creating the group, registering it, repairing the root depth and verifying the
count. Adding a late-found file to a group that already exists is none of that.
So a control found by triage after its group has moved costs a small follow-up,
not a second migration — and the stage still ends with the strong sentence,
"`tests/` holds no control", rather than "no control the audit found".

The triage is bounded, which is why it is worth running at all: the signature
witness has **measured recall 42/42** over every control known to be one, so its
327 hits are a complete work list for that witness rather than an arbitrary
sample. It is not proof of completeness and is not claimed as any — no tracked
guard is written for "is this a control", because a guard that models that
question instead of measuring it is the defect this campaign exists to remove.

## 2. Deletions — none. Both candidates relocate

The earlier notes justified deleting them as duplicates of
`suppression-snapshot:check` and `architecture:check`, both inside
`check:artifacts`. The blocker they named turned out not to exist, but reading
the code produced the opposite answer to the one they expected.

`ModularArchitectureGovernanceIntegrationTest:44-48` asserts that
`composer test` is exactly `phpunit --no-coverage --exclude-group=benchmark`,
and its failure message is *"Standalone composer test must retain its full
freshness coverage."* That assertion exists to keep `--exclude-group=live-freshness`
**out** of the standalone script. The `live-freshness` fork is therefore a
declared intent, not an oversight, and these two cases are how a bare
`composer test` notices a stale artifact at all. Deleting them would remove
exactly what that assertion is written to protect.

Cost of keeping them: two checks run twice under `composer check`. Accepted.

Gain beyond correctness: stage 02 stays a **pure relocation**, so its arithmetic
oracle is exact — the executed-case total must come out at 9198, unchanged. A
stage that also deleted would have to argue the difference instead of asserting
identity.

## 3. Channel fixtures — move to `governance/Channel/Fixtures/`

`tests/Analysis/Finding/Fixtures/Channels/` has 8 real readers; all but
`ChannelCoverageTest` are controls, and the directory is their oracle. Co-change
puts it with them, and ADR 0016 makes the fixture part of the channel subject
rather than of the directory that happens to hold it.

`ChannelCoverageTest` stays in `tests/` and reads `excluded.txt` across roots.
No control forbids that in either direction — searched for and not found. The
same package edits the two pins the plan never listed
(`assertPathLiteralsResolve()` and the generated fixture-directory table) and
the hardcoded paths inside failure messages.

## 4. `DocumentationConsistencyTest` — split four ways

| methods | group             | subject                                                        |
| ------- | ----------------- | -------------------------------------------------------------- |
| 5       | `RuleDeclaration` | rule name, CLI alias, YAML and catalog against the docs        |
| 5       | `RatchetArtifact` | the published entry count of the tracked ratchet               |
| 4       | `PlanningRecords` | the plan index, and executable sources reaching no plan record |
| 1       | executor's call   | registered formatters named in the health-score documentation  |

`RatchetArtifact` takes the baseline-count methods rather than getting a sibling
group: its subject is the tracked ratchet snapshot, and key grammar and
published size are both properties of that one artifact — a format change
touches both. `PlanningRecords` is a new name because no existing group's
subject reaches it.

The last method is left to the package executor, with `subject-cohesion` loaded
and two permitted outcomes: an existing group whose subject genuinely covers it,
or `DocumentationCensus` retained for it alone. A group of one file is a normal
state; a group named so broadly that anything fits is not.

Independently of the split, that package fixes
`itKeepsExecutableSourcesIndependentFromPlanningRecords`, whose `$roots` list
omits `governance` **already on `main`** — a stage-01 leak, recorded in
`move-hazards.md`.

## 5. The two verdict corrections stand

`RatchetKeyGrammarTest` relocates whole; `DirectiveAuditReportReadingTest`
relocates four methods. Argued in `two-witness-adjudication.md`;
`controls-verdict.tsv` is not edited.
