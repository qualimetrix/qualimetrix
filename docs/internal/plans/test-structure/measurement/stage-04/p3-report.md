# Stage 04, package P3 — the Core- and Analysis-owned files move

Executed against `main` @ `ef090d27`, the commit P3 starts from. Nothing is
committed; the tree carries 11 renames and 8 modifications of mine, plus one
modification that is not.

**`tests/Unit`, `tests/Integration` and `tests/Functional` no longer exist.**
That is the headline of the stage, and it holds on disk, in the index and in
`phpunit.xml.dist`.

No PHPUnit test case was added or removed. No test file outside the 11 map rows
moved. `move-oracle.py --package=P3` exits **0**.

**One modification in the tree is not mine.** `docs/internal/plans/test-structure/04-packages.md`
was written at `23:56:17` by a process that is not this package — its diff is
entirely in the **P5** section ("a new group under `governance/`" becomes
"`governance/TestSuiteHygiene/` — an existing group"). `git status --porcelain`
was empty when this package started (checked, first command of the session) and
my first write landed at `00:03:00`. I neither made nor reverted it: the brief
forbids touching any plan document, and reverting a concurrent writer's work is
worse than reporting it. **It must not be committed as part of P3.**

## What changed

**The 11 moves.** Every row of `relocation-map.csv` whose `owner` is
`Core.Neutral` or starts with `Analysis.` — re-derived by command against the map
on this tree, not read from the brief — moved with `git mv` to its recorded
`target`, and each file's `namespace` declaration was rewritten to the PSR-4
implication of its new path.

```
python3 -c "
import csv
rows=list(csv.DictReader(open('docs/internal/plans/test-structure/measurement/stage-04/relocation-map.csv')))
mine=[r for r in rows if r['owner']=='Core.Neutral' or r['owner'].startswith('Analysis.')]
print(len(mine), sum(1 for r in mine if r['target'].endswith('Test.php')))
"
# -> 11 10
```

Four numbers, each derived here rather than recalled:

| Number                                | Derivation                                                                                                                                                | Result                                                                        |
| ------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------- |
| Rows, and how many are test classes   | `csv.DictReader`, owner filter, then `target.endswith('Test.php')`                                                                                        | **11**, of which **10** are test classes                                      |
| Generator constants naming them       | every `const X = [...]` block matched against the 11 current paths **and** against the 12 declared FQCNs in both spellings (single- and double-backslash) | `LEGACY_UNMOVED` ×10 and **nothing else** — no FQCN anywhere, either spelling |
| `<directory>` entries to remove / add | each declared entry's tracked-file count under the post-move path set, from `git ls-files`                                                                | remove **3**, add **1**                                                       |
| Allowance size                        | `LEGACY_UNMOVED` row count before / after                                                                                                                 | **10 → 0**                                                                    |

The support row, `tests/Analysis/Finding/RuleConfiguration/Support/FromArrayKeyReader.php`,
is the 11th and was never in `LEGACY_UNMOVED` — confirmed by the constant sweep
above, which returns 10 and not 11. **The file declares two classes**
(`FromArrayKeyReader` and `FromArrayKeyReading`), so the 11 files carry 12 FQCNs;
a per-file FQCN derivation that reads only the class matching the basename would
have missed one of them.

The per-suite origin is Unit 8, Integration 2 and (the support file) none; the
targets keep those levels exactly, which is why the package is suite-neutral.

**Directories emptied and removed from disk** — 11, of which 3 were declared in
`phpunit.xml.dist`: `tests/Unit{,/Core{,/Namespace_,/Observation,/Util}}`,
`tests/Integration{,/Architecture,/DependencyInjection}`, and
`tests/Analysis/Finding/RuleConfiguration{,/Support,/Unit}`.
`tests/Functional` was already absent — P2 emptied it.

**`scripts/generate-modular-architecture-test-inventory.php`** — the 10
`LEGACY_UNMOVED` rows deleted and the constant left empty; the vacuous guard and
its orphaned helper deleted (**V1**); `testSuitePrefixTable()` given the same
−3 / +1 as `phpunit.xml.dist`.

**`phpunit.xml.dist`** — the same −3 / +1.

**Five reference literals in four non-moving files**, closed; see "The reference
channels". Two of the four are the consumers the brief named; **two are not**.

**The generated artifacts** were regenerated: `test-ownership.tsv` and
`test-phpunit-discovery.txt`. `test-phpunit-suites.txt` is byte-identical —
the suite-neutrality claim showing up in an artifact rather than in prose.

## Decisions

Each names the alternative rejected and the command that checks it.

**D1. The two `use` imports and the three docblock references are repointed, not
removed.** The class each names is real and is one of the 11; a `{@see}` whose
target moved is a stale address, not a dead one. Checked by: `composer check:code`
exit 0 (a broken `use` is a fatal in both consumers, one of which runs in the
`Governance` suite and one of which is a production script), and by the post-move
re-sweep of the 12 pre-move FQCNs in both spellings, which returns 0.

**D2. `tests/Core/Unit` is declared, not its three leaves.** The six `Core.Neutral`
targets land at `tests/Core/Unit/VersionTest.php`, `tests/Core/Unit/Observation/`
and `tests/Core/Unit/Util/`. One ancestor covers all six under **exactly one**
declared entry — checked by counting covering entries per target, which is 1 for
all six, `tests/Core/Path/Unit` and `tests/Core/Symbol/Unit` being disjoint
prefixes. Declaring the leaves was rejected for P2's reason: more addresses, each
able to go stale, for no coverage gain.

**D3. The prefix-table row is inserted after `tests/Core/Symbol/Unit/`, and order
is not load-bearing here.** `currentSuite()` returns the first matching prefix, so
a row ordered before a longer sibling could shadow it. `tests/Core/Unit/` is not a
prefix of `tests/Core/Path/Unit/` or `tests/Core/Symbol/Unit/` and neither is a
prefix of it, so no order is wrong. Checked by: `composer architecture:check` exit
0, which runs `assertSuiteClassifierAgreesWithPhpunit()` in both directions, plus
the Unit suite count of 6705.

**D4. `tests/Analysis/Finding/Unit/LocationTest.php:69-70` is not edited.** It
constructs `RelativePath::fromString('tests/Unit/CoreTest.php')` as input to a
`Location` formatting assertion. No such file has ever existed; the string is
synthetic test data, not an address. Checked by: the file is a `#[CoversClass]`
test of `Location`, and the assertion on line 70 is the same literal echoed back
with `:10` appended — the test cannot distinguish one path spelling from another.

**D5. The generated `test-ownership.tsv` row for the moved support file is left as
the ladder answers it.** After the move,
`tests/Analysis/Finding/Support/FromArrayKeyReader.php` reports `subject_owner`
`Analysis/Finding`, `target_path` equal to itself and disposition
`Retain at the materialized subject-owned path.` — all three right — while
`closure_package` still says `P8`, meaning a later package owes this artifact a
move it does not. The column comes from the surviving ladder's
`tests/Analysis/Finding/` arm, which is about fixtures this package does not own,
and P2's analogue landed in a worse version of the same shape (its
`tests/Reporting/` arm has no retain case, so its disposition reads
"Move atomically…" beside a target equal to its own path). The plan assigns the
cure to P4 — "it changes the answer for fixtures those packages do not own".
Checked by:
`grep -P "^tests/Analysis/Finding/Support/FromArrayKeyReader\.php\t" docs/internal/generated/modular-architecture/test-ownership.tsv`,
and by `move-oracle.py --package=P3` exit 0 — the judge reads owner and target,
and agrees.

## Assumptions

- **A1.** The map is the authority for which 11 files move and where they land.
  The partition was re-derived by command against `relocation-map.csv` on this
  tree; `current` and `target` were used verbatim.
- **A2.** PSR-4 for tests is `Qualimetrix\Tests\ => tests/`, one namespace segment
  per path segment. Verified empty-handed first: the mover asserted, per file,
  that the **pre-move** namespace already equalled the rule's answer for the
  pre-move path, and aborts otherwise; all 11 passed. None of the 11 appears in
  `governance/TestSuiteHygiene/namespace-path-allow-list.php` (checked in both
  directions: neither the 11 current paths nor the 11 targets occur in that file),
  so the rewrite carries no pre-existing namespace defect and introduces none.
- **A3.** Depth-sensitive path arithmetic survives, because there is none:
  `git grep -n -E '__DIR__|dirname\(|getcwd|realpath|Fixtures|\.\./' -- <the 11>`
  returns **0 lines**. Five of the 11 change depth (two 4→6, one 5→6, one 5→7,
  the support file 6→5) and none of them computes a path. `FromArrayKeyReader`
  does read source files, and does so through `ReflectionClass` — it imports
  `ReflectionClass` and no path primitive — so its own location is irrelevant to
  what it reads.
- **A4.** `RETIRED_PATH_ASSERTIONS` neither names one of the 11 current paths nor
  one of the 11 targets (checked both ways). Its contract is checked in reverse —
  a path listed there that exists again is a defect — so a target colliding with a
  retired path would have reddened `architecture:check`.

## Deviations from the plan text

**V1 — the brief says leave `assertLegacyUnmovedShrinksOnly()` in place; it is
deleted, together with its only helper. PHPStan is the evidence.**

With `LEGACY_UNMOVED = []` the guard is three arms over an empty set. PHPStan
level 8 with strict rules refuses that shape, and refuses it in the file the brief
requires me to leave that way:

```
vendor/bin/phpstan analyse --memory-limit=512M --no-progress \
  scripts/generate-modular-architecture-test-inventory.php
```

```
1387  Function assertLegacyUnmovedShrinksOnly() returns void but does not have any side effects.   void.pure
1390  Empty array passed to foreach.                                                               foreach.emptyArray
1413  Strict comparison using !== between array{} and array{} will always evaluate to false.        notIdentical.alwaysFalse
```

Three identifiers, one fact: the function is vacuous. The three
`array_key_exists($path, LEGACY_UNMOVED)` call sites in `classifyOwner()`,
`dispositionFor()` and `targetPath()` are **not** flagged — measured, not
assumed — so the collision is confined to the guard.

So DoD 5 (`composer check:code` exit 0) and the brief's "leave the constant in
place, empty" plus its guard cannot both hold. What I did, and why:

- **Kept** `const LEGACY_UNMOVED = [];`. The brief's stated purpose is "an empty
  allowance for one commit is the state P4 asserts before doing so", and what P4
  must observe is the constant. That survives intact.
- **Deleted** `assertLegacyUnmovedShrinksOnly()` and its call at the former line
  369. The brief names it only as something P4 deletes *alongside* the constant,
  and P4's own Definition of Done reads "`LEGACY_UNMOVED` and its guard are gone."
  A guard whose every arm iterates an empty set is the exact shape AGENTS.md warns
  about ("a control asserting a property of every member of an empty set passes"),
  so deleting it loses no protection.
- **Deleted** `parsesToManifestOwner()` with it. `grep -n parsesToManifestOwner`
  showed the guard was its only caller; the package that orphans a function
  deletes it, rather than handing P4 an orphan that its own sweep is not about.
- **Rewrote the constant's docblock**, which said the constant and its guard "are
  deleted rather than emptied" — a sentence that, left above `= []`, would be a
  document arguing with its own code. It now states that the last row left with
  the last move, that the guard went with the rows and why, that the constant
  outlives them by one package so the closure is observable, and which three
  functions still read it.

Rejected, each for a stated reason:

- *An accessor or parameter indirection so PHPStan stops folding the constant to
  `array{}`* — its only effect is to hide a true fact from the checker. That is a
  hole cut in a check, and this repository's notes are mostly about that defect
  class.
- *`@phpstan-ignore` or a `phpstan.neon` `ignoreErrors` entry* — three
  suppressions for a function everyone agrees is dead within one commit, in a file
  outside this package's set.
- *Keeping one allowance row so the array is non-empty* — the guard's own arms 2
  and 3 refuse a row that describes nothing, so the row would have to be a lie.
- *Stopping and returning* — the collision has a cure that preserves the brief's
  stated purpose exactly, and the package is otherwise complete.

**The orchestrator can revert this with one `git checkout` of the function plus
its call, at the cost of `composer check:code`.** What P4 inherits is
correspondingly smaller: the empty constant to delete, and the sentence "or record
the move in `LEGACY_UNMOVED`" in `failUnownedTestClass()`'s refusal text, which is
correct while the constant exists and retires with it.

**V2 — the brief's consumer set is two files; it is four.** The brief names
`governance/RuleOptionKeys/DeclaredOptionKeysCoverReadKeysTest.php` and
`scripts/enumerate-rule-option-keys.php`, both importing `FromArrayKeyReader`. The
declared-FQCN sweep returns **two more**, both `{@see}` docblock references to
moved *test* classes:

| File                                                             | Line | Shape                                                                                                      |
| ---------------------------------------------------------------- | ---- | ---------------------------------------------------------------------------------------------------------- |
| `governance/RuleOptionKeys/CliAliasKeyWalkAgreementTest.php`     | 23   | `{@see \Qualimetrix\Tests\…\RuleConfiguration\Unit\UnknownRuleOptionKeyRefusalTest}`                       |
| `governance/ConfigurationVocabulary/YamlKeyReachabilityTest.php` | 428  | `{@see \Qualimetrix\Tests\Integration\Architecture\MaxExpandedLayersFromYamlTest}` (inside a `//` comment) |

Neither breaks a build, which is why neither was enumerated: a stale `{@see}` is
invisible to PHP, to PHPStan and to PHPUnit. This is the same channel P1 and P2
each reported; it is now three packages in a row, and the brief's enumeration
still did not carry it.

**V3 — a fifth literal the FQCN and path sweeps both miss.**
`scripts/enumerate-rule-option-keys.php:25` names the *directory*
`` `tests/Analysis/Finding/RuleConfiguration/Support/` `` in prose. The path sweep
searches full file paths, so it does not match a directory prefix; the FQCN sweep
searches namespaces, so it does not match a path. It was found only by sweeping
each **emptied directory prefix** separately. That sweep is the mirror of P2's
filled-prefix rule and belongs beside it.

## The reference channels, and how they were derived

Three sweeps over the whole tracked tree by content (`git grep`, no file-type
restriction), with `docs/internal/plans/**`, `docs/adr/**` and
`docs/internal/generated/**` excluded as history and regenerated output.
`git grep` throughout, never plain `grep`: an untracked `.claude/worktrees/`
checkout of this repository sits inside the working tree and doubles every hit.

### 1. By name, in both spellings — raw counts

| Sub-sweep                          | Pre-move lines | Of which outside the 11 and outside `LEGACY_UNMOVED` | Post-move lines |
| ---------------------------------- | -------------: | ---------------------------------------------------: | --------------: |
| 1a declared FQCN, single backslash | 4              | 4                                                    | **0**           |

The third column counts **lines**, not files: 1c's 14 sit in the same four
carriers 1a's 4 do.
| 1b PHP-escaped FQCN, every `\` doubled        |          **0** |                                                     0 |           **0** |
| 1c bare basename, word-anchored (`-F -w`)     |             39 |                                                    14 |              29 |
| 1d current path literal                       |             10 |                                                     0 |           **0** |

1b returning zero pre-move is a measured negative, not an absence of looking: it
is the sweep that a single-backslash grep cannot stand in for, and it is the one
whose omission P1 recorded.

The 39 pre-move 1c lines were 14 in the four consumers, 10 in the generator's
`LEGACY_UNMOVED` rows and 15 inside the 11 files themselves. The 29 that survive
are those 15, now at their new paths, plus the same 14 consumer lines, now
spelling the new name; the generator no longer appears at all, because
`LEGACY_UNMOVED` is empty. Checked by carrier with
`... | cut -d: -f1 | sort | uniq -c` on both sides of the move.

The five members, all closed:

| File                                                                | Line | Shape                           | Found by          |
| ------------------------------------------------------------------- | ---- | ------------------------------- | ----------------- |
| `governance/RuleOptionKeys/DeclaredOptionKeysCoverReadKeysTest.php` | 18   | `use` of a moved support class  | 1a, 1c            |
| `scripts/enumerate-rule-option-keys.php`                            | 38   | `use` of a moved support class  | 1a, 1c            |
| `scripts/enumerate-rule-option-keys.php`                            | 25   | directory literal in a docblock | prefix sweep only |
| `governance/RuleOptionKeys/CliAliasKeyWalkAgreementTest.php`        | 23   | `{@see}` FQCN                   | 1a, 1c            |
| `governance/ConfigurationVocabulary/YamlKeyReachabilityTest.php`    | 428  | `{@see}` FQCN in a `//` comment | 1a, 1c            |

**No intra-set reference exists.** P2's re-run with its own carriers included
found six; here the same re-run finds none — no moved file imports another moved
file, and no moved file names its own path. The one file that could have
(`FromArrayKeyReader.php`, whose two classes reference each other) does so by
short name inside one namespace.

Nothing was found in `.gitattributes`, `.githooks/pre-commit`, `.dockerignore`,
`scripts/init-environment.sh`, `phpstan.neon`, `.php-cs-fixer.dist.php`,
`composer.json` or `scripts/phpunit-aggregate.py`: no test *root* moves here, and
the emptied-prefix sweep below covers them by content anyway. The mover's
`createIsolatedProject()` copy list copies the roots `tests`, `governance`,
`tools` and `src` wholesale, so removing three directories inside `tests/` does
not reach it.

### 2. By the prefixes this package fills

The rule P2 derived, run before the first `git mv`. Five directories are created —
`tests/Core/Unit`, `tests/Core/Unit/Observation`, `tests/Core/Unit/Util`,
`tests/Analysis/Finding/Unit/RuleConfiguration`,
`tests/Analysis/Configuration/Integration/Pipeline` — while
`tests/Analysis/Finding/Support`, `tests/Analysis/Policy/Architecture/Integration`
and `tests/Analysis/Evidence/Measurement/Unit/Contract` already existed (checked
with `[ -d ]` per directory). Sweeping each new prefix and each of the 11 exact
target paths, repository-wide by content:

**0 members.** In particular `scripts/modular-architecture/tests/ModularArchitectureGeneratorRefusalTest.php`
— the control P2 had to repoint — plants its probe at
`tests/Reporting/GraphProjection/Functional`, which this package neither fills nor
declares (checked: the file's only `tests/` literals are that path and
`tests/Analysis/Policy/Baseline/Functional`, and neither is mine). P0's positive
probe `tests/Core/Unit/VersionTest.php` is recorded only in
`measurement/stage-04/generator-probe.md`, a plan document; nothing executable
asserts that the directory is absent: `git grep -l 'tests/Core/Unit/VersionTest.php'`
returns six files, every one of them under `docs/internal/plans/`.

**The mirror sweep, by the prefixes this package empties**, is the one V3 came
from. `tests/Unit`, `tests/Integration`, `tests/Functional` and
`tests/Analysis/Finding/RuleConfiguration` as literals, repository-wide, excluding
plans, ADRs, generated output and the generator: **6 lines** — three
`<directory>` entries (removed), the two synthetic `LocationTest` strings (D4)
and the docblock of V3.

### 3. For names from an earlier epoch

P2's dangling-FQCN detector, rebuilt here: every `Qualimetrix\Tests\…` name
appearing anywhere in the tracked tree, in **either** spelling, resolved back to a
path and reported when neither a file, a directory, nor a class declared in a
sibling file of that name exists. Source:
`scratchpad/dangling.py`.

**27 distinct dangling names across 63 carriers, before and after the move —
unchanged, and not one of them names a file this package moves.** Checked by
filtering the output on the 12 moved basenames: empty, exit 1.

My detector is a superset of P2's 22/3 because it also reports the files that
*declare* an allow-listed namespace, not only the allow-list rows. Attributed:

- **17** are `governance/TestSuiteHygiene/namespace-path-allow-list.php` rows and
  the files declaring them — namespaces deliberately not following their path,
  guarded by that list. P4's own Definition of Done names them ("the 30 files
  whose namespace the allow-list already guards").
- **4** are `P6_RENAMED_TEST_IDS` keys in the generator, which is P2's count for
  the same carrier — the closed-epoch record P1 already reported, read by nothing,
  P4's sweep.
- **5** are references to classes renamed in stage 02/03, carried by five other
  files: `…\Integration\Configuration\YamlKeyReachabilityTest`,
  `…\Integration\Infrastructure\Rule\ChannelDeclarationFixtureDriftTest`,
  `…\Infrastructure\Unit\ChannelDeclarationCompilerPassTest`,
  `…\Analysis\Policy\Architecture\Unit\Configuration\Validation\AllowValidatorTest`
  and `…\Architecture\Unit\Configuration\Allow\AllowAliasExpanderTest`.
- **1** is the refusal control's fixture-namespace constant.

17 + 4 + 5 + 1 = 27.

Three of those spell `Qualimetrix\Tests\Unit\…` or `Qualimetrix\Tests\Integration\…`
— the namespace prefixes corresponding to the directories this package deletes —
but every one of them is a name from an epoch that ended before stage 04 began,
carried by a file this package does not own. They are named here so the next
package does not re-derive them.

## `phpunit.xml.dist` — the derivation and the fresh-clone proof

The set is computed, not recalled. For every declared `<directory>`, the count of
tracked files beneath it, before and after applying the map's P3 partition to
`git ls-files`:

```
tests/Unit                                       7 -> 0    REMOVE
tests/Integration                                2 -> 0    REMOVE
tests/Analysis/Finding/RuleConfiguration/Unit    1 -> 0    REMOVE
tests/Analysis/Finding/Unit                     46 -> 47   stays
tests/Analysis/Policy/Architecture/Integration  10 -> 11   stays
tests/Analysis/Configuration/Integration         4 -> 5    stays
tests/Analysis/Evidence/Measurement/Unit        25 -> 26   stays
```

No other declared entry's count changes. **Added 1**: `tests/Core/Unit` (6 files).
`tests/Functional` was already gone with P2, so this package removes three and not
four. 79 declared entries become **77**.

Each of the four edits was made in both places — `phpunit.xml.dist` and
`testSuitePrefixTable()`. `assertSuiteClassifierAgreesWithPhpunit()` reconciles
them in both directions and runs inside `composer architecture:check`, exit 0: a
`<directory>` `currentSuite()` cannot place, or a table literal with no matching
`<directory>`, is refused by name.

**A fresh clone of this commit runs**, proved over *every* declared entry rather
than over the four that were touched:

```
sed -n 's/.*<directory>\(.*\)<\/directory>.*/\1/p' phpunit.xml.dist | sort -u |
  while read -r d; do
    printf '%s %s %s\n' "$(git ls-files "$d" | wc -l)" \
      "$([ -d "$d" ] && echo present || echo ABSENT)" "$d"
  done
```

**77 distinct declared entries; every one is `present` on disk and every one has
`git ls-files` ≥ 1.** Seventeen track exactly one file; none tracks zero. The
second column is the fresh-clone half: `git ls-files` reads the index, which is
what a clone of this commit materialises, so an entry git tracks nothing under is
an entry that would be absent there — and PHPUnit exits 2, having run nothing, on
a `<directory>` that is not on disk.

## Definition of Done

| #   | Item                                                                 | Result                                                                                                                                  |
| --- | -------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | `php scripts/generate-modular-architecture-test-inventory.php`       | **exit 0** — 921 artifacts, 120 fixture directories, 725 PHPUnit classes, 9198 expanded cases                                           |
| 1   | `move-oracle.py --package=P3 --base=ef090d27`                        | **exit 0** — `package P3: 11 rows, allowance now 0` / `agreed`. The judge was not edited                                                |
| 2   | The six per-suite counts                                             | **6705 / 383 / 152 / 1029 / 179 / 748** — all six identical to P2's row                                                                 |
| 3   | A fresh clone runs: every declared `<directory>` present and tracked | **77 / 77** present with ≥ 1 tracked file                                                                                               |
| 4   | `composer architecture:check`                                        | **exit 0**                                                                                                                              |
| 5   | `composer check:code`                                                | **exit 0** (run with nothing else touching the tree; see the concurrent plan edit above)                                                |
| 5+  | `composer check:artifacts`                                           | **exit 0** — not in the brief's DoD; run because this package edits `phpunit.xml.dist`, which AGENTS.md names as what that group covers |
| 5+  | `ModularArchitectureGovernanceIntegrationTest` (`live-freshness`)    | **exit 0**, `OK (4 tests, 785 assertions)` — the aggregate excludes this group, and this package rewrote generator code                 |
| 6   | `git status --porcelain` / `git diff --stat -M HEAD`                 | 11 `R` + 8 `M` of mine + 1 `M` **not mine** = the 20 files `--stat` reports; 247 insertions, 286 deletions, all 11 recorded as renames  |
| 7   | `tests/Unit`, `tests/Integration`, `tests/Functional` gone           | Absent from disk and from the index; **no `<directory>` names any of them**; see the accounting below                                   |

Item 2, measured with the runner's own exclusions:

```
for S in Unit Integration Functional Infrastructure Tooling Governance; do
  echo -n "$S "; vendor/bin/phpunit --testsuite=$S --no-coverage \
    --exclude-group=benchmark --exclude-group=live-freshness --list-tests | grep -c '^ - '
done
```

| Suite          | Expected | Measured |
| -------------- | -------: | -------: |
| Unit           | 6705     | 6705     |
| Integration    | 383      | 383      |
| Functional     | 152      | 152      |
| Infrastructure | 1029     | 1029     |
| Tooling        | 179      | 179      |
| Governance     | 748      | 748      |

Unit and Integration stay put although `tests/Unit` and `tests/Integration` cease
to exist: the eight and two files keep their level and change only their owner
root.

Item 4 verbatim: `Checked modular-architecture governance: 955 declarations, 37
semantic-owner layers, 0 seams, 73 exact internal grants -> 13 coarse edges.` and
`Checked 921 artifacts, 120 fixture directories, 725 PHPUnit classes, and 9198
expanded cases.`

Item 5, the six suite results inside the aggregate: Unit `OK (6705 tests, 16633
assertions)`, Integration `OK (383, 2199)`, Functional `OK (152, 486)`,
Infrastructure `1029 tests, 3529 assertions, 1 skipped`, Tooling `OK (179,
39048)`, Governance `OK (748, 15503)`, plus PHP-CS-Fixer (`"files":[]`), PHPStan
(`[OK] No errors`), the JS tests (150) and both cross-tool suites (17 + 15).

Item 6: the eight modifications of mine are the generator, the two changed
generated artifacts, `phpunit.xml.dist`, and the four reference carriers outside
the move set. `test-phpunit-suites.txt` is unchanged. The ninth, the plan
document, is not mine. `bash scripts/check-private-leaks.sh` is exit 0 with the
new report file present.

**Item 7, the accounting.** On disk: `ls -d` reports all three absent. Declared:
`git grep -n -E '<directory>tests/(Unit|Integration|Functional)</directory>'`
returns five lines, all in `docs/internal/plans/test-structure/measurement/`
prose about this stage — nothing executable. As a path prefix,
`git grep -n -E 'tests/(Unit|Integration|Functional)/'` over the whole tracked
tree returns 1115 occurrences in 62 files; by carrier:

| Carrier                                                    | Files | Live address?                                                                                                           |
| ---------------------------------------------------------- | ----: | ----------------------------------------------------------------------------------------------------------------------- |
| `docs/internal/plans/**`                                   | 58    | no — plans and this stage's own measurements                                                                            |
| `docs/adr/**`                                              | 2     | no — ADR 0009 and 0014 record where tests stood when they were written                                                  |
| `docs/internal/generated/**`                               | 0     | **not excluded in this grep — it has no match at all.** After this package no generated artifact names any of the three |
| `scripts/generate-modular-architecture-test-inventory.php` | 1     | **no, and this is the one to look at**                                                                                  |
| `tests/Analysis/Finding/Unit/LocationTest.php`             | 1     | no — synthetic test data, D4                                                                                            |

The generator's 43 lines are the surviving ladder in `classifyOwner()`,
`dispositionFor()` and `targetPath()`, plus one `RETIRED_PATH_ASSERTIONS` key
(`tests/Unit/Infrastructure/Logging/LoggerFactoryTest.php`) whose contract
*requires* its path to be absent. The ladder literals are not addresses to files:
the file's own docblock declares classifier literals to be claims about arbitrary
inputs, including paths handed in through `--classification-probe=`. What this
package changes about them is that the last tracked member of each is now gone —
`tests/Unit/` 7 → 0, `tests/Integration/` 2 → 0, `tests/Functional/` 0 → 0 — so
every branch keyed on the three bucket prefixes is now unreachable for every
tracked path, joining the Reporting branches P2 recorded. **One sweep retires them
together, in P4**, which is where the plan puts it.

## What the judge does not check, measured rather than assumed

- **Nothing in the judge looks at `phpunit.xml.dist`, at `testSuitePrefixTable()`,
  or at any address that becomes wrong *because* this package created something.**
  The whole `<directory>` half is checked by `composer architecture:check` and
  `composer check:code`, never by the judge; a package that got the four edits
  wrong in a way PHPUnit tolerates would still read `agreed`.
- **Nothing in the judge checks that a row outside the package stayed put in the
  inventory.** Checked here separately:
  `git diff --numstat HEAD -- docs/internal/generated/modular-architecture/test-ownership.tsv`
  is exactly **`11 11`** — one line changed per moved file and no other row
  reclassified. `test-phpunit-discovery.txt` is `180 180`, and of its 360 changed
  lines, **0** fail to name one of the 11 moved classes: the moved test cases'
  fully qualified ids being renamed, and nothing else.
- **The judge's old-FQCN arm cannot see a PHP-escaped literal**, as P1 measured.
  Compensated here by sweep 1b and by sweep 3, both of which return nothing for
  any of the 12 names.
- **Nothing checks the deleted guard.** V1 removes a function; no control asserts
  its existence (`git grep assertLegacyUnmovedShrinksOnly` outside the generator
  returns nothing but this stage's plan documents), so its absence is silent by
  construction. That is why it is reported as a deviation rather than a decision.

## Findings, recorded rather than acted on

- **The concurrent write to `04-packages.md`.** Reported at the top. Whoever
  commits P3 must stage explicitly, not `git commit -a`.
- **The `{@see}` docblock channel has now cost three packages in a row.** The
  brief's *procedure* was complete — it prescribes the declared-FQCN sweep, which
  is what found the two extra carriers — but its *enumeration* named two consumers
  where there are four, and an executor who trusted the enumeration and skipped
  the sweep would have shipped two stale addresses. P1 and P2 each reported the
  same shape. The channel is not "consumers that would fail to load"; it is
  "carriers that spell a moved name". A named consumer set in a brief should say
  it is the subset the sweep is expected to grow.
- **The dangling-FQCN detector has now been written twice from scratch** — P2's
  and this one, both in session scratchpads that vanish with the session, and P4
  is told to run it a third time. The copy this report cites
  (`scratchpad/dangling.py`) is ephemeral and will not exist when this is read.
  Whether it should become a tracked control, and where, is the orchestrator's
  call, not a move package's.
- **A directory literal is a fifth spelling** that neither the FQCN nor the
  full-path sweep reaches (V3). The stage's sweep list should be four spellings
  plus two prefix sweeps — the prefixes filled (P2's rule) and the prefixes
  emptied — not four plus one.
- **`tests/Core/Unit/` is the first `Core.Neutral` test directory on disk**, and
  P0 probed its classification without a file existing. It now exists, and
  `currentSuite()` places it as `Unit` through the new table row; the six moved
  files are discovered there and counted in the 6705. P0's positive probe is
  therefore now redundant with a real measurement.
- **`failUnownedTestClass()`'s refusal text still ends "…or record the move in
  LEGACY_UNMOVED."** Correct while the empty constant exists; it retires with the
  constant in P4.
