# Session 01: CLI refusals that misstate the condition

Issues: #160 items 1, 2, 3 and #167. Branch: `fix/cli-refusal-wording`.
Read `00-overview.md` first.

Every item here changes only the text of a refusal and the data the text is
built from. No finding, metric or exit code changes.

## Files this session owns

| File                                                                                                           | Items |
| -------------------------------------------------------------------------------------------------------------- | ----- |
| `src/Infrastructure/Console/RuntimePolicy/ExitPolicy.php`                                                      | 1     |
| `tests/Infrastructure/Console/Unit/ExitPolicyTest.php`                                                         | 1     |
| `src/Reporting/DrillDown/DrillDownBinding.php` (shared with 04: you change `classDeclarationSelection()` only) | 2     |
| `src/Infrastructure/Console/CheckReport/Assembly/ReportAssembler.php`                                          | 2     |
| `tests/Reporting/Unit/DrillDown/DrillDownBindingTest.php`                                                      | 2     |
| `tests/Infrastructure/Console/Functional/Check/CheckCommandTest.php`                                           | 2     |
| `src/Reporting/README.md` (shared: the class drill-down binding paragraph, ~645–649)                           | 2     |
| `src/Infrastructure/Cache/CacheDirectoryContents.php`                                                          | 3     |
| `src/Infrastructure/Console/CacheLifecycle/CacheRunLifecycle.php`                                              | 3     |
| `src/Infrastructure/Console/Refusal/EnvironmentRefusal.php`                                                    | 3     |
| `tests/Infrastructure/Console/Integration/RuntimeConfiguratorTest.php`                                         | 3     |
| `tests/Infrastructure/Cache/Unit/FileCacheTest.php`                                                            | 3     |
| `src/Infrastructure/Console/Invocation/RuleOptions/CliRuleOptionAddressing.php`                                | #167  |
| `src/Analysis/Finding/Contract/Rule/OptionLanguage/RuleOptionRefusalWording.php`                               | #167  |
| `docs/internal/modular-architecture-manifest.json` (shared)                                                    | #167  |
| `tests/Infrastructure/Console/Unit/CliOptionsParserTest.php`                                                   | #167  |
| `CHANGELOG.md` (shared)                                                                                        | all   |

Not yours: `CacheClearOutcome.php`, `AstEntryCodec.php`, `FileCache.php`,
`src/Infrastructure/Cache/README.md` (session 04 owns the last three; nobody
changes `CacheClearOutcome`).

## Item 1 — `--fail-on=false` is told about `info`

**Cause.** `ExitPolicy::rejection(string $value)` appends the
"Severity "info" is report-only ..." sentence to every rejection.
`resolvedPolicy()` already knows whether the value parsed as
`Severity::Info` (`$severity`), so the distinction is local.

**Change.** The rejection text depends on what was rejected:

- the value is `info` (the constructor with `Severity::Info`, or
  `resolvedPolicy()` with `$severity === Severity::Info`): today's text,
  unchanged;
- any other rejected value (an unknown word such as the CLI string `false`, a
  boolean, an array): `Invalid value "<v>" for "fail_on". Allowed values: none, warning, error.`
  followed by one sentence that names `none` as the value that never fails on
  findings. No mention of `info`.

Keep one private builder per case or pass the parsed severity to
`rejection()`; no public signature changes.

**Tests (ExitPolicyTest).** Add: an unknown word (`false`, `warnin`) is
refused without the words `info` and `report-only`, and the text names
`none`. Keep `itRejectsInfoAsAFailOnThreshold`,
`itNamesTheAllowedValuesWhenRejectingAnUnknownWord` and
`itPreservesTheAuthorWhenTheFactoryRefusesAnInvalidValue` green unchanged.

## Item 2 — `--class` advice does not say which file works

**Cause.** `DrillDownBinding::classDeclarationSelection()` computes the
per-file declaration counts (`$declarationsByFile`) but returns only
`singletonFileCount`; `ReportAssembler::assertDrillDownBinds()` therefore can
only say "Analyse one declaring file".

**Contract change (both sides are in this session).**
`classDeclarationSelection(string $fqcn, MetricRepositoryInterface $metrics)`
returns `array{declarations: list<string>, fileCount: int, singletonFiles: list<string>}`:
`singletonFiles` are the declaring files holding exactly one declaration of the
class, sorted with `SORT_STRING`. It replaces `singletonFileCount`; only
`ReportAssembler` and `DrillDownBindingTest` read that key (check with
`grep -rn singletonFileCount src tests` before removing it).

**New advice text** in `assertDrillDownBinds()`, used only when
`singletonFiles` is not empty. It must name the file(s) and must not contain
the retired phrase `Analyse one declaring file`:
- one file: for example "Analyse `<file>` alone to select its declaration.";
- several: "Analyse one of these files alone to select its declaration:
  `<a>`, `<b>`." and, past five names, "and N more".
The other branches of the `match` stay as they are.

**Tests.**
- `DrillDownBindingTest::itCountsDistinctDeclarationFilesFromExactIdentity`
  pins the array shape; update it to the new key and assert the sorted list.
- In `tests/Infrastructure/Console/Functional/Check/CheckCommandTest.php`, two
  tests pin the retired phrase: `itRefusesAnAmbiguousClassSelectionWithBothDeclarations`
  (around line 98) and `itSuggestsADeclaringFileWhenOneFileSelectsASingleDeclaration`
  (around line 137). Update both to assert the file name instead.
- Two negative tests in the same file,
  `itExplainsWhenOneFileCannotDisambiguateAClassSelection` and
  `itDoesNotSuggestAFileWhenEveryDeclaringFileRemainsAmbiguous`, assert that the
  retired phrase is absent; once the phrase is gone they can no longer fail.
  Change them to assert that the new advice (its fixed words, for example
  `alone to select`) is absent.
- Add a functional case with the issue's fixture: `App\Dup` once in
  `one.php`, twice in `two.php` (an `if`/`else` pair) — the advice names
  `one.php` and not `two.php`.

**Docs.** `src/Reporting/README.md` (~645–649) describes "count of files
containing exactly one matching declaration"; describe the list instead.

## Item 3 — failed `--clear-cache` calls a directory a file and says nothing remains

**Cause.** `CacheRunLifecycle::refuseIncompleteCacheClear()` uses
`EnvironmentRefusal::aboutFile()`, whose template says `file`, and prefixes
`<remaining> cache entries remain`. `CacheClearOutcome::$remaining` is not a
reliable count of what is left: it counts owned entries that could not be
unlinked, is 0 when only foreign files keep a detached directory alive, and is
1 for a failed detach (`rename()`) whatever the entry count. The detached
`.clearing-<hex>` directories that stay behind can hold foreign files **or**
cache-owned files that could not be removed (the existing
`RuntimeConfiguratorTest` case makes a shard unwritable, so it is the second
kind).

**Decision.** The owner of the facts writes the explanation: `CacheDirectoryContents`,
which builds every `CacheClearOutcome`, produces a `reason` that says what
happened. The refusal prints the directory and that `reason`, and no longer
prints the count. `CacheClearOutcome` is unchanged.

**Changes.**
- `CacheDirectoryContents`, the retained-directories outcome (reached only when
  every owned entry was removed): `reason` becomes neutral and counted, without
  claiming who owns the files — for example "kept K detached directories that
  still hold files: <paths>", with correct singular/plural. When owned entries
  remain, a failure reason already wins today (for example
  `Cannot remove cache entries in: .../.clearing-X/aa`, the existing
  `RuntimeConfiguratorTest` scenario); those reasons and the detach-failure
  reason (`Cannot detach cache version directory: ...`) stay as they are.
- `CacheClearOutcome::$remaining` stays; after this change no production code
  reads it. Do not remove it in this session (tests read it); mention it in the
  report.
- `EnvironmentRefusal` gains `aboutDirectory(string $path, string $operation, string $detail)`,
  the directory twin of `aboutFile()`. Do not change `aboutFile()`: nine
  production call sites use it for real files.
- `CacheRunLifecycle::refuseIncompleteCacheClear()`:
  `Cannot clear cache in directory "<dir>": <reason>` via `aboutDirectory()`.

**Tests.**
- `RuntimeConfiguratorTest::itRefusesAnIncompleteCacheClearWithTheDirectoryAndReason`
  pins `1 cache entries remain`; update it to assert `directory` and the
  existing failure reason (`Cannot remove cache entries in:`), and that no
  `cache entries remain` count is printed.
- Add a `RuntimeConfiguratorTest` case with a foreign file inside the versioned
  directory (the issue's reproduction): the text says `directory`, does not
  contain `0 cache`, and names the retained `.clearing-` path.
- `FileCacheTest`: the existing assertions that `reason` contains `.clearing-`
  stay; add one for the foreign-file case asserting the new counted wording.

## #167 — `--rule-opt` and a map key that contains a dot

**Decision: no new syntax.** `docs/internal/CLI_CONVENTIONS.md` and
`website/docs/usage/cli-options.md` already say that channel-keyed maps belong
in YAML. Only the refusal is wrong: it says the option does not exist and lists
the map's own name as valid.

**Where.** The "is not an option" decision is in
`CliRuleOptionAddressing::unknownOption()` (not in `RuleOptionsParser`). Option
shapes are known: `RuleOptionSurface::locate()` returns an address,
`RuleOptionSurface::shapeAt()` its `RuleOptionShape`, whose `kind` is
`RuleOptionShape::MAP` for a map. The only map keyed by arbitrary strings today
is the framework key `suppress-namespace-channels`; the other map shapes are
maps with fixed keys inside lists, which this change must not affect.

**Change.** In `unknownOption()`, after the existing level-slot branches and
before the generic "not an option" branch: if some dotted prefix of the written
option locates an option whose shape kind is `MAP`, refuse with a new sentence
that names that option, says `--rule-opt` cannot address its keys, and points to
`qmx.yaml`. Add the sentence builder to `RuleOptionRefusalWording` next to
`notAnOptionOfRule()` (for example `mapKeysNotAddressable(string $option, string $ruleName): string`).
Do not change `notAnOptionOfRule()`: `RuleOptionsFactoryTest` pins it for the
YAML door.

**Manifest.** Reading `RuleOptionShape::MAP` from `CliRuleOptionAddressing` is
a new cross-owner import: add `CliRuleOptionAddressing` to the `consumers` of
`RuleOptionShape` in the manifest (overview rule 2), and check with
`composer architecture:check`.

**Tests (CliOptionsParserTest).**
- Both spellings from the issue (`suppress-namespace-channels.coupling.cbo:namespace`
  and `suppress_namespace_channels.coupling.cbo:namespace`) produce the new
  refusal, which names `suppress-namespace-channels` and `qmx.yaml` and does not
  say "is not an option".
- A genuinely unknown dotted option (`no-such.key`) still gets the old text.
- `--rule-opt=coupling.cbo:suppress-namespace-channels=App` keeps its current
  "must be a map" refusal.
- Run `tests/Infrastructure/Console/Functional/RuleOptionKeyDoorSymmetryTest.php`
  and `tests/Infrastructure/Console/Functional/TranslatedRefusalVocabularyTest.php`
  once each after the change; they pin the existing sentence for other inputs and
  must stay green unchanged.

## Pins to look for before you finish

`grep -rn "cache entries remain\|Analyse one declaring file\|is report-only" tests governance finding-gate website docs`
— every hit is either updated by this plan or must stay true; list any other
hit in the report.

## CHANGELOG (`### Fixed`)

- `--fail-on` with an unknown value no longer mentions `info`.
- An ambiguous `--class` selection names the files that can select one declaration.
- A failed `--clear-cache` names the directory and what it kept.
- `--rule-opt` addressing a key inside a map option explains that the map belongs in `qmx.yaml`.

## Session gate

After the overview ladder steps 1–5:

1. `composer gate ...` once (overview, "The finding gate"). **Expected gate
   differences:** none, or only refusal texts of the four refusals above if a
   corpus case triggers one.
2. `composer check` once (overview step 7).

## Definition of Done

- The four refusals read as described, each pinned by the tests named above.
- `grep -rn "singletonFileCount" src tests` returns nothing.
- `git diff origin/main -- src/Infrastructure/Console/Refusal/EnvironmentRefusal.php`
  shows only an added method; `git diff origin/main -- src/Infrastructure/Cache/CacheClearOutcome.php`
  is empty.
- `composer architecture:check` passes.
- The printed exit code of the final `composer check` is 0.
