# Stage 03 — plan review, consolidated

Two reviewers, one brief, one material: commit `194dbfea` (documentation only).
`findings-claude.md` (native, 14 findings / 10 confirmed) and
`findings-codex.md` (external Codex CLI, 13 / 10 confirmed, 3 self-refuted).
Material was **not** sliced.

Every finding below was re-checked by the orchestrator against the tree or by
running the command. Nothing is accepted on a reviewer's word, and two were
cut down on checking.

## They agree on four, and all four are mine to fix

| The defect                                                                   | native      | codex      | verified by                                                                                          |
| ---------------------------------------------------------------------------- | ----------- | ---------- | ---------------------------------------------------------------------------------------------------- |
| `surfaces()` in the rename enumeration is a measured address no package owns | claude-02 H | codex-03 H | `:56-61` reads `'tests' => ['roots' => ['tests','governance']]`; the plan says `surfaces` zero times |
| The two-step plant cannot execute as written                                 | claude-03 H | codex-02 H | the plan's step 1 says "under the old scan scope"; what I ran put the probe **outside** the literal  |
| P3 claims it empties `tests/Unit/RuleVocabulary/`, and cannot                | claude-04 M | codex-07 M | five tests there, P3 moves four                                                                      |
| D3-1's rejected alternative is understated by my own measurement             | claude-07 M | codex-05 M | "three tools of five" in the plan, **four of five** in the table                                     |

Agreement raises priority; it did not decide any of them — each was checked
separately.

## Unique to Codex, and the worst single finding of the round

**codex-01 (HIGH) — the DoD names a command that cannot pass.** Confirmed by
running it. `composer directives:controls` is
`['@directives:controls:coverage', 'php scripts/directive-audit-controls.php']`;
coverage is already red on `main`; Composer halts a chain on failure. The run
ends at

```
Script php scripts/directive-audit-coverage-control.php handling the
directives:controls:coverage event returned with error code 1
```

and the full control is never reached. My DoD required exactly that unreachable
run "in full, once, without `--only`".

**codex-10 (MEDIUM) — my own measurement artifacts contradict each other.**
`registration-addresses.md` C5 asserts CLAUDE.md's exit-0 claim with the words
"I did not need a probe to establish this: it is PHPUnit's documented,
long-standing behavior", while `baseline.md` of the same stage refutes it with
four probes. The conclusion is right for a different case than the one cited.

## Unique to native, and the one that breaks the package cut

**claude-01 (HIGH) — P1 cannot go green without editing a file the plan gives
to P5.** `createIsolatedProject()` in
`tests/Analysis/Policy/Architecture/Integration/ModularArchitectureGeneratorRefusalTest.php:186-196`
copies `tests`, `governance`, `src` and the generated directory into a scratch
root. Registering `Tooling` with a `<directory>` under `tools/` makes that
copy incomplete, and the code's own comment says why it matters:

> PHPUnit exits 2 when a `<testsuite>` names a directory that is not there, so
> every root the tracked configuration declares has to exist here before the
> inventory script can reach its own refusal.

That file is row 16, owned by **P5**. So the package boundary is wrong, and the
comment also **independently corroborates** this stage's exit-2 measurement
against CLAUDE.md — a third witness I did not solicit.

**claude-05 (MEDIUM) — the `tools/` guard list is short by one.** The DoD names
five addresses; the measured set is six.
`governance/TestSuiteHygiene/ScratchPathsCarryRealEntropyTest.php:42` is
`ROOTS = ['tests', 'governance', 'scripts']` — `tools` absent, and CLAUDE.md
grades this address **silent**. `scripts` is already there, so only the `tools/`
movers are exposed.

**claude-06 (MEDIUM) — dead prefixes, none named by path.** Enumerated:
`classifyOwner()` `:667` `tests/TestSupport/ArchitectureStaticAnalysis/`, `:960`
`tests/Unit/RuleVocabulary/`, `:966` `tests/Unit/PromiseEffect/`;
`testSuitePrefixTable()` `:1040`; `systemSupportContents()` `:1505`
`tests/TestSupport`; and the `<directory>tests/TestSupport/ArchitectureStaticAnalysis/Unit</directory>`.
`tests/Reporting/Formatter/Suppressed/Unit` **survives** — `SuppressedFormatterTest`
stays.

**claude-12 (LOW, and sharper than its severity) — the "silent" case is loud on
a fresh clone.** `baseline.md` says a declared directory that exists and holds
no tests is silent, measured locally. Git tracks no empty directory, so after
the move the same state is an *absent* directory in CI — exit 2. The local
green and the CI red are the same tree. It makes removing the stale
`<directory>` mandatory rather than tidy.

## Cut down on checking

- **claude-13** — claimed the `Probes.php` cure is unsafe because a mover shares
  the prefix and lands in a different root. **Refuted for `Probes.php`:**
  `DirectiveAuditControlsSuiteKeyTest` appears there **0** times, and the only
  three pinned classes (16 + 38 + 65 = 119) all land in the *same* root. **Kept
  in the general form:** `Qualimetrix\Tests\Unit\RuleVocabulary\` maps to three
  different destinations across the repository, so no single repo-wide
  find/replace is correct — the hazard is real one level up from where the
  finding put it.
- **codex-11** — claimed my CLAUDE.md exit-0 correction is itself wrong. Refuted:
  measured four ways, and now corroborated by the comment quoted above. Codex
  had already self-refuted this one.
- **codex-12, codex-13** — self-refuted by the reviewer and independently wrong
  (the 119 literals are exactly countable; `RuleVocabulary` holds five tests).

## What the orchestrator found that neither reviewer did

Both were read-only, so neither ran the plant. I did, and it produced two
corrections neither reported: the scan-scope entry must be a **test directory**
(widening to `tools` makes the generator refuse on a *production* rule file
instead of the probe), and `classifyKind()`/`classifyOwner()` need a branch for
the new paths — which the package table does not name. Recorded with the rest.

A third population channel was also run — enumerate the 131 tool files, grep
each exact basename inside `tests/` — the inverse of what both witnesses did.
Same 16. Both reviewers independently failed to find a 17th too.

## Reviewer usefulness

- **native (`claude`)** — deeper on the *package cut* and on cross-file
  consequence: claude-01 is the round's structural finding, and claude-12 turned
  a measured "silent" into a CI-loud. It also reproduced the prediction per
  file and the 9198 baseline in a copied tree, which is the only independent
  check of those numbers.
- **codex** — sharper on *contract coherence*: the unreachable composer chain
  and the self-contradiction between two of my own artifacts are both of the
  form "these two documents cannot both be true", which is what it consistently
  finds best. It also self-refuted three of its own findings, which is worth
  more than three extra findings.
- Neither reached: the executed total 9196 / per-suite rerun, `composer check`
  in full, any package rehearsal, stage 04's map, CI workflow YAML.

## Disposition

All ten confirmed findings are accepted and fixed in the next commit. Grouped by
mechanism rather than patched one by one, because three of them share one cause:
**an address the measurement found and the plan did not carry** (`surfaces()`,
the sixth `tools/` guard, the dead prefixes). That is a transport defect between
`measurement/stage-03/` and the package table, so the fix is a derived table in
the plan rather than three more prose sentences.

---

# Round 2 — narrow, on the fix diff

Scope: commit `7e94911b` only, plus the artifact it created. One reviewer
(native), because the trigger was "the fixes touched material the first round
never saw" — `addresses-to-edit.md` was 92 new lines nobody had reviewed.
`findings-claude-round2.md`: 14 findings, 13 confirmed, 6 self-refuted.

## The finding that matters most

**The cure reproduced the disease, three times.** Round 1's three worst findings
shared one cause — an address the measurement found and the plan did not carry —
and the cure was a table of every address. Round 2 found three addresses that
the table's **own declared sources** name and the table omits:

| Missing address                     | Carrier                    | Why it bites                                                                                                           |
| ----------------------------------- | -------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| `dispositionFor()` / `targetPath()` | inventory `:1152`, `:1199` | named by the table's source; each needs a branch per new path                                                          |
| `P7_MEASUREMENT_PATHS:81`           | inventory `:70-81`         | pins `test_cross_tool_comparison.py`, which P6 moves; `assertPathLiteralsResolve()` refuses                            |
| `P6_C_BASELINE_PATHS_SHA256`        | inventory `:17`, `:328`    | a digest over every file under `tests/Analysis/Policy/Baseline`, which row 7 leaves — P4's, and P4 had no such address |

A fourth, `.dockerignore`, the orchestrator found separately while closing a
blind spot the measurements had declared. `Dockerfile:29` is `COPY . .` and
`tests/` is root-anchored, so every test directory this stage creates ships in
the image.

**The structural response, rather than a fourth patch:** addresses live in one
table and nowhere else, and no prose in either plan file restates a count. Both
rounds found the same shape — a number copied into prose, left to go stale —
and round 2's two `contract` findings were exactly that: a paragraph still
saying "five" guards where the DoD said six, and a paragraph still carrying a
claim `baseline.md` had refuted.

## Four addresses that were wrong, not missing

- **`createIsolatedProject()` (HIGH)** was assigned to P1 alone. Every mover
  declares a `<directory>`, and every declared root must exist in the scratch
  copy or PHPUnit exits 2 there. So every mover touches it.
- **`Unclassified test artifact`** is `classifyOwner()`'s refusal at `:976`, not
  `classifyKind()`'s — which has a different message at `:1003`. And a
  `classifyKind()` branch is not automatically wanted: classifying a new path as
  `placeholder` would silence the guard that a test was never discovered.
- **The allow-list pair** was graded loud. Removing a row is loud; lowering the
  `ceiling` is not, and a stale-high ceiling silently absorbs a future
  violation — the file says so itself.
- **The G2/G3 proof was over-claimed.** It reproduces as executed only under
  `tools/phpstan`, which already has a parent PSR-4 root. A `scripts/**` probe
  is invisible to `TestTree` until its own root is declared, so those packages
  must declare the root first, then plant.

## Reviewer honesty worth recording

Round 2 declared what it did not do rather than padding coverage: it ran none of
`composer check`, `architecture:check`, the aggregate, the full directive
control or the plant, and took `baseline.md`'s four-way exit-2 measurement as
given — while noting that its own HIGH finding rests on it. It also did not
re-derive the 119 pinned literals, reporting that a line-by-line count gives
16 / 37 / 49, consistent with several literals per line but **not** an
independent reproduction of the totals. That caveat is now in the table, with
the instruction that P3 re-derives rather than trusts.

It also half-refuted the brief's own premise: `registration-addresses.md` does
measure `.dockerignore` and `init-environment.sh`, so only one of the two named
blind spots was live. CI workflow YAML it read itself — no addresses there.

## Disposition

All 13 confirmed findings applied. The plan passed 400 lines, so execution split
into `03-packages.md`. Two measurement artifacts orphaned by the round-1 rewrite
are cited again. The overview's stage-03 row, its "further 9 files" and its
shared-cost figure of 12 pinned paths were all stale and are corrected or marked
superseded — with the note that the other stages' figures there carry the same
risk and have not been re-derived.

**No round 3.** The trigger for one would be fixes touching unseen material;
round 2's fixes touched only the table and two paragraphs it had itself read,
and the remaining risk is no longer in the plan but in execution — which each
package's own verification, not another plan review, is what establishes.

---

# Execution review, and the fix round

Scope: `c49fc0b4..8629a5c7`, the whole stage as executed. One reviewer.
`findings-execution.md`: 10 confirmed, **no HIGH and no CRITICAL** — 3 MEDIUM,
7 LOW. Every one re-checked by the orchestrator against the tree.

## What it found, and the one cause behind four of them

**The set of tooling test roots was written out four times in the inventory
generator and reconciled against nothing** — the scan-scope pathspec,
`classifyOwner()`'s branches, `dispositionFor()`'s chain and `targetPath()`'s
chain. That is why `cross-tool-comparison` reached one list and missed three,
and why the two Python roots missed the rename surface. It is also, in
retrospect, the mechanism behind the whole stage's recurring defect: **a set
written out N times without reconciliation diverges exactly where nobody
looks.**

The cure was already in the same file. `assertSuiteClassifierAgreesWithPhpunit()`
reconciles the suite map in both directions and refuses by name; the four lists
became one `TOOLING_TEST_ROOT_OWNERS`, and a new check scans the disk
independently and compares both ways.

**Both directions shown refusing before the green was trusted:**

```
scripts/benchmark/tests/ exists on disk but is not registered in TOOLING_TEST_ROOT_OWNERS
scripts/ghost-tool/tests/ is registered in TOOLING_TEST_ROOT_OWNERS but no longer exists on disk
```

## A hole this stage opened its own measurement with, now closed

`itReadsEveryTestRootItJudges` floored two roots by name and said "the two that
exist today". So a **declared root holding no test files passed silently** — and
`tools/phpstan` was exactly that before P1 filled it, which this stage measured
on its first day and then did not fix. The guard now floors every root the scan
returns:

```
scripts/ghost-tool/tests is a declared test root with no test files
```

## One finding contradicted a commit message of this stage

`2f4a3da7` called `cross-tool-comparison`'s classification "sound". It was sound
about *existence* and wrong about *correctness*: the inventory answered
`Analysis/Evidence/Measurement`/`P7` for a file that no longer belongs to that
capability, while its sibling answered `Tooling/PhpunitAggregate`/`P8`. The
reviewer declined to drop the finding on the strength of that message and wrote
the distinction into its mechanism instead. It was right to.

## Why no round 3

The fix round introduced material no review had seen: a new check, and
`classifyOwner()` refactored from thirteen branches to a loop. Both were
verified directly rather than re-reviewed.

- The new check: plant-verified in both directions, above.
- The refactor: behavioural equivalence proved by regenerating the inventory and
  diffing. **One line changed out of 921 artifacts** — the intended
  `cross-tool-comparison` owner — so classification is preserved for the other
  920.
- The guard floor: plant-verified, above.
- The rest is configuration and prose.

`composer check` green end to end (`STAGE_GATE_EXIT=0`), all six suite counts
unmoved, 9198 discovered, 921 artifacts, rename surface at 58/54/82/113.

## What no review in this stage reached

The G2/G3 refusal was reproduced on some new roots, not all ten — a full sweep
needs an isolated copy with `vendor` **copied**, because a symlinked `vendor`
resolves PSR-4 back to the source tree and greens falsely. No Docker image was
built, so `.dockerignore`'s effect is reasoned from `COPY . .` rather than
observed. And the 616 files still in `tests/` remain unread by anyone, which is
the population this stage narrowed by 18 and did not close.
