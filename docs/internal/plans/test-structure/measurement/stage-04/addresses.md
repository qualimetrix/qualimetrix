# Stage 04 — registration addresses, merged from two independent witnesses

Two witnesses enumerated independently and neither was complete. Witness A walked the
stage-03 taxonomy and re-verified each of its 42 addresses against the tree
([`addresses-witness-a.md`](addresses-witness-a.md)). Witness B was forbidden to read
that taxonomy and derived addresses from the code by asking what stops being true when
the three bucket paths and their namespaces cease to exist
([`addresses-witness-b.md`](addresses-witness-b.md)).

**Where they disagreed, and who was right.** Every row below marked `A only` or `B only`
was missed by the other witness; every such row was then re-measured here by the command
in its own row, and all of them held. The two largest:

- **B only** — the set of declared `<directory>` entries that empty out. A named 4 of 7,
  missing `tests/Analysis/Finding/RuleConfiguration/Unit`,
  `tests/Reporting/FindingProjection/Unit` and `tests/Reporting/Formatter/Suppressed/Unit`.
- **A only** — `validateP4Topology()`, which pins one exact target path as a string
  literal. B's sweep was by path prefix and by namespace, and this address carries a path
  that does not exist yet, so no sweep over the current tree could reach it.

A count is not evidence either witness was thorough; the union is the list, and the
"cannot see" section at the end says what the union still misses.

## Addresses

| Address                                                                                                                                                                  | Carrier                                                                                                       | L/S                                                                                                             | Found by             | Verified with                                                                                                                              |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- | -------------------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| 7 declared `<directory>` entries empty out; 3 new level directories are undeclared                                                                                       | `phpunit.xml.dist`                                                                                            | **L** — PHPUnit exits 2 on a directory that is not on disk, and a fresh clone has no empty directory to save it | vanishing: B; new: A | the python block in this file's *Reproducing the counts* section                                                                           |
| `testSuitePrefixTable()` and `currentSuite()` mirror those entries both ways                                                                                             | `scripts/generate-modular-architecture-test-inventory.php`                                                    | **L** via `assertSuiteClassifierAgreesWithPhpunit()`                                                            | A and B              | `php scripts/generate-modular-architecture-test-inventory.php --classification-probe=tests/Core/Unit/VersionTest.php` returns suite `none` |
| `classifyOwner()` — the prefix ladder returns a coarse owner for every post-move Infrastructure and Reporting path                                                       | same                                                                                                          | **S** — the coarse owner is a legal value, nothing refuses it                                                   | A and B              | `--classification-probe=tests/Infrastructure/Git/Unit/GitClientTest.php` → owner `Infrastructure`                                          |
| `targetPath()` — template is `tests/{owner}/{suite}/{basename}`, with no remainder segment                                                                               | same                                                                                                          | **S** — the artifact is regenerated and compared to itself, so a wrong target is self-consistent                | A and B              | `--classification-probe=tests/Reporting/Unit/Formatter/Html/HtmlFormatterTest.php` → drops `Formatter/Html`                                |
| `dispositionFor()` — `^tests/Infrastructure/(Unit\|Integration)/` stops matching once a file moves one level deeper                                                      | same                                                                                                          | **S**                                                                                                           | A and B              | same probe; disposition flips to "move" for a file already in place                                                                        |
| `validateP4Topology()` pins the literal `tests/Infrastructure/Console/Functional/LayerAssignmentCommandTest.php`; the map's target is `.../Functional/Command/Debug/...` | same                                                                                                          | **L** (`fail()`)                                                                                                | **A only**           | `grep -n "LayerAssignmentCommandTest" scripts/generate-modular-architecture-test-inventory.php`                                            |
| `P3_TEST_PATHS` names 2 moving files, `P6_D_REPORTING_TEST_PATHS` 1, `P6_D_GIT_TEST_PATHS` 2                                                                             | same                                                                                                          | **L** via `assertPathLiteralsResolve()`                                                                         | B, then A            | the python block below                                                                                                                     |
| `use Qualimetrix\Tests\Analysis\Finding\RuleConfiguration\Support\FromArrayKeyReader;` in two files that do **not** move                                                 | `governance/RuleOptionKeys/DeclaredOptionKeysCoverReadKeysTest.php`, `scripts/enumerate-rule-option-keys.php` | **L** (class not found)                                                                                         | **A only**           | `git grep -n FromArrayKeyReader -- governance scripts`                                                                                     |
| `use ...\StubChannelPresentation;` in 4 files that all move                                                                                                              | the four Reporting tests                                                                                      | **L**                                                                                                           | A and B              | `git grep -l StubChannelPresentation -- tests`                                                                                             |
| `assertCount(28, tsv('test-orphan-dispositions.tsv'))` and `assertCount(1, tsv('test-system-support-owners.tsv'))`                                                       | `governance/ModularOwnership/ModularArchitectureGovernanceIntegrationTest.php`                                | **L**, but carries neither a path nor a name, so no path sweep reaches it                                       | A and B              | `grep -n assertCount governance/ModularOwnership/ModularArchitectureGovernanceIntegrationTest.php`                                         |
| generated artifacts under `docs/internal/generated/modular-architecture/`                                                                                                | —                                                                                                             | **L** via `composer architecture:check`                                                                         | A and B              | `composer architecture:check`                                                                                                              |

## Checked and found not to apply

| Address                                                                                             | Why not                                                                                        | Verified with                                                                                      |
| --------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| `createIsolatedProject()` copy list                                                                 | copies whole roots (`tests`, `governance`, `tools`, `src`); stage 04 moves only inside `tests` | `sed -n '179,215p' scripts/modular-architecture/tests/ModularArchitectureGeneratorRefusalTest.php` |
| `composer.json` `autoload-dev`                                                                      | one PSR-4 root `Qualimetrix\Tests\ => tests/`; every new namespace is under it                 | `python3 -c "import json;print(json.load(open('composer.json'))['autoload-dev']['psr-4'])"`        |
| `governance/TestSuiteHygiene/namespace-path-allow-list.php` and its `ceiling`                       | none of its 55 rows names a moving file, so no row goes stale and the ceiling does not move    | `php -r '$a=require "governance/TestSuiteHygiene/namespace-path-allow-list.php"; ...'` (0 of 55)   |
| `scripts/phpunit-aggregate.py` `SUITES` and its second copy                                         | holds suite **names**, which do not change                                                     | `grep -n SUITES scripts/phpunit-aggregate.py`                                                      |
| `dirname(__DIR__, N)` in the 4 moving files that use it                                             | each move permutes segments without changing depth                                             | both witnesses computed the arithmetic independently and agreed                                    |
| `phpstan.neon`, `.php-cs-fixer.dist.php`, `.githooks/pre-commit`, `.gitattributes`, `.dockerignore` | all name the `tests` root, which does not move                                                 | `grep -n tests phpstan.neon` and the rest                                                          |

## Reproducing the counts

```python
# 7 vanishing and 3 new <directory>, and the 5 pinned literals
import csv, os, re
rows = list(csv.DictReader(open('docs/internal/plans/test-structure/measurement/stage-04/relocation-map.csv')))
moving = {r['current'] for r in rows}
decl = re.findall(r'<directory>([^<]+)</directory>', open('phpunit.xml.dist').read())
# a declared entry vanishes when every file under it is in `moving`
# a level dir of a target is new when it is neither declared nor under tests/Infrastructure
```

## What the union of both witnesses still cannot see

- **Neither ran the generator on a tree where the files had actually moved.** Every `S`
  verdict above rests on the `--classification-probe` mode and on reading the code, which
  is the same evidence twice. The first move package must therefore regenerate and diff
  the artifact, not assume the probe generalises.
- **Dynamically assembled class names.** Both swept for `use` statements and for literal
  FQCN strings. A name built by concatenation, by reflection, or by `#[CoversClass('...')]`
  written as a string rather than `::class` is invisible to both.
- **Paths carried in data** — YAML, JSON and TSV rows that name a test path. The tracked
  generated artifacts are regenerated and so self-heal; hand-maintained data files were
  not swept.
- **The PHPStan baseline of line-scoped ignores**, if one exists for a moving file.
- **Whether the level segment of any target is right.** That is stage 05's subject; this
  stage moves a wrong level to a new home unchanged.

## Addresses the merged list missed, found by plan review

The two witnesses and this merge all missed the two rows below. They are recorded
here rather than quietly added, because *how* they were missed is the reusable part.

| Address                                                                                                                                                               | Carrier                                                                                                                                                                                                                                                                                                                                                                          | L/S                                                                                                 | Verified with                                                                                                    |
| --------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| 4 `FQCN::method` literals naming a moving test class                                                                                                                  | `scripts/generate-modular-architecture-test-inventory.php`, `P6_LIVE_ADDED_TEST_IDS` and `P6_RENAMED_TEST_IDS`                                                                                                                                                                                                                                                                   | **S** — `assertPathLiteralsResolve()` guards nine path constants and neither of these is among them | match each `'...::...'` literal in the generator against the declared FQCN of every file in `relocation-map.csv` |
| **10** non-moving tracked files name a moving class by its fully-qualified name — `{@see ...}` docblock links and plain references (4 belong to P1, 4 to P2, 2 to P3) | `governance/ConfigurationVocabulary/YamlKeyReachabilityTest.php`, `tests/Analysis/Run/Unit/Configuration/ProjectScopeCoverageTest.php`, `tests/Infrastructure/Console/Functional/ApplicationRefusalTest.php`, `tests/Infrastructure/Console/Functional/Command/CheckCommandInputValidationTest.php`, `tests/Infrastructure/Console/Integration/RuleExclusionStatsWiringTest.php` | **S** — a docblock reference is not resolved by anything                                            | `git ls-files '*.php'` scanned for `Qualimetrix\\+Tests\\+(Unit\|Integration\|Functional)`                       |

**Why they were missed, which is the point.** The first is not a path and not a
namespace — it is a class-plus-method identifier, so a sweep for either misses it.
That is the same spelling this repository's own campaign notes list as invisible to
path-and-name sweeps, and it was still missed here.

The second was missed twice, for two different dull reasons, which is why the count in
the row above is 10 and an earlier version of this section said 5.

First the sweep was written with PCRE-style double escaping against files holding
single backslashes, so it matched nothing, and the empty result was read as "no such
references". A sweep that returns zero is a claim to be falsified before it is
believed — corrected, it returns 128 files.

Then, with the escaping fixed, the sweep pattern was
`Qualimetrix\Tests\{Unit,Integration,Functional}` — which is the **88 legacy-bucket
rows**, while this stage moves **114**. The 26 residue rows carry namespaces like
`Qualimetrix\Tests\Infrastructure\Unit`, so six more referencing files stayed
invisible. Sweeping by the population's *declared class names* rather than by a
namespace prefix returns all 10.

That is the same defect twice at two scales: a pattern that covers a subset of the
population, read as covering the population. The guard is not a better pattern — it is
each move package's DoD grep for the old fully-qualified names of the files **it**
moves, which cannot be narrower than its own set.

**Correction to this document's own opening claim.** The summary above said every
`A only` / `B only` row was re-measured here. That was true of those rows, but the
sentence implied a completeness this merge did not have: rows neither witness
produced were, by construction, not re-measured, and the two above are exactly that.

## Known defects in the witness reports themselves

Recorded rather than silently corrected, because a reader who trusts a witness report
needs to know which of its numbers were checked.

- **Witness B states the population as 91 legacy-bucket rows and 23 residue rows.** The
  map has **88 and 26**. The union of 114 is right in both, so the address work is
  unaffected, but B's per-group figures are not a source.
- **One of witness B's "verified with" cells is false** — it reports a file as absent
  which is present and is row 52 of the map. That row's address verdict still holds;
  the evidence cell does not.
- Neither witness produced the two addresses in the section above. Their absence was
  not detected by either witness's own coverage section, which is the argument for a
  guard in the packages rather than a third enumeration: `04-packages.md` gives every
  move package an old-FQCN grep, and that is what closes the channel.
