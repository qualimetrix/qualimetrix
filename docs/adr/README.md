# Architecture Decision Records

This directory contains Architecture Decision Records: concise documents that
capture durable design choices and the reasons behind them.

## When to create an ADR

Create an ADR after a feature introduces a non-obvious decision that cannot be
recovered reliably from code and tests. Audits, inventories, execution plans,
measurement protocols, and implementation diaries belong with the subject or
tool that owns them, not in this index.

## Format

Each ADR is a Markdown file named `NNNN-short-title.md`.

```markdown
# NNNN. Short Title

**Date:** YYYY-MM-DD
**Status:** Proposed | Accepted | Superseded by NNNN

## Context

What problem or question prompted this decision?

## Decision

What was decided and why? Include alternatives considered.

## Consequences

What follows from the decision: trade-offs, constraints, and implications.
```

## Guidelines

- Keep the record concise and separate durable decisions from implementation
  evidence.
- Keep one coherent subject per ADR; use links when another accepted decision
  owns part of the contract.
- An accepted ADR is historical evidence. When its decision changes, create a
  new ADR and mark the old record as superseded.
- Current implementation details belong in the owning component README.
- A current ADR must be self-contained and must not depend on an execution plan.

## Current decisions

- [0001 — Computed Metrics](0001-computed-metrics.md) — formula-based computed metrics and health scores.
- [0002 — Interactive HTML Report](0002-html-report.md) — self-contained interactive report delivery.
- [0003 — Reporting UX Redesign](0003-reporting-ux-redesign.md) — summary-first reporting and progressive disclosure.
- [0006 — Declaration-Order Matching](0006-architecture-rules-declaration-order.md) — first matching declared layer wins.
- [0009 — YAML Loader Normalization](0009-yaml-loader-normalization-model.md) — schema-owned normalization policy.
- [0013 — Threshold Override Validators](0013-threshold-override-validators.md) — options-owned threshold validation.
- [0014 — Deptrac Retirement](0014-deptrac-retirement.md) — project-owned architecture enforcement replaces deptrac.
- [0015 — Typed Paths](0015-relative-path-vo.md) — typed absolute and relative path boundaries.
- [0016 — Subject Cohesion](0016-subject-cohesion.md) — directories and module boundaries follow subjects.
- [0017 — Baseline Ceiling](0017-baseline-ceiling.md) — baselines cap the reported magnitude they accepted; amended 2026-09-24: the baseline commands accept the project-scope flags `--include-generated` and `--include-autoload-dev`, `baseline:migrate` is gone, and a copy of a duplicate block's findings follow the blocks the detector finds and the copy's file, rather than its first copy.
- [0018 — Coverage, Verdict, and Projection](0018-analysis-coverage-verdict-and-output-projection.md) — analysis completeness is separate from verdict and presentation; its discovered population is widened by 0078.
- [0019 — Namespace Metric Ownership](0019-namespace-metric-ownership-and-attribution.md) — explicit namespace contribution and attribution.
- [0020 — Method Size and NPath](0020-method-size-and-npath-semantics.md) — method-size and recursive NPath semantics.
- [0021 — Declaration-Scoped Identity](0021-declaration-scoped-callable-identity-and-dependency-projections.md) — callable identity and dependency projections, partially superseded by 0026.
- [0022 — Capability-Oriented Modular Monolith](0022-capability-oriented-modular-monolith.md) — capability ownership, manifest governance, and module topology.
- [0023 — Context Locality and Composition Bindings](0023-p8-context-locality-and-composition-bindings.md) — owner-local state and exact private composition.
- [0024 — Channel Identity and Selectors](0024-channel-identity-and-selector-semantics.md) — channel addressing and selection semantics.
- [0026 — Assigned Declaration Ordinal](0026-assigned-declaration-ordinal.md) — stable declaration ordering and its named limits.
- [0027 — Weight of Class](0027-weight-of-class-measures-accessors-not-visibility.md) — accessor-based WOC semantics.
- [0029 — Channel Presentation Join](0029-channel-presentation-join.md) — presentation joins channel and producer metadata at run time.
- [0030 — One Rule per Judgement](0030-one-rule-per-type-coverage-dimension.md) — independent judgements have independent producers; the five declaration diagnostics' former gate/options relationship is partially superseded by ADR 0103.
- [0031 — Producer-Owned Channel Shape](0031-channel-shape-is-a-producer-property.md) — a producer declares magnitude or occurrence shape.
- [0032 — Computed-Metric Producer Split](0032-computed-metric-producer-split.md) — one producer name per closed built-in definition.
- [0033 — Derived Display Family](0033-display-family-is-derived-from-the-producer-name.md) — presentation family follows the producer name.
- [0034 — Symbol Level](0034-the-level-is-a-coordinate-of-a-symbol.md) — level is a coordinate of symbol identity.
- [0035 — Metric Key Grammar](0035-a-metric-key-names-its-family-in-kebab.md) — metric keys name their subject family in kebab case.
- [0036 — Formula Metric Addressing](0036-a-formula-addresses-a-metric-by-its-key.md) — formulas address metrics by published key.
- [0037 — Suppressed Output](0037-suppressed-format-and-produced-findings.md) — produced, published, and suppressed findings remain distinct.
- [0038 — Options-Owned Warning Boundary](0038-an-options-class-names-its-own-warning-boundary.md) — options expose their configured warning boundary.
- [0039 — Directive Audit Contract](0039-directive-audit-command-and-contract.md) — the directive audit is a separate four-verdict contract.
- [0040 — Narrow Directive Sweep](0040-narrow-directive-sweep.md) — threshold directives are checked by re-executing their producer.
- [0041 — Unsuppressible Unused-Directive Channel](0041-no-directive-may-silence-the-unused-directive-channel.md) — a directive cannot hide its own ineffectiveness.
- [0043 — Late-Assembled Finding Selection](0043-late-assembled-findings-and-channel-selection.md) — late findings obey channel selection, not a frozen exclusion ledger.
- [0044 — Identifier-Keyed Configuration](0044-identifier-keyed-configuration-options.md) — authored identifier spelling survives normalization.
- [0045 — Error Stream Ownership](0045-one-owner-for-the-error-stream.md) — one adapter owns diagnostics and progress interaction.
- [0046 — Judged Metric Declaration](0046-a-channel-declares-the-metric-it-judges.md) — channels declare the metric they judge.
- [0047 — Suppression and Exclusion](0047-suppression-is-not-exclusion.md) — produced-and-hidden findings use suppression vocabulary.
- [0049 — Rule Option Recognition](0049-rule-option-key-recognition.md) — option keys are recognized at every declared depth or refused.
- [0050 — Configuration Refusal Carrier](0050-configuration-refusal-carrier.md) — Configuration owns the typed bad-input carrier.
- [0051 — Refusal Routing](0051-refusal-is-not-routed-by-command.md) — throwable kind, not command, determines refusal presentation; amended 2026-09-23: the JSON envelope is `{error, exit_code, position}`, publishing the refused key's position; amended 2026-09-24: an ending after the report is published goes to stderr.
- [0055 — Rule Option Value Shape](0055-a-rule-option-declares-the-shape-of-its-value.md) — accepted option keys declare their value shape.
- [0058 — Layered Value Survival](0058-a-layers-value-survives-the-layers-above-it.md) — higher layers preserve values they did not rewrite.
- [0059 — Declared-Layer Policy and Architecture Governance](0059-declared-layer-policy-and-architecture-governance.md) — current layer-policy semantics and manifest authority.
- [0060 — Published Vocabulary and Name Ownership](0060-published-vocabulary-and-name-ownership.md) — current naming grammar, owners, and completeness rule.
- [0061 — Configuration Miss and Refusal Semantics](0061-configuration-miss-and-refusal-semantics.md) — final miss classification and refusal contract.
- [0062 — Health Scores Measure What They Cover](0062-health-scores-measure-what-they-cover.md) — aggregation, corpus floor, and recalibrated thresholds for the six health dimensions, partially superseded by 0080.
- [0063 — One Declaration Answers About a Rule's Options](0063-one-declaration-answers-about-a-rules-options.md) — the rules listing and the option refusal read one declaration.
- [0064 — The HTML Viewer Lives Outside the PSR-4 Root](0064-the-html-viewer-lives-outside-the-psr-4-root.md) — the HTML report's browser program is a root-level subject, not a PSR-4 path.
- [0065 — The Manifest Records Ownership, Not a Migration Schedule](0065-the-manifest-records-ownership-not-a-migration-schedule.md) — the migration's package labels leave the manifest; a consumer is owner-wide or exact, and never dated.
- [0066 — The Phar Is Built by a Tool Outside the Graph It Builds](0066-the-phar-is-built-by-a-tool-outside-the-graph-it-builds.md) — box is fetched and pinned rather than required; the include list is a third enumeration with a control holding it to the dist's.
- [0067 — Every Test-Shaped Directory Is One the Inventory Scans](0067-every-test-shaped-directory-is-scanned.md) — the test-shaped half of the unregistered-root gap is closed by a population of test-shaped directories minus a literal exclusion list; the non-test-shaped half stays open and is named.
- [0068 — The Pre-commit Hook Is Generated, Not Shipped](0068-the-pre-commit-hook-is-generated-not-shipped.md) — `hook:install` writes the hook's contents; the script it used to symlink reached no consumer.
- [0069 — A Metric Is Declared by the Collector That Writes It](0069-a-metric-is-declared-by-the-collector-that-writes-it.md) — re-aggregation follows the declaration, so the collector writing the published value owns it, and a metric's population is the set the measuring pass identified.
- [0070 — Subprocess Read Discipline](0070-subprocess-read-discipline.md) — one drain-safe way to run a child process and capture its output, with supervision left where it is.
- [0071 — An Anonymous Class's Declaration Edge Is Flagged, Not Retyped or Dropped](0071-an-anonymous-classs-declaration-edge-is-flagged-not-retyped.md) — a nested anonymous class's own `extends`/`implements`/attribute/`trait_use` edge is marked as not a declaration fact of its recorded source, so declaration readers (DIT, NOC, layer membership) skip it while dependency readers (coupling, ClassRank, cycles, `relations:`) keep reading it as before.
- [0072 — The Commit-Subject Rule Names the Commits It Can Judge](0072-commit-subject-authority.md) — the commit-msg hook declines to judge subjects git itself writes, detected by `MERGE_HEAD`/`REVERT_HEAD`/`CHERRY_PICK_HEAD` rather than by the word "Merge", and CI judges a pull request's own commits so a conflict can no longer decide the verdict.
- [0073 — A Depth Belongs to a Declaration, a Child Count to a Name](0073-a-depth-belongs-to-a-declaration-a-child-count-to-a-name.md) — exact child-declaration identity and distinct logical-child NOC remain current; ancestry outcomes and duplicate-parent merging are partially superseded by ADR 0107.
- [0074 — DIT Reads the Ancestors It Measures](0074-dit-reads-the-ancestors-it-measures.md) — depth beyond the analysed path is followed by parsing the project's own sources, never by loading them; the port is Design's and the adapter is Infrastructure's.
- [0075 — The Builtin-Class List Is Compared, Never Generated](0075-the-builtin-class-list-is-compared-never-generated.md) — the hand-written registry of PHP's own classes stays hand-written so metrics do not become a function of the analysing machine; a governance control compares it against the loaded extensions in both directions, and the extension and version metadata that comparison needs lives in the control rather than in the product.
- [0076 — A Floor Is Reported, and Said to Be One](0076-a-floor-is-reported-and-said-to-be-one.md) — historical floor-publication and collector-warning decision, partially superseded by ADR 0107 for loops, the unresolved metric and rule-owned diagnostics.
- [0077 — Open-Universe Selectors Are Explicit](0077-open-universe-selectors-are-explicit.md) — path and PHP-name selectors use explicit `exact`, `subtree`, or full-subject `regex` forms; Architecture's mutual DSL refusal is partially superseded by ADR 0103.
- [0078 — An Entry the Run Did Not Read Makes It Incomplete](0078-an-entry-the-run-did-not-read-makes-it-incomplete.md) — a directory symlink, a non-regular `*.php` entry and an unlistable directory are terminal failures rather than silent drops, so the run is incomplete and answers exit 4; the published `kind` vocabulary grows to five, and a path named on the command line is still followed.
- [0079 — A Criterion the Run Cannot Answer Is Undecidable, Not a Non-Match](0079-a-criterion-the-run-cannot-answer-is-undecidable.md) — graph-backed layer criteria combine three-valued, so a chain that leaves the analysed set no longer reads as a confident non-match; an unanswered layer or `exclude:` never withdraws a match and the doubt is published on `architecture.doubted-assignment`, a layer naming only types the run never met stays unreachable, template observation falls toward the layer existing, and a template naming a non-pattern criterion under `match: any` is refused; amended 2026-09-24: a type the analysed project's composer install declares is asked of the install, the whole-project channels wait for a run that covers the project (see ADR 0084), and a carve-out loads.
- [0080 — A Project Fold Reads a Partition, Not the Leaves](0080-a-project-fold-reads-a-partition-not-the-leaves.md) — the project population of a namespace-collected metric is every namespace declaring a type, folded over its own scope rather than its subtree rollup; the coverage denominator comes from a size measurement independent of that fold.
- [0081 — A Channel Not Named After Its Producer Describes Itself](0081-a-channel-not-named-after-its-producer-describes-itself.md) — a channel's description lives on its declaration; a channel whose name differs from its producer's must declare one and the producer's own channel must not, or the container build refuses, so SARIF no longer publishes a producer's description for another channel.
- [0082 — The Project Aggregate Is Typed, Not Spelled](0082-the-project-aggregate-is-typed-not-spelled.md) — only `SymbolPath::forProject()` yields the project type; its namespace field shows `(project)`, a form no PHP namespace can take, so a namespace named `__PROJECT__` is analysed as itself.
- [0083 — A Number Option Declares Its Range in Its Form](0083-a-number-option-declares-its-range-in-its-form.md) — whole and fractional rule options accept 0 and above and a negative value is refused by the value; only computed-metric thresholds declare the signed form.
- [0084 — A Project Scope Has Three States, and the Report Names It](0084-a-project-scope-has-three-states-and-the-report-names-it.md) — covered, narrowed or unknown; only a narrowed run withholds the whole-project channels, an unknown one leaves namespace values of suppressions unjudged, every format with a place for it publishes the state, and the suppression values a run skipped are named with the channels derived from them.
- [0085 — A Copy of a Duplicate Block Is a Finding of Its Own](0085-a-copy-of-a-duplicate-block-is-a-finding-of-its-own.md) — `duplication.clone` reports one finding on each copy under an identity of its own and valued by the lines that copy spans, every copy of a block its longest copy admits by `min_lines` — so a pasted copy, however densely written, is a new finding in its own file and to GitLab/SARIF.
- [0086 — One Configuration Document, Merged by Declared Policy](0086-one-configuration-document-merged-by-declared-policy.md) — every node of the configuration document declares its keys, form, merge policy and shorthands; one engine judges each layer before merging, `~` is "not written" and `{}` changes nothing at any depth, a refusal and a warning name the layers they are about, and the author's table of policies is generated from the declarations.

- [0087 — The Finding Gate Declares Measured Changes](0087-the-finding-gate-declares-measured-changes.md) — complete physical records, one correspondence for ranking values, occurrence-preserving order and exact measured declaration forms; its self-test witnesses and negative controls are superseded by ADR 0130.

- [0088 — Atomic Section Declarations and a Shared Format Vocabulary](0088-atomic-section-declarations-and-format-vocabulary.md) — one key/schema declaration per provider, one registered format dictionary for both validation boundaries, and one project-scope measurement for callers.

- [0089 — Composer Manifest Facts and Project Scope Reasons](0089-composer-manifest-facts-and-project-scope-reasons.md) — one captured Composer universe distinguishes intact, partial and unavailable project evidence.

- [0090 — Exact Finding Gate Surface Deltas](0090-exact-finding-gate-surface-deltas.md) — measured surface changes and baseline eligibility are declared exactly when semantic forms cannot express them.

- [0091 — Declared Rule Options and Enablement](0091-declared-rule-options-and-enablement.md) — typed authored forms and one immutable execution/publication snapshot.

- [0092 — Typed Document Declarations and Option Judgement](0092-typed-document-declarations-and-option-judgement.md) — typed document facts and rule option form projection.

- [0093 — Measured Run Scope and Project Tree Queries](0093-measured-run-scope-and-project-tree-queries.md) — one metadata walk separates declaration absence from selector binding and retains the source snapshot.

- [0094 — Analysis Results Publish Subject-Owned Values](0094-analysis-results-publish-subject-owned-values.md) — measurement and directive observations have their own owners; execution publication and late findings compose without duplicates and preserve merge order.

- [0095 — Inline Directives Are Authored Sites with Bounded Reach](0095-inline-directives-are-authored-sites-with-bounded-reach.md) — Inline owns physical admission, declaration reach, shared refusals and actual suppression attribution; Reporting consumes the measured site.

- [0096 — File Target Claims](0096-file-target-claims.md) — neutral filesystem judgement, held writes, publication and explicit descriptor/race limits.

- [0097 — Duplication Copy Evidence](0097-duplication-copy-evidence.md) — connected token coverage, copy-owned values, File identity and explicit incomplete-run limits.
- [0098 — Baseline Entry Comparability](0098-baseline-entry-comparability.md) — baseline ceilings compare only complete, compatible finding groups with explicit unmeasured outcomes.
- [0099 — Rule Option Shape Is a Declaration](0099-rule-option-shape-is-a-declaration.md) — rule option shapes are immutable declaration data projected through the shared configuration schema.
- [0100 — Core AST Name Resolution and Superglobal Reads](0100-core-ast-name-resolution-and-superglobal-reads.md) — shared, bounded AST evidence for detector consumers.
- [0101 — Class Count Judges the Own Namespace](0101-class-count-judges-own-namespace.md) — all namespace levels use own count while subtree totals stay published.
- [0102 — Detector Verdicts and Finding Identity](0102-detector-verdicts-and-finding-identity.md) — credential and smell decisions, declaration identity, and raw threshold wording; amended 2026-10-22: file-subject namespace selection; amended 2026-10-22: one direct bare-continue guard before later work is not a chain attempt.

- [0103 — Layer Policy Declaration Evidence and Selection](0103-layer-policy-declaration-evidence-and-selection.md) — separate declaration facts, source-owned findings, independent diagnostic publication, observed spelling and consumer baseline migration.

- [0104 — Source Bytes and Prose Publication](0104-source-bytes-and-prose-publication.md) — canonical identity, display repairs and the closed prose mode.
- [0105 — Finding Publication and Drill-down](0105-finding-publication-and-drill-down.md) — shared records, published bags, selection reach and generated-hook revision; amended 2026-10-22: lexical namespace selection for file findings.
- [0106 — Declaration Metric Records and Declared Publication](0106-declaration-metric-records-and-declared-publication.md) — exact declaration records separate metric publication from logical dependency identities; amended 2026-10-22: lexical namespace records for file findings.

- [0107 — Inheritance Chain Outcomes](0107-inheritance-chain-outcomes.md) — exact depths, numeric floors, non-numeric loops and independent Throwable knowledge share one Design-owned ancestry result.

- [0108 — Health Score Applicability and Evaluation](0108-health-score-applicability-and-evaluation.md) — health evaluation distinguishes measured zero, absent input, inapplicability and invalid applicable input.
- [0109 — Rule Populations and Coupling Units](0109-rule-populations-and-coupling-units.md) — channel declarations own eligibility, data-gap judgement and stable coupling units.
- [0110 — Complete Offender Ranking and Catalog Thresholds](0110-complete-offender-ranking-and-catalog-thresholds.md) — one complete offender snapshot and resolved definition catalog drive ranking and presentation.

- [0111 — Resolved RFC Targets and Trait Composition](0111-resolved-rfc-targets-and-trait-composition.md) — exact response membership and neutral PHP trait method composition.

- [0112 — Validation Check Scheduling](0112-validation-check-scheduling.md) — verified code tests finish before PHPStan overlaps the remaining validation groups; amended 2026-10-22: each command's captured output is published when that command exits; amended 2026-10-23: publication waits for the whole process group, at most a 2-second grace in normal execution, and interrupt cleanup precedes every output write.

- [0113 — Producer-Declared Metric Reach](0113-producer-declared-metric-reach.md) — evidence dependency is declared by the producer independently of execution phase.

- [0114 — Effective methods and cohesion populations](0114-effective-methods-and-cohesion-populations.md) — effective trait methods, graph populations and conservative Run baseline comparison.
- [0115 — Defined distance and type-weighted project fold](0115-defined-distance-and-type-weighted-project-fold.md) — defined abstractness/distance and eligible-type-weighted own scopes.
- [0116 — Measured health-score recalibration](0116-measured-health-score-recalibration.md) — declared search, measured calibration and before/after receipts.

- [0117 — Inheritance depth completeness publication](0117-inheritance-depth-completeness-publication.md) — bounded collected-measurement outcomes survive rule activation and finding selection.
- [0118 — PHPUnit execution working directory](0118-phpunit-execution-working-directory.md) — parent-owned execution windows, reasoned residue and native Git fixtures.
- [0119 — The Process Edge Answers Every Ending](0119-process-edge-answers-every-ending.md) — one Console process boundary owns refusals, diagnostic streams and answer publication.
- [0120 — AST Cache Is Untrusted Input Outside the Checkout](0120-ast-cache-untrusted-outside-checkout.md) — shared external cache location, current-format decoding and bounded cleanup.
- [0121 — Parallel Collection Is Judged by Worker Start](0121-parallel-collection-judged-by-worker-start.md) — process-worker selection is judged by a bounded first probe and explicit-start refusals.
- [0122 — Self-Analysis Exceptions Remain Bounded](0122-self-analysis-exceptions-remain-bounded.md) — accepted quality debt keeps exact finite ceilings; live structural and contract-hub exclusions remain narrow.
- [0123 — Worker Process Boundary and Strategy Port](0123-worker-process-boundary-and-strategy-port.md) — Parallel owns both sides of its task protocol, and runtime startup determines worker availability.
- [0124 — Finding Publication and Rule Execution Contracts](0124-finding-publication-and-rule-execution-contracts.md) — publication readers use a narrow contract over the shared invocation snapshot and ledger.
- [0125 — Subject Boundaries and Explicit Composition](0125-subject-boundaries-and-explicit-composition.md) — subject namespaces, finite DI registration and separate Console preparation, assembly and publication.
- [0126 — Warning-Only Ratchet and Exact Signal Decisions](0126-warning-only-ratchet-and-exact-signal-decisions.md) — residual debt stays warning-only while class limits and contract-popularity exceptions remain exact.
- [0128 — One-Way Subject Operations](0128-subject-boundaries-with-one-way-operations.md) — authored facts, file-target resources, named metric families and deterministic directive advice.
- [0129 — Public Report and Baseline Values with Subject-Owned Delivery](0129-public-report-and-baseline-values-with-subject-owned-delivery.md) — public immutable report and baseline values remain separate from subject-owned assembly and delivery.
- [0130 — The Finding Gate Keeps Behaviour Tests, Not a Witness Registry](0130-the-finding-gate-keeps-behaviour-tests-not-a-witness-registry.md) — behaviour and failure-class tests at the cheapest level; a comparator change proves its new check bites once.
- [0131 — A Value Object Exposes Its State](0131-a-value-object-exposes-its-state.md) — a readonly all-promoted constructor is a VO only when its class exposes a promoted property; constructor overinjection leaves VO constructors to long-parameter-list.
- [0132 — Editor Schema of the Configuration Document](0132-editor-schema-of-the-configuration-document.md) — `qmx.schema.json` is projected from the section declarations, judges one file's form without being stricter than the engine, and is held to the engine itself.
- [0133 — Removed Code's Namespaces Narrow the Suppression Namespace Audit](0133-removed-code-namespaces-narrow-the-suppression-namespace-audit.md) — literal namespace suppression selectors are judged against the namespaces excluded and generated code declares, within bounded read limits; supplements ADR 0093.
- [0134 — References in Attribute Arguments Are Their Own Edge Kind](0134-attribute-argument-edges-are-their-own-kind.md) — `attribute_argument` is a standalone relations kind outside every alias, the vocabulary lives in DependencyModel, and `architecture.circular-dependency` gains `ignore-relations`; answers ADR 0071.
- [0135 — Namespace Instability Judges Its Own Declarations](0135-namespace-instability-judges-its-own-declarations.md) — namespace `coupling.instability` judges `coupling.instability-own` over the namespace's own types (`min_type_count`, as distance); the own value is absent without a declared type; the Martin diagram and `health.coupling` stay on the subtree; supplements ADR 0080.
- [0136 — A Constructor Has One Parameter-Count Owner](0136-a-constructor-has-one-parameter-count-owner.md) — a value-object constructor belongs to `long-parameter-list`, any other constructor to `constructor-overinjection`; the shared `code-smell.is-injection-constructor` flag drives `long-parameter-list`'s abstention; supplements ADR 0131.
- [0137 — Constructor-Only Helpers Leave the LCOM4 Graph](0137-constructor-only-helpers-leave-the-lcom4-graph.md) — private helpers only a constructor or destructor reaches leave the LCOM4 graph; deferred calls keep their callees; LCOM may rise or the graph may empty; TCC/LCC unchanged.
- [0138 — Closures Are Named Under Their Enclosing Declaration](0138-closures-are-named-under-their-enclosing-declaration.md) — a closure is named `{Ns}\{parent}::{closure#k}` after its nearest enclosing frame and counted inside it; its path stays function-shaped; amends ADR 0026's closure stability statement.
- [0139 — Configuration Imports](0139-configuration-imports.md) — the root configuration file's `imports:` list adds each file as a layer below it, flat and in written order, identified by inode; `imports` is read per file and never composed; the loader takes an origin; C3 linearization is the recorded path to nested imports.
- [0140 — Built-in Preset, Explicit Selector and Nested Imports](0140-built-in-preset-glob-and-nested-imports.md) — `imports:` entries may name a built-in preset, a `subtree` / `regex` selector over the files below the importing file's directory (a glob was withdrawn under ADR 0077), or a file that imports in turn; selector matches are unordered groups inside the C3 merge, since byte order and reachability pre-sorts both refuse graphs another order accepts.
- [0141 — Committed Dist Configuration Discovery](0141-committed-dist-configuration-discovery.md) — with no `qmx.yaml`/`qmx.yml`, discovery reads `qmx.dist.yaml`/`qmx.dist.yml`; a local file wins alone and builds on it only by importing it, an unimported dist file is reported, a dist file selected as a preset stays in that band.

## Superseded history

- [0008 — ArchitectureProcessor Service](0008-architecture-processor-service.md) — replaced by the capability-oriented topology in ADR 0022.
- [0010 — Architecture Vertical-Slice Pilot](0010-architecture-vertical-slice.md) — replaced by subject cohesion and ADR 0022.
- [0012 — Hybrid Architectural Direction](0012-hybrid-architectural-direction.md) — replaced by ADR 0022; its HTML-viewer placement prescription by ADR 0064.
- [0056 — Source Composition Loses the Middle Layer's Value](0056-source-composition-is-measured-and-left-alone.md) — corrected by ADR 0058.
