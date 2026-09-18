# Stage 04, P6 — the two remaining named cases

Branch `x30-stage-04-subject-layout`, from `fa19b515`. Nothing committed.

## What changed

Fourteen tracked files — eleven under `tests/`, three of which git records as renames,
plus the generator and the two artifacts it regenerates.

| Change                                                                                                                               | Kind                                         |
| ------------------------------------------------------------------------------------------------------------------------------------ | -------------------------------------------- |
| `tests/Analysis/Finding/Support/FindingFactory.php` → `tests/Analysis/Policy/Baseline/Support/FindingFactory.php`                    | move, namespace rewritten                    |
| `tests/Analysis/Policy/Architecture/Integration/UnmatchedExcludeIntegrationTest.php` → `UnmatchedLayerExcludeIntegrationTest.php`    | rename in place, class renamed with the file |
| `tests/Analysis/Run/Integration/ExcludeBinding/UnmatchedExcludeIntegrationTest.php` → `UnmatchedDiscoveryExcludeIntegrationTest.php` | rename in place, class renamed with the file |
| the 8 consumers under `tests/Analysis/Policy/Baseline/Unit/`                                                                         | one `use` line each                          |
| `scripts/generate-modular-architecture-test-inventory.php`                                                                           | two constants                                |
| `docs/internal/generated/modular-architecture/test-ownership.tsv`, `test-phpunit-discovery.txt`                                      | regenerated                                  |

No directory is created and none is emptied. `tests/Analysis/Policy/Baseline/Support/`
already held three files; `tests/Analysis/Finding/Support/` keeps three. So no
`<directory>` entry in `phpunit.xml.dist` is added or removed and the file is not
touched — the stage's "a package leaves the tree runnable" rule has nothing to do here,
and that is a measured fact (`[ -d ]` on the target before the move, `ls` on the source
after), not an assumption.

## The consumer set, re-derived rather than inherited

The brief hands down "eight, all under `tests/Analysis/Policy/Baseline/Unit/`, none in
Finding", and names it a subset a sweep is expected to grow. The sweep grew it by zero:

```
git ls-files -z -- . ':(exclude)docs/internal/plans/*' ':(exclude)docs/adr/*' \
  ':(exclude)docs/internal/generated/*' \
  | xargs -0 grep -nF -- 'Qualimetrix\Tests\Analysis\Finding\Support\FindingFactory'
```

**8 lines, 8 files**, every one a `use` statement under
`tests/Analysis/Policy/Baseline/Unit/`: `BaselineCeilingStageAcceptanceTest`,
`BaselineCeilingStageFailSafeTest`, `BaselineCeilingStageJudgeAllTest`,
`BaselineCeilingStagePromotionTest`, `BaselineCleanerTest`, `BaselineGeneratorTest`,
`BaselineIdentityTest`, `BaselineUpdaterTest`. This is the first package in the stage
whose handed-down list was not short — worth recording as such, because "the list was
right this time" is only knowable by having run the sweep.

**`git grep -F` is not a fixed-string search, and this package hit it.** It wraps its
pattern in `\Q…\E` and hands it to PCRE2, so a backslash-spelled FQCN containing a
segment that starts with `E` closes the quoting early and everything after is read as a
regex:

```
$ git grep -n -F 'Qualimetrix\Tests\Analysis\Run\Integration\ExcludeBinding\UnmatchedExcludeIntegrationTest'
fatal: … PCRE2 does not support \F, \L, \l, \N{name}, \U, or \u
```

`\ExcludeBinding` closes the `\Q`; `\UnmatchedExclude…` is then an illegal escape. Every
sweep in this report therefore runs `git ls-files -z | xargs -0 grep -nF`, which has no
regex engine behind it. Cross-checked against `git grep -F` on the four patterns it
*can* handle: same counts (8, 0, 99, 1).

**The loud form is the lucky one.** When what follows `\E` happens to be a *valid* PCRE
escape, there is no error at all — the search silently becomes a different search.
Reproduced in an isolated throwaway repository, one file, one line:

```
$ cat probe.txt
Qualimetrix\Tests\Analysis\Evidence\Size\Foo
$ grep -cF 'Qualimetrix\Tests\Analysis\Evidence\Size' probe.txt
1
$ git grep -c -F 'Qualimetrix\Tests\Analysis\Evidence\Size'      # exit 1, no output, no warning
```

`\Evidence` closes the quote and `\S` is PCRE's non-whitespace class, so the pattern
still compiles and matches nothing. A sweep reporting that zero has measured nothing and
cannot tell.

**Whether this corrupted an earlier package's evidence: measured, and no.** The hazard
needs a name segment beginning with `E`. Across all 114 rows of `relocation-map.csv`,
the `current` column — the spelling every old-FQCN sweep is keyed on, and the one whose
zeros are load-bearing — has **0** such segments; the `target` column has **1**
(`tests/Analysis/Evidence/Measurement/Unit/Contract/NamespaceTreeTest.php`, row 40).
Target-side sweeps in this stage were run over *paths*, which use `/` and never reach
`\Q…\E`. So: a real defect in the tool, demonstrated, with no retro-active effect on
P1–P5. It is live for **stage 05**, which moves `Analysis\Evidence\…` names and will
sweep them as namespaces.

```
cut -d, -f1 docs/internal/plans/test-structure/measurement/stage-04/relocation-map.csv \
  | tail -n +2 | grep -cE '(^|/)E[A-Za-z]'     # 0
```

## Decisions

| Decision                                                                                                                         | Alternative rejected                                                                                                                                                                                                                                                                                                                                                                    | Verified by                                                                                                                                                                                                                                                                                                                                                                                                  |
| -------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **`P6_A_FINDING_TEST_PATHS` follows the rename** — the entry becomes `tests/Analysis/Policy/Baseline/Support/FindingFactory.php` | Retiring it to `RETIRED_PATH_ASSERTIONS`. The guard's own refusal text offers both and distinguishes them by whether the artifact is *gone*; this one exists. A `RETIRED` row is also checked in the other direction — "a path here that exists again is a defect" — and the old path no longer exists, so a retirement would be inert rather than wrong, which is the worse of the two | `composer architecture:check` exit 0; and the ownership worry is measured away below                                                                                                                                                                                                                                                                                                                         |
| Following the rename records **no ownership claim**, so there is nothing to compensate for                                       | Believing the constant's docblock ("Exact Finding test closure") and concluding that a Baseline path listed there makes the inventory say Finding                                                                                                                                                                                                                                       | `grep -n P6_A_FINDING_TEST_PATHS` on the generator returns **one** use, line 1745, inside `assertPathLiteralsResolve()`. It feeds no classifier — unlike `P7_MEASUREMENT_PATHS` and `P6_D_PRIORITIZATION_TEST_PATHS`, which `classifyOwner()` reads at lines 815 and 833. The owner comes from the path ladder, which has a `tests/Analysis/Policy/Baseline/` branch above the `tests/Analysis/Finding/` one |
| The constant's docblock is left alone                                                                                            | Amending "Exact Finding test closure" to mention the emigrant. The list already holds four `governance/Channel/` and `governance/ThresholdKeys/` paths, so membership was never by path prefix — it is the P6-A package's artifact closure. A note would document a rule the list never had                                                                                             | the four governance rows, lines 194–196 and 213/216                                                                                                                                                                                                                                                                                                                                                          |
| **`P6_C_BASELINE_PATHS_SHA256` is measured, not copied**                                                                         | Pasting the plan's `818d94bd…`, computed on a tree three packages older                                                                                                                                                                                                                                                                                                                 | the command below                                                                                                                                                                                                                                                                                                                                                                                            |
| `composer cs-fix` reorders the eight imports rather than hand-placing them                                                       | Hand-sorting: the fixer is the authority `cs-check` judges against, and the ordering rule is `ordered_imports`, not alphabetical-by-eye                                                                                                                                                                                                                                                 | `composer check` exit 0                                                                                                                                                                                                                                                                                                                                                                                      |

### The probe that settles the ownership question

The discriminating test is the generator's own, run against the moved file and against a
`Support/` sibling that is in no constant at all:

```
$ php scripts/generate-modular-architecture-test-inventory.php \
    --classification-probe=tests/Analysis/Policy/Baseline/Support/FindingFactory.php
Analysis/Policy/Baseline	P6-C	none	tests/Analysis/Policy/Baseline/none/FindingFactory.php
$ php scripts/generate-modular-architecture-test-inventory.php \
    --classification-probe=tests/Analysis/Policy/Baseline/Support/FixedClock.php
Analysis/Policy/Baseline	P6-C	none	tests/Analysis/Policy/Baseline/none/FixedClock.php
```

Both exit 0 and the two rows are identical. The moved file is owned exactly as its
Support siblings are, and its `P6_A` membership changes nothing about that. The
regenerated `test-ownership.tsv` row agrees: owner `Analysis/Policy/Baseline`, closure
`P6-C`, disposition "Retain at the materialized subject-owned path."

## The digest

Both values were produced by the same command — `p6CBaselinePaths()` transcribed
verbatim, run from the repository root:

```
php -r '$root = getcwd();
$dir = $root . "/tests/Analysis/Policy/Baseline";
$paths = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
foreach ($it as $f) { if ($f->isFile()) { $paths[] = substr($f->getPathname(), strlen($root)+1); } }
sort($paths, SORT_STRING);
echo count($paths), " ", hash("sha256", implode("\n", $paths) . "\n"), "\n";'
```

| When   | Files | Digest                                                             |
| ------ | ----: | ------------------------------------------------------------------ |
| before | 64    | `6ec107b914a7e7df6ba2f1984792d48cc7d9c014317c7b190b859e1cdc587833` |
| after  | 65    | `818d94bd58fb2ec644dfa6b30db12c1891aaa04f7362e4f1ce993b80085890ae` |

The "before" value reproduces the tracked constant exactly, which is what makes the
"after" value a measurement of *this* move and not of an unrelated drift.

**The plan's `818d94bd…` turns out to be right, and the reason it survived three
packages is worth naming rather than taking as luck:** the digest is over the sorted
*path list*, not over file contents, so it moves only when a file enters or leaves
`tests/Analysis/Policy/Baseline/`. P1–P5 moved nothing into or out of that directory.
Had any of them done so, the copied value would have been wrong and the copy would not
have revealed it. It was re-measured regardless.

**Reason for the change, recorded as the decision requires:** the set grew by exactly
one member, `tests/Analysis/Policy/Baseline/Support/FindingFactory.php`, because P6
moves `FindingFactory` to the subject whose eight tests are its only consumers.

**It is the only digest change.** `git grep -n -i sha256 -- scripts governance` returns
a single constant declaration in the whole of both roots — line 18 of the generator —
so there is no second digest that could have moved unnoticed.

## The six sweeps

Population: tracked files, `docs/internal/plans/`, `docs/adr/` and
`docs/internal/generated/` excluded as history and regenerated output (the generated
tree is swept separately below, after regeneration). Counts are **lines**.

### 1. Declared fully qualified name, single backslash

| Name                                                                                                 | before | after |
| ---------------------------------------------------------------------------------------------------- | -----: | ----: |
| `Qualimetrix\Tests\Analysis\Finding\Support\FindingFactory`                                          | 8      | **0** |
| `Qualimetrix\Tests\Analysis\Policy\Architecture\Integration\UnmatchedExcludeIntegrationTest`         | 0      | **0** |
| `Qualimetrix\Tests\Analysis\Run\Integration\ExcludeBinding\UnmatchedExcludeIntegrationTest`          | 0      | **0** |
| `Qualimetrix\Tests\Analysis\Policy\Baseline\Support\FindingFactory`                                  | 0      | 8     |
| `Qualimetrix\Tests\Analysis\Policy\Architecture\Integration\UnmatchedLayerExcludeIntegrationTest`    | 0      | 0     |
| `Qualimetrix\Tests\Analysis\Run\Integration\ExcludeBinding\UnmatchedDiscoveryExcludeIntegrationTest` | 0      | 0     |

The two renamed classes are named by nothing but themselves, before or after: nothing
imports an integration test.

### 2. The same, PHP-escaped, every backslash doubled

All six patterns: **0 before, 0 after.** A measured negative, not an absence of looking
— and the one sweep whose tooling failure is described above, so it is also the one
whose zero had to be earned twice.

### 3. Bare basename, word-anchored (`grep -w -F`)

| Name                                       | before | after |
| ------------------------------------------ | -----: | ----: |
| `FindingFactory`                           | 99     | 99    |
| `UnmatchedExcludeIntegrationTest`          | 2      | **0** |
| `UnmatchedLayerExcludeIntegrationTest`     | 0      | 1     |
| `UnmatchedDiscoveryExcludeIntegrationTest` | 0      | 1     |

`FindingFactory`'s 99 is unchanged because none of its carriers went away: 8 `use`
lines, 1 class declaration, 1 path literal in the generator and 89 call sites, all of
which spell the short name. The pre-move 2 for `UnmatchedExcludeIntegrationTest` were
the two `final class` lines and nothing else — the clearest possible statement that this
is a rename with no reference to repoint.

### 4. Current path literal

| Path                                                                                 | before | after |
| ------------------------------------------------------------------------------------ | -----: | ----: |
| `tests/Analysis/Finding/Support/FindingFactory.php`                                  | 1      | **0** |
| `tests/Analysis/Policy/Architecture/Integration/UnmatchedExcludeIntegrationTest.php` | 0      | **0** |
| `tests/Analysis/Run/Integration/ExcludeBinding/UnmatchedExcludeIntegrationTest.php`  | 0      | **0** |
| `tests/Analysis/Policy/Baseline/Support/FindingFactory.php`                          | 0      | 1     |
| `…/UnmatchedLayerExcludeIntegrationTest.php`                                         | 0      | 0     |
| `…/UnmatchedDiscoveryExcludeIntegrationTest.php`                                     | 0      | 0     |

The single carrier on both sides is `P6_A_FINDING_TEST_PATHS`. Nothing else in the
repository names any of these three files by path.

### 5. The prefixes this package fills

No directory is created, so the sweep is over the target prefix and the three exact
target paths. `tests/Analysis/Policy/Baseline/Support/` as a literal: **0 before, 1
after** (the `P6_A` entry this package writes). Nothing in the tree carried a claim
about the destination before the move — in particular no control plants a probe there,
which is the shape P2 found the hard way.

### 6. The prefixes this package empties

**None.** `tests/Analysis/Finding/Support/` keeps `CorpusCaseRun.php`,
`FromArrayKeyReader.php` and `StubChannelDeclarationRegistry.php`. Swept anyway, because
a prefix that survives can still carry a claim that assumed the departed file: **2
lines, both about other files** —
`scripts/enumerate-rule-option-keys.php:25` (the directory in prose, P3's V3, about
`FromArrayKeyReader`) and the generator's `StubChannelDeclarationRegistry` row. Neither
is about `FindingFactory`; both are correct as they stand.

### The generated tree, swept after regeneration

Excluded from the six sweeps above, so checked separately once
`composer architecture:generate` had run: **0** hits for each of the three old path/FQCN
spellings and for `UnmatchedExcludeIntegrationTest`; 1, 9 and 15 for the three new
spellings. The regenerated diff is 22 renamed method IDs (8 Architecture + 14 Run, zero
net) and 3 relocated rows — no other artifact row moved.

## The detector

```
python3 docs/internal/plans/test-structure/measurement/stage-04/dangling-test-names.py
```

**9 name(s) across 9 carrier(s)**, exit 1, before and after — and not merely the same
count: the nine names were diffed member for member against the pre-change run and are
identical. No name was created and none was closed. Exit 1 is the script's normal
verdict while any dangling name stands — its line 122 is `return 1 if dangling else 0`,
read rather than inferred. The `EXIT=0` a shell pipeline reported during the baseline
run was `tail`'s exit code, not the script's.

## Deviations

**`composer cs-fix` was run, and the first `composer check` was red (exit 8).** The
`use` rewrite was a textual substitution in place, which left the new
`…\Policy\Baseline\Support\FindingFactory` import at the old import's slot — past its
sorted position in seven of the eight consumers. `ordered_imports` caught it. The fix
changed exactly one line per consumer and nothing else: `git diff` over
`tests/Analysis/Policy/Baseline/Unit/` is 8 files, 8 insertions, 8 deletions, and every
changed line on both sides is a `use` line (checked by filtering the diff for lines that
are not `use`: zero). The second `composer check` is exit 0.

Recorded rather than quietly fixed because it is a property of the *method*: a
substitution that changes a namespace changes an import's sort key, so any package that
repoints imports by `perl -pi` owes the tree a `cs-fix` before it claims green.

## Assumptions

- The sweep population excludes `docs/internal/plans/`, `docs/adr/` and
  `docs/internal/generated/` — the convention P1–P3 established, for the same reason:
  the first two are history and the third is output. The third was swept separately
  after regeneration rather than trusted.
- `git ls-files` is the tracked-file oracle, so the untracked `.claude/worktrees/`
  checkouts of this repository inside the working tree — which a plain `grep -r` doubles
  every hit through, and which carry *older* copies of the generator with *different*
  digest constants — are outside every count here.
- `P6_LIVE_ADDED_TEST_IDS` and `P6_RENAMED_TEST_IDS` were checked before the renames on
  the chance that a frozen test-ID authority would refuse 22 changed method IDs. They
  are declared and read nowhere in the tracked tree. See the observation below.

## Definition of Done

| #   | Item                                                                                                 | Result                                                                                                                                                                                                                                                                                                                                                                       | Exit                                       |
| --- | ---------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -----------------------------------------: |
| 1   | six per-suite counts unchanged                                                                       | Unit 6705, Integration 383, Functional 152, Infrastructure 1029, Tooling 179, Governance 757 — `diff` against the pre-change measurement is empty                                                                                                                                                                                                                            | 0                                          |
| 2   | invariant control green, exception lists untouched                                                   | `git diff --exit-code governance/TestSuiteHygiene/subject-path-exceptions.php` empty; the whole `governance/TestSuiteHygiene/` directory is byte-unchanged. `--testsuite=Governance` OK (759 tests, 15543 assertions — 759 and not row 1's 757 because the focused run omits `--exclude-group=benchmark --exclude-group=live-freshness`, which the counting command carries) | 0                                          |
| 3   | the P6-C digest change is the only digest change, recorded with its reason                           | `6ec107b9…` → `818d94bd…`, measured both times; the sole `sha256` constant in `scripts` and `governance`                                                                                                                                                                                                                                                                     | —                                          |
| 4   | `composer architecture:check`                                                                        | 955 declarations, 37 layers, 926 artifacts, 726 PHPUnit classes, 9207 cases                                                                                                                                                                                                                                                                                                  | 0                                          |
| 5   | `composer check`                                                                                     | full aggregate, nothing else touching the tree                                                                                                                                                                                                                                                                                                                               | 0                                          |
| 6   | `git status --porcelain` clean but for the intended changes; `git diff --stat -M HEAD` shows renames | 11 files under `tests/`, all three shown in git's `{old => new}` rename form; plus the generator and two regenerated artifacts                                                                                                                                                                                                                                               | —                                          |
| 7   | detector still 9                                                                                     | 9 across 9 carriers, same names member for member                                                                                                                                                                                                                                                                                                                            | 1 (its verdict while the known nine stand) |

Not committed, as instructed.

## Two observations outside the package

**`git grep -F` must not be used for namespace sweeps in stage 05.** Demonstrated above,
including the silent form. Stage 05 moves `Analysis\Evidence\…` names, every one of
which carries the `\E` that breaks it. The replacement is one line and needs no tool:
`git ls-files -z -- <pathspec> | xargs -0 grep -nF -- <pattern>`.


`P6_LIVE_ADDED_TEST_IDS` (line 269) and `P6_RENAMED_TEST_IDS` (line 279) of
`scripts/generate-modular-architecture-test-inventory.php` are declared and read
**nowhere** in the tracked tree — a `git ls-files | xargs grep -F` on each name returns
only its own declaration. They are the kind of frozen authority whose silence would look
exactly like agreement: this package renamed 22 test-method IDs and neither constant
noticed, which is correct here only because neither constant does anything. Left alone —
P6's brief forbids widening — and named for whoever closes the stage.
