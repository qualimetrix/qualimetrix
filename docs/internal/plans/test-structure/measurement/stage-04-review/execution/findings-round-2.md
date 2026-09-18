# Stage 04 — execution review, round 2 (Claude reviewer)

Material: branch `x30-stage-04-subject-layout`, **one commit** `1338d5fa..d0acd677`
("fix(architecture): close the review's fourteen findings"). This is not a
re-review of stage 04; the question is what the cures introduced.
Finding schema: `dvizh-vr-review/reference/finding-schema.md`.

**Ids are `claude-r2-NN`.** Round 1's Claude findings are `claude-01..08` and
`fixes.md` cites them by those ids; reusing the namespace would make the
disposition table ambiguous.

Findings are ordered by descending severity. After them: coverage, then the
hypotheses tested and refuted.

**Verdict: the commit is sound.** All six of the orchestrator's hypotheses were
tested; four are clean and are recorded as refuted with the measurement that
settles them. Three findings, all MEDIUM, none blocking. Two are about the
diagnosis a new refusal gives, one is about a new guard nothing proves bites.

**Boundary note:** nothing in the repository was modified. `git status` shows
`docs/internal/plans/test-structure/00-overview.md` as modified — that predates
this review and is not mine; the only file this review writes is this one.

---

## Findings

### claude-r2-01

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: point
- **domain**: tests
- **title**: The new stray-move arm in `move-oracle.py` fires on moves git *does* detect, and its message asserts the opposite
- **mechanism**: The cure for codex-04 added a second stray-move arm that pairs `base_tracked - tracked` against `tracked - base_tracked` by basename, because "a file moved *and* heavily edited arrives as a delete plus an add" and `-M` cannot see it. The arm never asks whether git saw the pair: `gone` and `appeared` are the raw set differences minus this package's own rows, with no subtraction of the `renamed` set the arm above it already computed (line 156-163). So every unexpected move is reported twice — once by rename detection and once here — and the second report ends with a sentence stating a fact about git that the arm never checked and that is false whenever `-M` did see the rename.
- **trigger**: reproduces in normal work — it fires at HEAD, on the tree as committed, with no manufactured input. `--package=P3 --base=ef090d27` prints four disagreements; the fourth is the `FindingFactory` move that the first line already reported as a rename.
- **in_scope**: yes — the arm is added by this commit (`move-oracle.py` +27 lines)
- **anchor**: `docs/internal/plans/test-structure/measurement/stage-04/move-oracle.py:177-190` (message at 187-190)
- **evidence**:
  ```python
  gone = {path for path in base_tracked - tracked if path not in expected_gone}
  appeared = {path for path in tracked - base_tracked if path not in expected_new}
  ```
  Run at HEAD, and git's own view of the same pair:
  ```
  $ python3 .../move-oracle.py --package=P3 --base=ef090d27
    tests/Analysis/Finding/Support/FindingFactory.php -> tests/Analysis/Policy/Baseline/Support/FindingFactory.php: a rename the map does not name
    tests/Analysis/Finding/Support/FindingFactory.php -> tests/Analysis/Policy/Baseline/Support/FindingFactory.php: a move the map does not name. git reports it as a delete and an add, so rename detection does not see it
  $ git diff --name-status -M ef090d27 | grep FindingFactory
  R098	tests/Analysis/Finding/Support/FindingFactory.php	tests/Analysis/Policy/Baseline/Support/FindingFactory.php
  ```
- **verification**: confirmed
- **verification_note**: `R098` is a rename at 98% similarity, i.e. exactly the case the new arm's sentence says did not happen. `fixes.md` records the duplication itself — "the `FindingFactory` move once per arm" — so the double line was seen; what was not seen is that the duplicate carries a false explanation. The cost is not cosmetic: the arm exists to make one specific blind spot visible, and a reader who checks the claim against `git diff -M` and finds it untrue learns to discount this arm's output, which is the one thing it cannot afford.
- **fix_direction**: make the arm answer only the question it advertises — exclude pairs that rename detection already reported before printing, so each stray move prints once and the sentence about git's view stays true for every line that carries it. The exclusion set is already computed a few lines above.

### claude-r2-02

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: point
- **domain**: tests
- **title**: The soft landing became a hard stop with a wider mouth, and its three named causes exclude the one this repository will actually hit
- **mechanism**: Two changes at once. (1) Scope: the old behaviour fell through to `not-a-prefix` only when *every* claim was unresolvable (`$coveredOwners === []` was the entry condition of that path); the new throw fires on **any** unresolvable claim, so a file whose verdict was `exact` — filed correctly, covering its own subject — now aborts the scan because one further `#[CoversClass]` names something outside the manifest. (2) Diagnosis: the refusal enumerates three causes — "a stale name, a typo, or a class that belongs in src/". The manifest declares exactly the 955 class-likes under `src/` and nothing else (measured: `src/` classlikes minus manifest declarations = 0, and the reverse = 0), so under `tests/` the realistic way to produce an unresolvable claim in this repository is neither of those three: it is **a class that already is in `src/` while the manifest has not been regenerated**. The refusal that diagnoses that correctly is `failSetDifference('manifest declarations do not match the production AST', …)` in `generate-modular-architecture-production-inventory.php:809-810`, reached through `composer architecture:check` — which sits in `check:artifacts`, after `check:code`. `composer check` aborts on the first failing group, and `scripts/phpunit-aggregate.py:51` excludes `--exclude-group=live-freshness`, which is the only PHPUnit case that runs the generator check. So the developer sees the `LogicException` and nothing else.
- **trigger**: reproduces in normal work — add a class under `src/` and a test for it under `tests/`, run `composer check` before regenerating the manifest. That is the ordinary order of work for the "Adding a new collector / rule / formatter" flows AGENTS.md documents.
- **in_scope**: yes — the throw is added by this commit
- **anchor**: `governance/TestSuiteHygiene/TestSubjectPaths.php:290-299`; ordering evidence at `composer.json` `check` / `check:code` / `check:artifacts` and `scripts/phpunit-aggregate.py:50-51`
- **evidence**:
  ```php
  if ($undeclared !== []) {
      throw new LogicException(\sprintf(
          '%s claims to cover %s, which the manifest does not declare. Under %s/ a #[CoversClass] names'
          . ' production code and production code is what the manifest declares, so this is a stale name,'
          . ' a typo, or a class that belongs in src/ — none of which part 3 can be asked about.',
  ```
  Probe through the class's own handed-in-facts entry point (no corpus touched), a file that was `exact` before the change:
  ```
  tests/Core/Unit/VersionTest.php  Qualimetrix\Core\Version                          => exact | (the level itself)
  tests/Core/Unit/VersionTest.php  Qualimetrix\Core\Version + PhpParser\Node\Stmt\Class_ => LogicException: … a stale name, a typo, or a class that belongs in src/
  ```
- **verification**: confirmed
- **verification_note**: both halves measured, not argued. The widening is demonstrated by the probe pair above (same path, one extra claim, `exact` → throw). The ordering is read off `composer.json` and the aggregate's exclusion list, and the correct diagnosis exists and is precise (`missing=[] extra=[<the class>]`) — it simply never runs in that scenario. This does not refute the cure: `fixes.md` argues the widening deliberately ("otherwise the partial case keeps silently dropping a claim"), and 0 of 759 claims in the tree are unresolvable today, so nothing is broken now. What is defective is the sentence, measured against the standard this very cure was written to: claude-01's complaint was a refusal "telling the reader to rename a directory — which would not have cured it", and the replacement tells the reader three things none of which is the cure either. Compare the sibling refusal added in the same commit, which ends "Either file the artifact under the owner that owns it, or name the exception in NON_MANIFEST_TEST_OWNERS" — a route. This one offers none.
- **fix_direction**: two separable moves. Name the missing cause in the message — a class present in `src/` that the manifest does not yet carry, with the command that settles it — so the first red the developer sees points at the right file; and decide explicitly whether a claim on a class outside `src/` is legitimate at all, because today the refusal implies it never is while offering no way to say so, unlike every other exception surface in this group. If the answer is "never legitimate", say that in the sentence rather than leaving it to be inferred from three causes that do not cover it.

### claude-r2-03

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: point
- **domain**: tests
- **title**: Two new generator refusals have no tracked control that proves they bite, in a file whose existing refusals do
- **mechanism**: The commit adds two refusal branches to the inventory generator — the unknown-owner arm of `assertTestOwnersAreManifestOwners()` (1490-1498) and the two-level sentence in `failUnownedTestClass()` (832-841). Neither has a live population: every row's owner is either a manifest owner or one of the two counted allowances, and no path in the tree names two levels. Neither has a planted control either. The repository already uses exactly that idiom for this file: `scripts/modular-architecture/tests/ModularArchitectureGeneratorRefusalTest.php` plants four breakages, two of which are the *forward* and *backward* halves of the suite-map reconciliation — the mechanism M2 rewrote in this same commit. That file is untouched by this commit. The verification for both new refusals is the hand plant recorded in `fixes.md`, which is a measurement of a tree that no longer exists. Consequence: a later edit that narrows the `tests/` prefix filter, or the arrival of a shape the level scan reads differently, disarms the refusal in silence — `composer check` stays green because a refusal that never fires and a refusal that cannot fire are indistinguishable from the outside. AGENTS.md names this failure mode in its own words for a neighbouring mechanism — "a control asserting a property of every member of an empty set passes".
- **trigger**: not reproducible today — it is a guard with an empty population and no control, so the failure mode is a future edit, not an input. Severity capped accordingly.
- **in_scope**: yes — both branches are added by this commit
- **anchor**: `scripts/generate-modular-architecture-test-inventory.php:1490-1498` and `:832-841`; the idiom they are missing from — `scripts/modular-architecture/tests/ModularArchitectureGeneratorRefusalTest.php` (4 cases, incl. `itFailsWhenACurrentSuiteLiteralHasNoDeclaredDirectory`)
- **evidence**:
  ```php
  foreach ($unknown as $owner => $paths) {
      $mismatches[] = sprintf(
          '%s is not one of the %d manifest owners, and %d row(s) under tests/ publish it: %s',
  ```
  Population of that branch at HEAD, from the regenerated artifact: every `subject_owner` under `tests/` is a manifest owner except `Reporting/HtmlTemplate` (10 rows) and `TestSupport/Logging` (1), both of which take the *allowance* path and never reach `$unknown`.
- **verification**: confirmed
- **verification_note**: stated precisely, because half of the new check is not in this gap. The **counted** half is exercised on every run and is genuinely closed in both directions: `$allowed[$owner] !== $entry['rows']` refuses a row leaving the allowance as loudly as one entering it, so a filter that silently stopped matching would miscount to 0 and redden. Hypothesis 4 of the brief is answered "yes, closed" on that half. It is the `$unknown` arm and the two-level sentence — the two branches with no live population — that nothing but a hand plant has ever executed. Likewise, the `judge()` throw of claude-r2-02 *is* covered, by two rewritten probes in `TestPathsNameTheirSubjectTest::itRefusesEachWayOnThePathsItIsGiven`, including the partial case; this finding does not extend to it.
- **fix_direction**: add the two breakages to the refusal test that already exists for this generator, alongside the suite-map pair it carries — one isolated-project case per refusal, asserting the exit code and the sentence. The alternative, leaving the hand plant in `fixes.md` as the record, makes the guard's liveness a claim about a past tree rather than a property the suite re-derives.

---

## Coverage — checked and clean

Everything below was examined against the commit and found sound; no finding was
written for it. Method named where a claim rests on a measurement.

- **Suite classification is unchanged by M2 (brief hypothesis 2).** The eleven
  regexes-turned-rows were compared as implementations, not read: old
  (two regexes above the walk) vs new (pure table walk) over **3,702 paths** —
  every tracked file in the repository plus 180 synthetic probes shaped to break
  a first-match walk: deeper nesting, adjacent capability names, trailing-slash
  and directory forms. **0 differences.** Structurally there is also no row earlier in the table that
  is a prefix of any path the removed regexes matched, so ordering cannot bite.
  The backward half of `assertSuiteClassifierAgreesWithPhpunit()` is exact
  literal equality against a `<directory>`, and all eleven new rows satisfy it.
- **The four owner-parse copies agree (brief hypothesis 3).** All four were
  executed, not compared by eye: the generator's `parseOwnerFromTestPath()`
  (extracted by source), `TestSubjectPaths::judge()` (called through the real
  class), and `parse_owner()` from `move-oracle.py` and `p0-oracle.py` (imported
  as modules), over 776 paths — every tracked file under `tests/` plus the
  awkward shapes the brief names: zero level segments, a `Unit` segment inside a
  remainder, two levels, `Support/` under a level, a directory path whose
  basename *is* a level, a file named `UnitTest.php`. **0 disagreements.** The
  basename drop the Python copies gained is a no-op for their callers (both are
  guarded by `endswith("Test.php")`), and the strictness change moves them onto
  the generator's answer in every case where they differed.
- **The dangling-name census survives substitution (brief hypothesis 5).** The
  comparison is set-difference in both directions, not a count, so a retired pin
  replaced by a new name is caught twice. Proven on a modified copy in
  `mktemp -d` (one pin swapped for a fictional name, census still nine): prints
  `NEW` for the unpinned real name and `GONE` for the fictional pin, exit 1.
  The clean tree run at HEAD: nine names, nine carriers, exit 0.
- **The counted allowance is closed downward as well as upward.** See
  claude-r2-03's verification note. Row counts verified against the regenerated
  artifact: `Reporting/HtmlTemplate` 10, `TestSupport/Logging` 1, matching
  `NON_MANIFEST_TEST_OWNERS` exactly.
- **M5's rewritten figures reproduce exactly.** Recomputed from
  `test-ownership.tsv`: settled rows carrying a package label **277**
  (`P3 4, P4 57, P5 1, P6-C 25, P6-D 1, P7 10, P8 179` — sums to 277), of which
  **156** outside `tests/` (`governance` 132, `scripts` 18, `tools` 6) and
  **121** under it; pending `permanent` **14**, pending `P3` **19**, total
  **926**. Every number in the rewritten paragraph and the block quote is right,
  including the correction of the stale 151.
- **The `--classification-probe` kind proxy agrees with the artifact
  (codex-05, opposite direction).** The probe decides the kind by basename while
  the main pass decides it by PHPUnit discovery, so the two could disagree.
  Measured over `test-ownership.tsv`: rows whose path ends `Test.php` but whose
  kind is not `phpunit-test-class` — **0**; rows whose kind is
  `phpunit-test-class` but whose path does not end `Test.php` — **0**. The proxy
  is exact over the current tree. Probed directly on the paths the fix names:
  `governance/Other/ProbeTest.php` and `tools/phpstan/tests/Unit/FooTest.php`
  both answer as before, the `Support/` path targets `{owner}/Support/`, the
  Schema fixture targets `tests/Reporting/Fixtures/Schema/`.
- **`derive-subject-path-exceptions.php` degrades gracefully under the new
  throw.** `SubjectPathExceptions::derive()` wraps `TestSubjectPaths::measure()`
  in `catch (Throwable)` and returns `MEASUREMENT_FAILED` having written
  nothing, printing the refusal text. The throw does not corrupt the tracked
  lists. List sizes at HEAD match `fixes.md`: 84/84, 19/19, 4/4.
- **Removing the two `targetPath()` disjuncts changes no live answer.** The
  population proof was re-derived, not accepted: every file under the eight
  `tests/Analysis/Evidence/{CodeSmell…Size}/` roots and under
  `tests/Infrastructure/Logging/Unit/` is a `*Test.php`, which the arm above
  answers for first. The retained `classifyOwner()` twin is genuinely not
  redundant — probed, it is the only branch naming an owner for a support file
  under those roots.
- **The population floor 500 → 600 is not currently at risk.** Population 616;
  pending moves out of `tests/` in the inventory: **0**. The 16-file headroom is
  spent only by deletions, and the comment says as much.
- **The tracked instruments were run, not cited.**
  `dangling-test-names.py` exit 0 (nine, exactly the pinned census);
  `move-oracle.py --package=P3 --base=ef090d27` exit 1 with the four
  disagreements — three are P6's admitted exceptions as `fixes.md` records, the
  fourth is claude-r2-01.

## Coverage — not reached

- **The full `composer check` aggregate was not re-run.** `fixes.md` records it
  green at this commit and the tree is unchanged since; re-running it would not
  have been evidence about anything this review claims.
- **`move-oracle.py` for `--package=P1` / `P2`.** Arm 6b's zero-hit measurement
  is recorded for P3's eleven rows only; the other two partitions were not
  measured, and their bases predate this commit.
- **The `check:code` → `check:artifacts` ordering of claude-r2-02 was derived,
  not executed.** Producing it for real means adding a class to `src/`, which
  this review may not do. The derivation rests on three read facts:
  composer aborts a script list on first failure, `test:aggregate` excludes
  `live-freshness`, and the AST-vs-manifest refusal lives only in
  `architecture:check`. The last was closed by name as well as by word: the only
  two non-`live-freshness` tests that invoke a `generate-modular-architecture*`
  script are `ModularArchitectureGeneratorRefusalTest` — which runs it inside an
  isolated project and asserts a *non-zero* exit, so a stale real manifest never
  reaches it — and a Python file that mentions it in a comment.
- **`04-packages.md` prose was checked for arithmetic and for agreement with the
  artifact, not for editorial quality.**
- **The two `live-freshness` cases themselves were not executed.** They are
  excluded from the aggregate by design; the one that matters here
  (`itChecksEveryGeneratedProjectionWithoutWriting`) runs the same script
  `composer architecture:check` runs standalone in `check:artifacts`, which
  `fixes.md` records as exit 0.

---

## Refuted

The brief's hypotheses, each tested and found clean. Listed here so that silence
is not mistaken for absence of a check; the measurement for each is in coverage.

- `H2` | Eleven regex-classified directories became table rows — can a path get a different suite? | No: 3,702 paths (every tracked file plus 180 synthetic probes), 0 differences between the old and new implementation; no earlier row is a prefix of any affected path.
- `H3` | Four copies of the owner parse aligned — do all four agree? | Yes: all four executed over 776 paths including every awkward shape named in the brief, 0 disagreements.
- `H5` | Can a substitution keep the dangling census at nine and pass? | No: the comparison is a two-way set difference, proven on a modified copy — one swap prints both `NEW` and `GONE`, exit 1.
- `H4` (counted half) | Is the allowance closed in both directions? | Yes for the count: a row leaving the allowance refuses as loudly as one entering it. The unfired `$unknown` arm is carried as claude-r2-03 instead.
- `H1` (soundness half) | Is a hard failure the right answer? | Not refuted and not carried as a defect: `fixes.md` argues it and the throw is properly probe-covered. Only the *message* and the widened mouth are carried, as claude-r2-02.
