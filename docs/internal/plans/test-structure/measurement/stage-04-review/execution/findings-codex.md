# Stage 04 execution review — codex (external CLI)

Range reviewed: `e15c7f42..HEAD` (11 commits, HEAD `1338d5fa`), branch `x30-stage-04-subject-layout`. Codex CLI run via `codex-eval.sh`, exit code 0 (clean, non-empty response). All findings below were independently re-verified against the checked-out tree by the facilitator (file reads / grep / TSV recomputation), not accepted on codex's word alone.

### codex-01

- **reviewer**: codex
- **severity**: MEDIUM
- **kind**: contract
- **domain**: architecture
- **title**: `closure_package` matches neither of the two readings `04-packages.md` claims are "equally defensible"
- **mechanism**: `04-packages.md` argues `closure_package` can be read either as "which package still owes a move" or as "which package already settled it, `permanent` meaning none was ever needed" — and that both readings are correct over the 272 rows it measured. The facilitator recomputed the predicate `target_path != current_path` (a genuine pending move) over the full artifact: 33 rows satisfy it, 19 labelled `P3` and 14 labelled `permanent`. Both readings fail on this subset: `permanent` cannot mean "no move needed" when the row's own `target_path` differs from `current_path`, and `P3` cannot mean "P3 already moved it" for the same reason.
- **trigger**: воспроизводится в нормальной работе — reading `closure_package` as either advertised meaning misclassifies these 33 rows today, at HEAD, without any manufactured input.
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/04-packages.md:460-476` (the argument); `docs/internal/generated/modular-architecture/test-ownership.tsv` (regenerated at HEAD, columns `current_path`(1)/`target_path`(9)/`closure_package`(10))
- **evidence**:
  ```
  $ awk -F'\t' 'NR>1 && $1!=$9 {print $10}' test-ownership.tsv | sort | uniq -c
    19 P3
    14 permanent
  ```
  Example rows (`current_path` / `target_path` / `closure_package` / `disposition`):
  - `src/Reporting/Template/package.json` → `tests/Reporting/HtmlTemplate/Tests/package.json`, closure_package=`permanent`, disposition="Move atomically…"
  - `tests/Analysis/Evidence/Measurement/Fixtures/GlobalNamespaceOnly/Storage.php` → `.../Fixtures/Storage.php`, closure_package=`P3`, disposition="Move atomically…"
- **verification**: confirmed
- **verification_note**: recomputed independently from the checked-out `test-ownership.tsv`, not taken from codex's assertion; the plan's own 272-row measurement is a superset that happens to exclude this 33-row subgroup where the practical move signal (`target_path`/`disposition`) still disagrees with `closure_package`.
- **fix_direction**: derive `closure_package` from `target_path`/`disposition` directly for rows with a pending move, or rename the column to make clear it is a historical-attribution field that must never be read as a move-ownership signal; keep the two concerns in separate columns.

### codex-02

- **reviewer**: codex
- **severity**: MEDIUM
- **kind**: contract
- **domain**: architecture
- **title**: generator's level-segment parsing and the governance invariant control disagree on how many level segments a path may name
- **mechanism**: `parseOwnerFromTestPath()` in the generator walks path segments and returns at the *first* match against `TEST_LEVELS` (`Unit`/`Integration`/`Functional`), never checking whether a second level segment exists further down the path. `TestSubjectPaths::judge()` (the invariant control introduced this stage) collects *all* level-segment matches in the directory portion of the path and requires the count to be exactly 1, failing with a `LEVEL` verdict otherwise. `targetPath()` treats any path already matching `isTestClassPath()` (`tests/.../…Test.php`) as already correct and returns it unchanged (Retain).
- **trigger**: правдоподобное — adding a test file whose namespace-remainder directory happens to be named after another test level, e.g. `tests/Reporting/Unit/Formatter/Integration/FooTest.php` (owner "Reporting", remainder containing literal directory "Integration").
- **in_scope**: да
- **anchor**: `scripts/generate-modular-architecture-test-inventory.php:721-727` (`parseOwnerFromTestPath`, first-match), `scripts/generate-modular-architecture-test-inventory.php:1277-1279` (`targetPath`, unconditional Retain for `isTestClassPath`), `governance/TestSuiteHygiene/TestSubjectPaths.php:218-228` (`judge`, requires exactly one level)
- **evidence**:
  ```php
  // generator, first match wins:
  foreach ($segments as $index => $segment) {
      if (in_array($segment, TEST_LEVELS, true)) {
          return implode('/', array_slice($segments, 0, $index));
      }
  }
  ```
  ```php
  // governance control, counts all matches:
  $levels = array_keys(array_filter($directories,
      static fn(string $segment): bool => \in_array($segment, self::LEVELS, true)));
  if (\count($levels) !== 1) { return ['verdict' => self::LEVEL, ...]; }
  ```
- **verification**: confirmed
- **verification_note**: within the generator's own three functions the answer is internally consistent (owner derived, target computed, disposition = Retain, all agreeing) — but that internal agreement is a weaker property than the Stage-04 invariant the new governance control enforces, and the two would produce opposite verdicts for the example path.
- **fix_direction**: make `parseOwnerFromTestPath()` reject (return null / treat as `dispositionFor` violation) when more than one level segment is present, matching `TestSubjectPaths`'s stricter rule, instead of silently accepting the first.

### codex-03

- **reviewer**: codex
- **severity**: MEDIUM
- **kind**: contract
- **domain**: tests
- **title**: the `phpunit.xml.dist` ↔ `testSuitePrefixTable()` reconciliation is not actually bidirectional for 11 directories
- **mechanism**: 11 of the 76 `<directory>` entries in `phpunit.xml.dist` (the 8 `Analysis/Evidence/{CodeSmell,Cohesion,Complexity,Coupling,Design,Maintainability,Security,Size}/Unit` dirs plus the 3 `{CodeSmell,Complexity,Coupling}/Integration` dirs) are classified by two hardcoded regexes inside `currentSuite()` rather than by an entry in `testSuitePrefixTable()`. `assertSuiteClassifierAgreesWithPhpunit()`'s forward loop iterates the *declared* `<directory>` entries and classifies each via `currentSuite()` (which does cover these 11 via the regex branches, so today's forward check is green). Its backward loop, however, iterates only `testSuitePrefixTable()` and checks that each literal has a matching declared directory — it never asks "is every path `currentSuite()` classifies also declared". A directory that only exists via a regex branch can be deleted from `phpunit.xml.dist` without either loop noticing: it disappears from the forward loop's input (nothing left to check), and it was never in the table for the backward loop to miss.
- **trigger**: обычное изменение — deleting one of these 11 lines from `phpunit.xml.dist` (e.g. accidental rebase/merge damage, or an editor "cleaning up" what looks like a redundant regex-covered entry) silently drops that suite's discovery with no red anywhere in this control.
- **in_scope**: да
- **anchor**: `phpunit.xml.dist:36-43,63-65` (the 11 undeclared-in-table directories); `scripts/generate-modular-architecture-test-inventory.php:1028-1097` (`testSuitePrefixTable`, 65 entries, missing these 11); `scripts/generate-modular-architecture-test-inventory.php:1101-1106` (the two regex branches in `currentSuite()`); `scripts/generate-modular-architecture-test-inventory.php:1129-1159` (`assertSuiteClassifierAgreesWithPhpunit`, forward loop over declared dirs, backward loop over the table only)
- **evidence**: forward loop:
  ```php
  foreach ($document->testsuites->testsuite as $suite) {
      foreach ($suite->directory as $directory) {
          $classified = currentSuite($declared . '/probe/ProbeTest.php');
          if ($classified !== $name) { $mismatches[] = ...; }
  ```
  backward loop:
  ```php
  foreach (testSuitePrefixTable() as $entry) {
      if (!in_array($literal, $declaredDirectories[$entry['suite']] ?? [], true)) { $mismatches[] = ...; }
  ```
- **verification**: confirmed
- **verification_note**: `grep -c '<directory' phpunit.xml.dist` = 76 lines matched in the earlier read; the docblock at `:1015-1024` explicitly documents "six directories had drifted apart... before this check existed" and claims the check is now symmetric, but the symmetry only holds for table-registered directories, not regex-classified ones.
- **fix_direction**: either fold the two regex-classified families into `testSuitePrefixTable()` as ordinary entries (removing the special-cased regex branches from `currentSuite()`), or add a second backward pass that also verifies every directory `currentSuite()` can classify via a non-`none` regex branch is present in `phpunit.xml.dist`.

### codex-04

- **reviewer**: codex
- **severity**: MEDIUM
- **kind**: point
- **domain**: tests
- **title**: `move-oracle.py`'s "nothing outside the package moved" claim only inspects git-detected renames
- **mechanism**: the oracle's "no stray move" check parses `git diff --name-status -M` and collects only lines whose status starts with `R` (rename), comparing that set against the package's expected renames. A file moved with content changed enough to fall below git's rename-similarity threshold is reported by git as a `D` (delete) + `A` (add) pair, not `R`, and is invisible to this loop entirely — the oracle would report a package as clean while an unrelated file was silently relocated with edits.
- **trigger**: обычное изменение — moving a file to a new path while editing enough of its content to drop below git's default rename-detection similarity.
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/measurement/stage-04/move-oracle.py:149-155`
- **evidence**:
  ```python
  for line in git("diff", "--name-status", "-M", arguments.base).split("\n"):
      fields = line.split("\t")
      if fields[0].startswith("R") and len(fields) == 3:
          renamed.add((fields[1], fields[2]))
  expected_renames = {(row["current"], row["target"]) for row in mine}
  for rename in sorted(renamed - expected_renames):
      failures.append(f"{rename[0]} -> {rename[1]}: a rename the map does not name")
  ```
- **verification**: confirmed
- **verification_note**: the loop's own set-difference logic is correct for what it inspects (`R*` renames); the gap is in scope, not logic — it never examines `D`/`A` pairs at all, so a stray delete+add is neither flagged as a stray rename nor caught by any other arm of the script (the "gone from tree, not at target" arm only fires for the package's own `mine`/`others` rows, not for arbitrary third-party paths).
- **fix_direction**: compare the full `git diff --name-status` output (all statuses) against the package's allowed file-set, rather than relying on rename-detection as the sole signal that nothing outside the package moved.

### codex-05

- **reviewer**: codex
- **severity**: LOW
- **kind**: point
- **domain**: architecture
- **title**: `--classification-probe` calls `targetPath()` with a hardcoded `kind`, producing a fabricated target for non-test-class paths
- **mechanism**: the main inventory pass first determines each path's actual `kind` (`phpunit-test-class`, `support`, `fixture`, etc.) before calling `targetPath()` with that real kind. The `--classification-probe` code path, used for ad-hoc single-path queries, always passes the literal string `'phpunit-test-class'` to `targetPath()` regardless of what the path actually is. For a real support file this produces a target path shaped for the test-class branch (`{owner}/none/{basename}`, using the probe's own `$targetSuite` fallback of `'none'`), which does not match what the main pass would compute for the same file.
- **trigger**: normal use of the `--classification-probe` debug flag on any non-test-class path (support/fixture files).
- **in_scope**: нет — the call site predates this stage's diff (the probe existed before), but Stage 04's rewrite of `targetPath()`'s branching (new remainder-segment logic) is what makes the divergence produce a materially wrong path rather than a coincidentally-matching one; flagged here because it surfaced only after this stage's change.
- **anchor**: `scripts/generate-modular-architecture-test-inventory.php:348-360`
- **evidence**:
  ```php
  [$owner, $closurePackage] = classifyOwner($path);
  $currentSuite = currentSuite($path);
  $targetSuite = $currentSuite === 'Infrastructure' ? (...) : $currentSuite;
  fwrite(STDOUT, implode("\t", [
      $owner, $closurePackage, $currentSuite,
      targetPath($path, 'phpunit-test-class', $owner, $targetSuite),
  ]) . "\n");
  ```
  Confirmed with a real file: `tests/Analysis/Policy/Baseline/Support/FixedClock.php` exists on disk at that path (main pass, `kind=support`, would Retain it there); the probe's forced `'phpunit-test-class'` kind produces a different, fabricated target.
- **verification**: confirmed
- **verification_note**: verified the file exists at the claimed current path via `ls`; did not execute the probe flag itself (execution was avoided per the read-only constraint, since the generator script is a project file whose invocation was not deemed necessary to risk — the code-path logic alone is sufficient to confirm the divergent branch is reached for any support/fixture input).
- **fix_direction**: have the probe determine the real `kind` for the given path the same way the main pass does, instead of hardcoding `phpunit-test-class`.

### codex-06

- **reviewer**: codex
- **severity**: LOW
- **kind**: point
- **domain**: tests
- **title**: `dangling-test-names.py` cannot distinguish a stale class name from a live namespace prefix
- **mechanism**: for each regex match of a dotted/backslashed name in tracked source, the script skips it as "not dangling" if the name is a declared class/namespace, or if it is a strict prefix of some declared name (i.e., looks like a namespace segment). It never inspects how the name is actually used at the match site (e.g. `Foo\Bar::class`, a `use` statement, a static call) — it decides purely from string-prefix membership against the declared-name set.
- **trigger**: специфичное, но правдоподобное — a deleted class's FQCN happens to be a literal prefix of a currently-live namespace (e.g. old class `App\Foo` deleted, new namespace `App\Foo\Bar` exists); a genuine dangling reference to the old class name would be silently treated as an innocuous namespace mention and never reported.
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/measurement/stage-04/dangling-test-names.py:106-111`
- **evidence**:
  ```python
  if name in declared:
      continue
  # A namespace is not a class reference: it dangles only when no
  # declared name lives beneath it.
  if any(other.startswith(name + "\\") for other in declared):
      continue
  ```
- **verification**: confirmed
- **verification_note**: this is a limitation of the script's stated, narrower contract (regex-based lexical scan, explicitly a "measurement" per its own exit-code convention, not a pass/fail control) rather than a bug relative to a claim it never made; still worth recording since the review brief asked whether the tool's proven property matches what it is trusted for.
- **fix_direction**: narrow the tool's documented claim to "no bare-name matches outside declared-name prefixes", or add a lightweight usage-context check (e.g. requiring `::` or `use ` adjacency) before treating a prefix match as exculpatory.

## Coverage

- **Q1 (are `classifyOwner()`/`dispositionFor()`/`targetPath()` mutually consistent?)** — Internally consistent for the 616 `phpunit-test-class` rows in the regenerated `test-ownership.tsv` at HEAD: `classifyOwner()` validates the owner, `targetPath()` returns the path unchanged for any path already matching `isTestClassPath()`, and `dispositionFor()` derives Retain/Move purely from `target == current`. No owner/target/disposition mismatch was found by direct inspection of these code paths. However, this is a *narrower* invariant than the Stage-04 layout rule the new governance control enforces: codex-02 shows a concrete path shape where the generator's first-level-wins parsing and the stricter one-level-only governance rule diverge, and codex-05 shows the `--classification-probe` debug path uses a different, wrong `kind` than the main pass would.
- **Q2 (can a file satisfy both the new owner-vs-path invariant and `TestNamespacesFollowTheirPathTest`'s namespace-vs-path check, and still be misplaced? does one allow-list quietly excuse what the other should catch?)** — Yes, a file can satisfy both and still be "misplaced" relative to what it actually covers: `tests/Analysis/Policy/Baseline/Functional/BaselineCleanupCommandTest.php` has a namespace that matches its path exactly (satisfies the namespace control) and is at a path that resolves to a valid manifest owner (satisfies the new path-vs-owner invariant), yet its sole `#[CoversClass(BaselineCleanupCommand::class)]` names a class owned by `Infrastructure.Console`, not `Analysis.Policy.Baseline` — excused only via the pre-existing `covers_another_owner` allow-list in `governance/TestSuiteHygiene/subject-path-exceptions.php:116`. Verified: the file's namespace declaration and `CoversClass` attribute both checked by direct read. This is explicitly called out in the plan as deferred Stage-05 debt, not an accidental Stage-04 regression. On whether the two controls mask each other: no silent cross-masking was found — the two allow-lists (`NamespacePathAllowList`, `SubjectPathExceptions`) are independently maintained and each enforces its own axis (PSR-4 path↔namespace vs. path-owner↔`CoversClass`-owner); a file can appear on both lists simultaneously without either list compensating for what the other should have caught, because neither reads the other's exceptions. `TestTree.php`'s addition of a `covers` key was confirmed additive by reading the diff context around it — existing named keys (`namespaces`/`classes`/`declarations`) are untouched and its four existing callers were not observed to change behavior for paths outside Stage 04.
- **Q3 (is the `closure_package`/`dispositionFor` "both readings defensible" argument in `04-packages.md` sound?)** — No — see codex-01 above; the plan's own 272-row measurement excludes a 33-row subgroup (independently recomputed from the live TSV) where `target_path != current_path` genuinely, and neither of the two offered readings of `closure_package` is consistent with that subgroup. The practical relocation signal today still lives entirely in `target_path`/`disposition`, so nothing is currently lost in practice — but a future consumer that trusts `closure_package` as a planning signal (exactly the use the plan's own "which package still owes a move" reading invites) would miss these 33 real pending moves.
- **Q4 (do the three package judges — `move-oracle.py`, `p0-oracle.py`, `dangling-test-names.py` — prove what they're trusted for, given each was found defective four times by the package it judged?)** — Each judge's code was read in full. `p0-oracle.py` provably checks: equality of the `current_path` sets between baseline and actual snapshots, four named columns for allowance/conforming rows, and immutability of the six other columns — but `read_tsv()` collapses rows into a dict keyed by path, so it cannot detect duplicate rows or ordering changes, and its own owner-parsing helper reimplements the same first-level-wins semantics as the generator (so it cannot catch codex-02-shaped defects — it would agree with a wrong answer computed the same way). `move-oracle.py` provably checks package-file-set presence/absence, an inventory-agreement check restricted to the package's own rows, and (per codex-04) a stray-rename check that is blind to non-rename moves. `dangling-test-names.py` provably enumerates literal regex matches against declared names in tracked, readable files — excluding history, untracked carriers, and (per codex-06) namespace-prefix collisions — and is explicitly self-described as a measurement, not a control (its own exit convention returns nonzero for known, accepted names, so exit code alone cannot be package pass/fail without comparing to a baseline set). The common pattern across all three: each proves a real but narrower set-equality/consistency property than the informal claim ("this package didn't move anything it shouldn't have" / "no stale names remain") it was used to certify. Given that pattern held across three independently-authored tools and was already caught four separate times per package by the package's own later work, further undetected instances of the same class (a judge proving a strictly weaker property than what its pass/fail was read to mean) are plausible; the facilitator did not attempt to enumerate a complete list beyond what codex surfaced.

Additionally confirmed clean by direct inspection (facilitator, not codex-only): `2edb8d33`/`39d018a7` do open and close a transitional allowance in the generator (commit subjects and diff both consistent with "the transitional allowance closes"); `validateP4Topology()` is absent from HEAD's `generate-modular-architecture-test-inventory.php`; the `TestTree.php` `covers` key addition does not alter the four existing named keys consumed elsewhere; no repository files were modified during this review (read-only throughout, per the task constraint).

## Refuted

- codex-R01 | `dispositionFor()` hides a pending move as Retain | Recomputed over the live `test-ownership.tsv`: zero rows have `target != current` with `disposition=Retain`, and zero rows have `target == current` with `disposition=Move`; the practical relocation signal (`target_path`/`disposition`) is currently sound even though `closure_package` (codex-01) is not.
- codex-R02 | the namespace allow-list silently compensates for a defect the subject-path control should have caught | The two allow-lists (`NamespacePathAllowList`, `SubjectPathExceptions`) are independently maintained, check orthogonal axes, and neither reads the other; overlap exists (the same file can appear on both) but is not masking — each list's own missing/stale-row check still fires independently.
- codex-R03 | adding the `covers` key changed `TestTree`'s prior semantics for existing consumers | The diff around `TestTree.php` adds a new output key and its own visitor state without altering the previously emitted `namespaces`/`classes`/`declarations` values or the four existing callers that read them.

## Confidence and gaps

High confidence on the generator's three classifying functions, the governance controls read in full, the `phpunit.xml.dist`/table reconciliation, and all three Python judge scripts — all read in full and cross-checked against the live tree (recomputed TSV predicates, `grep`/`sed` line verification, direct file existence checks), not accepted from codex's prose alone.

Not covered in this pass: `composer check`/PHPUnit/`architecture:check` were not executed (would create cache/generated artifacts, conflicting with the read-only constraint on this shared working tree); no attempt was made to actually invoke `--classification-probe` to observe codex-05's fabricated output firsthand (confirmed by code-path reading and file-existence check instead); the facilitator did not independently re-derive whether *further* instances of the codex-04/codex-06-shaped gaps (judges proving a narrower property than claimed) exist beyond what codex surfaced — Q4's answer in Coverage above is codex's judgment, spot-checked but not exhaustively re-derived by the facilitator; the six commits doing the mechanical file moves (`ae65dc9d`, `ef090d27`, `3213b905`, `fa19b515`, `1338d5fa`, and the P4/P5 predecessors) were trusted as mechanical renames per `git diff -M` and were not individually diffed file-by-file.
