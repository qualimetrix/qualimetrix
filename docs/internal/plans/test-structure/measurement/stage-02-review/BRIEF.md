# Stage 02 review — common material

**Range:** `52eae218..HEAD` on branch `stage-02-controls-extraction`.
180 files changed: 84 renames, 41 additions, 54 modifications, 1 deletion.

**Do not modify anything.** No edits to project files, no `git add`, `commit`,
`stash`, `restore`, `checkout`, `clean`, no branch switching — the working tree
holds work that is committed but not pushed, and these commands destroy it. To
test a hypothesis that needs edited code, copy into a `mktemp -d` directory.

## What the change is

Files that assert facts about **this repository** rather than about product
behaviour left `tests/` for `governance/{GuardedSubject}/`, a root that stage 01
created and registered (PSR-4 `Qualimetrix\Governance\`, a fifth PHPUnit suite,
a fifth aggregate shard, so they run under `check:code`). The root now holds 21
groups. Six commits, one per package, plus measurement and documentation.

The criterion that decides what moves, in one question: *was the assertion's
expected side invented by the test, or copied from the repository?* A DI
container, reflection, `glob`, a parser and a tracked fixture are all reading
instruments and none of them decides anything. "One named thing behaves so" is a
product test; "every registered X has property Y" is a control.

## Read these first

- `docs/internal/plans/test-structure/02-controls-extraction.md` — the plan.
- `docs/internal/plans/test-structure/measurement/stage-02/decisions.md` — the
  five forks and how each was decided.
- `docs/internal/plans/test-structure/measurement/stage-02/move-hazards.md` —
  the ways this move breaks things silently. Every package brief carried it.
- `docs/internal/plans/test-structure/measurement/stage-02/prediction.md` — the
  executed-case arithmetic.
- `docs/internal/plans/test-structure/measurement/stage-02/two-witness-adjudication.md`
  — two corrections made to the reviewed verdict table.
- `docs/internal/plans/test-structure/measurement/stage-02/census-completeness.md`
  and `triage/README.md` — the audit's population was incomplete; 19 more files
  were found and moved.

## State claimed, and how it was checked

- `composer check` green, exit 0 (the aggregate, not a subset).
- Executed cases invariant at **9198** across every package. Relocation creates
  no cases, so the invariance is the check, not the per-suite figures.
  Now: Unit 7184, Integration 437, Functional 203, Infrastructure 663,
  Governance 711.
- `docs/internal/plans/test-structure/measurement/stage-02/check-verdict-conformance.py`
  — exit 0; its refusal was obtained first on both failure shapes.
- `finding-gate/enumeration-renames.tsv` unchanged, as required: `surfaces()`
  holds `tests` and `governance` as one surface, so a relocation between them is
  invisible to it, and a diff there would be a signal.

## Where to look hardest

The stage exists to remove a defect class — a check that stays green while
measuring less than it did. It reproduced that defect once, which is the shape
worth hunting for more of:

1. **A control that now measures nothing.** Group directories are flat, so every
   file reaches the repository root with `\dirname(__DIR__, 2)`. A stale depth
   resolves to a real directory above the repository; a walk of it returns `[]`;
   a control asserting a property of every member of an empty set passes. Some
   moved controls carry their own non-empty assertion and some do not — the ones
   that do not are named in the package reports and were deliberately not given
   one, because this stage adds zero guards. **Is any moved control now
   vacuously green?** Check by perturbing, not by reading.
2. **A declared directory that no longer exists.** PHPUnit warns, exits 0, and
   hands back an empty suite. This happened: `tests/Reporting/Formatter/Unit`
   emptied and its declaration stayed, so Unit enumerated 0 instead of 7184
   while every per-suite run stayed green. Only the aggregate refused. Are there
   other addresses with this shape?
3. **A pin or census that moved without its subject, or vice versa.** Several
   hand-maintained lists were edited: `SILENTLY_EXCLUDED`, a SHA256 digest of a
   finite path set, `systemSupportContents()`, `assertCount` pins that count its
   rows, `Probes.php` test identifiers in JUnit dotted form, `Suite.php` file
   lists, `namespace-path-allow-list.php`. **Did any of these lose a member
   without anyone noticing, or gain one that describes nothing?**
4. **Split files.** 24 files had a control half extracted. Shared private
   helpers and constants were duplicated rather than shared. **Did a split leave
   either half asserting less than before** — an orphaned data provider, a
   `#[CoversClass]` on the wrong side, a helper copy that drifted from its twin,
   a method whose population is now built differently?
5. **Subject cohesion.** 21 group names. Does each answer "what is this about?"
   without naming a technical role? Is any of them broad enough that anything
   would fit? Are two groups actually one subject, or one group two?
6. **Verdicts.** The population is `controls-verdict.tsv` plus a triage of 100
   files the original audit never examined. **Is anything in `governance/` not
   actually a control**, or anything still in `tests/` clearly one? The 507 files
   below the triage instrument's threshold were read by nobody.

## Known and deliberate, not findings

- Six dangling `{@see}` references predate this branch (verified by `git grep`
  on `52eae218`): `Tests\Architecture\Unit\Configuration\Allow\AllowAliasExpanderTest`,
  `Tests\Infrastructure\Unit\ChannelDeclarationCompilerPassTest`,
  `Tests\Integration\Configuration\YamlKeyReachabilityTest`,
  `Tests\Integration\Infrastructure\Console\RulesCommandWiringTest`,
  `Tests\Integration\Infrastructure\Rule\ChannelDeclarationFixtureDriftTest`,
  and the prefix `Tests\Analysis\Policy\Architecture\Unit\Configuration\Validation`.
  Nothing in the repository checks `{@see}` targets; that is itself worth a
  finding if you think it should.
- No control was deleted. Two were candidates, and reading the code reversed the
  expectation: `composer test` is asserted to keep `--exclude-group=live-freshness`
  out, so the duplication is a declared intent. Argued in `decisions.md` §2.
- Stage 02 adds **zero** new guards, deliberately. A guard that models "what is
  a control" instead of measuring it is the defect the campaign removes.
- `controls-verdict.tsv` is not edited; its two corrections live in
  `two-witness-adjudication.md`.

## Report

Write findings to `docs/internal/plans/test-structure/measurement/stage-02-review/`
per the plugin's `finding-schema.md`: severity, a `file:lines` anchor, evidence,
coverage, and whether the fact was verified. Name the actual reviewer in
`reviewer`. State coverage honestly, including what you did not reach.
