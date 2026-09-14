81

This many unique paths in the union of two witnesses (60 from `controls-from-category.tsv` + 21
from the "mechanics only" block; the "reports only" block lies entirely inside the first 60).
Exactly this many rows in `controls-verdict.tsv`, the file has no header.

## Count

| verdict | how many |
| ------- | -------- |
| control | 43       |
| test    | 24       |
| mixed   | 14       |

Total "control substance": 43 whole files plus 20 individual methods inside 14 mixed ones.

## How to split each mixed file

The common rule for all fourteen: the control part moves out, the product part stays in place
under its old name. The new file name below is a suggestion, not a requirement.

1. `tests/Analysis/Configuration/Unit/ConfigSchemaTest.php` — extract
   `itLeavesNoConstantUnreferencedByAConsumer` (L279-331) as "every ConfigSchema constant has a
   consumer in src/". The rest is a unit test over the constants, with no dependencies on the
   extracted method.
2. `.../Identity/ClassProducerOrdinalTest.php` — `itCoversEveryClassMetricProducer` (L121-141) and
   `itFindsTheHelperCallSiteOfEveryCoveredProducer` (L150-171) move together: the second proves
   the completeness of the first one's population. One product method on inline source is left.
3. `.../Identity/RatchetKeyGrammarTest.php` —
   `itFindsNoPositionInAnyDeclarationKeyOfTheRepositoryRatchet` (L48-58) moves out to the controls
   over `qmx-baseline.json`; the key grammar stays a unit test.
4. `.../ChannelLevelAssemblyTopologyTest.php` — `itFindsNoProductionSourceThatSpellsALevelSuffixAsALiteral`
   (L58-81) and its self-check `itRecognisesARetiredLevelBearingChannelName` (L123-129) move out
   together with the private `levelSegmentOf()` and the `sourceRoot()`/`parse()` pair. Left behind
   is `itFindsNoDeclaredChannelCodeThatCarriesALevel` — it asks the registry from the container,
   but it needs `levelSegmentOf()`: on the split, the primitive will have to be duplicated or
   raised into a shared Support.
5. `.../ConfigurationErrorClassificationTopologyTest.php` — the first two methods (L67-99,
   L117-154) move out with all the file-based scaffolding (`productionFiles()`, `relative()`,
   `sourceRoot()`). Five DI/compiler-pass methods and the fixture classes at the bottom of the
   file stay.
6. `.../RuleDocsPageCoverageTest.php` — `itRequiresEveryDeclaredDocsPageToCarryTheRulesOwnAnchor`
   (L75-98) and `itRequiresEveryClasslessComputedMetricProducerToCarryItsAnchor` (L107-127) move
   out together with `docsRoot()`. Both need `ruleClasses()` from the container — a copy of it
   will be needed on both sides.
7. `.../RuleRemediationMinutesCoverageTest.php` — `itRequiresEveryRulesRemediationMinutesToMatchTheReferencePage`
   (L79-84) and `itRequiresEveryProducerOfTheComputedFamilyToBeOnTheReferencePage` (L120-138) move
   out together with the private `assertReferencePageMatchesDeclaredMinutes()` (L86-118),
   `readFile()` and `docsRoot()`. The first method is a two-line delegate, all the work is in the
   helper: move them as a pair, or an empty shell moves out instead.
8. `.../Exclusion/ConfiguredSuppressionTest.php` —
   `itIsTheOnlyPlaceInSourceThatReadsASuppressionOptionKey` (L78-103) moves out whole, the
   remainder is a plain two-method unit test.
9. `.../Baseline/Functional/BaselineCommandOptionSurfaceTest.php` —
   `itKeepsRepositoryEntrypointsOnTheBaselineLifecycleSurface` (L169-203) moves out to the controls
   over `action.yml` / `docker-compose.yml` / `scripts/pre-commit-hook.sh`; four methods on the
   command surface stay.
10. `.../Baseline/Unit/ChannelRenameMapTest.php` — `itReadsTheRepositorysOwnDeclaredChannelMap`
    (L65-75) moves out and logically joins the already fully-control `ChannelRenameTsvGateAgreementTest`;
    three methods over a row corpus stay.
11. `.../Sarif/Integration/SarifRuleDescriptorCoverageTest.php` — the two coverage methods
    (L57-79, L90-118) move out along with the private helper that does `is_file()` and searches
    for the `**Rule ID:**` anchor in `website/docs`. Left:
    `itKeepsTheHumanisedFallbackAndTheRepositoryUrlForAnUnknownCode` — plain formatter behavior.
12. `tests/Unit/Core/Util/GlobSyntaxTest.php` —
    `itIsTheOnlyPlaceInSourceThatEnumeratesTheGlobCharacters` (L69-92) moves out; two unit methods
    stay.
13. `tests/Unit/Core/Util/NamespaceMatcherTest.php` —
    `itLeavesPatternNormalizationToThePrimitiveOnEverySurface` (L341-382) moves out together with
    the private `codeWithoutComments()`; thirty unit methods stay.
14. `tests/Unit/Core/VersionTest.php` — `itDoesNotResolveTheVersionThroughTheRootPackage` (L42-56)
    moves out (it reads the `Version.php` source as text); two behavioral methods stay.

## What duplicates mechanisms the repository already has

Checked by reading `composer.json` (`scripts`) and the tests themselves, not by name.

**A duplicate of the check, but NOT a duplicate of the route — deletable only together with an
edit to the route:**

Both files below are marked `#[Group('live-freshness')]`, and `scripts/phpunit-aggregate.py:36`
excludes this group. That means under `composer check` (via `check:code` → `test:aggregate`) they
**do not run at all**, while under a bare `composer test` they do run. This is a deliberate fork:
the same `ModularArchitectureGovernanceIntegrationTest::itRoutesFreshnessOraclesExactlyOnceThroughAggregateCheck`
asserts that `composer test` must keep full freshness coverage, and the aggregate hands it off to
`check:artifacts`. So a deletion must be accompanied by an edit to this assertion, or it will turn
red.

- `tests/Reporting/Formatter/Suppressed/Integration/SuppressionSnapshotFreshnessTest.php` — its
  single method runs `php scripts/generate-suppression-snapshot.php --check` as a subprocess and
  asserts `exit == 0`. This is literally `composer suppression-snapshot:check`, already part of
  `check:artifacts`. Under `composer check` it is a plain duplicate.
- `tests/Analysis/Policy/Architecture/Integration/ModularArchitectureGovernanceIntegrationTest.php`,
  method `itChecksEveryGeneratedProjectionWithoutWriting` (L21-32) — runs
  `php scripts/generate-modular-architecture.php --check`, i.e. exactly `composer architecture:check`,
  already present both in `check:artifacts` and as the first half of `selfcheck`. **The file's
  other six methods do not form a duplicate** (the composer-script graph, permanent exact
  composition bindings, PHPUnit-suite coverage, production→test imports) — the file must not be
  deleted, only this method.

What exactly is lost on deletion: the ability of a bare `composer test` (without `composer check`)
to notice a stale artifact. The decision belongs to the route's owner, not to this relocation.

**Partially overlaps — must not be deleted:**

- `tests/Analysis/Policy/Architecture/Integration/DogfoodingTopologyTest.php` — reads
  `docs/internal/modular-architecture-manifest.json` and asserts the projection's semantics
  (owner↔layer, fail-closed coverage, acyclicity, `external` only for non-project namespaces).
  `architecture:check` verifies the freshness of what's generated, not these properties.
- `tests/Analysis/Policy/Architecture/Unit/ArchitectureInternalTopologyTest.php` and
  `.../ComputedMetrics/Unit/ComputedMetricsInternalTopologyTest.php` — the capability's internal
  DAG; the manifest checker judges cross-owner imports, not internal zoning.
- `tests/Unit/RuleVocabulary/DirectiveAudit*Test.php` (three files) — these are unit tests of the
  audit report's **reader and gate** (`scripts/directive-audit/*`), not a re-run of
  `bin/qmx directives`. The subject is different: they ARE the check on the instrument. The same
  for `DirectiveAuditControlsSuiteKeyTest` (the controls-suite key).
- `tests/Unit/RuleVocabulary/RenameEnumerationRetirementTest.php` — tests the code of
  `scripts/generate-rename-enumeration.php` rather than checking the freshness of its output
  (that's `enumeration:renames:check`'s job).
- `tests/Analysis/Policy/Baseline/Unit/ChannelRenameTsvGateAgreementTest.php` and the
  `itReadsTheRepositorysOwnDeclaredChannelMap` method from `ChannelRenameMapTest` — require that
  the **product's** `ChannelRenameMap` reads the same `finding-gate/maps/channels.tsv` as the
  gate. `composer gate` compares findings; it does not check agreement between two readers of one
  table.
- `tests/Unit/PromiseEffect/*` (three files) — unit tests over `scripts/promise-effect/*`;
  `promise-effect:check` runs the tool itself, but not its internal classes.

## Divergences from wave 1 (verdict changed)

- `ChannelDeclarationFixtureDriftTest`, `ChannelOrderFixtureDriftTest`: wave 1 — control ("fixture
  drift"). Here — **test**: the population is taken from the assembled container, and the
  snapshot to compare against lives **inside `tests/`**. Under the brief's classifier, a
  container-based path remains a test. These are the only two verdicts where the classifier
  disagrees with common sense: they are drift controls in spirit, not in letter.
- `LevelActivityCoversEveryDeclaredLevelTest`, `WarningBoundaryDeclarationTest`,
  `ErrorStreamContainerIdentityTest`, `RuleRegistryTest`: wave 1 — "borderline control-invariant".
  Here — **test**: there is no filesystem access at all, only the container and reflection over
  the classes it produced.
- `MetricNameVocabularyTest`: wave 1 — "a disputed boundary". Here — **test**: reflection over
  one class's constants is not "reflection layered on a directory walk".
- `CodeSmellRuleContractTest`: wave 1 — mixed (method 2, "integration via DI"). Here — **control
  in full**: the second method checks a directory walk against the registry, i.e. its subject is
  agreement between the filesystem and the container, not product behavior.
- `RuleThresholdKeyGroupRegistryCompletenessTest` / `...DriftTest`,
  `ThresholdValidatorAssignmentTest`: wave 1 wavered ("control-invariant-like", "a candidate").
  Here — **control**, no caveats.
- The "mechanics only" block (21 paths): 17 confirmed as **test** (false positives from the
  mechanical witness), and 4 turned out to be control substance that wave 1 missed by category:
  `SuppressionSnapshotKeyTest` — control in full, plus three mixed —
  `BaselineCommandOptionSurfaceTest`, `ChannelRenameMapTest`, `SarifRuleDescriptorCoverageTest`.
  Wave 1 missed the first one because it pulls in code not via an import but via `require_once`
  from `scripts/` — exactly the class of miss the brief predicted.
- `LayerViolationRuleTest`, `LayersValidatorTest`, `RuleOptionsFactoryTest` — the three largest
  files in the union (2194/1644/1230 lines) turned out to be pure **test**: the mechanical witness
  fired on the word `glob` in the product's `LayerSelector::glob` name, on the word `require` in a
  comment, and on fixture imports from `tests/`.

## What this method cannot see

- **Helpers.** I collected every `Qualimetrix\Tests\*` import from the 24 files with a `test`
  verdict (there are exactly ten: four `TestRuleOptions*` fixtures, `AllowListBuilder`,
  `LayerVerdicts`, `ProcessorBuilder`, `BaselineCliFixture`, `TempDirectory`, `PseudoTerminalRun`)
  and grepped each for a tree walk. Only `BaselineCliFixture::copyDirectory()` touches the
  filesystem — it copies a fixture directory into a temp project, i.e. it's a harness, not an
  assertion. The verdicts hold. But the same grep also checked the controls' helpers
  (`FromArrayKeyReader` reads files — expected): I did not look at a third level (a helper's
  helper).
- **Base classes.** Checked: 79 of the 81 classes inherit `TestCase` directly, two inherit
  `PHPStan\Testing\RuleTestCase` from vendor. There is no shared ancestor with a `setUp()` in the
  union where a control could hide.
- **A second level of `require_once`.** I found the direct `require_once` calls from `scripts/`,
  but I did not check whether the pulled-in script itself pulls in a third file that touches
  `src/`.
- **The "control over `tests/`" boundary.** The classifier talks about `src/`, `docs/`,
  `website/`, `scripts/`, configs and generated artifacts. Two files
  (`ScratchPathsCarryRealEntropyTest`, `ErrorStreamSoleOwnerTest`) sweep in `tests/` too; I counted
  them as controls, but the brief is formally silent on this case.
- **The "container versus a snapshot in `tests/`" boundary.** The same silent case from the other
  side: the fixture-drift tests (items above) are tests by the letter, controls by purpose. The
  decision was made by the letter and can be flipped by one word in the classifier.
- **The method-level split is not verified by execution.** I never ran PHPUnit anywhere (the
  brief forbids it), so the "extract method X" suggestions are not proven by a build: private
  helpers, shared constants (`REGISTERED_RULE_COUNT`, pinned lists) and `#[CoversClass]` may tie
  the halves together more tightly than reading shows.
- **The verdict is per file, not per line of coverage.** Where a control method and a product
  method share a population (`ruleClasses()` from the container in `RuleDocsPageCoverageTest` and
  `RuleRemediationMinutesCoverageTest`), the split will create duplicate code — that is a cost, not
  a defect in the verdict, but it is not measured here.
- **A third class the classifier doesn't name: tests of in-repository tooling.** Ten `control`
  verdicts rest not on "learns about `src/` via the filesystem" and not on "checks the contents
  of `scripts/`", but on the fact that the test's subject is code living in `scripts/`, pulled in
  via `require_once`: `ClassifierTest`, `FloorTest`, `LedgerVocabularyTest`,
  `DirectiveAuditGateTest`, `DirectiveAuditReportReadingTest`, `DirectiveAuditControlsSuiteKeyTest`,
  `ChannelRenameTsvGateAgreementTest`, `RenameEnumerationRetirementTest`,
  `SuppressionSnapshotKeyTest`, and the tooling halves of `BenchmarkConsumersCoverageTest` and
  `ThresholdPopulationAgreementTest`. This is my extension of the classifier, not its letter: one
  line in the brief flips it for all ten at once.
- **Input completeness.** The input itself is the union of two wave-1 witnesses. A control both
  of them missed (say, a test with no file calls and a "unit" category) never entered these 81
  paths and is not caught by this method at all.
