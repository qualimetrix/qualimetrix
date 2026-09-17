# Stage 03 — plan review, round 2 (narrow)

Material: exactly `7e94911b`, against `194dbfea`. Subject of the round: the new
`measurement/stage-03/addresses-to-edit.md`, the four rewritten plan sections,
and the edits to `baseline.md` / `population.md` / `registration-addresses.md`.
Round-1 findings are not revisited.

Every line number and every loud/silent verdict below was re-derived against the
tree in this session; where a verdict needed execution, the command is named.

---

### claude-r2-02

- **reviewer**: claude
- **severity**: HIGH
- **kind**: contract
- **domain**: tests
- **title**: Address 10 (`createIsolatedProject()`) is assigned to P1 alone, but every mover breaks it the same way — the scratch project copies `tests`, `governance`, `src` and one `scripts/` file
- **mechanism**: The round-1 fix correctly moved the copy-list edit to P1 because P1 declares a `<directory>` under `tools/`. But the same argument holds for P2-P5: each declares `<directory>scripts/<tool>/tests</directory>`, and `createIsolatedProject()` does not copy `scripts/` — it does `mkdir($projectRoot . '/scripts')` and copies exactly one file into it. So `scripts/promise-effect/tests`, `scripts/directive-audit/tests`, `scripts/directive-audit-controls/tests`, `scripts/finding-gate/tests` and the five new flat-tool test directories are all *absent* inside the scratch root, which is the case this stage's own `baseline.md` measured as PHPUnit exit 2, four ways. The table grades address 10 `L (PHPUnit exits 2 inside the scratch root)` and assigns it `**P1**, though the file moves in P5`; the plan's boundary note (`03-tooling-tests.md:334-345`) argues it only for `tools/`. Every other "each mover" address (1, 4, 6, 7, 17) is graded that way; this one is not.
- **trigger**: воспроизводится в нормальной работе — P2 is red on `ModularArchitectureGeneratorRefusalTest` the moment it declares its directory
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/measurement/stage-03/addresses-to-edit.md:24` (row 10) and `docs/internal/plans/test-structure/03-tooling-tests.md:334-345`
- **evidence**: `tests/Analysis/Policy/Architecture/Integration/ModularArchitectureGeneratorRefusalTest.php:196-200`:
  ```php
  self::assertTrue(mkdir($projectRoot . '/scripts'));
  self::assertTrue(copy(
      $sourceRoot . '/scripts/generate-modular-architecture-test-inventory.php',
      $projectRoot . '/scripts/generate-modular-architecture-test-inventory.php',
  ));
  ```
  and `phpunit.xml.dist` declares no `scripts/` directory today, which is why the current copy list suffices. `baseline.md:56-65` is the exit-2 measurement.
- **verification**: confirmed
- **verification_note**: The `git add` list at `:207-216` is a second root list in the same function, but it is *not* a third edit: the generator's scan uses `git ls-files --cached --others --exclude-standard` (`scripts/generate-modular-architecture-test-inventory.php:349`), and `--others` lists untracked files, so a copied-but-unadded directory is still enumerated. Checked so the finding does not over-claim.
- **fix_direction**: Regrade row 10 as "each mover, for its own dir", the way rows 1/4/6/7 are graded, and drop the P1-only assignment; keep the separate note that P5 owns the file's *relocation* only. The plan's boundary paragraph should state the rule (a declared `<directory>` must exist inside the scratch project) rather than the `tools/` instance of it.

---

### claude-r2-01

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: contract
- **domain**: tests
- **title**: The derived checklist drops `dispositionFor()` and `targetPath()`, which its own source measurement names — the two addresses that fail silently by publishing a false relocation
- **mechanism**: `addresses-to-edit.md` says it is "Derived from `registration-addresses.md` and `pinned-references.md`" and that it is "every address a package must edit". `registration-addresses.md:22` carries a row titled "Same file's `dispositionFor()`/`targetPath()`/`classifyOwner()` unmatched-path branches" with the exact anchors `:1152-1197` and `:1199-1233`, and `:62` repeats both by name. The derived table carries only `classifyOwner()` (address 9), `classifyKind()` (8), the prefix table (7) and the scan scope (6). Consequence, measured: once `classifyOwner()` gains a branch — which the table *does* require — `dispositionFor()` falls through to its terminal `return 'Move atomically with the named owner and closure package.'` and `targetPath()` falls through to `'tests/' . $owner . '/' . $targetSuite . '/' . basename($path)`. The generated inventory then asserts that every tooling test *must move back into* `tests/`, which is the opposite of what this stage decided, and `validateInventory()` checks only target-path *collisions*, never target-path *truth* — so `composer architecture:check` regenerates the lie and compares it against itself. Both functions carry a `str_starts_with($path, 'governance/')` branch added for exactly this reason when `governance/` became a root; that is the precedent, in the same two functions.
- **trigger**: воспроизводится в нормальной работе — any mover that adds the `classifyOwner()` branch the table requires and nothing else
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/measurement/stage-03/addresses-to-edit.md:20-23` (the registration table, rows 6-9)
- **evidence**: executed against an isolated copy of the generator (`mktemp -d`, project symlinked, only `classifyOwner()` patched with a `tools/phpstan/tests/` + `scripts/directive-audit/tests/` branch — the project tree was not touched):
  ```
  $ php <copy> --classification-probe=tools/phpstan/tests/BannedStringPathPropertyRuleTest.php
  Architecture.Governance	P8	none	tests/Architecture.Governance/none/BannedStringPathPropertyRuleTest.php
  $ php <copy> --classification-probe=scripts/directive-audit/tests/DirectiveAuditGateTest.php
  Architecture.Governance	P8	none	tests/Architecture.Governance/none/DirectiveAuditGateTest.php
  ```
  The fourth column is `targetPath()`'s return. Unpatched, both paths instead hit `classifyOwner()`'s terminal `fail('Unclassified test artifact: ' . $path)` (exit 1) — confirming address 9's `L` and that address 9 is the only gate in front of these two functions.
- **verification**: confirmed
- **verification_note**: **Deliberately MEDIUM and not HIGH, after looking for a consumer.** Nothing reads `target_path` or the disposition string programmatically: `test-ownership.tsv` is emitted (`:445`) and byte-compared by `architecture:check`, so a wrong value regenerates and matches itself; the only assertions on `target_path` are `validateInventory()`'s collision check (`:1327-1334`) and `validateP4Topology()`'s owner-scoped prefix checks (`:1378-1388`), and stage 04's `04-subject-layout.md` names neither the column nor the artifact. So the confirmed effect is a permanently false relocation claim in a published governance artifact with a human reader, not a broken run — which is MEDIUM on this scale. An automated consumer of the column would make it HIGH. **One fork does bite loudly, and the plan names it nowhere:** row 16 (`ModularArchitectureGeneratorRefusalTest.php`, moved by P5) probes today as `Analysis/Policy/Architecture / P4`, and `validateP4Topology()` refuses any P4 Architecture class whose `target_path` does not start with `tests/Analysis/Policy/Architecture/`. Keep the owner and P5 hits `fail('P4 Architecture test has an unexpected target path: …')`; change the owner to escape it and the row silently leaves a governance invariant nobody decided to relax. The omission is further aggravated by the plan's own P7 item, `03-tooling-tests.md:377-379`: it instructs P7 to rewrite CLAUDE.md's inventory row as "two mechanisms" — the scan-scope literal and `TestTree`'s derivation. CLAUDE.md's row is currently the only place in the repository that names `dispositionFor()` and `targetPath()` as addresses; executing that correction as written deletes the last record of them.
- **fix_direction**: Add both functions as their own rows, graded silent, with the `governance/` branch cited as the precedent for what the branch must return ("retain at the path it is already at", not a move). Assign them to P1 together with the `classifyOwner()` branch, since the three fall through together. Re-scope the P7 CLAUDE.md correction so that it splits the loud/silent grading without shortening the function list.

---

### claude-r2-03

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: point
- **domain**: tests
- **title**: `P7_MEASUREMENT_PATHS` pins a file P6 moves, and it is not among the 31 addresses
- **mechanism**: `scripts/generate-modular-architecture-test-inventory.php:81` pins `tests/Analysis/Evidence/Measurement/Tests/test_cross_tool_comparison.py` inside `const P7_MEASUREMENT_PATHS` (opened at `:70`), and `assertPathLiteralsResolve()` (`:1716-1741`) fails on any member the worktree does not have. That is one of the two Python files P6 moves. The pinned-literal family in the table (addresses 27-30) covers `Probes.php`, `Suite.php` and the rename-enumeration comment, and the inventory literals (18-23) cover the prefix tables and `systemSupportContents()`; none covers the generator's pinned-path constants. P6's package row carries no address numbers at all — only "`test:cross-tool`'s `-s` paths".
- **trigger**: воспроизводится в нормальной работе — P6 is red until the literal is retired
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/measurement/stage-03/addresses-to-edit.md:73-79` (the pinned-references table)
- **evidence**: `scripts/generate-modular-architecture-test-inventory.php:1720,1733-1737`:
  ```php
  'P7_MEASUREMENT_PATHS' => P7_MEASUREMENT_PATHS,
  ...
  fail(sprintf('%s names %s, which the worktree does not have. Follow the rename,'
      . ' or move the entry to RETIRED_PATH_ASSERTIONS with the reason it is gone.', $constant, $path));
  ```
- **verification**: confirmed
- **verification_note**: The same constant is read by `dispositionFor()` (`:1161`) and `targetPath()` (`:1208`) through `in_array($path, P7_MEASUREMENT_PATHS, true)`, which is how that Python file currently earns "Retain at the materialized subject-owned path" — so retiring the literal without claude-r2-01's branches turns its disposition into a false "Move atomically". The two findings compound at this one row.
- **fix_direction**: Add a row for the generator's pinned-path constants, graded loud with the `RETIRED_PATH_ASSERTIONS` escape it names itself, and assign it to P6. Sweep the other constants in `assertPathLiteralsResolve()`'s list against the 18 moving files rather than only `P7_MEASUREMENT_PATHS`.

---

### claude-r2-14

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: point
- **domain**: tests
- **title**: Moving row 7 out of `tests/Analysis/Policy/Baseline/` invalidates a tracked SHA-256 digest of that directory, and P4 owns no address for it
- **mechanism**: `p6CBaselinePaths()` enumerates **every file** under `tests/Analysis/Policy/Baseline`, and the generator hashes that list against the tracked constant `P6_C_BASELINE_PATHS_SHA256` on every run, before any classification happens. Row 7 of the population — `tests/Analysis/Policy/Baseline/Unit/ChannelRenameTsvGateAgreementTest.php`, moved by P4 — is a member of that set, so the moment it leaves, the digest no longer matches and the generator refuses. The fix is to re-derive a tracked 64-character literal, which is neither a classifier branch nor a prefix row and is covered by none of the 31 addresses; P4's package row is `1, 4, 6, 7, 17, 31`. The same `tests/Analysis/Policy/Baseline/` prefix also carries its own hard refusal inside `classifyOwner()` for any path not in the digest-checked set.
- **trigger**: воспроизводится в нормальной работе — P4 cannot run the generator at all until the digest is re-derived
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/measurement/stage-03/addresses-to-edit.md:73-79` (the pinned-references table) and `docs/internal/plans/test-structure/03-tooling-tests.md:327` (P4's row)
- **evidence**: `scripts/generate-modular-architecture-test-inventory.php:317-319`:
  ```php
  $p6CBaselinePaths = p6CBaselinePaths($projectRoot);
  if (hash('sha256', implode("\n", $p6CBaselinePaths) . "\n") !== P6_C_BASELINE_PATHS_SHA256) {
      fail('P6-C Baseline test artifact set differs from the reviewed finite path digest.');
  }
  ```
  `p6CBaselinePaths()` at `:634-648` walks the directory with `RecursiveDirectoryIterator` and keeps every `isFile()`; confirmed by iterating it that row 7 is in the set. The constant is at `:17`.
- **verification**: confirmed
- **verification_note**: Row 7 probes today as `Analysis/Policy/Baseline / P6-C`, and `classifyOwner()` at `:678-681` refuses with `fail('Unclassified P6-C Baseline test artifact: ' . $path)` for any `tests/Analysis/Policy/Baseline/` path outside `$p6CBaselinePaths` — so the digest and the classifier are two refusals on one move. Loud, which is why this is MEDIUM: P4 cannot miss it, but a checklist claiming to be every address should carry it rather than leave it to be discovered.
- **fix_direction**: Add a row for the tracked digest, graded loud, with the re-derivation named; assign it to P4. More generally, sweep the generator's *tracked constants* — the digest, `RETIRED_PATH_ASSERTIONS`, the pinned-path lists — against the 18 moving files, since the table's pinned-references section currently covers only literals carried by the tools.

---

### claude-r2-04

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: point
- **domain**: tests
- **title**: Address 24 is graded `L`, but only its two rows are loud — the `ceiling` half is silent
- **mechanism**: Row 24 is "two rows + lowered `ceiling`", graded `L; **re-derive, never hand-edit**`. The two rows are genuinely loud: `itCarriesNoStaleAllowListEntry` refuses a row whose violation is gone, and `itJudgesEveryTestFileInTheTree` refuses a row whose path is not in `TestTree::testFiles()`. The `ceiling` is not. The only assertion on it is `assertLessThanOrEqual(NamespacePathAllowList::ceiling(), count($allowed))` — a ceiling left at 57 over 55 rows passes. The lowering happens only inside the derive command, via `min($ceiling, count($violations))`. So the exact failure mode the row warns against — hand-editing the rows out instead of re-deriving — is the one it grades loud and the code grades silent. A wrong `L` is the defect class this table was created to close.
- **trigger**: воспроизводится в нормальной работе — a mover who deletes the two rows by hand, trusting the `L`
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/measurement/stage-03/addresses-to-edit.md:64` (row 24)
- **evidence**: `governance/TestSuiteHygiene/TestNamespacesFollowTheirPathTest.php:146-150`:
  ```php
  $allowed = NamespacePathAllowList::load();
  self::assertGreaterThan(500, \count($judged));
  self::assertLessThanOrEqual(NamespacePathAllowList::ceiling(), \count($allowed));
  ```
  and `governance/TestSuiteHygiene/NamespacePathAllowList.php:292`: `self::write(self::render($violations, min($ceiling, \count($violations))))`. Current tracked value: `namespace-path-allow-list.php:20` `'ceiling' => 57`, rows `:77-78` are the two that move.
- **verification**: confirmed
- **verification_note**: Nothing else in `governance/` reads `ceiling()`; grepped.
- **fix_direction**: Split the row: the two rows loud, the ceiling silent, with the derive command named as the only thing that lowers it. The DoD already prescribes re-deriving (`03-tooling-tests.md:249-252`); the table should agree with it instead of implying a guard will catch a hand-edit.

---

### claude-r2-05

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: contract
- **domain**: tests
- **title**: P1's package row omits address 17 — the round-1 finding's own address, for the first mover
- **mechanism**: Address 17 (`surfaces()` gains the new roots) is graded "each mover". The package table gives it to P2, P3, P4 and P5, and P1's row reads `1-16, 18-20, 23-26, 31` — 17 skipped between two ranges. P1 *is* a mover: it takes two `*Test.php` plus four fixtures out of `tests/` into `tools/phpstan/tests/`. Since `surfaces()`'s `tests` surface is `['tests', 'governance']` and no surface names `tools/` or `scripts/`, `tools/phpstan/tests/` then sits outside the rename-enumeration sweep permanently — and it is a directory that will accumulate PHPStan-rule tests. The prose paragraph has the same gap: "P2-P4 each leave the `surfaces()` count (17) correct only for the roots added so far" names neither P1 nor P5.
- **trigger**: воспроизводится в нормальной работе — and silently: the count does not move, so no command refuses
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/03-tooling-tests.md:324` (the package table, P1 row) and `:352-357`
- **evidence**: `scripts/generate-rename-enumeration.php:56-57`:
  ```php
  'tests' => [
      'roots' => ['tests', 'governance'],
  ```
  Measured that the omission is silent rather than caught by the DoD's own oracle: `grep -c BannedStringPath finding-gate/enumeration-renames.tsv` → `0`, and `composer enumeration:renames:check` currently prints exactly the DoD's baseline `up to date (58 channel, 54 producer, 82 metric-key rows, 113 executed)` — so P1's two files contribute no row and their departure moves no column.
- **verification**: confirmed
- **verification_note**: The DoD's cure for this address is "A drop here is the `surfaces()` address (17) unedited" — which is precisely the oracle that cannot fire for P1.
- **fix_direction**: Add 17 to P1's address list and name P1 and P5 in the uncompensated-state paragraph. Since the count-based oracle is blind for a root whose files carry no enumerated name, the DoD needs a second check for this address — the surface roster itself, compared against `TestTree::roots()`.

---

### claude-r2-06

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: point
- **domain**: tests
- **title**: Address 8 is wrong twice: the refusal it quotes belongs to `classifyOwner()`, and the `classifyKind()` branch it prescribes would silence the undiscovered-test guard
- **mechanism**: Two errors in one row, and the second makes the prescribed cure harmful. (a) The refusal quoted is the wrong function's: `classifyKind()` emits `fail('Unclassified test artifact kind: ' . $path)` at `:1003`; the quoted `Unclassified test artifact: ` with no "kind" is `classifyOwner()`'s terminal `fail()` at `:976`, i.e. row 9 — and row 9's is what the plant actually hit (verified by execution). (b) A branch is not what `classifyKind()` needs. For the sixteen real moved tests it is already right: PHPUnit discovers them, so `$discoveredClasses !== []` and the kind is `phpunit-test-class` on the first line of the function; fixtures match `/Fixtures/`, other `.php` matches `support`, Python matches `non-php-test-process`. The one shape that reaches its terminal `fail()` is a `*Test.php` PHPUnit did **not** discover — which is exactly the plant's `OrphanProbeTest.php`. That refusal is a guard against an undiscovered test, not a classifier gap, so adding a "branch for the new paths" to `classifyKind()` would silence the one thing it is there to catch, inside the very directories this stage is adding. The plan's rewritten step 2 carries both errors forward: "`classifyKind()` and `classifyOwner()` need a branch for the new paths — 'Unclassified test artifact' *is* their refusal."
- **trigger**: воспроизводится в нормальной работе — a package looking for a refusal `classifyKind()` cannot produce, while claude-r2-01's two real edits go unnamed
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/measurement/stage-03/addresses-to-edit.md:22` (row 8) and `docs/internal/plans/test-structure/03-tooling-tests.md:300-303`
- **evidence**: `scripts/generate-modular-architecture-test-inventory.php:990-991,1003`:
  ```php
  if (str_contains($path, '/Support/') || (str_ends_with($path, '.php') && !str_ends_with($path, 'Test.php'))) {
      return 'support';
  }
  ...
  return fail('Unclassified test artifact kind: ' . $path);
  ```
  Confirmed by execution: the probe on an unbranched `tools/phpstan/tests/…Test.php` prints `Unclassified test artifact: …` and exits 1 — `classifyOwner()`, before `classifyKind()` is ever called for it.
- **verification**: confirmed
- **verification_note**: The plant's recorded refusal in the plan (`Unclassified test artifact: tools/phpstan/Rules/BannedStringPathPromotedPropertyRule.php`) is likewise `classifyOwner()`'s, consistent with the plant's real finding (do not widen to a tool root) but not with attributing it to `classifyKind()`. My reading of why row 8 exists at all — that the orchestrator, having branched `classifyOwner()`, met `classifyKind()`'s refusal on the orphan probe next — is inference from the plant's shape, not something the artifacts state; the two code facts above are not.
- **fix_direction**: Drop row 8's edit and replace it with the opposite instruction — `classifyKind()` must be left alone, because its terminal refusal is the undiscovered-test guard the new directories need most. Attribute the "Unclassified test artifact" refusal to row 9 in both the table and the step-2 rule, and spend the freed row on the two functions of claude-r2-01.

---

### claude-r2-07

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: contract
- **domain**: tests
- **title**: The surviving Registration bullet still says the stale `<directory>` is "not a silent hazard", which this same commit's `baseline.md` and address 18 now contradict
- **mechanism**: The commit fixed round-1 claude-12 in two places — `baseline.md:84-91` ("that silence does not survive a clone … Local green and CI red would be the same commit") and address 18 ("**L on a fresh clone, S locally**"). The plan paragraph that states the opposite was left in force: it still concludes that a stale `<directory>` is removed "as a lie about the suite map, not as a silent hazard". After the fix, the reason for removal is that the emptied directory is *absent* in a fresh checkout and PHPUnit exits 2 there — i.e. it is exactly a hazard, and the removal is mandatory rather than hygienic. A reader of the plan alone now gets the pre-fix reason.
- **trigger**: воспроизводится в нормальной работе — the plan is the document a package executes from
- **in_scope**: да (file in the diff; these lines are surviving text the rewrite left)
- **anchor**: `docs/internal/plans/test-structure/03-tooling-tests.md:148-154`
- **evidence**: plan, `:151-154`: "A directory that exists and holds no tests *is* silent, and that is the shape this stage can produce — which is why a stale `<directory>` is removed here as a lie about the suite map, not as a silent hazard." Against `baseline.md:84-91` and `addresses-to-edit.md:58` in the same commit.
- **verification**: confirmed
- **verification_note**: This is the class the project already records as `plan_patching_hazard` — a section rewritten while the paragraph it overrides stays.
- **fix_direction**: Rewrite that bullet to the corrected two-case form (exists-and-empty: silent locally, absent: loud — and an emptied directory *is* absent after a clone), so the plan, `baseline.md` and row 18 state one thing.

---

### claude-r2-08

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: contract
- **domain**: tests
- **title**: The "`tools/` is an unguarded root today" paragraph still enumerates exactly the five guards, omitting the sixth the round-1 fix added
- **mechanism**: Round-1 claude-05 was "the `tools/` guard list is short by one": the DoD named five addresses where the measured set is six, the sixth being `ScratchPathsCarryRealEntropyTest::ROOTS`. The fix put the sixth into `addresses-to-edit.md` (row 16, with the note "Address 16 is the one the first DoD dropped") and replaced the DoD's enumeration with a citation. The plan's other enumeration of the same set was not touched: it still lists `phpstan.neon` `paths`, the cs-fixer finder, the pre-commit filter, `.gitattributes` and `DEVELOPMENT_NAMESPACE_PREFIXES`, and closes "This stage puts tests there, so closing these is in scope rather than reported" — five, over a sentence whose scope is the same as the table's.
- **trigger**: воспроизводится в нормальной работе — а reader taking that paragraph as the scope statement closes five of six
- **in_scope**: да (file in the diff; these lines are surviving text the rewrite left)
- **anchor**: `docs/internal/plans/test-structure/03-tooling-tests.md:168-178`
- **evidence**: `governance/TestSuiteHygiene/ScratchPathsCarryRealEntropyTest.php:42`:
  ```php
  private const ROOTS = ['tests', 'governance', 'scripts'];
  ```
  `tools` absent; the paragraph does not mention this file.
- **verification**: confirmed
- **verification_note**: The paragraph is framed as current state rather than as a checklist, but row 16 is a current-state gap in the identical sense, so the framing does not excuse the omission. Independently confirmed that no governance control cross-checks these carriers: `grep -rln 'php-cs-fixer.dist|phpstan.neon|githooks/pre-commit|gitattributes|dockerignore' governance/` returns nothing, so the `S` verdicts on rows 11-14 and 16 are correct and there is no oracle behind any of them.
- **fix_direction**: Either add the sixth carrier to that paragraph or replace its list with the same citation the DoD now uses, so the document holds one enumeration of this set rather than two of different lengths.

---

### claude-r2-09

- **reviewer**: claude
- **severity**: LOW
- **kind**: contract
- **domain**: tests
- **title**: The table's completeness claim is false by the plan's own text — two addresses a package must edit are outside the 31, and the table has no "consciously excluded" section
- **mechanism**: The table asserts it is "every address a package must edit" and the DoD says "Do not restate it here; a second copy would drift". Two addresses live only outside it. (a) `composer.json:84-88` `test:cross-tool` names two `-s` directories that P6 empties; it appears in P6's package row and nowhere in the table. (b) `.dockerignore:26-27` excludes `tests/` and `governance/` and not `scripts/`/`tools/`, so a new test tree under either rides into the Docker build context. `registration-addresses.md:36,66` measured (b) and reasoned it away as "an already-open door, it does not open a new one" — but the table carries `.gitattributes` (row 14, `S (ships in the dist package)`), which is the same already-open door for the same `tools/phpstan/`. Without a section naming what was deliberately excluded and why, a reader cannot tell a reasoned omission from an oversight — which is the exact confusion the artifact was created to end.
- **trigger**: только на рукотворном входе for (b) — no command reads `.dockerignore`; (a) is loud
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/measurement/stage-03/addresses-to-edit.md:1-9` (the completeness claim) and `:33` (row 14)
- **evidence**: measured the `test:cross-tool` grading rather than assuming it — `python3 -m unittest discover -s <missing dir>` exits **1** (`ImportError: Start directory is not importable`), `-s <existing but empty dir>` exits **5** (`NO TESTS RAN`) on the Python this machine runs (3.14.6). So (a) is loud either way and cannot be skipped silently; that is why this is LOW and not higher.
- **verification**: confirmed
- **verification_note**: The brief's premise that `.dockerignore`/`init-environment.sh` were never audited is refuted: `registration-addresses.md:36,66` measures both, and rules `scripts/init-environment.sh` out on the ground that it carries no test-discovery literal. I checked that file independently — its only root list is `CRITICAL_DIRS` at `:297-306` (`tests`, `governance`, `docs`, `website/docs`, four `src/` subjects), and a missing entry only suppresses a warning, so dropping it from the table is right. CI workflow YAML was also read and is genuinely empty of addresses: `.github/workflows/{qmx,docs,release}.yml` name no suite, no test root and no `SUITES` tuple — they call `composer` scripts. So of the brief's two named blind spots, only `.dockerignore` is a live omission.
- **fix_direction**: Give the table a short closing section for addresses considered and excluded, with the ground for each (`.dockerignore` and `init-environment.sh` are the two measured ones), and either number `test:cross-tool` or state that P6's addresses are carried in the package table by design. Also reconcile `.gitattributes` and `.dockerignore`: they are the same mechanism and should be graded the same way.

---

### claude-r2-10

- **reviewer**: claude
- **severity**: LOW
- **kind**: point
- **domain**: tests
- **title**: The DoD's evidence that "G2 and G3 bite on the new roots" is reproducible only for `tools/phpstan`
- **mechanism**: The DoD replaced "G2 and G3 cover every new root" with "shown to bite … The evidence is the step-1b refusal above, re-run once per new root" — a good tightening, but the named refusal is available for one root only. Step 1b's G3 refusal (`declares Qualimetrix\PhpStan\Tests, and its path says Qualimetrix\PhpStan\tests`) exists because `tools/phpstan` is *already* a PSR-4 root, so a probe under `tools/phpstan/tests/` is inside `TestTree::testFiles()` before the test directory is declared. For the nine other new roots the parent is not a dev root at all, so before declaration the probe is in no root, `TestTree::testFiles()` never reaches it, and neither guard produces any refusal; after declaration both are green. "Re-run once per new root" therefore cannot be executed as written for nine of ten roots, and the DoD's own standard ("Green proves nothing here") is what it fails.
- **trigger**: воспроизводится в нормальной работе — at acceptance, for every root but the first
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/03-tooling-tests.md:241-245`
- **evidence**: `governance/TestSuiteHygiene/TestTree.php:167-171`:
  ```php
  foreach (self::roots() as $root) {
      foreach (self::testFilesIn($root) as $file) {
          $files[$file] = true;
      }
  }
  ```
  `roots()` is `array_values(self::autoloadDevRoots())`, i.e. the `composer.json` map and nothing else.
- **verification**: confirmed
- **verification_note**: A per-root probe that *does* bite exists — declare the root, then plant a class the `<directory>` set does not reach (G2's "no class from any file in X is listed"), or one whose namespace disagrees with its path (G3). The defect is the prescribed procedure, not the claim.
- **fix_direction**: State the per-root probe in a form that works for a root whose parent is not already a dev root, and keep the step-1b refusal as what it is — the `tools/phpstan` instance, and the demonstration that the nesting rule is real.

---

### claude-r2-11

- **reviewer**: claude
- **severity**: LOW
- **kind**: pattern
- **domain**: tests
- **title**: Anchor and count drift in the new table, in the one artifact whose promise is "re-checked against the tree with the line numbers below"
- **mechanism**: Four rows point at a line range that is off, and one points at a line that does not need editing while the lines that do are unnamed. Individually harmless; collectively they weaken the one claim that makes the table usable as a checklist rather than prose.
- **trigger**: воспроизводится в нормальной работе — a package walking the table by line number
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/measurement/stage-03/addresses-to-edit.md:30,31,34,63,77`
- **evidence**: measured, row by row:
  - row 11 `phpstan.neon:11-16` — `paths:` is `:11` and its block ends `:15`; `:16` is `excludePaths:`.
  - row 12 `.php-cs-fixer.dist.php:6-12` — `->in([` is `:6` and `])` is `:11`; `:12` is `->name('*.php')`.
  - row 15 `…production-inventory.php:993-996` — `const DEVELOPMENT_NAMESPACE_PREFIXES = [` is `:994` and the block closes `:997`.
  - row 23 `systemSupportContents()` literal `tests/TestSupport` at inventory `:1505` — `:1505` is the taxonomy loop `foreach (['tests/System', 'tests/TestSupport'] as $taxonomy)`, which needs no edit (the emptied directory simply stops being globbed). The edit the row's `L` verdict actually describes is the two `$rows` entries at `:1497-1498`, which drive `fail('missing System/TestSupport artifact: ' . $row[1])` at `:1502`.
  - row 29 "three of eight test paths" — `Suite::FILES` holds **eleven** entries (`Suite.php:45-55`); eight of them are under `tests/` and three under `governance/`. The anchor `:50-52` is exact; "eight" is only true under the reading "paths under `tests/`", which the row does not state.
- **verification**: confirmed
- **verification_note**: Rows 2, 3, 6, 7, 9, 13, 16, 17, 19, 20, 21, 22, 24, 25, 26, 28, 30 were re-derived and are exact. Row 27's counts were not re-derived by occurrence (a line count gives 16/37/49 against the claimed 16/38/65, which is expected for multiple literals per line) — see coverage.
- **fix_direction**: Re-derive the five ranges. For row 23 in particular, anchor the row on the `$rows` entries that carry the moving paths, since that is what produces the refusal the row promises.

---

### claude-r2-12

- **reviewer**: claude
- **severity**: LOW
- **kind**: point
- **domain**: tests
- **title**: P6's row and the out-of-scope list disagree about `fake_phpunit.py`
- **mechanism**: The package table says P6 "empties `tests/Analysis/Evidence/Measurement/Tests/` and `tests/System/TestRunnerConfiguration/Tests/` **except its fixture**"; nineteen lines later the out-of-scope list names `tests/System/TestRunnerConfiguration/Tests/Fixtures/fake_phpunit.py` as a file "which moves with P6 as its test's fixture". If the fixture stays, the directory is not emptied and its registrations must survive — the `tests/Unit/RuleVocabulary/` situation this commit just corrected. If it moves, the directory is emptied and its `classifyOwner()` prefix (`:661`, `tests/System/TestRunnerConfiguration/`) goes dead and must be removed, which no address covers.
- **trigger**: воспроизводится в нормальной работе — P6 has to pick one reading
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/03-tooling-tests.md:329` and `:362-365`
- **evidence**: on disk, `tests/System/TestRunnerConfiguration/Tests/` holds `test_phpunit_aggregate.py`, `Fixtures/` and `__pycache__`; `tests/Analysis/Evidence/Measurement/Tests/` holds `test_cross_tool_comparison.py` and `__pycache__` — so the second directory is emptied on either reading, and only the first is in question.
- **verification**: confirmed
- **verification_note**: `classifyOwner()` at `:661` returns `['System/TestRunnerConfiguration', 'P8']` for that prefix; if the directory empties, that prefix joins rows 21-22's dead-prefix family, which P6 owns no address for.
- **fix_direction**: Settle the fixture in one place and make P6's addresses explicit — including the dead `classifyOwner()` prefix if the directory empties.

---

### claude-r2-13

- **reviewer**: claude
- **severity**: LOW
- **kind**: judgement
- **domain**: architecture
- **title**: D3-1's restated gain for the rejected hybrid counts tool directories, not tools
- **mechanism**: The rewrite fixed round-1's numerator ("three tools of five" → "four are PSR-4-clean") but kept a denominator of five, which counts only the tool *directories* that exist today. D3-3 gives the six flat `scripts/*.php` tools test directories in this same stage, and a flat script has no tool-directory root to autoload from at all. So the hybrid would autoload the SUT for four of eleven moving tools, not four of five, and "delete their `require_once` boilerplate" applies to four elevenths of the boilerplate. The decision is unaffected — the correct number strengthens "one rule that holds everywhere" — but the record now carries a gain overstated by more than twice, in the section that exists to state the ground of a rejection.
- **trigger**: недостижим — no behaviour depends on it; it is the decision record's own arithmetic
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/03-tooling-tests.md:49-69`
- **evidence**: the Placement block at `:118-125` lists ten destination directories — five existing tool directories plus "five new subject directories" for rows 11-16 — against D3-1's "the five tool directories that have a test".
- **verification**: unverifiable
- **verification_note**: A judgement about how the number should be framed, not a code fact; the two counts (five existing directories, eleven tools with a moving test) are both verified against the placement block and `population.md:51-66`.
- **fix_direction**: State the denominator the decision actually ranges over — every tool that gets a test directory in this stage — and let the gain be the fraction of that.

---

## Coverage

**Item 1 — `addresses-to-edit.md` as an enumeration: closed.**

- *Line numbers and content, all 31 rows re-derived against the tree.* Exact: 2, 3, 6, 7, 9, 13, 16, 17, 19, 20, 21, 22, 24 (rows; the ceiling lives at `:20`, unnamed), 25, 26, 28, 30. Drifted: 11, 12, 15, 23, 29 — claude-r2-11. Row 10's `:186-196` is exact for the copy block; its `git add` sibling at `:207-216` was checked and is not a second edit. Row 31's `composer architecture:generate` exists (`composer.json:95`). Row 5's `classmap` entry is at `composer.json:62-64` as claimed. Row 18's `<directory>` is present in `phpunit.xml.dist`'s Unit suite as claimed.
- *Loud/silent verdicts.* Every `L` was traced to the refusing code or executed. Confirmed loud: 2, 3 (suite-partition refusal), 4, 7, 9 (executed — `Unclassified test artifact:`, exit 1), 10 (exit 2, `baseline.md`'s four probes), 19-23 (the inventory's own `fail()`s), 24's rows, 25, 26, 27-29, 31. Confirmed silent: 6, 11, 12, 13, 14, 16, 17, 30 — and independently, that no governance control reads any of those carriers, so none of them has an oracle anywhere. Wrong: 8 (claude-r2-06), 24's ceiling half (claude-r2-04). Row 18's split verdict ("L on a fresh clone, S locally") is right and is the sharpest row in the table.
- *Completeness.* Derived three ways rather than read, plus a fourth pass over the generator's own tracked constants, which is what surfaced claude-r2-14. (i) Against CLAUDE.md's own address table, row by row: everything it names is carried except `dispositionFor()`, `targetPath()` (claude-r2-01), `.dockerignore` and `init-environment.sh` (claude-r2-09). `currentSuite()` was checked and is *correctly* absent — it delegates to `testSuitePrefixTable()` (`:1091-1094`), so row 7 covers it; the aggregate's "docstring partition proof" and "`--jobs` bound" are likewise correctly absent, verified: the docstring names no suite and `--jobs` defaults to `len(SUITES)` (`:101`). (ii) Against `registration-addresses.md`, which is where `dispositionFor()`/`targetPath()` surfaced — the derived table lost two rows its stated source carries. (iii) By sweeping the tree for each of the 18 moving files' basenames outside `tests/`: that returned `P7_MEASUREMENT_PATHS` (claude-r2-03) and four prose carriers — `governance/ControlRigLedger/TrackedLedgerNonEmptinessTest.php:12,17`, `governance/TestSuiteHygiene/SeededFixtureIsolationTest.php:20`, `scripts/directive-audit/Gate.php:44`, and `AGENTS.md` — all four matching the plan's "five dead prose mentions (`@see`, a 'split off from' comment)", so they are accounted for, not missing. One further carrier is neither prose nor covered and I could not grade it: `directive-audit/enumeration-unguarded-cases.tsv:15,17` names `tests/Unit/RuleVocabulary/DirectiveAuditReportReadingTest.php` **with line numbers** inside a free-text evidence column of a tracked declaration file. Whether anything validates that literal I did not establish.
- *Package assignment.* The union of the seven package rows covers all 31 with no orphan, so the plan's "none of the 31 addresses" is true as stated. Mis-assigned: row 10 (claude-r2-02), row 17 for P1 (claude-r2-05). Beyond the 31: P4's row is short the tracked digest (claude-r2-14), P6's carries no address numbers at all (claude-r2-03, claude-r2-12).
- *One address class I checked and it is clean: hardcoded suite-**name** lists in PHP.* `SUITES` lives in the two Python files the table already carries (rows 2, 3). `TestFilesAreExecutedTest` does **not** carry a third copy — it parses the suite names out of the configuration (`:638-648`, `foreach ($document->testsuites->testsuite as $suite)`, refusing a `<testsuite>` without a name and a configuration with none), so `Tooling` enrols itself. Its only literal suite names are the floor `assertContains('Unit', $suites)` / `assertContains('Governance', $suites)` at `:396-397`, which a sixth suite neither breaks nor is checked by. `assertSuiteClassifierAgreesWithPhpunit()` likewise reads the XML. So there is no 32nd address of this shape.
- *And one consumer question I resolved rather than assumed:* who acts on the inventory's `target_path` / disposition columns. Answer, and it is what set claude-r2-01's severity: nobody programmatically, outside `validateInventory()`'s collision check and `validateP4Topology()`'s owner-scoped prefix assertions. Recorded in that finding's note.

**Item 2 — the four rewritten sections: closed for internal coherence, partly closed for DoD executability.**

- Checked each rewritten section against the surviving document. Two surviving paragraphs now contradict the fix: `:148-154` (claude-r2-07) and `:168-178` (claude-r2-08). One contradiction is internal to a rewritten section: claude-r2-12.
- DoD, item by item, for "is there a command that can refuse". Has one, verified by running it: `composer enumeration:renames:check` prints exactly the DoD's `58 channel, 54 producer, 82 metric-key rows, 113 executed`, exit 0 — the baseline is real, not remembered. Arithmetically consistent: the six per-suite integers sum to 9196, the executed total, and `9198 − 9196 = 2` matches `baseline.md`'s two named live-freshness cases; the Tooling 179 equals the two predicted drops (7148−6987 = 161, 437−419 = 18). Has one by construction: the PHPUnit suites, `architecture:check`, `php scripts/directive-audit-controls.php` (and the round-1 fix here is right — `composer directives:controls` is `['@directives:controls:coverage', …]` and coverage is red, so the composer form cannot reach the full control). Has none: the new central item, "every address in `addresses-to-edit.md` is edited or explicitly retired" — for the eight silent rows nothing in the tree refuses, and I confirmed no governance control reads their carriers. Over-specified: the G2/G3 per-root probe (claude-r2-10).
- Not run, and therefore not evidence from me: `composer check`, `composer architecture:check`, `python3 scripts/phpunit-aggregate.py`, `php scripts/directive-audit-controls.php`, the two-step plant, and any package rehearsal. The four-way exit-2 measurement in `baseline.md` I took as given rather than re-probing it; claude-r2-02 rests on it.

**Item 3 — the three measurement files: partly closed.**

- Read the full diff of all three and the surrounding sections. The `registration-addresses.md` C5 correction is sound and now separates the absent case from the empty one; it no longer contradicts `baseline.md`. The `baseline.md` addition and the `population.md` narrowing are each consistent with the plan's restatement of them (`:29-34`).
- Searching for a second mutual contradiction, I found one — but it runs from `registration-addresses.md` into the *new* artifact rather than between the three: claude-r2-01 (`:22`, `:62` name two addresses the derived table dropped). Between the three files themselves I found none.
- Not covered: `pinned-references.md` (41 KB) and `witness-b-population.md` (33 KB) were not read; both are unchanged in this commit. (Grepping `pinned-references.md` for one string while chasing claude-r2-01 showed it does discuss `test-ownership.tsv` as regenerated-and-loud — consistent with what I measured, and not a substitute for having read it.) Consequently row 27's three counts (16 / 38 / 65 dot-separated literals, 119 total) are **not** independently re-derived — a per-line count gives 16 / 37 / 49, which is consistent with several literals per line but does not confirm the totals. The `DirectiveAuditControlsSuiteKeyTest` → 0 occurrences claim in `Probes.php`, which is what makes the file-scoped substitution safe, I did verify.
- Also verified, since the table's rule depends on it: `Qualimetrix\Tests\Unit\RuleVocabulary\` does map to three destinations, so the ban on a repo-wide find/replace is correct.

**Out of scope by the brief and deliberately not touched:** round-1 findings, unchanged plan sections other than where a fix left them contradicting (claude-r2-07, claude-r2-08), stage 04's relocation map, and `pinned-references.md` / `prediction.md` / `witness-b-population.md`.

## Refuted

- `claude-r2-15 | currentSuite() is a missing address | refuted by code: it delegates to testSuitePrefixTable() (:1091-1094), so row 7 covers it — CLAUDE.md naming it separately is CLAUDE.md's redundancy, not the table's gap`
- `claude-r2-16 | the aggregate's docstring partition proof and --jobs bound are missing addresses | refuted: the docstring enumerates no suite and --jobs defaults to len(SUITES) at :101 — the plan's own :156-166 already establishes this and is correct`
- `claude-r2-17 | createIsolatedProject()'s git add list is a second address | refuted: the generator's scan is git ls-files --cached --others --exclude-standard (:349), and --others lists untracked files, so a copied-but-unadded root is still enumerated`
- `claude-r2-18 | CLAUDE.md names the wrong file for createIsolatedProject(), so the table does too | refuted for the table: the method exists only in tests/Analysis/Policy/Architecture/Integration/ModularArchitectureGeneratorRefusalTest.php (governance/ModularOwnership/ModularArchitectureGovernanceIntegrationTest.php carries no root list at all), which is what the table cites and what the plan's P7 correction 4 says`
- `claude-r2-19 | CI workflow YAML is an unaudited address family | refuted: .github/workflows/{qmx,docs,release}.yml name no suite, no test root and no SUITES tuple — they invoke composer scripts, so there is no address there to miss`
- `claude-r2-20 | scripts/init-environment.sh is a missing address | refuted: its only root list is CRITICAL_DIRS (:297-306) and a missing entry suppresses a warning rather than a check, so registration-addresses.md:66 is right to rule it out`
