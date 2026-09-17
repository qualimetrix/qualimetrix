# Stage 02 forks — facts, not decisions

Scope: facts needed to resolve the four open forks named for stage 02. No
deletion, move, or edit was performed; PHPUnit was run only with
`--list-tests`. Every claim below carries a `file:line` address; where nothing
was found, that is stated explicitly rather than left implicit.

## Fork 1 — deleting the two duplicate controls

### 1. `itRoutesFreshnessOraclesExactlyOnceThroughAggregateCheck`, read whole

`tests/Analysis/Policy/Architecture/Integration/ModularArchitectureGovernanceIntegrationTest.php:33-68`
(body delegates to the private helper `assertFreshnessScriptGraph()` at
`:38-68`).

What it asserts, source by source, all against `composer.json`'s `scripts`
map (`$this->composer()`):

- `scripts.test` equals exactly
  `['Composer\\Config::disableProcessTimeout', 'phpunit --no-coverage --exclude-group=benchmark']`
  (`:43-47`), with the message "Standalone composer test must retain its full
  freshness coverage."
- `scripts['test:aggregate']` equals exactly
  `['Composer\\Config::disableProcessTimeout', 'python3 scripts/phpunit-aggregate.py']`
  (`:48-54`).
- `scripts.selfcheck` equals `['@architecture:check', '@selfcheck:analysis']`
  (`:55`).
- `scripts['selfcheck:analysis']` equals the literal `bin/qmx check` command
  (`:56-59`).
- `scriptSteps($scripts, 'check:code')[2]` equals `'@test:aggregate'` (`:60`).
- `scriptSteps($scripts, 'check:artifacts')` contains `'@architecture:check'`
  and `'@suppression-snapshot:check'` (`:61-62`).
- `scriptSteps($scripts, 'test:cross-tool')` contains the
  `TestRunnerConfiguration` Python discovery command (`:63-66`).
- `scripts['check:self']` equals
  `['@gate:self-test', '@selfcheck:analysis', '@directives:audit']` (`:67`).

**Key finding: this method never names either candidate file or method by
FQCN, string, or path.** It asserts only the shape of `composer.json`'s
script graph — which named scripts exist and which aggregate scripts contain
which steps. Deleting `SuppressionSnapshotFreshnessTest` (variant а),
deleting `itChecksEveryGeneratedProjectionWithoutWriting` (variant б), or
both (variant в) requires **no textual change to this method** under any
variant, as long as `composer.json`'s `suppression-snapshot:check` and
`architecture:check` scripts themselves stay unchanged (which the deletions
do not touch — they remove PHPUnit tests, not composer scripts).

Note the group attribute placement:
`ModularArchitectureGovernanceIntegrationTest.php:19` carries
`#[Group('live-freshness')]` immediately above `itChecksEveryGeneratedProjectionWithoutWriting`
(`:21`) — **not** above `itRoutesFreshnessOraclesExactlyOnceThroughAggregateCheck`
(`:33`), which carries no group and runs under every suite including
`composer check`.

### 2. `SILENTLY_EXCLUDED` and the two guard methods

`governance/TestSuiteHygiene/TestFilesAreExecutedTest.php:110-115`:

```
private const SILENTLY_EXCLUDED = [
    'Qualimetrix\Tests\Analysis\Policy\Architecture\Integration\ModularArchitectureGovernanceIntegrationTest'
        . '::itChecksEveryGeneratedProjectionWithoutWriting',          // :111-112
    'Qualimetrix\Tests\Reporting\Formatter\Suppressed\Integration\SuppressionSnapshotFreshnessTest'
        . '::itMatchesAFreshSelfAnalysisOfSrc',                         // :113-114
];
```

Both guards work off the same diff (`excludedIds()` at `:545-548`, computed
as `reachableIds() \ executedIds()`, i.e. what a suite could run minus what
`composer check` actually runs):

- `itNamesEveryCaseTheRunnerExcludesFromCheck` (`:170-180`) reddens when
  `excludedIds()` contains an id **not** in `SILENTLY_EXCLUDED`
  (`undeclaredExclusions()`, `:522-525`).
- `itCarriesNoStaleSilentExclusionDeclaration` (`:183-194`) reddens when
  `SILENTLY_EXCLUDED` contains an id **no longer** in `excludedIds()`
  (`staleExclusions()`, `:533-536`).

Consequences per variant, all confirmed against the live PHPUnit method
identity `SuppressionSnapshotFreshnessTest` has exactly one method
(`itMatchesAFreshSelfAnalysisOfSrc`, `tests/Reporting/Formatter/Suppressed/Integration/SuppressionSnapshotFreshnessTest.php:24-25`):

| Variant                                                                                           | What happens if `SILENTLY_EXCLUDED` is left untouched                                                                                                                                                                                                                                                                                                                 | Fix required                                                              |
| ------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------- |
| (а) delete only `SuppressionSnapshotFreshnessTest`                                                | line `113-114` names an id that no longer exists at all, so it is no longer in `excludedIds()` → `itCarriesNoStaleSilentExclusionDeclaration` reddens (stale)                                                                                                                                                                                                         | remove the `113-114` entry                                                |
| (б) delete only the duplicate method                                                              | line `111-112` becomes stale the same way → `itCarriesNoStaleSilentExclusionDeclaration` reddens                                                                                                                                                                                                                                                                      | remove the `111-112` entry                                                |
| (в) delete both                                                                                   | both entries stale simultaneously → same guard reddens with two names                                                                                                                                                                                                                                                                                                 | remove both entries (constant becomes `[]`)                               |
| plain **move** (FQCN changes `Qualimetrix\Tests\...` → `Qualimetrix\Governance\...`, no deletion) | the **old** FQCN string is now stale (`itCarriesNoStaleSilentExclusionDeclaration` reddens) **and**, because the method still carries `#[Group('live-freshness')]` under its **new** FQCN, it is still excluded from `composer check` under that new name, which `SILENTLY_EXCLUDED` does not declare → `itNamesEveryCaseTheRunnerExcludesFromCheck` **also** reddens | rewrite both string literals to the new `Qualimetrix\Governance\...` FQCN |

One more consequence of variant (в) specifically: with both `live-freshness`
methods deleted, no `#[Group('live-freshness')]` case remains anywhere in
the tree, so `scripts/phpunit-aggregate.py:51`'s
`--exclude-group=live-freshness` flag stops excluding anything — it becomes
an inert exclusion flag, the same class of defect the memory note on inert
suppression (`inert_suppression.md`) describes for a config key whose subject
disappeared. This is not a failing check (nothing currently asserts the
group is non-empty), but it is a residue worth naming if variant (в) is the
one chosen.

The class-level docblock of `TestFilesAreExecutedTest.php` also names both
classes in prose at `:84-85` and `:94` (`SuppressionSnapshotFreshnessTest`
and `ModularArchitectureGovernanceIntegrationTest`); these are documentation
sentences, not assertions, so they do not fail a run, but they go stale in
the same commit under any of the four scenarios above and should be updated
for the same reason a comment tracks the code it describes.

### 3. What bare `composer test` loses

`composer.json` (`scripts.test`, confirmed via `python3 -c "import json..."`):
`scripts.test = ['Composer\\Config::disableProcessTimeout', 'phpunit --no-coverage --exclude-group=benchmark']`
— this excludes only the `benchmark` group, **not** `live-freshness`. This is
the *only* composer entry point that runs these two methods at all:
`scripts['test:aggregate']` runs `scripts/phpunit-aggregate.py`, which passes
`--exclude-group=live-freshness` explicitly (`scripts/phpunit-aggregate.py:51`),
and `composer check` → `check:code` → `test:aggregate` inherits that
exclusion (`composer.json`, `check:code` = `['@cs-check', '@phpstan',
'@test:aggregate', '@test:js', '@test:cross-tool']`).

Deleting either or both PHPUnit methods therefore removes assertions that
exist **only** under bare `composer test` today; nothing in `composer check`
loses coverage, because `composer check` never executed them in the first
place. The facts each method checked are covered under `composer check`
by a non-PHPUnit script, both already members of `check:artifacts`
(`composer.json`, `check:artifacts` =
`['@architecture:check', '@enumeration:renames:check',
'@enumeration:runtime-channels:check', '@enumeration:directives:check',
'@suppression-snapshot:check', '@input-doors:grid:check',
'@promise-effect:p1-set:check', '@promise-effect:grid:check']`):

- for `SuppressionSnapshotFreshnessTest`: `suppression-snapshot:check` =
  `php scripts/generate-suppression-snapshot.php --check` (`composer.json`).
- for `itChecksEveryGeneratedProjectionWithoutWriting`: `architecture:check`
  = `php scripts/generate-modular-architecture.php --check`, which is the
  exact command the deleted method itself shells out to
  (`ModularArchitectureGovernanceIntegrationTest.php:23-27`).

Both scripts are confirmed present in `check:artifacts`, and `check:artifacts`
is one of the four groups composing `composer.json`'s `check` script
(`['@check-leaks', '@check:code', '@check:docs', '@check:artifacts', '@check:self']`).
So `composer check` keeps equivalent freshness coverage through the
generator script either way; only the bare-`composer test` PHPUnit assertion
disappears.

### 4. Expanded-case counts

Command run: `vendor/bin/phpunit --configuration=phpunit.xml.dist
--testsuite=Integration --list-tests --no-coverage --exclude-group=benchmark`
(both files live under the `Integration` suite:
`phpunit.xml.dist:65` and `:78`). 717 lines listed, exit 0.

Splitting each `- Class::method[data-set]` line at the **first** `::`:

- `SuppressionSnapshotFreshnessTest::itMatchesAFreshSelfAnalysisOfSrc` — 1
  expanded case (no data provider; confirmed the class declares only this
  one `#[Test]` method).
- `ModularArchitectureGovernanceIntegrationTest::itChecksEveryGeneratedProjectionWithoutWriting`
  — 1 expanded case (no data provider).

So: variant (а) removes 1 case, (б) removes 1 case, (в) removes 2 cases —
from a suite that, before any removal, lists 717 expanded cases total.

---

## Fork 2 — channel-fixture directory readers

Directory: `tests/Analysis/Finding/Fixtures/Channels/` contains
`computed-producers.txt`, `declared.txt`, `excluded.txt`, `observed-levels.tsv`,
`order.txt` (confirmed by `ls -la`).

### 1-2. Readers, by file, with TSV verdict/group (path/class/proposed_group
columns from `docs/internal/plans/test-structure/measurement/controls-verdict.tsv`)

Actual readers (open the file at runtime, not just mention it in prose):

| Reader                                                                                                                                                                                  | Fixture(s) it reads                                    | TSV verdict → group                                                                                 |
| --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------ | --------------------------------------------------------------------------------------------------- |
| `tests/Analysis/Finding/Integration/ChannelOrderFixtureDriftTest.php:37` (`FIXTURE = '/Fixtures/Channels/order.txt'`)                                                                   | `order.txt`                                            | `repo-control` → `Channel`                                                                          |
| `tests/Analysis/Finding/Integration/ChannelDeclarationFixtureDriftTest.php:242,313` (`\dirname(__DIR__) . '/Fixtures/Channels/excluded.txt'` and `.../declared.txt`)                    | `excluded.txt`, `declared.txt`                         | `repo-control` → `Channel`                                                                          |
| `tests/Analysis/Finding/Integration/ChannelLevelDeclarationDriftTest.php:54,56` (`OBSERVATION_ORACLE` = `.../observed-levels.tsv`, `DECLARED_CHANNELS` = `.../declared.txt`)            | `observed-levels.tsv`, `declared.txt`                  | **not present in the 81-row TSV at all** — see caveat below                                         |
| `tests/Analysis/Finding/Integration/ChannelUniverseCoverageTest.php:468` (`\dirname(__DIR__) . '/Fixtures/Channels/' . $name`) plus `:334` (`linesOfFixture('computed-producers.txt')`) | `declared.txt` (via `$name`), `computed-producers.txt` | **not present in the 81-row TSV at all**                                                            |
| `tests/Analysis/Finding/Integration/ChannelEmissionStaticGuardTest.php:1036` (`\Fixtures/Channels/excluded.txt`)                                                                        | `excluded.txt`                                         | `repo-control` → `Channel`                                                                          |
| `tests/System/DocumentationConsistency/Integration/ChannelPublicationConsistencyTest.php:420` (`readFile('tests/Analysis/Finding/Fixtures/Channels/declared.txt')`)                     | `declared.txt`                                         | `repo-control` → `Channel`                                                                          |
| `scripts/finding-gate/ChannelWitness.php:38` (`FIXTURE = 'tests/Analysis/Finding/Fixtures/Channels/declared.txt'`)                                                                      | `declared.txt`                                         | outside `tests/`; a `scripts/finding-gate` tooling file, not in the controls-verdict TSV population |
| `scripts/finding-gate-controls/Controls.php` (6 hits: `:219,591,782,845,1049,1511`)                                                                                                     | `declared.txt`                                         | outside `tests/`; `scripts/finding-gate-controls` tooling, not in the TSV population                |

Mention-only (docblock/prose, **not** a runtime read — verified by grepping
each file for `Fixtures|FIXTURE|readFile|file_get_contents|__DIR__` and
finding only the prose hit):

- `tests/Analysis/Finding/Integration/ChannelCoverageTest.php:71` — prose
  only, no file read anywhere in the file.
- `tests/Integration/DependencyInjection/RuleRegistrationDriftTest.php:33` —
  prose only (`RuleRegistrationDriftTest.php:72` reads `src/`, not the
  fixture directory). TSV: `repo-control` → `RuleDeclaration`, but that
  verdict is not driven by this fixture.
- `src/Analysis/Evidence/CodeSmell/AbstractCodeSmellRule.php:101`,
  `src/Analysis/Finding/Contract/ChannelDeclarationRegistryInterface.php:62`,
  `src/Infrastructure/DependencyInjection/Configurator/DesignConfigurator.php:22`
  — docblock references inside `src/`, no read.

**Caveat (explicit, per instructions):** `ChannelLevelDeclarationDriftTest.php`,
`ChannelUniverseCoverageTest.php` and `ChannelCoverageTest.php` are absent
from both `controls-verdict.tsv` (82 lines total, 81 verdicts, confirmed by
`wc -l` and a `grep` that returned zero hits for all three class names) and
from `docs/internal/plans/test-structure/measurement/stage-02/case-census.tsv`
(same zero-hit grep). This means they were not part of the 81-file audited
population and, on current evidence, are ordinary product tests that stay in
`tests/` — but this is inferred from absence, not a verdict anyone wrote
down. **Not verified** against any authoritative "in/out of stage 02 scope"
list beyond the two TSVs checked.

### 3. Hardcoded error-message strings naming the fixture path literally

- `tests/Analysis/Finding/Integration/ChannelOrderFixtureDriftTest.php:49` —
  inside an `assertSame()` failure message: `"...no longer matches"
  . " tests/Analysis/Finding/Fixtures/Channels/order.txt. That order is
  published..."`.
- `tests/Analysis/Finding/Integration/ChannelDeclarationFixtureDriftTest.php:57`
  — `sprintf('Channel "%s" is statically declared in code but missing from
  tests/Analysis/Finding/Fixtures/Channels/declared.txt. ...', $key)`.
- `tests/Analysis/Finding/Integration/ChannelDeclarationFixtureDriftTest.php:80`
  — `sprintf('tests/Analysis/Finding/Fixtures/Channels/declared.txt lists
  "%s", but no rule declares it any more — remove the stale line (or move
  it to excluded.txt ...)', $key)`.

Any of these three lines needs its literal path text rewritten if the
directory moves, independent of whether the *file the message lives in*
itself relocates (a moved test still prints a now-wrong path in its own
failure message unless edited).

### 4. Non-PHP references

Checked: `.gitattributes`, `.dockerignore`, `phpstan.neon`,
`.php-cs-fixer.dist.php`, `phpunit.xml.dist`, `composer.json` — zero hits for
`Channels` or `Finding/Fixtures` in any of them (`grep -n` on each, empty
output).

`scripts/*.sh`, `scripts/*.py` — zero hits (`grep -rl` empty).

`finding-gate/README.md:143` — one hit: a table row documenting
`` `tests/…/Fixtures/Channels/declared.txt`, `<levels>` `` as one of the
gate's inputs. This is prose documentation, not code; it needs updating on
any move but does not fail a machine check.

### 5. Two more machine-checked pins, found while re-checking `scripts/`

`scripts/generate-modular-architecture-test-inventory.php:167-170`:

```
const P6_A_FINDING_TEST_PATHS = [
    'tests/Analysis/Finding/Fixtures/Channels/declared.txt',
    'tests/Analysis/Finding/Fixtures/Channels/excluded.txt',
    ...
```

This constant is one of nine closures fed to `assertPathLiteralsResolve()`
(`:1713-1721`), whose own docblock (`:1700-1711`) states both directions are
checked: "a closure entry must exist, and a retired entry must not." So
`declared.txt` and `excluded.txt` are pinned by literal string a **third**
time (beyond the 57-path `test-ownership.tsv` row for the directory itself
and the `finding-gate-controls`/`ChannelWitness.php` occurrences already
listed above) — moving the directory without editing these two literals
reddens `assertPathLiteralsResolve()` loudly (it is a hard assertion, not a
generated-artifact diff).

`scripts/generate-modular-architecture-test-inventory.php:763-765`:

```
if (str_starts_with($path, 'tests/Fixtures/Channels/')) {
    return ['Analysis/Finding', 'P6'];
}
```

Note this branch matches `tests/Fixtures/Channels/`, **not**
`tests/Analysis/Finding/Fixtures/Channels/` — confirmed no directory named
`tests/Fixtures/Channels` exists on disk (`ls` returns "No such file or
directory"). This branch is therefore live code inside `classifyOwner()`
that no path on disk reaches today; per that function's docblock (`:1093-1104`
in context), `classifyOwner()`'s literals classify "an arbitrary input path,
including pre-migration ones," and are deliberately excluded from
`assertPathLiteralsResolve()`'s existence check — so this branch will not
redden on its own regardless of the fork's outcome. Flagging it as a
probable dead branch from an earlier directory rename; **not verified**
whether any `--classification-probe=` input or historical fixture still
relies on it.

Also checked, per the task's item 4: `docs/internal/generated/modular-architecture/test-fixture-directories.tsv:12`
pins the directory itself: `tests/Analysis/Finding/Fixtures/Channels	Analysis/Finding	P8	"Move atomically with the owning subject."`
— a fourth generated/checked address for the same directory, alongside
`test-ownership.tsv`'s per-file rows (Fork 4 §5 below covers the full
generated-inventory sweep for the 57 controls; this row is the fixture
*directory's* own entry, which sits outside that 57-path set because the
directory itself is not one of the 40 repo-control / 17 mixed files).

---

## Fork 3 — `DocumentationConsistencyTest` split by subject

File: `tests/System/DocumentationConsistency/Integration/DocumentationConsistencyTest.php`
(872 lines). TSV row (`controls-verdict.tsv` line matching this path):
`repo-control | whole | DocumentationCensus | "fourteen censuses of the docs
across different subjects..."`.

### 1. Every `#[Test]` method, its subject, and the populations it compares

| #   | Method (line of `#[Test]`)                                                | Subject / what is compared                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| --- | ------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | `itIndexesEveryActivePlanDirectory` (`:56`)                               | Plan-index census: every directory under `docs/internal/plans` vs. the links `docs/internal/plans/README.md` lists (`:57-79`).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| 2   | `itKeepsExecutableSourcesIndependentFromPlanningRecords` (`:81`)          | Placement law: no file under `bin/scripts/src/tests` may reference a concrete plan path, a plan-local filename, or a package-chronology marker (`X12`, `Р5`, …) outside a comment (`:82-137`). **Its scan roots are hardcoded at `:84`: `$roots = ['bin', 'scripts', 'src', 'tests']` — `governance/` is absent.** After stage 02 moves 40 files there, this method silently stops scanning them: exactly the failure mode the plan text names for `ScratchPathsCarryRealEntropyTest::ROOTS` ("narrower scope, green run," `02-controls-extraction.md`, "Registration — the part that leaks" section). Whichever group this method ends up in, `governance` must be added to `$roots` in the same commit as the first file lands there, or the guard goes quiet rather than red. Also note `$self = __FILE__` at `:86` (self-exemption from its own scan): if this method's file splits, the exemption must follow whichever new file still declares `PLAN_LOCAL_REFERENCE_PATTERN`/`PACKAGE_CHRONOLOGY_PATTERN` literals, or the method would flag its own constants. |
| 3   | `itRecognizesPlanningChronologyOnlyInsideComments` (`:139`)               | Self-test of the comment-extraction helpers (`commentContent()`, the two regex constants) against hand-built strings (`:140-157`). **This method invents its own expected side — it reads no repository state** (no `readFile()`, no filesystem walk); by the plan's own criterion ("is the expected side invented by the test, or copied from the repository?") it looks like a `product-test` companion of method 2, not a census on its own.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        |
| 4   | `itDocumentsAllRuleNamesInDefaultThresholds` (`:162`)                     | Every rule `NAME` constant (via `scanRuleClasses()`/`collectAllRuleNames()`) vs. `website/docs/reference/default-thresholds.md` text (`:163-194`).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| 5   | `itDocumentsAllCliAliasesInConfigurationReadme` (`:200`)                  | Every `#[CliAlias]` (via `collectAllCliAliases()`) vs. `src/Analysis/Configuration/README.md` (`:201-233`).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| 6   | `itDocumentsLayerViolationCliAliasesInWebsiteCliReferences` (`:241`)      | Same alias census, filtered to `architecture.layer-violation`, vs. both language variants of `website/docs/usage/cli-options*.md` (`:242-270`).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        |
| 7   | `itValidatesReadmeYamlExamples` (`:275`)                                  | YAML blocks in `README.md` parse, and any `rules:` keys they use are real rule names (`collectAllRuleNames()`) (`:276-302`).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| 8   | `itListsAllRulesInLlmsOnlyRuleCatalog` (`:309`)                           | The `<!-- llms-only -->` slug block in `website/docs/rules/index.md` vs. `collectAllRuleNames()` (`:310-349`).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| 9   | `itUsesRegisteredFormattersInHealthScoreDocumentation` (`:356`)           | `--format=` examples in `health-scores.md`/`.ru.md` vs. the real `FormatterRegistryInterface` built from the DI container (`:357-379`).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| 10  | `itPublishesTheDerivedBaselineCountTupleExactlyOnce` (`:387`)             | `qmx-baseline.json` entry/group count vs. the one canonical publication in `docs/ARCHITECTURE.md` (`:388-394`, uses `readBaselineEntries()`/`readBaselineCountPublications()`).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        |
| 11  | `itRejectsBaselineOnlyBaselineCountDrift` (`:400`)                        | Mutation proof: a changed baseline with unchanged docs must fail the oracle (`:401-414`).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| 12  | `itRejectsDocumentationOnlyBaselineCountDrift` (`:420`)                   | Mutation proof: changed doc tuple with unchanged baseline must fail (`:421-442`).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| 13  | `itRejectsMissingMalformedAndDuplicateBaselineCountPublications` (`:448`) | Mutation proof: missing/malformed/duplicate publication text must fail (`:449-468`).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| 14  | `itRejectsExtraBaselineCountPublicationsInSemanticReadmeAndAdr` (`:474`)  | Mutation proof: an extra tuple appearing in a semantic README or an ADR must fail (`:475-493`).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        |

14 `#[Test]` methods total (the file's own docblock at `:22-29` and the TSV
note both say "fourteen censuses" — confirmed by `grep -c '#\[Test\]'` = 14
and by the `--list-tests` output below).

### 2. Candidate grouping, reusing existing `proposed_group` names where the
subject matches

- **Methods 4, 5, 6, 7, 8** (rule-name / CLI-alias / YAML-example / catalog
  documentation) all quantify "every registered rule has property Y in some
  documentation carrier." The existing `RuleDeclaration` group already holds
  exactly this shape of census — e.g. `tests/Analysis/Finding/Integration/RuleDocsPageCoverageTest.php`
  is described in the TSV as "a census over all 48 registered rules ... their
  pages under website/docs and the anchors on them" (`controls-verdict.tsv`,
  row for that path). **`RuleDeclaration` fits methods 4-8 directly.**
- **Methods 10-14** (baseline-count publication and its four mutation
  proofs) all quantify over `qmx-baseline.json` as a tracked artifact vs. its
  published count. The existing `RatchetArtifact` group already holds
  `tests/Analysis/Evidence/Measurement/Integration/Identity/RatchetKeyGrammarTest.php`,
  described as "the named method quantifies over the keys of the tracked
  `qmx-baseline.json`" (`controls-verdict.tsv` row). **`RatchetArtifact` is
  the closest existing fit**, though its current sole member is about *key
  grammar*, not *entry count published in docs* — same tracked file, adjacent
  but not identical sub-subject; flagging this as a judgment call rather than
  a clean match.
- **Method 9** (formatter names in health-score docs) has no clean existing
  match. `FormatOptionKeys` is the nearest carrier-sibling (also under
  `Reporting/Formatter`, also a two-way census — `tests/Reporting/Formatter/Unit/FormatOptionKeyDeclarationTest.php`
  is "a two-way census: every declared format key is read by its own
  formatter tree and vice versa"), but its subject is *option keys*, not
  *formatter existence*. No group cleanly owns "documented formatter name
  exists in the registry."
- **Methods 1, 2, 3** (plan index, planning/code independence, the
  chronology-regex self-test) have no existing group at all among the 11
  named in the plan (`Channel, RuleDeclaration, SolePrimitiveOwnership,
  ThresholdKeys, ModularOwnership, TestSuiteHygiene, ConsoleComposition,
  RepositoryEntrypoints, RuleOptionKeys, Occurrence`, plus the 9 named
  singletons) — none is about `docs/internal/plans/`. A new group name would
  be needed if these are kept as `repo-control` at all; method 3 in
  particular may not belong in `governance/` under the plan's own criterion
  (see row 3 above).

This grouping is a candidate reading of the evidence, not a decision — the
task asked for facts, and the "no existing group fits" cases above are
exactly the facts that make the choice non-mechanical.

### 3. Shared helpers/constants and what a split duplicates

- `private static string $projectRoot` + `setUpBeforeClass()` (`:49-54`) and
  `readFile()` (`:749-758`) are used by essentially every method (all except
  method 3, which builds no file path). A class split into N files each
  needs its own copy of this ~10-line root-resolution boilerplate, or a
  shared trait/base class introduced solely for the split (which the plan's
  "no generic lifecycle port" principle and ADR 0022 would treat as a new
  contract decision, not a free extraction).
- `scanRuleClasses()` / `collectAllRuleNames()` / `collectAllCliAliases()`
  (`:500-612`) are used only by methods 4-8 — moving as a unit with them
  duplicates nothing extra.
- `commentContent()`, `hashCommentsOutsideStrings()`,
  `javascriptCommentsOutsideStrings()`, `PLAN_LOCAL_REFERENCE_PATTERN`
  (`:45`), `PACKAGE_CHRONOLOGY_PATTERN` (`:47`) are used only by methods 2
  and 3 — also move as a self-contained unit.
- `BASELINE_COUNT_PUBLICATIONS` (`:35-40`), `SEMANTIC_DOCUMENTATION_ROOTS`
  (`:43`), `readBaselineEntries()`, `readBaselineCountPublications()`,
  `baselineCountPublicationErrors()`, `isExplicitlyHistoricalDocumentation()`
  (`:617-747`) are used only by methods 10-14 — self-contained unit.
- Method 9 uses `ContainerFactory`/`FormatterRegistryInterface` (`:359-361`)
  — nothing else in the file touches the DI container, so it is already
  isolated; no duplication cost either way.
- Net duplication cost of a full subject split: the `$projectRoot`/`readFile()`
  pair repeated across roughly 3-4 new classes (rule-declaration group,
  ratchet-artifact group, the formatter method, the planning-records group)
  — each a small (~10-15 line) repeat, not a structural cost.

### 4. Expanded cases per method

Command: `vendor/bin/phpunit --configuration=phpunit.xml.dist
--testsuite=Integration --list-tests --no-coverage --exclude-group=benchmark`
(same run as Fork 1; the file is under the `Integration` suite via
`phpunit.xml.dist:64`). All 14 methods appear exactly once each in the
717-line listing, splitting on the first `::` — no data providers anywhere
in this file (confirmed: zero `#[DataProvider]` occurrences via `grep`).
**1 expanded case per method, 14 total.**

---

## Fork 4 — cross-tree imports and the allow-list

### 1. The two hardcoded-support-class imports

**`FromArrayKeyReader`** —
`tests/Analysis/Finding/RuleConfiguration/Support/FromArrayKeyReader.php`.
Consumers, found by `grep -rl FromArrayKeyReader tests/ src/ governance/`:
only `tests/Analysis/Finding/RuleConfiguration/Unit/DeclaredOptionKeysCoverReadKeysTest.php`
(uses it at `:73,116,219`). TSV verdict for that test file: `repo-control |
whole | RuleOptionKeys` (`controls-verdict.tsv` row, line 30). **Single
consumer, and that consumer moves whole** — no orphan risk; the support class
can move alongside it into `governance/RuleOptionKeys/Support/` (or
equivalent) without leaving anything behind in `tests/`.

`FromArrayKeyReader.php` itself is already pinned as its own row in
`docs/internal/generated/modular-architecture/test-ownership.tsv:327`
(kind `support`, target column already reads
`tests/Analysis/Finding/Support/FromArrayKeyReader.php` — a **different**
target path than `governance/...`, left over from an earlier, unrelated P8
relocation plan for `Analysis/Finding`). This row sits outside the 57-path
set this task scoped (it is a support file, not one of the 40 repo-control /
17 mixed test classes), but moving it to `governance/` regenerates this row
too, and its currently-recorded target disagrees with a `governance/` move —
a fact worth carrying into whichever plan actually executes the relocation.

**`ChannelRenameTsvCorpus`** —
`tests/Analysis/Policy/Baseline/Fixtures/ChannelRenameTsvCorpus.php`.
Consumers, found the same way: `tests/Analysis/Policy/Baseline/Unit/ChannelRenameMapTest.php`
and `tests/Analysis/Policy/Baseline/Unit/ChannelRenameTsvGateAgreementTest.php`.
A third hit, `src/Analysis/Policy/Baseline/ChannelRenameMap.php:25`, is a
`@see` docblock reference only, not a runtime `use`/consumption (verified by
reading the surrounding lines — it is inside a class-level PHPDoc comment).

`ChannelRenameMapTest.php` is `mixed` in the TSV (row 49): only
`itReadsTheRepositorysOwnDeclaredChannelMap` is `repo-control` → `Channel`;
its other three methods are `product-test` and stay. Read directly
(`ChannelRenameMapTest.php:64-74`): the moving method reads
`finding-gate/maps/channels.tsv` straight off disk via
`ChannelRenameMap::fromFile()` and **does not reference
`ChannelRenameTsvCorpus` at all** — that class is used only by
`itAnswersTheSharedCorpusAsDeclared` and `itReadsTheRowsItAccepted` in the
same file (staying, product-test) and by `ChannelRenameTsvGateAgreementTest`,
which the plan text explicitly reclassified as `tooling-test` (row 50: "the
single method runs the reader from scripts/finding-gate over a corpus
declared by the test fixture; it never opens the real channels.tsv") and
therefore stays in `tests/` entirely, unmoved. **`ChannelRenameTsvCorpus.php`
has zero consumers among anything that moves — it does not need to relocate
and cannot become orphaned by the move.**

### 2. No import barrier between `Qualimetrix\Tests\` and `Qualimetrix\Governance\` found

Checked, per the task's list:

- `scripts/generate-modular-architecture-test-inventory.php` — no rule
  restricting one dev PSR-4 root from importing the other; `classifyOwner()`,
  `dispositionFor()`, and `targetPath()` treat any `governance/`-prefixed
  path generically (see fork-4 §4 below) and say nothing about `tests/`
  importing from it or vice versa.
- `scripts/generate-modular-architecture-production-inventory.php:993-996` —
  the only relevant control found, and it points the other way: `const
  DEVELOPMENT_NAMESPACE_PREFIXES = ['Qualimetrix\\Tests\\',
  'Qualimetrix\\Governance\\']`, used at `:998-1006` to forbid **`src/`**
  (production) from importing **either** dev root. It does not forbid the two
  dev roots from importing each other.
- `phpstan.neon:13-14` — lists `tests` and `governance` as sibling scan
  paths; no cross-root rule (`grep -n "Governance" phpstan.neon` → only this
  one hit).
- `tools/phpstan/Rules` — `grep -rn "Governance\|Qualimetrix.Tests"
  tools/phpstan/Rules` returns nothing (its three files,
  `BannedStringPathPromotedPropertyRule.php`, `BannedStringPathPropertyRule.php`,
  `PathPropertyMatcher.php`, mention neither namespace).
- `qmx.yaml`, `docs/internal/modular-architecture-manifest.json` — zero
  occurrences of `Governance` in either file (`grep -c` on the manifest
  returns 0).

**Conclusion: no check forbids a `Qualimetrix\Tests\...` class from importing
a `Qualimetrix\Governance\...` class, or the reverse, in either direction.**
This was checked by grep across the five named locations plus the production
inventory script; it was not checked by attempting an actual cross-import and
running PHPStan/architecture:check against it, so treat "no check found" as
the evidence, not as a guarantee that no other seam exists.

### 3. Allow-list hits among the 57 target paths

`governance/TestSuiteHygiene/namespace-path-allow-list.php` has 60 rows
(`'ceiling' => 60` at `:20`, confirmed by parsing every `'<path>' =>` line).
Of the 57 target paths (40 `repo-control` + 17 `mixed`, counted directly from
`controls-verdict.tsv` by `class` column), **exactly 2** already appear in the
allow-list:

```
'tests/System/DocumentationConsistency/Integration/ChannelPublicationConsistencyTest.php'
    => 'Qualimetrix\\Tests\\Integration\\Documentation',   // namespace-path-allow-list.php:78
'tests/System/DocumentationConsistency/Integration/DocumentationConsistencyTest.php'
    => 'Qualimetrix\\Tests\\Integration\\Documentation',   // namespace-path-allow-list.php:79
```

Both already declare a namespace that mismatches their current path (path
says `System/DocumentationConsistency/Integration`, declared namespace says
`Integration\Documentation`) — a pre-existing PSR-4 violation the G3 guard
already tracks. Moving either file to `governance/...` requires either
correcting the namespace to match the new path (removing the row, lowering
the list below its current 60-row measured state) or updating the row to
name the new path with the same wrong namespace (keeping the violation
alive under its new address). Leaving the row pointing at the old,
now-nonexistent path would make it "a row that no longer describes a
violation" — exactly what `TestFilesAreExecutedTest`'s sibling guard,
`NamespacePathAllowList.php:19-20,42`, calls out as a stale row a strict
guard refuses.

### 4. `testSuitePrefixTable()` and what is literally tied to
`governance/TestSuiteHygiene/`

Full function, `scripts/generate-modular-architecture-test-inventory.php:1028-1074`:
40 explicit `['prefix' => ..., 'suite' => ...]` rows, ending with:

```
['prefix' => 'governance/TestSuiteHygiene/', 'suite' => 'Governance'],   // :1072
```

`currentSuite()` (`:1076-1091`) walks this table with `str_starts_with()` and
returns `'none'` if nothing matches. **Adding a new group
`governance/Channel/` requires one new row here**, e.g. `['prefix' =>
'governance/Channel/', 'suite' => 'Governance']`, or the new files classify
as suite `'none'` and `assertSuiteClassifierAgreesWithPhpunit()`
(`:1106-1127`, invoked from the artifact-freshness check) reddens because a
`<directory>` entry that exists in `phpunit.xml.dist` under `Governance`
would classify to a different suite here.

`assertSuiteClassifierAgreesWithPhpunit()` checks both directions
(`:1099-1104`): forward, every `<directory>` in `phpunit.xml.dist` must
classify to the suite name it is declared under; backward, every
`testSuitePrefixTable()` literal must have a matching `<directory>` declared
for that same suite. This means the new `testSuitePrefixTable()` row and the
matching `<directory>governance/Channel</directory>` entry in
`phpunit.xml.dist`'s `Governance` `<testsuite>` must land in the **same
commit** — adding either one alone reddens this check from the direction
that is missing.

Checked the other three functions the task named, against the literal
`governance/TestSuiteHygiene/` string specifically:

- `currentSuite()` (`:1076-1091`) — no literal beyond what it inherits from
  `testSuitePrefixTable()`; not separately tied to `TestSuiteHygiene`.
- `classifyOwner()` (`:655-745`) — its governance branch is generic:
  `if (str_starts_with($path, 'governance/')) { return
  ['Architecture.Governance', 'P8']; }` (`:660-662`). **Not** tied to
  `TestSuiteHygiene` literally; a new `governance/Channel/...` path already
  classifies correctly with zero edits.
- `dispositionFor()` (`:1145-1179`) — same pattern: `if
  (str_starts_with($path, 'governance/')) { return 'Retain at the
  materialized subject-owned path.'; }` (`:1150-1152`). Generic, no edit
  needed for a new group.
- `targetPath()` (`:1192-1232`) — same pattern: `if (str_starts_with($path,
  'governance/')) { return $path; }` (`:1194-1196`). Generic, no edit
  needed.

Scan scope itself (which files even reach `classifyOwner()`/`dispositionFor()`)
is also already generic: the `git ls-files` invocation that seeds the scan
already includes the whole `governance` root, not just
`governance/TestSuiteHygiene`
(`scripts/generate-modular-architecture-test-inventory.php:351`: `['git',
'ls-files', '--cached', '--others', '--exclude-standard', '--', 'tests',
'governance', 'scripts/tests', ...]`). A new `governance/Channel/` directory
is therefore picked up by the scan automatically; the **only** literal edit
this generator needs for a new group is the one new row in
`testSuitePrefixTable()`.

### 5. Generated-inventory rows pinning the 57 target paths

Checked every file under `docs/internal/generated/modular-architecture/`
(19 files) for a line containing any of the 57 target paths as a substring:

- `docs/internal/generated/modular-architecture/test-ownership.tsv` — **57
  matching lines**, one per target path (the file is the generated
  path-level ownership inventory, so this is expected: it pins every one of
  the 57 files by path today).
- `docs/internal/generated/modular-architecture/test-system-support-owners.tsv`
  — **3 matching lines**, each naming one representative path for a
  `System/*` owner group's description column:
  `System/DocumentationConsistency` → `tests/System/DocumentationConsistency/Integration/DocumentationConsistencyTest.php`;
  `System/TestRunnerConfiguration` → `tests/System/TestRunnerConfiguration/Unit/CoverageIsRequestedExplicitlyTest.php`;
  `System/ScratchPathIsolation` → `tests/System/ScratchPathIsolation/Unit/ScratchPathsCarryRealEntropyTest.php`.
  Only the first of these three (`DocumentationConsistencyTest.php`) is
  itself one of the 57 target paths — the file was matched because that path
  string occurs inside the `test-system-support-owners.tsv` row, not because
  all three rows name moving files.
- All other 17 generated files: zero matches.

All 60 total pinned occurrences (57 + 3) are in generated artifacts that
`composer architecture:check` / `check:artifacts` regenerates and diffs
against; per `AGENTS.md`'s own registration table, any move that is not
accompanied by regenerating these files reddens `architecture:check` loudly,
not silently — confirmed by the presence of `test-ownership.tsv` and
`test-system-support-owners.tsv` explicitly in `scripts/generate-modular-architecture-test-inventory.php`'s
output set (not independently re-verified line-by-line here beyond the
grep-based occurrence count above).
