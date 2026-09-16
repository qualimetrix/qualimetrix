# Stage 02 — review, consolidated

Two reviewers on one brief, both read `52eae218..HEAD` whole: no slicing, so
cross-file seams had a reader who saw both sides.

| reviewer          | findings | CRITICAL | HIGH | MEDIUM | LOW |
| ----------------- | -------- | -------- | ---- | ------ | --- |
| native (`claude`) | 8        | 0        | 0    | 4      | 4   |
| external (Codex)  | 4        | 0        | 0    | 3      | 1   |

Every finding was re-checked against primary sources by the orchestrator before
being accepted. One was partly refuted by measurement; the rest stand.

## Grouped by mechanism, not by finding

The first fix question is not "which line" but "what produced several of
these", so the twelve rows collapse into five mechanisms.

### A. A reference living in prose has no resolver — 7 named, 6 fixed, 2 more found along the way

`codex-04`, `claude-04`, `claude-08`, and `claude-07` in its naming half.

Every **executable** reference to a moved class was repointed, which is
measured: PHPStan is clean, the directive stand reports `0 not as declared`,
and the aggregate partitions. Every reference in a **comment** was not.
`claude-04` names seven paths that resolved on `52eae218` and do not resolve
now, three of them in production docblocks under `src/` that tell a reader
where to put a channel declaration; `codex-04` finds one of the same seven
(`declared.txt`'s drift-guard comment) independently rather than an eighth,
so the named population is seven, not eight.

No package could have gone red on this: the only literal resolver in the
repository, `assertPathLiteralsResolve()`, looks inside one generator. This is
the answer to the brief's seam question — the address no package covered.

**Fixed here:** six of the seven named sites, plus two the fix round found by
sweeping class references rather than paths (`Finding.php`,
`RuleExclusionStatsTest.php`) — eight sites in the commit, none of them a
duplicate of another. **Not fixed here, and still stale today:** the seventh
named site, `finding-gate/README.md:912`, which still names
`tests/Analysis/Finding/Integration/ChannelLevelDeclarationDriftTest.php`; the
class now lives at `governance/Channel/ChannelLevelDeclarationDriftTest.php`.
Also not fixed here: the absence of a resolver.
Building one is a new guard and this stage adds none; it is recorded as the
stage's principal inheritance.

### B. A hand-maintained census drifted from its subject — 3 sites

`claude-01`, `claude-05`, `codex-02`. Same shape each time: a declaration that
now describes nothing, in a repository whose own guards exist to refuse exactly
that.

- The allow-list carries `ceiling => 60` over **57** rows. Its own docblock says
  deriving only ever lowers the ceiling, so three slots of slack exist that no
  derivation could have written: rows were removed by hand across two packages
  and the number was never re-derived. The list can grow back by three without
  anyone admitting it in a diff.
- `classifyOwner()` keeps a branch for `tests/Integration/Documentation/`, a
  directory that does not exist. Removing it exposed the same fault at scale:
  **50 of the 84** `tests/`-or-`governance/` prefixes the generator tests
  against name a directory that is not there. They are residue of the earlier
  architecture migration, not of this stage, and were deliberately left — a
  fifty-line cleanup inside a file this stage only registers groups in is a
  separate decision for that script's owner.
- `check-verdict-conformance.py`, written in this stage to check its own
  Definition of Done, carries one of the two corrections from
  `two-witness-adjudication.md` and not the other. The code is placed correctly
  — `itKeepsTheMeasuredMeaningOfEveryVerdictKnownToday` is in
  `governance/DirectiveVocabulary/` — but had it been left behind, the check
  would have passed. A check weaker than it claims is this stage's own subject.

### C. The population was still open — 11 more files

`codex-01`. Codex found, by reading, three controls that this session's
instrument scored at **zero** signals: they hold no pinned path list and walk no
directory, because their census is over a product class's own static table,
held in memory. `ComputedMetricDefaultsTest` quantifies over
`ComputedMetricDefaults::getDefaults()` and pins the count at six.

The instrument's blind spot was then measured rather than argued: a signal for
that shape accuses 16 further files, and a fourth triage of those returned 3
whole controls and 8 mixed — eleven carriers in all. The population grows from
76 files to 87, and `governance/` from 21 groups to 24.

This is the second measured failure of the claim "recall 42/42 makes the list
complete". It was hedged when written; it is now falsified twice, and the
conclusion in `census-completeness.md` is rewritten accordingly rather than
re-hedged.

### D. Two groups are named for a shape, not a subject

`codex-03` and `claude-03`, found independently, which is the strongest signal
in the set.

`SolePrimitiveOwnership` collects controls whose claim has the same *form* —
"exactly one place in `src/` reads this" — over subjects with nothing else in
common: glob characters, namespace matching, the version string, a suppression
option key, coupling classification sites. `RepositoryEntrypoints` is charged
with the same fault. Under ADR 0016's co-change test, a change to any one of
those subjects touches this directory and one other, which is what the axis was
chosen to prevent.

The plan named this exact risk and left it open: *"`SolePrimitiveOwnership` has
not been checked against co-change."* Two reviewers arriving at it
independently means the open point should close, but closing it means
re-deciding a taxonomy that a reviewed plan settled, and the same argument
touches stage 04's subject layout. **Referred to the owner, not fixed here.**

### E. No group carries a floor on its own size

`claude-02`, and the one finding measurement partly refuted.

Perturbation shows the registration half is caught **loudly**: a group
directory declared in `phpunit.xml.dist` with no row in `testSuitePrefixTable()`
makes `composer architecture:check` fail by name (`governance/_EmptyProbe is
suite Governance in phpunit.xml.dist, none in currentSuite()`) and reddens the
aggregate. A declared directory that does not exist at all is caught by the
aggregate's refusal, which is how this stage's own defect surfaced.

What remains true is narrower than the finding states: a group registered in
both places whose files later vanish loses its cases with nothing flooring the
count. Fixing that is a new guard. Deferred, with the refuted half recorded so
the next reader does not re-derive the wrong premise.

## Confirmed and not ours

`claude-06`: `composer directives:controls:coverage` exits 1 on two
`DirectivesCommandTest` cases that no probe guards. Verified independently to
pre-date the branch — `git show 52eae218:scripts/directive-audit-controls/Probes.php`
carries no probe for either case, and the stage's diff touches neither. The
command is not part of `composer check`. Reported upward, not fixed here.

## What neither reviewer reached

Stated so the next round starts from the gap rather than from the summary:

- `composer check` in full was not re-run by either reviewer; the green in this
  document is the orchestrator's own run.
- Perturbation covered 6 seams of 21 groups; co-change was measured for 3 groups
  of 21.
- The 491 files still below every triage instrument's threshold were read by
  nobody.
- Split halves were compared by a name-matching heuristic, not by pairing each
  split with its partner — strong evidence, not proof.
