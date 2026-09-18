# Stage 04, package P1 — the Infrastructure-owned files move

Executed against `main` @ `7d54fb67`, the commit P1 starts from. Nothing is
committed; the tree carries 52 renames and 11 modifications.

No PHPUnit test case was added or removed. No test file outside the 52 map rows
moved.

## What changed

**The 52 moves.** Every row of `relocation-map.csv` whose `owner` starts with
`Infrastructure.` — re-derived by command against the map, not read from
`p1-fileset.md` — moved with `git mv` to its recorded `target`, and each file's
`namespace` declaration was rewritten to the PSR-4 implication of its new path.
The per-suite origin is Unit 28, Functional 4, Integration 4 and
`tests/Infrastructure/` residue 16; all 52 land under `tests/Infrastructure/`,
which is the whole per-suite delta of the stage.

Five directories emptied completely and were removed from disk, none of them
declared anywhere: `tests/Unit/Infrastructure`, `tests/Integration/Infrastructure`,
`tests/Functional/Console`, `tests/Infrastructure/Unit`,
`tests/Infrastructure/Integration`.

**`scripts/generate-modular-architecture-test-inventory.php`** — the 52
`LEGACY_UNMOVED` rows deleted (112 → 60), and four path literals plus three
`FQCN::method` halves followed their rename. See D1–D3.

**Seven reference lines in six non-moving files** closed; see "The reference
channels" below.

**`src/Infrastructure/README.md`** — the compiler-pass paragraph named
`tests/Infrastructure/Unit/`, a directory this package deletes. Rewritten to
`tests/Infrastructure/DependencyInjection/Unit/CompilerPass/`.

**The generated artifacts** were regenerated: `test-ownership.tsv`,
`test-phpunit-discovery.txt`, `test-phpunit-suites.txt`.

## Decisions

Each names the alternative rejected and the command that checks it.

**D1. The four guarded path literals follow the rename; they are not retired.**
`P3_TEST_PATHS` ×2 (`CheckScopeResolverTest`, `RuntimeLoggerConfiguratorTest`)
and `P6_D_GIT_TEST_PATHS` ×2 (`ReportingGitScopeQueryProjectSubdirTest`,
`ReportingGitScopeQueryTest`) now spell each file's new path. The alternative —
moving the entries to `RETIRED_PATH_ASSERTIONS` — was rejected because the
guard's own refusal text prescribes the choice ("Follow the rename, or move the
entry to RETIRED_PATH_ASSERTIONS with the reason it is gone"): the files are not
gone. `addresses-witness-a.md` reaches the same instruction independently.
Checked by: `composer architecture:check` exit 0 (`assertPathLiteralsResolve()`
runs at startup and would `fail()` naming the constant and the absent path).

**D2. `P6_D_GIT_TEST_PATHS` keeps its three consumers, which are now
unreachable.** After the move both its paths are `tests/{owner}/{level}/…` test
classes, so `isTestClassPath()` answers first in all three of `classifyOwner()`,
`dispositionFor()` and `targetPath()`; the `in_array(..., P6_D_GIT_TEST_PATHS)`
branches can no longer be taken. Deleting the constant and its three branches
was rejected for P0's D1 reason: the dead-branch sweep is a rewrite of the
complement that P4 can do once with one check, and doing it piecemeal per move
package is how a partial cure gets written three times. Recorded as a finding.
Checked by: the oracle's inventory arm — every moved file now reports
`permanent` / `Retain at the materialized subject-owned path.`, which is the
parse answering, not the constant.

**D3. In `P6_RENAMED_TEST_IDS`, only the value halves follow the rename.** The
constant maps an ID as it stood in the accepted 509/7,245 authority to the ID
that replaced it, so the key is an earlier-epoch spelling *by construction* —
row 1's key already names `Tests\Unit\Infrastructure\DependencyInjection\CompilerPass\…`,
a namespace no file has had for two renames. Two value halves therefore changed
(`RuleCompilerPassTest`, `ContainerFactoryTest`) plus the single
`P6_LIVE_ADDED_TEST_IDS` entry (`ReportingGitScopeQueryProjectSubdirTest`),
which records IDs that exist *now*. Rewriting the keys as well was rejected: it
would assert that at the authority epoch the ID already carried the post-P1
FQCN, which is false, and would launder history rather than follow a rename.
Checked by: the both-spelling FQCN sweep below, whose one surviving hit is
exactly this key and no other.

This corrects `p1-fileset.md`'s count: it reports **3** `P6_RENAMED_TEST_IDS`
halves matching a P1 FQCN; **2** of them are value halves and are what this
package rewrites, the third being row 2's key.

**D4. Prose that names a moved class by its basename alone is not edited.** The
basename does not change, so nothing there is stale:
`governance/ConsoleComposition/RulesCommandFamilyCensusTest.php:21`
("Split off from `RulesCommandWiringTest`") and
`governance/RuleDeclaration/RuleRegistrationDriftTest.php:26`
("`ContainerFactoryTest::itRegistersAllRules` compares it against a").
`p1-fileset.md` suggested updating the second "for readability"; there is
nothing to update. Checked by: the bare-class-name sweep, which returns these
two and nothing else outside the generated artifacts.

**D5. The stale-epoch `{@see}` is repointed at the post-move name, not left as
found.** `tests/Infrastructure/Console/Integration/RuleExclusionStatsWiringTest.php:43`
named `\Qualimetrix\Tests\Integration\Infrastructure\Console\RulesCommandWiringTest`,
a spelling that matched no file even before this package. `p1-fileset.md`
flagged it and declined to fix it, on the argument that fixing it would launder
an unrelated defect. The brief overrides that and it is the right call: the
class it means is real and is one of the 52, the carrier belongs to this package
by the plan's consumer rule, and leaving a dangling link intact is not
book-keeping hygiene. It now reads
`\Qualimetrix\Tests\Infrastructure\DependencyInjection\Integration\RulesCommandWiringTest`.
Checked by: `git grep -F` for that FQCN resolves to the moved file.

## Assumptions

- **A1.** The map is the authority for which 52 files move and where they land;
  the derivation was re-run by command against
  `relocation-map.csv` on this tree rather than read from `p1-fileset.md`. 52
  rows, 8 distinct owners, and the map's `current` and `target` were used
  verbatim.
- **A2.** PSR-4 for tests is `Qualimetrix\Tests\ => tests/`, one namespace
  segment per path segment. Verified empty-handed first: all 52 files agreed
  with their *pre-move* path under that rule, so the rewrite carries no
  pre-existing namespace defect and introduces none. The oracle re-checks the
  post-move side independently.
- **A3.** Depth-sensitive path arithmetic survives. 21 of the 52 change the
  number of path segments, and **none of those 21 contains `__DIR__`**; the 3
  files that do (`PhpFileParserTest` `dirname(__DIR__, 3)`,
  `GitRepositoryLocatorTest` and `GitScopeResolverTest` ×2 `dirname(__DIR__, 4)`)
  all keep their segment count. Measured per file, not inferred from the map's
  shape.

## Deviations from the plan text

**V1.** The plan's P1 Definition of Done says "`git grep -l` for each moved
file's old fully-qualified class name across the whole repository returns
nothing." One hit is left deliberately: the key half of `P6_RENAMED_TEST_IDS`
row 2, in its PHP-escaped spelling. See D3 for why, and "What the judge does not
check" for why the judge is silent about it either way.

**V2.** `src/Infrastructure/README.md` is not in the plan's stated derived set,
which is "`relocation-map.csv` rows plus every generator constant naming one of
those rows" plus the by-content sweep over `git ls-files`. The by-content sweep
is what found it — it names `tests/Infrastructure/Unit/`, a *directory* this
package deletes, rather than any single moved file, so a sweep keyed on the 52
paths misses it. It is fixed here because AGENTS.md requires the affected
`src/` README to be updated by the change that invalidates it.

## The reference channels, and how they were derived

Four sweeps, each over the whole tracked tree by content (`git grep`, no
`*.php` restriction), with `docs/internal/plans/**` and `docs/adr/**` excluded
as history rather than addresses and the generated artifacts excluded as
regenerated output:

1. **Declared FQCN** — for each of the 52, `namespace` + class name read from
   the file itself, then `git grep -l -F <FQCN>`.
2. **PHP-escaped FQCN** — the same string with every `\` doubled. This is the
   sweep that reaches `'Qualimetrix\\Tests\\…'` literals inside PHP source, and
   it is the only one that found the generator's three test-ID halves. Sweep 1
   returns nothing for them.
3. **Bare class basename**, word-anchored (`git grep -l -F -w`).
4. **Current path literal** — `git grep -l -F <path>`, which is what reaches a
   hand-written `.txt` fixture and prose.

Closed, seven lines in six files, none of them one of the 52:

| File                                                                        | Line | Shape                      | Found by |
| --------------------------------------------------------------------------- | ---- | -------------------------- | -------- |
| `governance/Channel/Fixtures/declared.txt`                                  | 58   | path in a `#` comment      | 4        |
| `governance/Channel/ChannelDeclarationFixtureDriftTest.php`                 | 40   | `{@see}` FQCN              | 1, 3     |
| `tests/Analysis/Run/Unit/Configuration/ProjectScopeCoverageTest.php`        | 22   | `{@see}` FQCN              | 1, 3     |
| `tests/Infrastructure/Console/Functional/ApplicationRefusalTest.php`        | 19   | path in prose              | 4        |
| `tests/Infrastructure/Console/Functional/ApplicationRefusalTest.php`        | 156  | `{@see}` `FQCN::method()`  | 1, 3     |
| `.../Console/Functional/Command/CheckCommandInputValidationTest.php`        | 347  | `{@see}` FQCN              | 1, 3     |
| `tests/Infrastructure/Console/Integration/RuleExclusionStatsWiringTest.php` | 43   | `{@see}` FQCN, stale epoch | 3 only   |

The last row is the channel the brief named and no prior round had: its FQCN
belongs to no epoch a sweep for a *current* name can reach. Only the bare-name
sweep (3) surfaced it. `p1-fileset.md` had found it while confirming something
else, and its own conclusion was to flag rather than fix.

`governance/Channel/ChannelDeclarationFixtureDriftTest.php` is a **direct**
`{@see}` FQCN reference, not, as `p1-fileset.md` records it, a file whose
carried fixture holds the reference. Both the test and its fixture name the
moved class, in two different spellings, and both are fixed.

Nothing was found in `.gitattributes`, `.githooks/pre-commit`, `.dockerignore`,
`scripts/init-environment.sh`, `phpstan.neon`, `.php-cs-fixer.dist.php` or
`composer.json`: no test *root* moves here, only paths inside `tests/`. That
also closes `p1-fileset.md`'s open "the PHPStan baseline was not checked" item —
the sweep was repository-wide and no `.neon` carries a moved path, and PHPStan
runs green inside `composer check:code`. Verified
by a repository-wide `git grep -n -F` for each of the five emptied directory
prefixes before the first `git mv` — that sweep returned exactly three carriers,
all listed above or in "What changed".

## `phpunit.xml.dist` — the third verification

The plan claims P1 adds and removes no `<directory>`. Verified here by command,
after the moves, against the file's own declarations rather than against a
recollection of them:

```
sed -n 's/.*<directory>\(.*\)<\/directory>.*/\1/p' phpunit.xml.dist | sort -u |
  while read -r d; do echo "$(git ls-files "$d" | wc -l) $([ -d "$d" ] && echo present || echo ABSENT) $d"; done
```

Every declared entry is present on disk and tracks at least one file; the
smallest are `tests/Integration` (2) and `tests/Functional` (2), whose last
remaining files are P2's and P3's, and `tests/Unit` (48). `tests/Infrastructure`
goes 81 → 117 — the 81 measured, not inferred
(`git ls-tree -r --name-only 7d54fb67 -- tests/Infrastructure | wc -l`), because
16 of the 52 were already beneath it and 117 − 52 would name the wrong base. No entry emptied, and every target of the 52 lies under the
already-declared `tests/Infrastructure`. `testSuitePrefixTable()` needs no row
for the same reason: its `tests/Unit/`, `tests/Integration/`, `tests/Functional/`
and `tests/Infrastructure/` prefixes all still match files.

## Definition of Done

| #   | Item                                                                                  | Result                                                                         |
| --- | ------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------ |
| 1   | `php scripts/generate-modular-architecture-test-inventory.php`                        | **exit 0** — 921 artifacts, 725 classes, 9198 cases                            |
| 1   | `move-oracle.py --package=P1 --base=7d54fb67`                                         | **exit 0**, `package P1: 52 rows, allowance now 60` / `agreed`                 |
| 2   | The six per-suite counts                                                              | **6705 / 383 / 152 / 1029 / 179 / 748** — exact                                |
| 3   | `composer architecture:check`                                                         | **exit 0**                                                                     |
| 4   | `composer check:code`                                                                 | **exit 0** (run with nothing else touching the tree)                           |
| 5   | `git status --porcelain` / `git diff --stat -M HEAD`                                  | 52 `R`, 11 `M`, this report untracked; 63 files changed                        |
| 5   | generator diff shape: `git diff HEAD -- <generator> \| grep -c '^-[^-]'` / `'^+[^+]'` | **59 removed, 7 added** — 52 allowance rows plus the 7 rewritten literal lines |

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

Item 3 verbatim: `Checked modular-architecture governance: 955 declarations, 37
semantic-owner layers, 0 seams, 73 exact internal grants -> 13 coarse edges.`

Item 4 verbatim tail: `OK (748 tests, 15503 assertions)` for Governance, the
last of six green suites, plus 150 JS cases and both cross-tool suites.

Item 5: the eleven modifications are the generator, the three generated
artifacts, the six reference carriers and `src/Infrastructure/README.md`. Every
one of the 52 is recorded as `R`, not as a delete plus an add.

## What the judge does not check, measured rather than assumed

Two arms of `move-oracle.py` are weaker on this run than they read. Neither is a
reason to edit the judge; both are compensated here and named so the
orchestrator knows what its own run does and does not prove.

**The old-FQCN sweep (arm 6) cannot see a PHP-escaped literal.** It runs
`git grep -l -F <FQCN>` with single backslashes, and a PHP single-quoted string
spells the same name with `\\`. Measured: the escaped sweep finds three halves
inside the generator that arm 6 returns nothing for. Had those three been left
stale, **the oracle would still have printed `agreed`.** They were found and
fixed by sweep 2 above; the one escaped literal that remains is D3's, and it
remains by decision.

**The "no rename the map does not name" check (arm 2) is vacuous before the
commit.** It reads `git diff --name-status -M <base>..HEAD`, and this package
does not commit, so `HEAD` *is* `7d54fb67` and the rename set it inspects is
empty. Compensated by the same comparison against the working tree:

```
git diff --name-status -M HEAD
```

52 `R` lines, and as a set they are exactly the 52 `(current, target)` pairs of
the map's Infrastructure partition — no extra rename, none missing. The
orchestrator gets the real, committed form of this check when it commits.

## Findings, recorded rather than acted on

- **`P6_D_GIT_TEST_PATHS` is now dead in all three of its consumers** (D2). Its
  entries resolve, so `assertPathLiteralsResolve()` stays green, but no input
  can reach the `in_array()` branches any more: the test-class parse precedes
  them. The same will be true of `P6_D_REPORTING_TEST_PATHS` after P2 and of
  `P6_D_PRIORITIZATION_TEST_PATHS` and `P7_MEASUREMENT_PATHS` for whatever part
  of their contents is `*Test.php`. P4 is the package that can sweep this once.

- **The `tests/Infrastructure/Logging/Unit/` comment in `dispositionFor()` is
  now false.** It reads "its `Infrastructure/{Subject}/Unit` siblings (Cache,
  Console, Parallel, Rule, ...) do not have that decision yet" — this package
  materializes exactly those siblings. The branch itself is also unreachable for
  any `*Test.php` beneath that prefix. Outside this package's derived set (a
  generic comment names no map row), so flagged, not edited.

- **`P6_LIVE_ADDED_TEST_IDS` and `P6_RENAMED_TEST_IDS` are referenced by
  nothing.** `grep` over the repository finds only their declarations; no
  function reads either. They are bookkeeping that no control exercises, which
  is why their literals rot silently — which is exactly what the next finding
  shows.

- **The earlier-epoch channel has at least two more members, both in
  `P6_RENAMED_TEST_IDS` row 3.** Its value half reads
  `Qualimetrix\Tests\Infrastructure\Integration\RuleExclusionStatsWiringTest`,
  but the real namespace of that file today is
  `Qualimetrix\Tests\Infrastructure\Console\Integration` (read from
  `tests/Infrastructure/Console/Integration/RuleExclusionStatsWiringTest.php`).
  The file is not one of the 52 and did not move here, so both halves of that
  row name a class by a spelling from an earlier epoch — the same shape as the
  `{@see}` in D5, and in the same subject. This is the brief's "the channel has
  more members" signal: a stale name is produced wherever a rename lands next to
  a record nothing executes, and neither the FQCN sweep (the name is
  PHP-escaped) nor a path sweep (there is no path) nor the judge (it greps for
  names that *are* current) will surface the next one.
