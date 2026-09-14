81 = 15 product-test + 40 repo-control + 9 tooling-test + 17 mixed

The same 81 paths as before; the input enumeration did not change, only the criterion did.
In the seventeen `mixed` files, 33 non-product methods are named.

## Criterion: by the subject of the assertion

The previous classifier asked **how** the test learns about the product (filesystem versus
a DI container). That is a question about the instrument. A container, reflection, `glob`, a
parser and a tracked fixture are all equally reading instruments; choosing between them says
nothing about what is being asserted.

There is one new question: **what makes the assertion true — and where its expected side came from.**

- **product-test** — truth is decided by `src/`'s behavior on the given input. The input is
  built by the test (source, YAML, an object, CLI arguments), and so is the expected side. Break
  the logic and it turns red; add a new rule, channel, or constant to the repository and it stays
  green.
- **repo-control** — truth is decided by the repository's current contents: which declarations
  exist in it, what they say, whether two places agree, whether a generated artifact is fresh,
  where code is allowed to live. The expected side is a mirror of the repository, not something
  the test invented. The product's logic is untouched, behavior on any fixed input is the same,
  yet the test turns red — which means it is a control.
  Two subforms, both repo-control:
  - **census** — a pinned list, a tracked file, or a generated artifact that must be edited
    alongside the population (`CONSUMERS`, `FROZEN_SPELLING`, `declared.txt`,
    `REGISTERED_RULE_COUNT = 48`);
  - **law** — a universal property over the repository's population that never needs editing
    (the `MetricName` key grammar, "the sole place in `src/` that reads the suppression key").
- **tooling-test** — the same shape as product-test, but the SUT is an in-repository tool, not
  the product: code from `scripts/`, from `finding-gate/`, or PHPStan rules in the
  `Qualimetrix\PhpStan\Rules` namespace. The subject is the tool's behavior, not repository state
  and not `src/`'s behavior.
- **mixed** — the file has methods from more than one class.

### A working one-question classifier

> Was the assertion's expected side invented by the test — or copied from the repository?

The pair where this is most visible, and where the old criterion erred systematically:

| assertion shape                                                                             | class        |
| ------------------------------------------------------------------------------------------- | ------------ |
| "ONE named thing behaves like this" (`itIsRegisteredAsAPublicServiceAndOfferedByTheBinary`) | product-test |
| "EVERY registered X has property Y"                                                         | repo-control |

The first is checkable on a made-up input; the second quantifies over a population the test did
not create. This is exactly where the old rule "through a container ⇒ test" was hiding.

### Hints, not a definition

They are convenient for self-checking, but a disputed case is settled by the question above, not
by them.

1. **Population growth.** Does a legitimate new neighbor (a new rule, a new channel, a new
   constant) oblige an edit to this file? A census — yes, by construction. product-test — no.
   (A sufficient sign, not a necessary one: a "law" is never edited and is still repo-control.)
2. **Failure text.** A control says "update this file"; a product test says "the product answers
   incorrectly".
3. **An instrument's self-check inherits its class.** A method that feeds the detector synthetic
   source to prove the detector bites is part of the same control, not product-test: its subject
   is not the product but the instrument over the repository. Classified this way:
   `ChannelLevelAssemblyTopologyTest::itRecognisesARetiredLevelBearingChannelName`, a dozen
   `ThresholdOverrideOwnRuleNameGuardTest` methods on synthetic code, and the drift methods of
   `ChannelPublicationConsistencyTest`.
4. **A table-class.** When the product class is itself a table of declarations
   (`ConfigSchema::ENTRIES`, the `MetricName` constants): an assertion about output LOGIC on
   specific keys is product-test; an assertion about closure over ALL rows (every entry has a
   constant, every constant has a consumer, keys don't collide) is repo-control.

### Convention for the `scope` column

`whole` — the entire file is one class. Otherwise it's a comma-separated list of methods, and
these are the **non-product** methods; `reason` opens with a `repo-control:` marker and states
directly what the remainder is. Where there is no product half at all (repo-control +
tooling-test), the repo-control methods are listed, and `reason` says "the rest is tooling-test".
The `proposed_group` column is meaningful for repo-control (and for the repo-control half of
`mixed`); for product-test it is `-`, for tooling-test it names the tool.

## Disputed pairs: verdict and why

| pair / claim                                                                                                          | before                  | after                                                                                                     | why                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| --------------------------------------------------------------------------------------------------------------------- | ----------------------- | --------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **1.** `ErrorStreamContainerIdentityTest` versus `ChannelLevelRefusalTopologyTest`, `OccurrenceKindFreezeGuardTest`   | `test` versus `control` | **both repo-control**                                                                                     | The claim is confirmed by the code. The file holds a `private const array CONSUMERS` of seven product FQCNs, the docblock calls it "an enumeration, not a floor", and the second method only reflects constructors. A consumer disappearing or appearing turns the file red without changing behavior on any input — this is a wiring census. Everything about them matched except how the population was gathered: a directory walk there, an object-graph walk from the container here. There is no difference by subject.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| **2.** `ChannelDeclarationFixtureDriftTest`, `ChannelOrderFixtureDriftTest` versus `SuppressionSnapshotFreshnessTest` | `test` versus `control` | **all three repo-control**                                                                                | Confirmed. Both drift tests read the tracked `tests/Analysis/Finding/Fixtures/Channels/declared.txt` / `order.txt` via `file_get_contents` and require it to be fresh against what the assembled container declares; the first one's docblock says outright "the fixture is the oracle". The expected side is a repository file. Only the directory holding the snapshot separated them from `SuppressionSnapshotFreshnessTest`, and the directory is a property of the carrier. The earlier notes themselves admitted this case was disputed ("control in spirit, not in letter"); the new criterion settles the dispute.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| **3.** `MetricNameVocabularyTest` versus `ConfigSchemaTest::itLeavesNoConstantUnreferencedByAConsumer`                | `test` versus `control` | **both repo-control**                                                                                     | The uniformity claim is only partly right, and it is worth saying precisely: the invariants are **different** — the first is a law over a vocabulary (grammar, no collision with the aggregated spelling), the second is a census of consumers. But the class is the same: both quantify over a declared vocabulary and turn red from a new CONSTANT while the logic stays untouched. The earlier verdict told them apart by instrument (reflection over one class versus concatenated text of `src/` files). Along the way, `ConfigSchemaTest`'s repo-control half grew from one method to seven: `ENTRIES`↔constants closure and "the full config loads" are of the same class.                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| **4.** An unwritten third criterion, "SUT outside `src/`"                                                             | 11 files got `control`  | **7 tooling-test + 4 mixed**                                                                              | The claim is confirmed: the criterion was applied and never formulated anywhere, and the earlier notes honestly called it "my extension of the classifier". It is now a separate class. `SuppressionSnapshotKeyTest` really should have landed in the tooling group and didn't — it pulls in its SUT not via an import but via `require_once` from `scripts/`. Reclassified: `ClassifierTest`, `FloorTest`, `DirectiveAuditGateTest`, `DirectiveAuditControlsSuiteKeyTest`, `RenameEnumerationRetirementTest`, `ChannelRenameTsvGateAgreementTest`, `SuppressionSnapshotKeyTest` → tooling-test; `LedgerVocabularyTest`, `DirectiveAuditReportReadingTest`, `BenchmarkConsumersCoverageTest`, `ThresholdPopulationAgreementTest` → mixed. Two files the claim did not name were added to the class: `BannedStringPathPropertyRuleTest` and `BannedStringPathPromotedPropertyRuleTest` — their SUT is in the `Qualimetrix\PhpStan\Rules` namespace, i.e. also an in-repository tool, just one that doesn't live in `scripts/` but under `tests/TestSupport/`. So the class is defined by subject ("the SUT is an in-repository tool"), not by directory. |
| **5.** The "43/24/13" arithmetic                                                                                      | —                       | **the claim is confirmed, but it's the plan and the measurement that disagree, not the file with itself** | The measurement was consistent: in `controls-verdict.tsv`, 43/24/**14** = 81, and the table in `controls-verdict-notes.md` also says 14 ("43 whole files plus 20 individual methods inside 14 mixed ones"). The number 13 does not exist anywhere in the notes or in `slice-reports/`. It lives in the planning documents: `00-overview.md:84` — "81 candidates → 43/24/**13**", `00-overview.md:65` — "43 + 13 split", `00-overview.md:15` — "inside 13 more", `02-controls-extraction.md:4` — "13 more do so in part", `02-controls-extraction.md:83` — "Splitting the 13 mixed files". So the plan copied the composition wrong and repeated it five times; the section heading in `02` promises to walk through thirteen files, when fourteen needed walking through. I do not edit these files (the brief authorizes only two), so the discrepancy is only named here. The new sum: 15 + 40 + 9 + 17 = 81.                                                                                                                                                                                                                                         |

## Grouping axis for repo-control

### What the review asserts and what the measurement showed

The review says: grouping by artifact CARRIER (`Documentation` / `GeneratedArtifacts` /
`SourceLayout` / `TestSuite`) is a renamed mechanism, and the real axis is the guarded VOCABULARY.

**By name.** Of the earlier 43 `control` files, the name contains
`Channel|Rule|Threshold|Occurrence|Declared` in **22** files (the review said 17 — an undercount).
Caveat: `Declared` in `DeclaredOptionKeysCoverReadKeysTest` is a word about shape, not subject;
the subject there is option keys. So counting by name is a pointer, not proof.

**By co-change (measured, not inferred).** This is proof. Three commits changing the "channel"
subject, and which of the 81 files they touched:

| commit                                                                | files touched, out of 81                                                                                                                                                                                                                                     |
| --------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `40ae4019` "a channel is one name, and the rule is a field beside it" | `ChannelDeclarationFixtureDrift`, `ChannelEmissionStaticGuard`, `ChannelLevelAssemblyTopology`, `ChannelPresentationCoverage`, `ConfigurationErrorClassificationTopology`, `SarifRuleDescriptorCoverage`, `ChannelPublicationConsistency` (+4 product files) |
| `0d8ee47d` "a level is addressed beside a channel name"               | `ChannelEmissionStaticGuard`, `ChannelLevelAssemblyTopology`, `ChannelLevelRefusalTopology`, `ChannelPresentationCoverage`, `SarifRuleDescriptorCoverage` (+1 product file)                                                                                  |
| `887c8fb6` "a channel declares the metric it judges"                  | `ChannelDeclarationFixtureDrift`, `ConfigurationErrorClassificationTopology` (+1 product file)                                                                                                                                                               |

One change to the "channel" subject touches controls whose carriers are `src/`, `website/docs/`
and a tracked fixture under `tests/` all at once. Under a carrier-based axis, every such commit is
obliged to touch three directories. The review's claim is confirmed by measurement.

### Proposed axis: guarded subject

A directory is named for WHAT is guarded — and everything that asserts about that subject falls
into it, whatever the carrier. The numbers are files where this group occurs (repo-control in
full plus the repo-control half of `mixed`); the sum is 57.

| group                    | files | what it guards                                                                                                                                                                                                   |
| ------------------------ | ----- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Channel`                | 12    | the channel's name, its declaration, order, levels, publication and renaming                                                                                                                                     |
| `RuleDeclaration`        | 7     | a rule's registration and its mandatory declarations (docs page, minutes, aliases, contract)                                                                                                                     |
| `SolePrimitiveOwnership` | 6     | "this primitive is read/enumerated by exactly one place in `src/`"                                                                                                                                               |
| `ThresholdKeys`          | 5     | threshold keys: the group registry, validators, the warning boundary, a rule's own name                                                                                                                          |
| `TestSuiteHygiene`       | 4     | the state of the test suite itself: limitation coverage, temp-path entropy, run configuration, no leakage from a seeded fixture                                                                                  |
| `ModularOwnership`       | 4     | the ownership manifest, zone DAGs, the projection and its freshness                                                                                                                                              |
| `ConsoleComposition`     | 3     | what and how is wired in the console runtime (commands, the error-stream owner)                                                                                                                                  |
| `RepositoryEntrypoints`  | 3     | the repository's entry points: `action.yml`, `docker-compose.yml`, the hook, the benchmark manifest, tracked consumers                                                                                           |
| `RuleOptionKeys`         | 2     | rule option keys: whether every read key is declared and every declared key is read                                                                                                                              |
| `Occurrence`             | 2     | frozen occurrence-kind literals and their pin tests                                                                                                                                                              |
| nine singletons          | 9     | `ConfigurationVocabulary`, `MeasurementVocabulary`, `MeasurementIdentity`, `RatchetArtifact`, `GeneratedArtifactFreshness`, `FormatOptionKeys`, `DirectiveVocabulary`, `ControlRigLedger`, `DocumentationCensus` |

The singletons are named rather than swept into `Misc`: a name that fits anything means the
subject hasn't been named. A group of one file is a normal state for a subject that currently has
a single guard.

**Co-change check.** All seven files touched by `40ae4019` land in `Channel`. `0d8ee47d` — five
of five in `Channel`. `887c8fb6` — two of two. The "channel" subject = one directory.

## What changed against the previous verdict — by name

The comparison is mechanical; the old version is taken from
`git show HEAD:…/controls-verdict.tsv`. **46 of the 81 paths did not change** (accounting for the
rename `control`→`repo-control`, `test`→`product-test`). The 35 that changed break down as:
6 + 3 + 7 + 2 + 5 + 1 + 2 + 9 = 35 = 81 − 46.

**Became repo-control from `test`** (six) — all six went through a container or through
reflection alone, and the old criterion called them tests on that basis:

- `MetricNameVocabularyTest` — a law over the constant vocabulary (claim 3);
- `ChannelDeclarationFixtureDriftTest`, `ChannelOrderFixtureDriftTest` — a mirror of a tracked
  fixture (claim 2);
- `ErrorStreamContainerIdentityTest` — a census of the owner's consumers (claim 1);
- `LevelActivityCoversEveryDeclaredLevelTest` — agreement between two declared sets plus an
  `OFF_BY_DEFAULT` pin;
- `WarningBoundaryDeclarationTest` — a quantifier over every reachable Options class plus a
  `DECIDES_INSIDE_THE_RULE` pin.

**Became repo-control in full from `mixed`** (three) — the earlier split ran by carrier, even
though both halves are about one subject:

- `ChannelLevelAssemblyTopologyTest` — `src/` text and declared channel codes, but the subject is
  one: a level does not live in a channel's name;
- `RuleDocsPageCoverageTest`, `RuleRemediationMinutesCoverageTest` — the container supplies the
  population, `website/docs` supplies the expectation; the subject is a rule's declarations.

**Became tooling-test from `control`** (seven): `ClassifierTest`, `FloorTest`,
`DirectiveAuditGateTest`, `DirectiveAuditControlsSuiteKeyTest`, `RenameEnumerationRetirementTest`,
`SuppressionSnapshotKeyTest`, `ChannelRenameTsvGateAgreementTest`.

A separate note on the last one, because the review expected something different:
`ChannelRenameTsvGateAgreementTest` has **one** test method, and it runs the reader from
`scripts/finding-gate` over a corpus declared by the `ChannelRenameTsvCorpus` fixture. This file
never opens the real `finding-gate/maps/channels.tsv` at all —
`ChannelRenameMapTest::itReadsTheRepositorysOwnDeclaredChannelMap` opens it, and that is the
repo-control half of the corresponding `mixed`. So "agreement between two readers" is proven by a
pair in which only the second half actually touches the repository.

**Became tooling-test from `test`** (two): `BannedStringPathPropertyRuleTest`,
`BannedStringPathPromotedPropertyRuleTest` — SUT `Qualimetrix\PhpStan\Rules\*`, not the product.
The old criterion had no class for them on either side.

**Became mixed from `control`** (five) — each has tooling and repository methods sitting side by
side:

- `ModularArchitectureGovernanceIntegrationTest` — four repo-control (projection freshness, the
  composer-script graph, manifest contents, the absence of production→test imports) and three
  tooling-test (perturbing an isolated copy and running
  `scripts/generate-modular-architecture*.php`);
- `BenchmarkConsumersCoverageTest` — one repo-control (`git grep` over tracked consumers) and
  three tooling;
- `LedgerVocabularyTest` — one repo-control (`itLoadsTheRepositorysOwnLedger`) and four tooling;
- `ThresholdPopulationAgreementTest` — two repo-control (nothing from the seeded fixture lives in
  `src/` or leaked into the enumeration over `src/`) and five tooling;
- `DirectiveAuditReportReadingTest` — three repo-control (the tooling table names exactly the
  values the product's `DirectiveEffect` and `DirectiveUnmeasurableReason` declare) and twenty
  tooling.

**Became mixed from `test`** (one): `RuleRegistryTest` — `itResolvesEveryCliAliasToARealRuleOption`
quantifies over every rule in the real registry; the other five methods build the registry from
fixtures.

**`scope` widened inside `mixed`** (two files), plus nine where only the column format changed:

- `ConfigSchemaTest`: 1 → 7 methods (claim 3);
- `ConfigurationErrorClassificationTopologyTest`: 2 → 3 —
  `itRefusesAProductionSiteThatHandsTheFlagToTheConstructorInstead` was added: the closedness of
  the `ChannelDeclaration` constructor is an assertion about source shape, not behavior;
- for nine `mixed` files the set of methods did not change, but line ranges were removed from
  `scope`: the column is declared as a list of methods, and line numbers go stale on the file's
  very first edit.

**Incidental finding.** In `ModularArchitectureGovernanceIntegrationTest`, the line
`public function itIsNeverActuallyRun()` is not a method of this class but text inside a heredoc
fixture that the test writes into a perturbed tree. Any regex-based method enumeration would
count it as a ninth test; the file has seven real `#[Test]` methods.

## What this method cannot see

- **The input was not re-verified.** These are the same 81 paths — the union of two wave-1
  witnesses. A control that neither witness saw (say, a census with not a single file call,
  sitting in `Unit`) never entered the population and is not caught by this method. Unlike the
  old one, the new criterion doesn't rely on file calls at all — which means an input miss became
  MORE likely, not less: the wave-1 mechanical witness searched for exactly the trait the
  criterion no longer considers essential.
- **Nothing was executed.** The brief forbids running anything; every verdict is from reading.
  Splitting `mixed` by method is not proven by a build: shared private helpers, constants
  (`REGISTERED_RULE_COUNT`, pinned lists) and `#[CoversClass]` may tie the halves together more
  tightly than reading shows.
- **The "census" / "spot-check" boundary inside a single method.**
  `ConfigSchemaTest::itReturnsTheCorrectSubKeysPerSection` does both: it pins
  `['dir','enabled']` for `cache` and, in the same breath, requires `assertEqualsCanonicalizing`
  over every section. It is assigned to repo-control by predominance, but it would be more honest
  to split the method rather than the file. A second known case is
  `ConfigurationErrorClassificationTopologyTest::itRefusesAProductionSiteThatHandsTheFlagToTheConstructorInstead`:
  `isPrivate()` is an assertion about source shape, while the next two factory calls are behavior
  on the test's input. It, too, is assigned by predominance. With two known cases already, there
  are surely more: I only looked for them where the verdict was disputed.
- **`DocumentationConsistencyTest` does not fit the axis as a whole.** Its fourteen methods guard
  different subjects: the plan index, rule names in `default-thresholds.md`, CLI aliases, YAML
  examples in the README, the LLM catalog, the ratchet count. The `DocumentationCensus` group is
  an honest "this is a rake made of different subjects" for it, not a subject. Along the axis it
  should split by method into `RuleDeclaration`, `RatchetArtifact` and a separate "plans" subject,
  and I did not do that.
- **`SolePrimitiveOwnership` is the axis's weakest group.** Its subject is "the primitive has
  exactly one reader in `src/`". That is closer to tree shape than to a vocabulary, and it was
  not checked by co-change: editing `GlobSyntax` and editing `Version` are separate events, with
  nothing in common but the property being proven. If the axis is disputed, this is where the
  dispute will start.
- **A product test that silently depends on repository state.**
  `DeclarationIdentityTest::itKeepsARealAcceptedFindingAcceptedWhenTextAboveItChanges` is classed
  as product-test: the expected side is "exit 0 before and after a blank line", i.e. behavioral
  invariance. But it takes its input from the repository
  (`src/Analysis/Evidence/Cohesion/LcomOptions.php` plus `qmx-baseline.json`), and if the accepted
  finding on that file ever stops existing in the ratchet, the test stays green while checking
  nothing at all. This is a zero-binding, and the subject-based criterion does not see it: it asks
  about the expected side, while here it is the input side that leaks.
- **Co-change was measured on one subject.** Three commits, all about "channel". For
  `ThresholdKeys`, `Occurrence` and `ModularOwnership` the axis is justified by reasoning, not by
  `git log`. A subject whose controls travel in pairs with someone else's is not caught by this
  check.
- **A third level of helpers.** As in the earlier pass: direct helpers and direct `require_once`
  calls were checked, but not what the thing pulled in itself pulls in.
