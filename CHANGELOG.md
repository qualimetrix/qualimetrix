# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- The progress bar is drawn on standard error instead of standard output, so a
  report on a terminal is no longer prefixed with terminal control bytes:
  `bin/qmx check src/ --format=json > report.json` now writes valid JSON without
  `--no-progress`. The bar is shown when standard error is a terminal, which it
  still is when standard output has been redirected.
- `--no-progress` is accepted by every command that shows progress, not by
  `check` alone: `directives`, `debug:layer-assignment`, `baseline:generate`,
  `baseline:update`, `baseline:cleanup` and `baseline:explain` take it too.
  `graph:export` analyses too but has never drawn a bar, so it does not take it.
- `bin/qmx directives` reports what every inline `@qmx-ignore` and `@qmx-threshold`
  in the analysed tree actually does — effective, applied-boundary-only, inert, or
  unmeasured with a named reason — in `text` or `json`, under the same `--preset`,
  `--only-rule`, `--disable-rule` and `--rule-opt` the run being defended uses.
  It exits `2` on a proven inert directive and `4` when the run could not parse
  part of the tree.
- `bin/qmx directives` takes `--sweep=narrow|full` (default `narrow`): a
  `@qmx-threshold` names one rule, so by default only that rule is re-executed
  to judge it; `--sweep=full` re-executes every enabled rule for the same
  verdicts. Both the text and `--format=json` report carry the sweep the
  verdicts were measured under.
- The `produced_findings` count `bin/qmx directives` reports is the number of
  findings the rules produced. It no longer includes `annotation.unused-directive`,
  which a run assembles after rule execution: no directive may address that
  channel, so no verdict is measured against it. On a tree with stale directives
  the number is lower than before.
- `composer check` now audits inline directives as part of `check:self`: a
  proven inert directive fails the aggregate the same way a red gate does.
- `bin/qmx rules` prints, under each rule, the catalogue metric its channels
  judge. Twenty-two of the fifty-two static channels say something; the rest
  publish a magnitude of their own making, or no magnitude at all, and stay
  silent. The rule pages carry the same pair under their rule id.
- A `@qmx-threshold` naming a metric key instead of a rule is answered with the
  channel that judges that metric and the rule to address. It used to be told
  that no declared name was close to it: `complexity.ccn` is eight edits from
  `complexity.cyclomatic`, so a near-spelling search could never reach the
  answer.

### Fixed

- A mistyped directive target is no longer answered with `annotation.unused-directive`.
  The near-spelling search offered it to anyone who mistyped a neighbouring
  `annotation.*` name — it sits one edit from its own family — and following the
  advice produced a directive the next run refuses. Every branch of the answer now
  drops it: the near-spelling search, the channel list a rule name is answered
  with, and the answer to a group form. The full channel list of a rule, banned
  ones included, is what `qmx rules` is for.
- `--disable-rule` and `--only-rule` act on `annotation.unused-directive`. Naming
  it in `--disable-rule` was inert and said nothing, and an `--only-rule` naming
  a sibling channel of `annotation.directive` published it anyway. The channel is
  assembled after rule execution, which until now also meant assembled past the
  selection every other finding passes. The per-rule `exclude_paths` /
  `exclude_namespaces` under that rule are still inapplicable to it, as before.
- The progress bar and detailed logging no longer destroy each other on a
  terminal. Both write to standard error, and the bar erased upwards by the
  height of its own section, so at `-vv` and `-vvv` a log line that arrived
  between two frames was wiped out and the bar itself froze at `0%` for the rest
  of the run. Every writer to that stream — the logger, warnings emitted during
  a run, the report notes, `graph:export`'s incomplete-analysis report and an
  uncaught error's trace — now goes through one owner, which erases the frame,
  writes the line permanently and redraws the frame beneath it.
- An `exclude_namespace_channels` key can name a channel whose name contains a
  hyphen. The keys of that map were case-normalized along with the typed option
  keys around them, so `code-smell.boolean-argument` reached the run as
  `codeSmell.booleanArgument` and ended it with exit code 3 — printing the
  correct name in the same sentence that refused the written one. Every form of
  the key was affected: the exact name, the `X.*` group, the `channel:namespace`
  pair, and every computed metric, whose names the name validator *requires* to
  be kebab. Keys without a hyphen are unaffected, and nothing else about the
  option changes: a key still has to name a channel its rule produces, and one
  naming a channel that never reports a namespace aggregate is still accepted
  and still excludes nothing.
- `bin/qmx directives` no longer demands the removal of a live directive whose
  rule the configuration switched off per level. A directive bound to a
  declaration — `@qmx-threshold`, and `@qmx-ignore` in a docblock — now reports
  `unmeasured` and exit `0` when the rule is off at the level it sits on, as a
  rule disabled through a plain `enabled: false` already did, instead of
  `inert` and exit `2`. The two physical forms, `@qmx-ignore-file` and
  `@qmx-ignore-next-line`, carry no declaration and are still answered at
  producer granularity: a rule off at only one of its levels still reports them
  `inert`.

### Breaking

- `LoggerFactoryInterface::create()` takes the run's already-resolved diagnostic
  writer, not the console output. The parameter type is unchanged
  (`Symfony\Component\Console\Output\OutputInterface`) and the rename
  `$output` → `$diagnostics` does not stop old code compiling, but the factory
  no longer calls `getErrorOutput()` on what it is given: a caller that passed a
  full `ConsoleOutputInterface` expecting the factory to pick standard error now
  gets its log on standard output. Pass the writer the error stream's owner
  hands out instead —
  `$errorStream->writer($output)`, from
  `Qualimetrix\Infrastructure\Console\ErrorStream` — which is what
  `RuntimeLoggerConfigurator` does. The stream has one owner now, and choosing
  one here was the second opinion that put log lines inside the progress frame.
- The console classes that write diagnostics take that owner as a **required**
  constructor argument: `Application`, `ResultPresenter`, `ProfilePresenter`,
  `RuntimeLoggerConfigurator`, `FindingFilterOrchestrator` and
  `GraphExportCommand` no longer default it to an `ErrorStream` of their own.
  A composition that omitted it used to get a private owner, drawing its frame
  around a section list nobody else shares — the two-owner defect, reintroduced
  by omission. Two of them also take it earlier in the signature, before their
  optional collaborators: `ProfilePresenter($report, $errorStream, $renderer)`
  and `GraphExportCommand($analyzer, $projection, $errorStream, $logger)`.
  Code composing these by hand should pass the one instance the container holds
  (`$container->get(ErrorStream::class)`), as `bin/qmx` does.
- No inline directive can silence `annotation.unused-directive` any more — the
  channel that reports which directives did nothing. Three separate things
  change for a project that used it.

  **A directive naming the channel is now refused.** `@qmx-ignore`,
  `@qmx-ignore-next-line` and `@qmx-ignore-file` all fail the run with
  `annotation.unresolved-directive` on the line the directive was written on,
  whether the target is the exact name, `annotation.*`, or either with `:file`
  after it. Twelve spellings in total.

  **The form with no rule filter stops silencing it — with no diagnostic.**
  A bare `@qmx-ignore-file` with no channel, or an explicit `*` target on
  `@qmx-ignore-file` / `@qmx-ignore-next-line`, names nothing, so there is
  nothing to refuse and nothing to report; findings it hid until now simply
  appear in the report. There is no warning for this one, and there cannot be:
  this entry is the only notice of it.

  **`@qmx-ignore-file annotation.*` no longer addresses the three
  configuration-error channels.** It used to be a legal way to address
  `annotation.unresolved-directive`, `annotation.unsupported-threshold` and
  `annotation.invalid-threshold` together, and was reported `inert`. It is now
  refused whole, because its expansion reaches the banned channel. Naming any
  of the three by its exact name is unchanged.

  What to do instead, with what else each choice takes with it:

| Instead of the directive                 | What else it silences                                                                                                                                                                                             |
| ---------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Delete the directive it complains about  | nothing                                                                                                                                                                                                           |
| Accept the finding into a baseline       | nothing; the channel stays ratchetable on purpose                                                                                                                                                                 |
| Narrow the run with a git scope          | nothing on this channel; the scope applies to the whole run as always                                                                                                                                             |
| Top-level `exclude_paths`                | the file leaves the analysis entirely, with every channel on it                                                                                                                                                   |
| `rules: { annotation.directive: false }` | the three configuration-error channels above go with it — `annotation.unresolved-directive`, `annotation.unsupported-threshold`, `annotation.invalid-threshold` — because the same rule's validator declares them |

  Two exclusions do **not** work on this channel and never did: the top-level
  `exclude_namespaces` (the finding's subject is the file the annotation sits
  in, so it carries no namespace to match) and the rule's own `exclude_paths` /
  `exclude_namespaces` (they close with rule execution, and this channel is
  assembled after it).

  A finding on the channel is otherwise unchanged: ordinary debt with a
  configurable severity, inside every stage of the pipeline. Rationale and the
  rejected alternatives are in ADR 0041,
  `docs/adr/0041-no-directive-may-silence-the-unused-directive-channel.md`.
- Every class of the `design.*` rules moves under a subject segment of its own:
  `Qualimetrix\Analysis\Evidence\Design\DitGlobalCollector` becomes
  `Qualimetrix\Analysis\Evidence\Design\Inheritance\DitGlobalCollector`, and likewise
  for the `DataClass`, `GodClass` and `TypeCoverage` subjects. Rule names, channel
  names, metric keys and CLI options are unchanged; only class strings move.
- `ThresholdAwareOptionsInterface` requires `warningBoundary()`, returning the
  class's warning threshold or `NoConfiguredBoundary::MoreThanOneBoundary` when
  it holds several. `baseline:explain` asks for the number instead of guessing a
  property name, and now resolves `coupling.distance`, `design.god-class` and
  `design.data-class`, all three of which it previously reported as
  "not resolvable".
- Every published metric key is renamed to `family.metric` in kebab: `ccn` →
  `complexity.ccn`, `classCount` → `size.class-count`, `typeCoverage.paramTotal`
  → `design.type-coverage.param.total`, and so on for all 82. Aggregated
  spellings follow their key (`ccn.avg` → `complexity.ccn.avg`). The keys appear
  in `--format=metrics`, `--format=json` and the HTML report.
- Computed-metric formulas address a metric by its key through one variable:
  `m["complexity.ccn.avg"]` replaces the `ccn__avg` encoding. A formula that
  indexes `m` with anything but a quoted literal is refused.
- A computed metric's name must be lower-case kebab after `health.` or
  `computed.`: `computed.my_score` becomes `computed.my-score`.
- The three type-coverage rules are renamed `design.type-coverage.param`,
  `design.type-coverage.return` and `design.type-coverage.property`. Their CLI
  options are unchanged (`--param-type-coverage-warning` and its siblings).
- Every rule name must be lower-case kebab in every segment; a malformed name
  now fails container assembly instead of registering under a heading of its own.
- `qmx rules --group=<name>` fails and lists the existing groups when no rule
  belongs to the group, instead of printing an empty listing and exiting 0.
- Suppression stops calling itself exclusion. Of the six mechanisms that used
  to hide under the word `exclude`, four earned it — the finding is never
  produced — and stay: root `exclude`, `exclude_health`,
  `architecture.<layer>.exclude`, and the per-rule `exclude_readonly` /
  `exclude_promoted_only` / `exclude_data_classes` / `exclude_tests` /
  `exclude_exceptions` / `exclude_methods` family are unchanged. The other two
  produce a finding and then throw it away, and are renamed: `exclude_paths` →
  `suppress_paths`, `exclude_namespaces` → `suppress_namespaces`,
  `exclude_namespace_channels` → `suppress_namespace_channels`, at both the
  root of `qmx.yaml` and inside a `rules: { <rule-name>: { ... } }` block. The
  matching CLI flags rename the same way: `--exclude-path` → `--suppress-path`,
  `--exclude-namespace` → `--suppress-namespace`. `SuppressionMechanism`'s four
  path/namespace values gain the `-suppression` suffix
  (`path-exclusion` → `path-suppression`, `namespace-exclusion` →
  `namespace-suppression`, `rule-path-exclusion` → `rule-path-suppression`,
  `rule-namespace-exclusion` → `rule-namespace-suppression`), visible in
  `--format=suppressed`. Nesting level (global vs. per-rule) stays what it
  always was — a scope of application, not a second mechanism — so the two
  mechanisms are still two, not merged into one. Each retired key and flag is
  **refused**, not silently ignored: an unrecognized key inside a rule used to
  only warn, so an unmigrated config would keep running while its suppressions
  quietly stopped applying; the refusal message names both `suppress_*` (for
  suppressing findings a rule still produces) and the root `exclude` (for
  keeping a file out of the analysis entirely) so the two are not confused
  again. `graph:export --exclude-namespace` is untouched — it narrows the
  exported graph, not a set of findings — and neither is the root `exclude:`
  block. Existing `qmx-baseline.json` files apply unchanged: no channel code
  or subject moves.

### Fixed

- An aggregated metric requirement (`size.class-count.sum`) resolved its base
  key by cutting at the first dot, which matched no provider for any key whose
  name contains a dot.
- The GitHub Action's structured formats (`json`, `sarif`, `gitlab`,
  `suppressed`) redirected QMX's stderr into the same file as the JSON
  payload, so an ordinary product warning (e.g. a coverage notice) made the
  published artifact unparsable. stderr now goes to the Action log as a
  warning annotation instead.
- `annotation.unused-directive` judged a suppression by the findings the report
  published rather than by the findings the rules produced, so a `@qmx-ignore`
  covering a finding that `exclude_namespaces`, `exclude_namespace_channels` or
  `exclude_paths` would have dropped anyway was reported as silencing nothing.

### Changed
- Every rule now declares its own estimated remediation time (in minutes) on its own class, alongside its documentation page and default thresholds. See [Remediation Time](reference/remediation-time.md) for the full table. `coupling.class-rank` debt is no longer scaled by overshoot: its rank is a project-wide normalised PageRank rescaled per class count, so a stored value is not comparable across runs — it now reports its flat base estimate like every other `occurrence`-shaped channel.
- Baseline files are written one entry per line inside the same JSON document: a tightened ceiling is a one-line diff, and the file is two thirds its former size (60 401 B against 90 365 B for this project's own 264 entries). The layout is presentation only — the schema is unchanged at the time of this line-per-entry change, and a reformatted file still loads. (The schema itself changes separately; see the version 12 entry below.)
- Corrected computed-metric reference examples to use the registered `metrics` output format.
- Added universal per-rule `exclude_namespace_channels` configuration for suppressing selected namespace-aggregate violation channels without hiding class findings or sibling channels.
- `architecture.coverage` now includes analysed classes outside every declared layer even when they have no dependency edges, so `coverage: error` can enforce complete project ownership instead of checking only graph endpoints.
- Rule and channel selectors now support an explicit `X.*` wildcard for "strictly the descendants of `X`", available everywhere a name is written: `only_rules`, `disabled_rules`, `--only-rule`, `--disable-rule`, per-rule `exclude_namespace_channels`, and the `@qmx-ignore` family.
- New rule `annotation.directive` reports inline `@qmx-*` directives that address nothing, can never apply, or no longer do anything. Three of its channels (`annotation.unresolved-directive`, `annotation.unsupported-threshold`, `annotation.invalid-threshold`) are configuration errors that end the run regardless of `fail_on`; `annotation.unused-directive` reports a directive that is valid but no longer fires, at a configurable `unused_directive_severity` (default `info`). Diagnostics name what *can* be addressed, so `@qmx-threshold annotation.unused-directive` is told that the string is a channel of the rule `annotation.directive`.
- `@qmx-ignore-file` requires `--` before a reason whenever the channel is omitted, since a bare word right after the tag would otherwise be read as the channel (`annotation.unresolved-directive`). The channel argument on `@qmx-ignore` and `@qmx-ignore-next-line` is mandatory, so `--` stays optional there.
- Inline directives are validated after configuration resolves, so a `@qmx-ignore` naming a user-defined computed-metric channel such as `health.cohesion` resolves exactly like a statically declared one.
- Whether path and namespace exclusions may silence a finding is now a declared property of the channel rather than something inferred from the spelling of the rule name. A new rule named `architecture.something` no longer inherits immunity it did not ask for.
- Abstractness treats a bare enum as neutral and leaves it out of the denominator; an `enum X implements Y` still counts as concrete, because implementing a declared contract is exactly the substitution point a plain list of literals lacks. New `implementingEnumCount` metric; `enumCount` keeps its meaning.
- New rule `architecture.unassigned-class` counts analysed class-like declarations that landed outside every declared layer, without the vendor dependency-edge ends that drown `architecture.coverage`. Off by default; set `mode: ignore|warn|error` on it (CLI `--unassigned-class-mode`). It reports an absolute count, so a baseline can ratchet it down, and it reads the same single walk as `architecture.layer-violation`.
- `pending: true` on a declared layer reserves it for code not written yet and exempts it from `architecture.unreachable-layer`. The new `architecture.pending-layer-matched` diagnostic reports the moment such a layer matches something — including when a broader layer declared earlier wins every one of those matches, which is where the declaration lies loudest.
- `--format=json` for `debug:layer-assignment` returns the layer-assignment resolution (assigned layer, shadowing layers, criteria) as a machine contract, so an agent no longer parses the human report.
- `architecture.potential-shadow` now reports only a layer that is more specific than the one shadowing it, i.e. one declared too late to ever win in its own area. Plain overlap is no longer a finding: first match wins is the declared resolution mechanism, so the documented narrow-before-broad ordering — including a final `**` catch-all — is legal and silent. Pairs whose specificity cannot be compared (mid-pattern wildcards, capture templates, `suffix` / `attributes` / `implements` / `extends` criteria) keep the diagnostic.

- A rule stops running as soon as the disable selectors, taken together, silence every level of every channel it emits — `--disable-rule=duplication.code-duplication:project` now skips the memory-intensive duplication phase exactly as the level-free spelling does, instead of running it in full and filtering all of its output away. The same holds for `architecture.circular-dependency:project`, and for a union such as `--disable-rule=coupling.cbo:class --disable-rule=coupling.cbo:namespace`. One level of a two-level channel still leaves its producer running, and a channel whose levels come from configuration (`computed.*` / `health.*`) is never stopped by a selector naming one of them.
- New output format `suppressed` (`--format=suppressed`, or `format: suppressed` in `qmx.yaml`) reports, as machine-readable JSON, exactly what a run held back from its report and why: every suppressed finding paired with the mechanism that removed it (an inline `@qmx-ignore`, a global or per-rule path/namespace exclusion, the accepted-level baseline, or `--report=git:*` narrowing) and, separately, any configured exclusion that matched nothing at all this run. The composition is a multiset, not a set — one finding can be removed by more than one mechanism, so per-mechanism counts do not add up to a number of suppressed findings, and the format says so. Capture is armed the same way by `--show-suppressed` or by selecting the `suppressed` format itself, so the per-rule exclusion counts either surface reports never disagree; `--show-suppressed` on text prose covers only inline `@qmx-ignore` and per-rule exclusions, not the other mechanisms. A versioned snapshot of this repository's own composition lives under `docs/internal/generated/suppression/` and is checked for freshness by `composer check`.

### Breaking
- A rule no longer declares the group it is listed under; `bin/qmx rules` reads the group off the first dot-separated segment of the producer's name. `RuleInterface::getCategory()`, the `RuleCategory` enum and the `$category` property of `RuleMetadata` and `ProducerDeclaration` are removed, replaced by a derived `RuleMetadata::$family`. **Nothing you write or read changes**: the same producers are listed under the same headings, `--group` takes the same values (`--group=code-smell`, `--group=health`, …), and no channel name, rule name, metric key, configuration key, CLI flag, output field, exit code or baseline entry is affected — the declared category equalled the name's first segment for all 51 registered producers, so removing it removed a second spelling rather than a fact. Only PHP code that named the removed types has to change, and the change is mechanical: drop the `getCategory()` implementation from a rule class, and read `RuleMetadata::$family` (a `string`) where a `RuleCategory` was read before. A producer whose name yields no first segment (`''`, `.orphan`) is now refused while the container is built, instead of being listed under an empty heading. See ADR 0033.
- The six built-in health dimensions are producers of their own; `computed.health` no longer names anything. A finding of `health.complexity`, `health.cohesion`, `health.coupling`, `health.typing`, `health.maintainability`, or `health.overall` now carries that dimension's own name in its `rule` field instead of `computed.health`; a user-defined computed metric carries `computed`. Every surface that used to address `computed.health` now addresses the dimension by its own name instead: `qmx.yaml` `rules:` section keys (`rules: { health.cohesion: { ... } }`, not `rules: { computed.health: { ... } }`), `--disable-rule`, `--only-rule`, `only_rules`/`disabled_rules`, `exclude_namespace_channels` keys, and `@qmx-threshold`/`@qmx-ignore` directives. `bin/qmx rules` lists 51 rules instead of 45, under two group headings that did not exist before — `Health` and `Computed` — in place of the `Maintainability` heading `computed.health` used to be listed under. The debt breakdown (text/verbose output, JSON `violationsMeta.byRule`, the HTML report's `ruleName` field) now shows up to seven rows for computed metrics instead of one. Baseline entries are unaffected — a baseline stores the channel, not the producer — so no regeneration is needed for this change alone. Two configuration switches that used to look identical now look almost identical instead of being the same: `rules: { health.cohesion: { enabled: false } }` stops the dimension from publishing findings, while `computed_metrics: { health.cohesion: { enabled: false } }` removes the dimension itself and renormalizes `health.overall`'s weights — see [Health Scores](reference/health-scores.md).
- A level is no longer part of a channel's name. The ten level-suffixed channels collapse into five, each declaring both of the levels it reports at: `complexity.cyclomatic.callable` / `.class` become `complexity.cyclomatic`, the same for `complexity.cognitive`, `complexity.npath`, and `coupling.cbo.class` / `.namespace` and `coupling.instability.class` / `.namespace` become `coupling.cbo` and `coupling.instability`. The level was written twice — once in the name and once in the `subject` every finding already carries — and only these five rules ever wrote it into a name; the six `health.*` channels have always reported at three levels under one name. **Where a level mattered, address it beside the name with `channel:level`**, `level` being one of `callable`, `class`, `file`, `namespace`, `project`: `@qmx-ignore coupling.cbo:namespace` silences the namespace aggregate and leaves the class findings reported, and the same pair works in `@qmx-ignore-next-line`, `@qmx-ignore-file`, in `--only-rule` / `--disable-rule` / `only_rules` / `disabled_rules`, and in `exclude_namespace_channels` keys. Three things to change by hand. Rename every occurrence of the ten old names — selectors, directives, `exclude_namespace_channels` keys, `baseline:explain --channel` — dropping the level segment and, where the distinction was wanted, adding `:level` instead; a `.class` suffix left in place now names no channel and is refused by name rather than silently matching nothing. Regenerate the baseline (`bin/qmx baseline:generate <baseline> <paths...> --force`) and review it: entries on the ten old channels go inert as undeclared. And expect the `getFingerprint()`-derived identifiers of findings on these five channels to reset once — the channel name is the fingerprint's first component — so previously-seen GitLab Code Quality findings show as new and closed GitHub code scanning alerts reappear as open. `bin/qmx rules` is unchanged — it lists 45 rules, and a level was never one — while the channel count drops from 57 to 52; no rule name, metric key, configuration key, CLI flag or exit code changes, per-level configuration keeps its nested form (`coupling.cbo: { class: ... }`, `--rule-opt coupling.cbo:class.warning=`), and the baseline file is still version 13.
- `@qmx-threshold` refuses a `channel:level` pair instead of retuning the whole rule. A threshold addresses the producing rule and does not distinguish levels (ADR 0024 §2); the pair is captured by the directive grammar so that it can be named and refused, where before the pattern stopped at the `:` and quietly applied the left half to every level. Set a per-level boundary with the nested configuration key or `--rule-opt RULE:level.option=value`. The same widening applies to the three `@qmx-ignore` forms, where a pair used to be truncated to the bare channel — a suppression silently broader than the one that was written.
- A channel is named by one name. The `ruleName#violationCode` pair is gone: a channel's identity is what used to be the code half, and the rule that produces it stays a separate published field (`rule` in `--format=json`) and an edge of the registry. Everywhere the pair used to be written, write the channel name alone — `@qmx-ignore complexity.cyclomatic` instead of `@qmx-ignore complexity.cyclomatic#complexity.cyclomatic.callable`, the same for `@qmx-ignore-next-line`, `@qmx-ignore-file` and `@qmx-threshold`, for `--only-rule` / `--disable-rule` / `only_rules` / `disabled_rules`, for per-rule `exclude_namespace_channels` keys, for `baseline:explain --channel`, and for the `channel` field of a baseline entry. The old spelling is **refused, not ignored**: every one of those surfaces answers with the name to write instead, so a stale directive fails loudly rather than silencing nothing in silence. Two consequences you cannot avoid. Baseline entries whose `channel` still carries a `#` go inert as malformed, so regenerate with `bin/qmx baseline:generate <baseline> <paths...> --force` and review the result. And the `getFingerprint()`-derived identifiers GitLab Code Quality and SARIF use to track a finding across runs are reset, because the channel key is their first component: expect previously-seen GitLab findings to show once as new, and closed or dismissed GitHub code scanning alerts to reappear as open. No channel name, rule name, metric key, configuration key, CLI flag or exit code changes, and the baseline file is still version 13.
- A computed metric may no longer take a name that a registered rule or a declared channel already has. `computed_metrics: { computed.health: ... }` was accepted and produced a channel addressed by the same string as the rule producing it; a channel is one name now, so the two would be one address for two different things, and the run ends with a configuration error naming the metric instead of resolving the collision silently in the static half's favour.
- A finding is called a finding. The PHP class `Qualimetrix\Analysis\Finding\Contract\Violation` is now `Qualimetrix\Analysis\Finding\Contract\Finding`, `ViolationChannel` is `FindingChannel`, and the property, parameter and named argument `violationCode` is `code`. Every derived type follows: `ViolationFilterInterface`, `ViolationFilterStage`, `ViolationFilterStageInterface` and `ViolationFilterStageResult` become `FindingFilter*`; `Reporting\Filter\ViolationFilter` becomes `FindingFilter`; `MeasuredViolationSet` becomes `MeasuredFindingSet`; `ViolationFilterOrchestrator` becomes `FindingFilterOrchestrator`; `ViolationSorter`, `ViolationDetailRenderer`, `DetailedViolationRenderer`, `ViolationSummaryRenderer`, `JsonViolationSection` and `HtmlViolationPartitioner` become `FindingSorter`, `FindingDetailRenderer`, `DetailedFindingRenderer`, `FindingSummaryRenderer`, `JsonFindingSection` and `HtmlFindingPartitioner`. **Nothing you write or read changes**: no channel name, rule name, metric key, configuration key, CLI flag, exit code, field in any output format, or baseline entry — the JSON finding field was already `code`, the HTML report's embedded payload still spells its three aliases `ruleName` / `violationCode` / `symbolPath`, and the baseline file is still version 13. Which spellings stay behind is measured, not described: `docs/internal/plans/rule-vocabulary/enumeration-kept-spellings.tsv` lists every remaining `violation` spelling in the product with its disposition, and carries the command that regenerates it. Only code that imports these classes by name has to change, and the change is mechanical.
- `Violation::$level` is removed rather than renamed. Five hierarchical rules wrote it and nothing read it except the object's own copy in `reportedAsBreach()`; a finding's level is carried by its `subject`, which is where it was already read from. No published field, channel name or baseline entry ever carried it, so there is nothing to migrate unless you constructed the class yourself with a `level:` argument.
- `design.type-coverage` is three rules: `design.param-type-coverage`, `design.return-type-coverage` and `design.property-type-coverage`, one channel each, each with its own threshold, suppression and baseline entry. Migration is mechanical. Configuration: one `design.type-coverage` section with `param_warning`/`param_error`/`param_threshold` and the `return_*`/`property_*` equivalents becomes three sections keyed by the new names, each taking bare `warning`/`error`/`threshold`; the camelCase spellings (`paramWarning`, …) are gone with them. CLI: the six flags `--type-coverage-{param,return,property}-{warning,error}` become `--{param,return,property}-type-coverage-{warning,error}`. Selectors: `--disable-rule=design.type-coverage` matches nothing — name the three, or `design.*`. `@qmx-threshold design.type-coverage W E` no longer retunes all three dimensions at once; it names a rule that does not exist, and each dimension is retuned on its own. Baseline: entries for `design.type-coverage#design.type-coverage.param` and its siblings must be renamed to `design.param-type-coverage#design.param-type-coverage` and siblings, or regenerated. `bin/qmx rules` reports 45 rules instead of 42; the channel count is unchanged at 57, because the split moved a channel's owner rather than adding channels. See ADR 0030.
- The `architecture.unassigned-class` gate moved to its own rule. `rules: { architecture.layer-violation: { unassigned_class: warn } }` becomes `rules: { architecture.unassigned-class: { mode: warn } }`, and `--layer-violation-unassigned-class` becomes `--unassigned-class-mode`. The rule has no `enabled` key: `mode: ignore` is how it is declined. Neither `--disable-rule=architecture.layer-violation` nor `rules: { architecture.layer-violation: { enabled: false } }` silences it any more — the selector addresses the two producers separately, and the walk they share runs for either of them while each checks its own gate before reporting. That is the point: the two answer different questions. Its position in the published channel order moves from 45 to 50 (channels are yielded grouped by producer, and the five declaration verdicts stay with the layer-violation rule), which is observable only in a `did you mean` tie-break; no name is close enough to this one to tie, and that is measured rather than assumed. See ADR 0030.
- `SymbolLevel` and `SymbolLevelProjection` moved from `Qualimetrix\Analysis\Evidence\Measurement\Contract` to `Qualimetrix\Core\Symbol`, next to `SymbolType`, `SymbolPath` and `MetricSubject`. The level is a coordinate of a symbol — it is declared by rules, read off the symbol by `Finding::level()`, written right of the colon in `channel:level`, and filtered on by namespace-channel exclusions — so the capability that walks the aggregation tree reads the vocabulary but no longer owns it. **Nothing you write or read changes**: no channel name, rule name, metric key, configuration key, CLI flag, output field, exit code or baseline entry is affected. Only PHP code importing the two class names has to change, and the change is the namespace alone. See ADR 0034.
- The rule layer's `RuleLevel` is gone; `SymbolLevel` is now the project's one level vocabulary, and `HierarchicalRuleInterface` and `HierarchicalRuleOptionsInterface` name it instead. No channel name, configuration key, report field or baseline entry changes. Two behaviours do, both in computed-metric configuration. `levels: []` emits nothing and is now treated as declaring no channel at all, so a baseline entry naming it goes inert instead of being compared. A repeated level (`levels: [class, class]`) is now refused as a configuration error naming the metric, where it used to report the same finding twice.
- Renamed rule `design.lcom` to `cohesion.lcom` and its `qmx rules --group` category from `Design` to a new `Cohesion` group; the rule's algorithm, defaults, options, and CLI aliases are unchanged. Update every surface that names the old channel by string: `qmx.yaml` rule keys, `--only-rule` / `--disable-rule` selectors (`design.*` no longer sweeps LCOM in; use `cohesion.*` or `cohesion.lcom`), `@qmx-ignore` / `@qmx-threshold` directives, and baseline entries. A baseline entry still naming `design.lcom` does not fail the run — it degrades through `InertEntryReason::UndeclaredChannel`, the same fail-safe path any channel a baseline entry no longer resolves to already takes. See ADR 0028.
- `design.data-class` was inverted on its reported axis and could not report a Data Class at all. `woc` measured visibility (`public methods / all methods`), so any class whose methods are all public scored 100; the rule then flagged a **high** value, i.e. small classes with a plain public API, while two exclusions (`isDataClass`, and `minMethods` counted against non-accessor methods) removed every real data class — including the rule page's own "Flagged" example. `woc` is now the Lanza & Marinescu ratio: non-accessor public methods over all public members (public methods plus public properties), with a class that has no public members scoring 100. The rule gates on `woc <= woc_threshold`, whose default changes from 80 to 33, and its channel direction is now `Lower`. Accessor-ness is decided by method name, never by body, so a public method that only forwards to a collaborator counts as behaviour. The constructor counts on neither side of the ratio, and the size floor is now counted in members rather than methods: `min_methods` becomes `min_members` (`--data-class-min-members`) and sums declared methods and properties, so a struct of public fields is finally within the rule's reach. Three migration steps, none optional: rewrite a configured `woc_threshold` (the old value is not merely stricter, it means the opposite), rename `min_methods` to `min_members`, and regenerate any existing baseline with `bin/qmx baseline:generate <baseline> <paths...> --force` — its `design.data-class` magnitudes were stored under the old channel direction and would be read as breaches. The `woc` value in `--format=metrics`/`json` output changes for every class. ADR 0027 records the rationale.
- A declaration's stored identity no longer contains its position in the file. A subject key is now `declaration:{logical}@{file}` plus `#{n}` when the same logical identity is declared more than once in that file, where `n` is the declaration's rank in the file — so inserting a blank line above a class no longer rewrites its key. The name minted for an anonymous class changed with it, from `{anonymous@<byte offset>}` to `{anonymous#<rank>}`. Baseline files are now version 13; a version 12 file is rejected, because the position it stored cannot say which declaration it meant, and must be regenerated with `bin/qmx baseline:generate <baseline> <paths...> --force` and reviewed. The same change resets the `getFingerprint()`-derived identifiers GitLab Code Quality and SARIF use to track findings across runs, and irreversibly resets the `occurrence` key of `architecture.layer-violation` baseline entries, whose evidence contains the declaration key (no other channel's does — `architecture.circular-dependency` keys its evidence by logical paths and is unaffected): expect previously-seen GitLab findings to show once as new, and closed or dismissed GitHub code scanning alerts to reappear as open. See ADR 0026 for the property this guarantees and the three cases it deliberately does not: a closure, the methods and property hooks of an anonymous class, and a declaration sharing its logical identity with another in the same file. Each is a rank, so adding, removing or moving the siblings it counts renumbers it — and because a vacated rank is reused, such an entry does not go stale, it silently rebinds to the declaration that now holds the number.
- An `exclude_namespace_channels` key must now address a channel the rule it is written under actually produces, at a level that key can ever be matched at; a key that does not ends the run with exit code 3 instead of being accepted and excluding nothing. Three refusals, each of which used to be an accepted no-op. A key naming another rule's channel is answered with the owning rule's channels. A key carrying a level is judged as **one** thing — produced by this rule *and* reporting at that level — so `coupling.*:namespace` under `coupling.class-rank` is refused, where before the level was witnessed by `coupling.cbo` and the production by `coupling.class-rank`. And `namespace` is the only level such a key may name, because the option is offered namespace-aggregate findings and nothing else: `coupling.cbo:class` is refused by name, with the spelling that works. Level-free keys are unchanged, and `channel:namespace` excludes exactly what the bare `channel` does.
- Baseline files are now version 12. A magnitude-shaped entry no longer stores `count` alongside `magnitudes` — it is redundant with the magnitude list's length, and a file that still writes both is refused as malformed — shrinking this project's own 232 such entries by 2 320 B. The semantic occurrence key is now 16 hex characters instead of 64, since its discrimination domain is one (subject, channel) pair, not the whole baseline (2 064 B saved across 43 entries). Both changes reset the `getFingerprint()`-derived identifiers GitLab Code Quality and SARIF output use to track findings across runs: expect previously-seen GitLab findings to show once as new, and closed/dismissed GitHub code scanning alerts to reappear as open. There is no converter for either change or for the prior version; version 11 (and earlier) baselines are rejected and must be regenerated with `bin/qmx baseline:generate <baseline> <paths...> --force`, with the resulting acceptances reviewed like any other regeneration.

### Fixed
- SARIF rule descriptors are now derived from each channel's producing rule instead of a hand-kept table: most rules previously received a generic humanised placeholder (e.g. "Complexity Cyclomatic Callable") instead of their real description, `duplication.*` linked to the wrong documentation page, and several rule/violation-code arms in the old table could never match a real code at all.
- `cohesion.lcom` no longer counts `__construct`/`__destruct` as graph vertices. Any constructor whose assigned fields no other stateful method reads shared no property-access edge with the rest of the class, so it previously landed in the LCOM graph as an isolated vertex and inflated LCOM by one — property promotion (`public function __construct(private array $x) {}`) is the guaranteed case, since a promoted parameter never emits a property-access node at all, and affects the large majority of PHP 8+ constructors, but the artifact was never limited to it. The exclusion is not one-directional: on php-parser's own source (no promoted constructors) it changed LCOM for 106 of 260 classes — 97 dropped and 9 rose, the latter a real disconnection the constructor's edges had been masking. Same treatment TCC/LCC already gave constructors and destructors.
- A baseline entry whose identity is also claimed by an unreadable line beside it no longer suppresses. The documented rule — a duplicated identity makes an entry inert — counted only the lines the parser accepted, so a hand-edited pair was resolved by which of the two happened to parse.
- Repointed the six complexity CLI aliases (`--cyclomatic-warning`, `--cyclomatic-error`, `--cognitive-warning`, `--cognitive-error`, `--npath-warning`, `--npath-error`) to the `callable` level key, so they adjust thresholds again instead of silently no-oping after the method→callable rename.
- Counted the `??=` assignment-coalesce operator as a path-generating decision point in NPath complexity, matching the documented `??`/`?->` extension.
- Duplication detection now skips pathological hash buckets (hundreds of positions from generated parser tables and keyword lists) instead of exhausting memory with unbounded pair evaluation.
- Rejected wrong-typed scalar config values (`cache.enabled`, `parallel.workers`, `memory_limit`, `include_generated`) with a configuration error (exit 3) instead of silently falling back to defaults.
- Surfaced invalid computed-metric formulas, corrupt or unconvertible baseline files, and out-of-repo `--report=git:*` as configuration errors (exit 3) rather than an "Unexpected error" (exit 1).
- `code-smell.debug-code` now reports at Error severity (as documented) and detects `debug_zval_dump()`.
- `exclude_namespaces` (global `--exclude-namespace` and per-rule) now suppresses occurrence-style code-smell and security findings, resolving the declaring namespace from the finding's subject instead of the file-level symbol path, which always carried `null`.
- AST cache invalidation now fingerprints file contents, so a same-size rewrite with a preserved timestamp cannot reuse stale analysis results.
- Made duplicate-code candidate discovery use bounded memory before exact verification, without dropping real duplicate candidates.
- Preserved exact discrete namespace sums so abstractness and count-gated rules do not lose a class through fractional aggregation.
- Applied local `@qmx-threshold` overrides to Value Object constructor limits and recognize the top-level CBO `scope` option without a false unknown-option warning.

### Breaking

- **`fail_on` no longer accepts `info`.** Allowed values are `none`, `warning` and `error`. Severity `info` is now report-only: an Info-only run always exits 0, which makes `severity: info` a declaration of "observe, do not gate" instead of the old trick of configuring an unreachable threshold. To gate on a diagnostic shipped at `info`, raise that rule's own severity. This does not weaken baseline breach, which remains a separate gate for baselineable channels.

- **Namespace selectors are matched by one shared primitive.** `--namespace` filtering, health drill-down, worst-offender listings and the `coupling.distance` `include_namespaces` option previously used private copies of a literal-prefix check. Two behaviours change: a glob pattern (`App\*\Order`) is now matched as a pattern rather than compared literally, and an empty selector now selects nothing instead of the global namespace.

- **Selectors no longer swallow dotted descendants.** A name matches exactly and nothing else: `architecture.coverage` no longer selects a hypothetical `architecture.coverage.source`. If you relied on a selector reaching a descendant channel, name the descendant or use the explicit `X.*` form.

- **`@qmx-threshold` no longer accepts a prefix or `*`.** `@qmx-threshold coupling 15` and `@qmx-threshold * 15` are now errors. A threshold override addresses one rule by its exact name, and there is no group form at all — resetting thresholds across a family was a footgun, not a feature. Replace each with one directive per rule. `@qmx-ignore *`, `@qmx-ignore-next-line *` and a bare `@qmx-ignore-file` are unaffected: they mean "no rule filter here", not "every rule name", and continue to work.

- **`@qmx-ignore` requires a channel name, not a rule name.** For a rule that emits more than one channel, the rule name alone no longer suppresses anything: `@qmx-ignore annotation.directive` names a rule, and the diagnostic lists the channels it produces. Rules whose one channel is named after them are unaffected.

- **Group selectors require the star.** A bare prefix such as `complexity`, `duplication` or `code-smell` used to stand for the whole family and now selects nothing, which is reported rather than guessed at. Four surfaces are affected: `disabled_rules` / `only_rules` in both spellings (the shipped `legacy` preset used `disabledRules`), the CLI `--disable-rule` / `--only-rule`, the per-rule `exclude_namespace_channels` map keys, and the three inline suppression forms. Migration is mechanical: `disabled_rules: [complexity]` becomes `[complexity.*]`. Note that `X.*` means *strictly* the descendants of `X` — if a name is both a rule and a channel and you want both, write both. `X.*` on a rule that emits a single channel of the same name has no descendants and is rejected; write the exact name instead.

- **A `rules:` section key must be an exact rule name, and so must the owner in `--rule-opt RULE:option=value`.** No prefixes, no stars. This closes a silent no-op: `rules: { complexity: {...} }` previously passed both validations and configured *nothing*, because options are applied by exact key. If your configuration carries such a key, it has never had any effect; move each setting under the rule that actually owns it (`complexity.cyclomatic`, `complexity.cognitive`, `complexity.npath`, `complexity.wmc`). You will now be told rather than left guessing why a threshold appeared not to apply.

- **An unresolvable selector is an error, not an ignored warning.** In configuration or on the command line, a selector that matches no registered producer, group, or channel ends the run with exit code 3 before any report is produced. Inline, it becomes an `annotation.unresolved-directive` finding, which is a configuration error and therefore gates regardless of `fail_on` — including under `fail_on: none` — and cannot be accepted by a baseline or silenced by another `@qmx-ignore`.

- **`@qmx-threshold` on a disabled rule is no longer diagnosed.** Whether a rule is switched on is an execution filter, not a fact about whether its name exists, so such a directive is now valid and silent. If you were reading that warning as "this annotation is dead", it will no longer appear; the annotation is simply waiting for the rule to be re-enabled.

- **Removing a computed metric from `computed_metrics:` invalidates the annotations that referenced it.** A `@qmx-ignore` naming a channel that no longer exists is a dangling reference and is reported like any other unresolvable name. Deleting a metric now means deleting the directives that address it. (Existing *baseline* entries for a vanished channel still go inert rather than failing — an old baseline is a stored artefact, not something you just wrote.)

- **The three layer-diagnostic severity keys are removed.** `unreachable_layer_severity`, `potential_shadow_severity` and `empty_template_severity` no longer exist in either spelling, and the CLI flags `--layer-violation-unreachable-layer-severity`, `--layer-violation-potential-shadow-severity` and `--layer-violation-empty-template-severity` are gone with them. The layer-policy diagnostics — `architecture.coverage`, `architecture.unreachable-layer`, `architecture.potential-shadow`, `architecture.empty-template` and `architecture.pending-layer-matched` — now report a mistake in the *configuration* rather than debt in the code: they fail the run unconditionally without consulting `fail_on`, and cannot be accepted by a baseline or suppressed by `@qmx-ignore`. A severity key there would have looked like a behaviour switch while changing nothing but a word in the report, so it was removed rather than silently clamped. Delete the keys; there is no replacement. To decline the coverage diagnostic entirely, set `coverage: ignore` in the architecture section. `architecture.layer-violation` is unaffected — it is real code debt, and `@qmx-ignore architecture.layer-violation` and baseline entries still apply to it.

- The mixed configuration runtime surface was removed without compatibility
  aliases: `TransitionalResolvedConfiguration`,
  `TransitionalRuntimeConfiguration`, its provider/holder, and
  `ConfigurationContext` no longer exist. Invoke
  `ConfigurationPipelineInterface::resolve(ConfigurationResolutionRequest)` and
  pass the concrete `ConfigurationDocument` only to a named owner resolver.
  Use `RunConfigurationResolverInterface` for invocation data,
  `FindingConfigurationResolverInterface` for rule configuration, and the
  Cache, Parallel, Reporting, or Console resolver that owns the remaining
  value. This removes unrelated feature state from a universal runtime carrier.

- `CollectorConfigHolder`, `CollectorRuntimeConfiguration`, and their generic
  stores/configurable contracts were removed. The only configured collector is
  LCOM, so its exact value is now
  `Analysis\Evidence\Cohesion\Contract\LcomCollectionConfiguration`, applied
  through the Cohesion-owned store and worker contract. Consumers must not add
  another feature setting to a generic collector payload.

- `Core\Progress\*`, `ProfilerHolder`, `NullProfiler`, and Core-owned
  `Span` were removed. Collection code imports
  `Analysis\Run\Contract\Progress\ProgressReporterInterface`; instrumentation
  imports `Core\Profiler\Contract\ProfilerInterface`; Console uses the
  Profiler-owned session control/report contracts. These changes keep delivery
  modes and profiling state instance-owned rather than globally mutable.

- The YAML keys `namespace.strategy`, `namespace.composer_json`,
  `aggregation.prefixes`, and `aggregation.auto_depth` were removed and are now
  rejected as unknown. Project namespace discovery uses the invocation working
  directory's `composer.json`, and aggregation follows analyzed declarations.

- Private Symfony container references are now recorded as permanent exact
  `composition_binding` entries in the internal manifest. A binding has one DI
  source, private target, and observed container operation; it is not a public
  contract and does not authorize another source. Add a named public contract
  for cross-owner use, or add a reviewed exact binding for composition only.

- `Qualimetrix\Core\Coupling\FrameworkNamespaces` and
  `Qualimetrix\Core\Coupling\FrameworkNamespacesHolder` were removed with no
  compatibility shim. Coupling now owns its run-scoped framework-namespace
  state. Composition consumers must inject
  `Qualimetrix\Analysis\Evidence\Coupling\Contract\Configuration\CouplingConfiguratorInterface`
  and call `configure(ConfigurationDocument $document)` for every run, including
  an empty document to reset prior state. Configuration producers keep using
  the canonical `coupling.framework_namespaces` document key; they must no
  longer read or construct a Coupling field on a generic configuration carrier.

- Finding and policy ownership moved without aliases or shims. Replace
  `Qualimetrix\Analysis\RuleExecution\*`, `Qualimetrix\Core\Rule\*`, and
  `Qualimetrix\Core\Violation\*` imports with their
  `Qualimetrix\Analysis\Finding\*` counterparts and consume only the named
  Finding contracts for rule configuration, execution metadata/statistics,
  filters, and violations. Source controls and annotation suppression moved
  from `Core\Suppression` / Baseline internals to
  `Analysis\Policy\Inline`; baseline lifecycle and accepted-boundary types now
  live under `Analysis\Policy\Baseline`. Impact ranking and technical-debt
  calculation moved to `Analysis\Evidence\Prioritization`. Replace direct
  Console `ViolationFilterPipeline` and Infrastructure `GitScopeFilter`
  composition with `Reporting\FindingProjection\FindingProjector` and its
  `GitScopeQueryInterface`; the shipped adapter is
  `Infrastructure\Git\ReportingGitScopeQuery`. The transitional Configuration
  rule-option, selection, output-format, and finding-exclusion fields were
  replaced by the corresponding Finding/Configuration contracts. Update test
  namespaces with their subjects; removed provider getters, concrete rule-list
  exposure, old pipeline/result types, and old FQCNs have no compatibility
  replacement.

- Computed metrics and Health moved without aliases or shims into the
  `Qualimetrix\Analysis\Evidence\ComputedMetrics` capability. Replace
  `Qualimetrix\Configuration\ComputedMetricsConfigResolver` and
  `Qualimetrix\Configuration\ComputedMetricFormulaValidator` with the
  same-named classes at the new capability root. Replace
  `Qualimetrix\Configuration\ComputedMetrics\Contract\HealthFormulaExclusionInterface`
  with `Contract\Configuration\HealthFormulaExclusionInterface` and
  `Qualimetrix\Configuration\HealthFormulaExcluder` with
  `Health\Configuration\HealthFormulaExcluder` under that root. Move remaining
  `Core\ComputedMetric\*`, `Metrics\ComputedMetric\*`,
  `Rules\ComputedMetric\*`, and `Reporting\Health` score, offender, metadata,
  ranking, and drill-down imports to their corresponding root, `Contract\*`,
  and `Health\*` declarations under the new capability;
  Reporting retains only thin report assembly and projection consumers. The
  evaluator API changes from `compute($repository, $definitions)` to
  `evaluate($repository, $filesAnalyzed)`: definitions and configuration now
  belong to the injected, instance-owned catalog instead of caller-supplied or
  process-global state. Update imports and direct constructor calls, and wire
  the published ComputedMetrics contracts through DI; removed holder,
  evaluator-interface, Health builder-interface, and legacy drill-down
  surfaces have no compatibility replacement.

- Architecture implementation moved without aliases. Replace
  `Qualimetrix\Architecture\*` imports with either
  `Qualimetrix\Analysis\Policy\Architecture\*` for declared-layer policy or
  `Qualimetrix\Analysis\Evidence\CircularDependency\*` for SCC evidence.
  `ArchitectureProcessorInterface`, `ArchitectureLifecycleHook`,
  `AnalysisLifecycleHookInterface`, `CycleInterface`, and the Configuration
  deferred-warning transport were removed. External consumers use only the new
  named leaf contracts; no compatibility shims are provided.

- Analysis orchestration moved without aliases. Replace imports under
  `Qualimetrix\Analysis\Pipeline\*`, `Analysis\Collection\*`,
  `Analysis\Discovery\*`, and `Analysis\Lifecycle\*` with their
  `Qualimetrix\Analysis\Run\Contract\*`, `Analysis\Run\Collection\*`,
  `Analysis\Run\Discovery\*`, and `Analysis\Run\Pipeline\*` counterparts.
  In particular, use `Run\Contract\Pipeline\AnalysisPipelineInterface` for
  adapters and `Run\Contract\FileSetInspectionParticipantInterface` for the
  file-set invocation seam. There are no compatibility aliases.
- Measurement moved without aliases. Replace
  `Qualimetrix\Analysis\Aggregator\*`, `Analysis\Repository\*`,
  `Analysis\Namespace_\*`, and shared collection metric contracts with
  `Qualimetrix\Analysis\Evidence\Measurement\*`. External consumers must use
  the corresponding `Measurement\Contract\*` type; repository indexes,
  visitors, and aggregation implementations are internal.
- Configuration moved without aliases from `Qualimetrix\Configuration\*` to
  `Qualimetrix\Analysis\Configuration\*` where P3 moved the type. The final
  surface is `ConfigurationPipelineInterface::resolve()` returning concrete
  `ConfigurationDocument`; use the named owner resolver rather than a generic
  provider or resolved-configuration carrier. The remaining rule-option and
  computed-metric classes are deliberate P5/P6 migration inputs, not
  compatibility shims.
- Dependency extraction moved inside DependencyModel. Replace direct imports of
  `Analysis\Collection\Dependency\DependencyResolver`, `DependencyVisitor`,
  and handler types with the declared
  `Analysis\Evidence\DependencyModel\Contract\DependencyTraversalParticipantInterface`
  where an external promise is needed. Extraction internals have no public
  replacement. Tests move with their subject and must be discovered from their
  new `tests/Analysis/...` paths.
- Dependency graph types moved without aliases: replace `Qualimetrix\Core\Dependency\Dependency`, `DependencyType`, and `DependencyGraphInterface` with their `Qualimetrix\Analysis\Evidence\DependencyModel\Contract\*` equivalents; replace `EmptyDependencyGraph` with the internal capability implementation only inside composition. Replace the concrete `Analysis\Collection\Dependency\DependencyGraphBuilder` dependency with `Contract\DependencyGraphBuilderInterface`; graph implementations are no longer public module dependencies.
- Graph export is now a Reporting projection contract. Replace `Analysis\Collection\Dependency\Export\GraphExporterInterface` and direct `DotExporter`/`JsonGraphExporter` construction with `Qualimetrix\Reporting\GraphProjection\Contract\DependencyGraphProjectionInterface::project()` plus `GraphProjectionRequest`. The old exporter interface was removed, implementations moved under `Reporting\GraphProjection`, and no compatibility aliases or shims are provided.
- Duplication implementation moved without aliases or shims: update `Qualimetrix\Analysis\Duplication\*`, `Qualimetrix\Core\Duplication\*`, and `Qualimetrix\Rules\Duplication\*` imports to `Qualimetrix\Analysis\Evidence\Duplication\*`. The intermediate `DuplicationInspectionInterface` is removed; final composition registers the internal `DuplicationDetector` as an implementation of `Qualimetrix\Analysis\Run\Contract\FileSetInspectionParticipantInterface`. Remove `duplicateBlocks` arguments/reads from `AnalysisContext` and `EnrichmentResult`; no Duplication-owned public inspection contract remains. The capability-owned rule reads the internal `DuplicationResultProvider`.
- `architecture.allow` now rejects every directed cycle made only of exact selectors with `ConfigLoadException`. Exact self-references were previously stripped silently and exact mutual permissions only warned; remove redundant self-edges and break or reorient at least one allow edge in every cycle. Glob and captured selectors remain outside this static DAG check.
- Callable-level contracts now use `Callable` instead of `Method`, including symbol/rule levels and `*.callable` channels. Update enum cases, configuration selectors, stored channel names, and integrations; there are no `Method` aliases.
- Baselines now require version 11 typed subjects with optional semantic occurrence and dependency-edge identity. Version 5 and version 10 files are rejected because exact declaration identities cannot be inferred; run a fresh analysis, deliberately map or split accepted entries, and write a reviewed v11 file. The historical `baseline:migrate` command was removed and has no replacement shim.
- Violation JSON and fingerprints now use exact declaration subjects plus semantic occurrence and dependency edge where present. Consumers that persisted or joined findings by logical `symbol` alone must use `channel + subject + optional occurrence + optional edge`.
- A `Violation` carrying `dependencyTarget` without `dependencyType` now emits JSON `edge: {"target": "..."}` instead of `edge: null`, and its GitLab/SARIF fingerprint includes a collision-safe target-only edge component instead of colliding with the no-edge finding. No-edge and fully typed edge fingerprints are unchanged; baseline v11 already retained target-only edges, so no baseline migration is required.
- Layer-violation findings now project an owned logical target to each exact target declaration; an unowned target remains on the exact source declaration. Update symbol-scoped suppressions and baseline mappings to the projected target subject; physical next-line/file controls still use the dependency use-site.
- `CollectionOrchestrator` no longer creates default null progress/logger collaborators. Pass mandatory Run progress and PSR logger instances; shipped DI injects the instance-owned Console progress switch and `DelegatingLogger`, while every direct constructor call must provide its chosen implementations. No nullable/default overload or compatibility shim is provided.
- `FileParserInterface` now requires `parseContent(SplFileInfo $file, string $content): array`. Implementations must parse the supplied bytes while using `$file` as diagnostic source identity.
- `DependencyGraphAnalyzerInterface`, `DependencyGraphAnalyzer`, and `DependencyGraphAnalysisResult` moved from `Qualimetrix\Analysis\Collection\Dependency` to `Qualimetrix\Analysis\Pipeline`. Update imports and fully qualified type references to the new namespace; their constructor and method contracts are unchanged, and no compatibility aliases are provided.

## [0.24.0] - 2026-08-08

### Changed
- Baselines now use version 10 reported-magnitude ceilings: an accepted live group can fail only when its count or reported magnitude worsens, while a stale or inapplicable entry is reported without disabling the remaining baseline. Create files with `bin/qmx baseline:generate <baseline> <paths...>` and maintain them with `baseline:update` or explicitly selected `baseline:cleanup --remove` entries.
- Every output format now carries an explicit analysis-coverage verdict, including zero-file and generated-only runs. Parse or processing failures make policy results non-authoritative and return exit code 4; JSON/metrics expose a structured `coverage` object, SARIF uses invocation notifications, CI formats emit native failure records, and human/HTML reports show an explicit warning.
- Namespace LOC and structural metrics are attributed to every namespace block in multi-namespace files, while project totals continue to count each physical file exactly once. Git report scoping likewise indexes every namespace declaration in a changed file.

### Breaking
- `AnalysisConfiguration::isRuleEnabled()` and `isViolationCodeEnabled()` were removed because configuration no longer owns selection semantics. Inject `RuleSelector` and pass the producer name plus `ViolationChannel`; this preserves channels whose producer name, channel `ruleName`, and `violationCode` differ.
- Baseline file format v5 was removed. Convert an existing file with `bin/qmx baseline:migrate <baseline> <paths...>`; migration makes a fresh v10 capture because v5 has no recorded magnitude boundary.
- `check --generate-baseline=<file>` was removed. Use `bin/qmx baseline:generate <file> <paths...>` instead.
- `--baseline-ignore-stale` was removed. Stale entries now report without failing a run or disabling other baseline entries; inspect and remove only explicitly selected entries with `bin/qmx baseline:cleanup <baseline> <paths...> --remove=<selector>`.
- `--no-suppression` was renamed to `--no-suppression-annotations` with no alias. It is report-only: annotated findings are restored after baseline measurement, so the flag no longer widens the measured set or promotes an annotated finding to Error.
- The `Cycle data:` JSON trailer of an `architecture.circular-dependency` recommendation now lists fully qualified class names in its `cycle` array, where it used to list bare class names. The trailer exists to be machine-read, and a bare name does not identify a class. The keys and the shape of the object are unchanged; consumers matching on a short name must match on the fully qualified name or its trailing segment. Baseline entries are unaffected — they are not keyed by the recommendation.
- Baseline entries for `architecture.circular-dependency` must be regenerated. Cycles are now keyed by the canonically smallest class of the cycle, so any recorded entry whose key was a different member no longer matches and the cycle is reported as new. For a v5 file run `bin/qmx baseline:migrate <baseline> <paths...>`; for a v10 file, review the capture and replace it with `bin/qmx baseline:generate <baseline> <paths...> --force`. Entries for other rules are unaffected.
- `@qmx-threshold` accepts only a non-negative numeric shorthand or the generic `warning=N` / `error=N` keys (one or both, in either order). Arbitrary YAML / `--rule-opt` option names and trailing prose that were accidentally accepted by substring matching are now rejected; put an optional non-empty reason after `--` or an em dash (`—`). Prefix and wildcard rule patterns remain supported but skip per-rule validator checks, so exact rule names are recommended.
- Incomplete analysis no longer succeeds or returns a warning/error policy code: `check`, baseline lifecycle commands, and `graph:export` return exit code 4. Baseline writers and graph export refuse partial artifacts even with `--force`; existing destinations remain byte-identical.
- Maintainability Index now consumes the Size metric `methodStatementCount`; the Halstead-owned `methodLoc` / `halstead.methodLoc` metric was removed. Rename `minLoc` to `minStatements`, YAML and `--rule-opt` key `min_loc` to `min_statements`, and CLI alias `--mi-min-loc` to `--mi-min-statements`. No compatibility aliases remain. MI values, aggregates, health scores, thresholds, and baselines may shift.
- NPath now retains nested expression contributions through AST wrappers, counts every `match` arm, nullsafe access, and expression-bearing `for`, `foreach`, `switch`, and `echo` slots. Existing NPath values, thresholds, and baselines may shift.

### Fixed
- `--only-rule` now selects the full finding channel instead of assuming a rule name prefixes its `violationCode`. `--only-rule=computed.health`, `--only-rule=health.complexity`, and `--only-rule=computed.health#health.complexity` now all run the `computed.health` producer and retain the intended findings; Architecture diagnostic channels such as `architecture.coverage` and baseline lifecycle commands use the same selection contract. Valid channel selectors no longer trigger the false "does not match any registered rule" warning.
- `architecture.circular-dependency` now identifies a cycle by its smallest member instead of by whichever member the graph traversal happened to reach first. The reported symbol, the displayed cycle path and the order of reported cycles used to depend on file discovery order, so adding an unrelated file could re-key an existing cycle: its baseline entry looked resolved and the same cycle reappeared as a new violation.
- `architecture.circular-dependency` no longer renders every member of a cycle by its bare class name. Members of the same cycle that share a class name now carry the shortest trailing namespace suffix that tells them apart, so a cycle between `App\Billing\Service` and `App\Orders\Service` reads `Billing\Service → Orders\Service → Billing\Service` instead of the useless `Service → Service → Service`. Members whose short name is unique in the cycle are unchanged. This also changes the GitLab Code Quality fingerprint of an affected violation, so one such cycle will be reported as resolved and re-raised once.
- `@qmx-threshold` parsing now validates the entire value expression instead of accepting `warning=` or `error=` substrings hidden inside unsupported syntax. The documentation now also reflects the actual scope rule: a class override applies to evaluations inside the class, including its methods, while the smallest matching source span wins.
- Rules that share one Options class now receive producer-specific Options instances; configuring one code-smell or security rule no longer silently configures another rule that reuses the same immutable class.
- Unknown `--only-rule` / `--disable-rule` selectors and unknown rule-option owners now fail closed as input errors (exit 3) before a report payload is written instead of warning and continuing with an unintended rule set.
- `check` diagnostics are routed to stderr, keeping stdout valid for the selected report format even on configuration/input errors, deprecations, logging, and output-file notices.
- `baseline:explain` now rejects a symbol absent from both the current analysis and baseline, while labelling baseline-only symbols explicitly instead of presenting a misspelling as a clean result.


## [0.23.0] - 2026-07-29

### Fixed
- `code-smell.boolean-argument` no longer flags promoted constructor properties (`public bool $x`) by default — a promoted parameter declares a field, not a behavior switch, so the rule's "split into two methods" advice never applied to it. Set `flag_promoted_properties: true` to restore the previous behavior.
- `duplication.code-duplication` no longer flags a duplicate block that lies *entirely* inside a `const` array or a static/instance property's array-literal initializer — repeated key/value shape across the rows of a data table is normal, and "extract a shared method" was never actionable advice for it. A block that extends past the declaration into surrounding code is still reported, so two otherwise identical classes wrapping the same table remain a finding.

## [0.22.0] - 2026-07-28

### Fixed
- Rule options set through the config file or `--rule-opt` were silently ignored when the option name had more than one word (`vo-warning`, `param_threshold`, …), while the dedicated CLI flag for the same option worked but printed a bogus `Unknown option` warning. All three channels now agree, and `--preset=strict` applies its `vo-error` value instead of dropping it. Values were dropped for `code-smell.long-parameter-list` and `design.type-coverage`; `coupling.distance` only ever suffered the false warning.
- The documented `threshold:` shorthand crashed the whole run with `Cannot mix "threshold" with "warning"/"error"` (exit code 3) whenever it was written at the top level of a rule, which is exactly how `website/docs/getting-started/configuration.md` shows it. 15 rules were affected; the nested `method: {threshold: …}` form was never broken.
- The `threshold`, `vo-threshold` and `*_threshold` shorthands no longer produce a false `Unknown option` warning on rules that support them.
- `coupling.cbo` and `coupling.instability` now accept the `threshold` shorthand at the rule's top level, applying it uniformly to the class and namespace dimensions. They were the only two threshold rules of twenty that rejected it, answering a bare `threshold` with a bewildering `Unknown option` warning.
- `code-smell.long-parameter-list` never applied its `vo-warning` / `vo-error` thresholds: value-object constructors were reported against the ordinary thresholds instead. The VO detection flag never reached the rule.
- `architecture.unreachable-layer` no longer fires for layers that only ever match as the *target* of a dependency — such as vendor boundary layers (`ClickHouseDB\**`). Such a layer was reported unreachable in the same run where `architecture.layer-violation` flagged a real edge into it.
- Parameter and return types of closures and arrow functions are now collected into the dependency graph. Previously only their bodies were, so a layer violation that entered exclusively through a closure signature was invisible to `architecture.layer-violation`, coupling metrics and `graph:export`.
- Global `exclude_namespaces` (and `--exclude-namespace`) no longer suppress `architecture.*` violations. Silencing a noisy metric in a namespace used to switch off layer-policy enforcement there as a side effect. Per-rule exclusions still work — see below.

### Changed
- Severities of `architecture.unreachable-layer`, `architecture.potential-shadow` and `architecture.empty-template` are configurable via `unreachable_layer_severity`, `potential_shadow_severity` and `empty_template_severity` on the `architecture.layer-violation` rule. Defaults are unchanged, so a typo in `patterns:` can now fail the build instead of only whispering at info level.
- Violations dropped by per-rule `exclude_namespaces` / `exclude_paths` are now reported: `-v` prints how many were suppressed and by which rules, and `--show-suppressed` lists them in a block of their own, separate from `@qmx-ignore`. They used to disappear without a trace — on this repository's own configuration that hid 387 violations.

### Breaking
- `architecture.coverage` with `coverage: warn` now reports `Warning` severity instead of `Info`, matching the mode's name. If you relied on it staying silent under `fail_on: warning`, switch to `coverage: ignore` or raise `fail_on` to `error`.
- Dependency graph gained edges: parameter and return types of closures and arrow functions, plus attributes on their parameters. Coupling metrics that read the graph — CBO, ClassRank, instability, distance and the derived health scores — shift accordingly, and `architecture.circular-dependency` may report cycles that were previously invisible. Thresholds tuned against the old graph may need revisiting; a baseline generated before this release stays valid only for violations whose identity did not change.
- `ThresholdParser::parse()` replaced the `legacyWarningKeys` / `legacyErrorKeys` parameters with a single `legacyKeys` array keyed by `warning` / `error` / `threshold`. Named-argument calls fail with `Unknown named parameter`; the old positional form silently loses its legacy keys. Only affects third-party rule packages calling the parser directly.
- `RuleExecutorInterface` gained `getRuleExclusionStats()`. Third-party implementations of the interface must add it.
- `ViolationFilterOrchestrator::__construct()` takes an additional required `RuleExecutorInterface` argument. Only affects code constructing it directly; the container wires it automatically.

## [0.21.0] - 2026-07-28

### Fixed
- `qmx rules` crashed with a fatal `ArgumentCountError` instead of listing the rules. The command built rule objects itself, which breaks for rules that take constructor dependencies besides their options (`architecture.layer-violation`). Rule instances now always come from the DI container; `qmx check` was never affected.

### Breaking
- `RuleRegistryInterface::getAll()` removed — it could not build rules that declare constructor dependencies beyond their options. Embedding consumers that need rule instances should take them from the container (tag `qmx.rule`); `getClasses()` and `getAllCliAliases()` still cover metadata.
- `RulesCommand::__construct()` now takes `iterable<RuleInterface> $rules` instead of a `RuleRegistryInterface`. Only affects code that constructs the command directly; the container wires it automatically.

## [0.20.1] - 2026-07-28

### Fixed
- Qualimetrix reported the *consuming project's* version as its own. `qmx --version` printed things like `1.0.0+no-version-set`, and the same wrong value was stamped into every analysis artifact — `version` in JSON and SARIF, `toolVersion` in the metrics format, and the HTML report footer. The version is now resolved by package name instead of through Composer's root package, which is the host project whenever Qualimetrix is installed as a dependency.

## [0.20.0] - 2026-07-28

> **Earlier releases can no longer be installed.** The repository history was
> rewritten to remove content that should never have been published. Every tag
> before v0.20.0 now points at a commit that no longer exists, so
> `composer require qualimetrix/qualimetrix:<older version>` fails with a 404
> from GitHub. The published archives cannot be restored — upgrade to v0.20.0.

### Security
- `symfony/yaml` updated to v8.0.14, clearing three advisories: a ReDoS via catastrophic backtracking in the parser cleanup regex (CVE-2026-45305), stack exhaustion via unbounded recursion in nested blocks (CVE-2026-45133), and CVE-2026-45304. Qualimetrix parses YAML configuration on every run, so this affects all users.
- `symfony/cache` updated to v8.0.14, clearing CVE-2026-45073. It is pulled in transitively by `symfony/expression-language`, which backs computed metric formulas.

### Breaking
- `AnalysisConfiguration::{projectRoot, cacheDir, composerJsonPath}` are now typed as `AbsolutePath` / `?AbsolutePath` instead of `string` / `?string`. Embedding consumers that construct `AnalysisConfiguration` directly must wrap path arguments in `AbsolutePath::fromString(...)`. The no-arg constructor still works as before — defaults resolve lazily to `getcwd()` and `${projectRoot}/.qmx-cache`. `fromArray()` and `merge()` continue to accept string values from YAML / CLI input and resolve them via `PathFactory::fromCliArgument()`. ADR 0015 Phase 5.
- `BaselineWriter::write()` now requires `AbsolutePath` for the `$projectRoot` parameter (was optional `string = '.'`). Embedded callers must wrap their project root and pass it explicitly.
- `GitClient::getProjectRoot()` accessor removed. The project root is now owned by `GitScopeResolution` (returned from `GitScopeResolver::resolve()`); pass it explicitly to consumers that previously read it from `GitClient`.
- `FileProcessorInterface` gains `setProjectRoot(AbsolutePath): void`. `CollectionOrchestratorInterface::collect()` gains a required `AbsolutePath $projectRoot` parameter. Custom orchestrators / processors must add the method or update the call. ADR 0015 Phase 6.

### Changed
- Configuration, cache, parallel pipeline, namespace detection, and dependency analysis now consume `AbsolutePath` / `RelativePath` VOs at every internal boundary instead of untyped strings. The migration closes the path-type ambiguity that motivated the T10 git-subdirectory bug class. ADR 0015 Phase 5.
- Git infrastructure now uses typed `AbsolutePath` and `RelativePath` VOs instead of `string` throughout `GitClient`, `GitRepositoryLocator`, and `GitScopeFilter`. ADR 0015 Phase 1b.
- `GitScopeFilter` now performs eager git-to-project path translation at the `GitClient` boundary. Project roots that sit in a strict subdirectory of the git tree (T10) are now handled correctly: changed files outside the project are filtered out early, and namespace extraction for violations is resolved against the project root instead of the git top-level.
- The project's own dogfooding `qmx.yaml` now declares the full 27-layer architecture topology (Core + Configuration + Architecture slice + per-category `metrics-{Category}` template + 10 `analysis-*` sub-layers + 10 `infra-*` sub-layers) that previously lived in `deptrac.yaml`. Sub-layer enforcement (e.g. `analysis-discovery → analysis-pipeline` is now caught) gained, on top of features deptrac never had: per-category metric isolation via template expansion, and a `relations:` filter that permits `infra-di → metrics-*` references but forbids inheritance. ADR 0014.
- `Violation::$location->$file` is now typed as `?RelativePath` (was `string`). Architecture violations not tied to a single file use `Location::none()` (file is `null`). Wire/comparator surface preserved via `Location::pathString()` and `Location::isNone()` — formatters and JSON output emit the same shape as before, but file paths going *into* `Location` must be project-relative. `WorstOffender::$file` and `DuplicateLocation::$file` migrated similarly; `ParseException::$filePath` now carries `AbsolutePath` (lives at the parser boundary, where absolute paths are the natural representation). ADR 0015 Phase 1a.

### Removed
- `deptrac/deptrac` dev-dependency. `composer check` is now `cs-check + test + phpstan + selfcheck`; architecture enforcement runs entirely through Qualimetrix's own `architecture.layer-violation` rule.
- Internal `Qualimetrix\Core\Util\PathNormalizer` helper (was `@internal` since v0.18). Superseded by `Core\Path\PathFactory`. ADR 0015 Phase 6 also wires a PHPStan rule (`qmx.bannedStringPathProperty`) as a regression guard against re-introducing `string`-typed `$file` / `$filePath` / `$oldPath` properties in scoped namespaces.

### Fixed
- The HTML report build manifest (`src/Reporting/Template/package.json` and its lockfile) is now tracked. A blanket `*.json` ignore rule had been excluding it, so a fresh clone could not run `composer test:js` or `composer build:js`, and the committed `dist/` bundle could not be regenerated or audited.

## [0.19.0] - 2026-05-17

### Breaking
- `ThresholdAwareOptionsInterface` gains a static `getOverrideValidator()` accessor that returns the per-rule `OverrideValidatorInterface` strategy used to validate `@qmx-threshold` annotations. Custom Options classes in extension code must implement the new method or `use StandardOverrideValidatorTrait;` for the default `warning ≤ error + non-negative` semantics. See ADR 0013.

### Changed
- Invalid `@qmx-threshold` annotations now surface a rule-specific code (e.g. `warning_exceeds_error`, `error_exceeds_warning`, `error_not_supported`) as `violationCode: annotation.invalid-threshold.<code>` in JSON / SARIF / Checkstyle output; the human message is unchanged. Validators that provide a remediation hint (e.g. WarningOnly's "omit `error=...`") now flow through to `recommendation` so users see actionable follow-up.

### Fixed
- `@qmx-threshold maintainability.index warning=N error=M` annotations with `N > M` were silently rejected by the parser, even though the rule's defaults are `warning=40 error=20` (inverted thresholds are the natural orientation). The bug was latent across releases — Maintainability annotations work for the first time in v0.19.
- `@qmx-threshold design.type-coverage warning=N error=M` with `N > M` was rejected on the same parser invariant; type coverage is an inverted-threshold rule and now accepts the natural form.
- `@qmx-threshold design.data-class warning=N error=M` was rejected when `N > M`, but the rule maps warning to `wocThreshold` (high) and error to `wmcThreshold` (low) — independent metrics on independent axes. The annotation now validates accordingly.
- `@qmx-threshold design.god-class warning=W error=E` previously accepted the `error` value silently and then discarded it inside `withOverride()`. Explicit `error=N` is now rejected at parse time with a clear diagnostic; the shorthand form `@qmx-threshold design.god-class N` still works.

## [0.18.0] - 2026-05-16

### Breaking
- `architecture.layers` YAML schema is now an **ordered list** (long form only), not a map. The first layer whose patterns match a class FQN owns the class — declaration order is meaningful. Migration: replace `layers: { name: pattern }` with `layers: [{ name: x, patterns: [pattern] }]`. See ADR 0006.
- `RuleInterface::getCliAliases()` removed. CLI aliases are now declared via the repeatable class-level attribute `#[CliAlias('alias', 'optionName')]`. Custom rules in extension code must drop the method and add attributes on the class.
- Architecture-feature classes moved to a vertical slice under `Qualimetrix\Architecture\{Domain,Configuration,Processing,Rules}` per ADR 0010. Extension authors importing from the old `Qualimetrix\Core\Architecture`, `Qualimetrix\Configuration\Architecture`, `Qualimetrix\Analysis\Architecture`, or `Qualimetrix\Rules\Architecture` namespaces must update imports.

### Changed
- New rule `architecture.layer-violation`: declare layers in YAML and enforce allowed inter-layer dependencies. Membership supports `patterns`, `suffix`, `attributes`, `implements`, `extends` (combined via `match: any | all`); parameterised template layers expand against the observed class set (`{var}` capture); `exclude:` blocks hard-filter assignment; allow-list `relations:` whitelists restrict permitted `DependencyType` kinds; capture-binding (`'app-{m}': ['domain-{m}']`) constrains allows to same-instance edges for DDD bounded contexts. Incremental adoption via `architecture.coverage`; expansion capped by `architecture.max_expanded_layers` (default 500). See ADRs 0006–0008.
- New diagnostics: `architecture.empty-template` (warning — template expanded to zero layers), `architecture.unreachable-layer` (info — layer pattern matched zero classes), `architecture.potential-shadow` (info — evidence-based detection of layers silently stealing classes from later, narrower layers).
- New CLI command `debug:layer-assignment <fqn>`: per-class introspection of layer assignment — reports the assigned layer and which other layers' patterns would also have matched. Runs full Discovery + Collection so output matches `qmx check` byte-for-byte.
- `qmx.yaml.example` includes a commented-out `architecture:` stanza demonstrating multi-criterion membership, `exclude:`, templates, vendor layers, `allow:` (plain, captured same-instance, long-form with `relations:`), `coverage`, and `max_expanded_layers`.

### Fixed
- `@qmx-threshold` annotations on `design.type-coverage`, `design.god-class`, and `design.data-class` previously had no effect — the Options classes did not implement `ThresholdAwareOptionsInterface`. The three Options now implement it and apply overrides per class.
- `architecture.layer-violation` now respects `@qmx-ignore` suppressions placed on the offending class — the dependency visitor used absolute paths while the suppression map was keyed by relative paths.
- `architecture.layer-violation` no longer false-positives mutual-allow when the two directions use disjoint `relations:` filters or `allow_cross_instance: true`.
- `architecture.max_expanded_layers` now actually takes effect when set in YAML (previously silently camelCased and ignored). See ADR 0009.
- `architecture.allow` source and target selectors now reject `[` brackets at config-load time with an actionable hint suggesting `{var}` capture-variable syntax.
- `debug:layer-assignment` now honours `memory_limit` from `qmx.yaml`.
- Architecture configuration warnings (e.g. mutual-allow detection) now actually reach the user logger.
- SARIF formatter `$schema` URL updated to the OASIS canonical location after the upstream repo reorganized.

## [0.17.0] - 2026-05-12

### Fixed
- `health.typing` no longer reports 0% for namespaces with no typeable declarations (e.g. marker interfaces used for Symfony Messenger routing). Empty type surface now yields 100% (vacuous truth) at namespace and project levels, matching the existing class-level semantic.
- Disabling a health dimension via `computed_metrics.health.X.enabled: false` no longer breaks `health.overall`. Both `enabled: false` and `exclude_health: [X]` now follow the same pipeline — the dimension is removed and `health.overall` weights are renormalized across the remaining dimensions.

### Changed
- Excluding a health dimension when `health.overall` has been overridden with a non-canonical formula (one that does not match `(health__dim ?? fallback) * weight`) now throws an explicit error instead of silently dropping the formula. Custom formulas should handle disabled dimensions via `??` fallbacks.

## [0.16.0] - 2026-05-01

### Changed
- `health.coupling` namespace formula rewritten to use efferent-only signals (`ce.avg`, `ce_packages.avg`, `ce.max`, `ce`, distance). Stable contracts namespaces (high incoming, low outgoing dependencies) are no longer unfairly penalized by bidirectional CBO. Class- and project-level formulas are unchanged.
- New aggregations for the `ce` metric at namespace and project levels: `ce.avg`, `ce.max`, `ce.p95`.

## [0.15.0] - 2026-04-04

### Changed
- Strict configuration validation: unknown section sub-keys (`cache.typo`), invalid value types (`cache.enabled: "yes"`), and unknown rule names (`rules.complexty`) now produce clear errors with "Did you mean?" suggestions
- Warnings (e.g., unknown rule option keys) are now visible at default verbosity via stderr, without requiring `-v`

### Fixed
- Configuration warnings were invisible without `-v` flag due to `NullLogger` at default verbosity

## [0.14.0] - 2026-04-03

### Changed
- `--exclude-namespace` CLI option for violation suppression by namespace (prefix or glob), merged with `exclude_namespaces` from `qmx.yaml`

### Fixed
- Computed metric names with underscores (e.g., `computed.my_score`) were incorrectly normalized to camelCase in YAML config

## [0.13.0] - 2026-04-03

### Changed
- `--show-suppressed` now lists each suppressed violation with file, line, message, and rule name (was count-only)
- `exclude_paths` and `exclude_namespaces` now support both prefix matching (`src/Entity`) and glob patterns (`src/Metrics/*Visitor.php`); simple directory/namespace names work without trailing `/*`
- `--exclude-health` with invalid dimension name now produces an error instead of silently ignoring

### Fixed
- "No PHP files found" message shown when all files had parse errors — now shows "All N file(s) were skipped due to parse errors"

## [0.12.0] - 2026-04-03

### Changed
- LCOM4 rule: `exclude_methods` option to exclude specific methods from the cohesion graph (reduces false positives from interface-mandated methods like `getName`, `getDescription`)
- Partial scope warning when analysis paths don't cover all composer.json autoload entries
- `coupling.instability`: `min_afferent` option replaces `skip_leaf` — configurable minimum afferent coupling (Ca) threshold for skipping symbols (default: 1, skip Ca=0)
- `code-smell.boolean-argument`: parameters with common boolean prefixes (`is*`, `has*`, `can*`, `should*`, `will*`, `did*`, `was*`) are now allowed by default (configurable via `allowed_prefixes: []`)
- `code-smell.error-suppression`: `allowed_functions` option to whitelist functions where `@` usage is acceptable (e.g., `fopen`, `unlink`)
- Per-rule `exclude_paths` option for targeted violation suppression by file path patterns
- `@qmx-ignore` tags now work in regular comments (`//`, `/* */`), not just PHPDoc docblocks
- JSON format (`--format=json`) now outputs all violations by default (was limited to 50); use `--format-opt=violations=50` to restore the old behavior
- Global `exclude_namespaces` config option for suppressing violations by namespace prefix (like `exclude_paths` but for namespaces)
- Computed metric formulas referencing non-existent metrics now produce a clear error instead of silently failing
- Warnings (partial scope, unknown rules, missing composer.json) now go to stderr to avoid corrupting machine-readable output
- Exit codes: config/input errors now return exit code 3 (was 1, overlapping with "warnings found"). Scheme: 0=clean, 1=warnings, 2=errors, 3=config error

### Fixed
- `graph:export` command crash due to `-d` shortcut conflict with global `--working-dir`

### Removed
- `--analyze` option — was misleading (analyzed all files regardless, only filtered violations like `--report`). Use `--report` instead
- `analyze` command alias — use `check` instead
- `baseline.json` — replaced with proper `qmx.yaml` configuration using new features

## [0.11.2] - 2026-04-02

### Changed
- Project `qmx.yaml` for self-analysis with tuned coupling thresholds and `exclude_namespaces` for Core value objects
- `qmx.yaml.example` — comprehensive annotated example with documentation links, default values, and all available options (replaces `qmx.yaml.dist`)
- `parallel` section in config file for setting worker count (was CLI-only via `--workers`)

### Fixed
- `coupling` section in config file was rejected as unknown key

## [0.11.1] - 2026-04-01

### Changed
- `--memory-limit` option and `memory_limit` config key to control PHP memory limit (e.g., `--memory-limit=1G`)
- Removed hidden 512M memory limit override — PHP's `memory_limit` from php.ini is now respected by default

## [0.11.0] - 2026-04-01

### Changed
- Cognitive Complexity violations include breakdown of top contributors: `Top: nested if +5 L12, foreach +4 L15, &&/|| +1 L22`
- NPath Complexity violations include multiplicative chain: `Chain: ×6 if/else L25, ×4 match L31, ×3 switch L20`

## [0.10.0] - 2026-03-29

### Breaking
- Rule IDs `code-smell.god-class` and `code-smell.data-class` renamed to `design.god-class` and `design.data-class`
- `--format=health` now produces a text table (was HTML). Use `--format=html` for the interactive HTML report

### Changed
- `@qmx-threshold` annotations for per-class/method threshold overrides in source code
- Framework CBO distinction: `cbo_app` and `ce_framework` metrics separate application from framework coupling
- Full dependency graph in `--analyze=git:*` modes — coupling metrics now correct in partial analysis
- `--group-by=class|namespace` for JSON output
- Worst contributors per health dimension in `--format=health`, configurable via `--format-opt=contributors=N`
- Violation density metric (`violationDensity`: violations per 100 LOC) in worst offenders
- NPath violations include severity categories (low/moderate/high/very high/extreme)
- VO constructor exemption for `long-parameter-list` — relaxed thresholds (`vo-warning`, `vo-error`)
- LCOM4: stateless methods grouped together, reducing false positives on utility classes
- Duplication violations include content preview hint
- Martin Diagram view in HTML report with parent-namespace instability/abstractness/distance
- NamespaceTree: canonical namespace hierarchy replaces flat aggregator
- Warn when `@qmx-threshold` targets rules that don't support overrides
- Decomposed 13 large classes into focused components (SRP)

### Fixed
- Health: complexity contributors always empty; recalibrated formulas for per-method aggregation
- Metrics: namespace `.max`/`.avg`/`.p95` now aggregated from raw method values, not pre-aggregated class values
- Reporting: aggregation suffixes stripped from metric keys in health text; uppercase metric keys fixed
- Git: absolute path mismatch in `GitScopeFilter` for `--analyze=git:*`
- Security: hardcoded credentials no longer flag dot-notation identifiers (e.g., `config.database.host`)
- Duplication: self-duplication for overlapping/adjacent ranges in same file eliminated
- Removed dead weighted average from aggregation, dead `GitFileDiscovery` class

## [0.9.2] - 2026-03-26

### Fixed
- CI: refactored `ConfigDataNormalizer` to eliminate complexity violations (NPath 442K → 4), regenerated baseline

## [0.9.1] - 2026-03-26

### Changed
- "Top issues by impact" redesigned: file path on the first line (clickable in terminal), rule name + message + symbol context on the second line. Shows `recommendation` when available. Handles architectural violations (`[project]`)
- HTML report: violations table now shows `File` column, uses `violationCode` (more specific than `ruleName`), and prefers `recommendation` over technical `message`

## [0.9.0] - 2026-03-26

### Changed
- Analysis presets: `--preset=strict|legacy|ci` for one-flag configuration. Multiple presets can be combined (`--preset=strict,ci`). Custom preset files supported via path (`--preset=./team.yaml`)
- `rules` key now uses deep merge across pipeline stages — partial rule overrides in `qmx.yaml` no longer replace entire preset rule configurations

## [0.8.0] - 2026-03-26

### Changed
- Effort-aware prioritization: "Top issues by impact" section in summary and JSON output. Violations ranked by `classRank × severity × remediation time` — answering "what should I fix first?" New `--top=N` option (default 10, `--top=0` to disable)

## [0.7.1] - 2026-03-25

### Changed
- CBO metric no longer counts PHP built-in classes (`Exception`, `DateTime`, `Iterator`, etc.) — only project and third-party dependencies contribute to coupling scores. Dependency graph exports (`graph:export`) are also affected

## [0.7.0] - 2026-03-25

### Changed
- `--fail-on` now defaults to `error` — warnings are shown in output but don't cause non-zero exit code. Use `--fail-on=warning` or `fail_on: warning` in config for the old behavior
- `threshold` shorthand for rule configuration — sets both warning and error to the same value, making all violations errors at that threshold
- Health score labels renamed to industry-standard terminology: `Excellent` / `Good` / `Fair` / `Poor` / `Critical` (was `Strong` / `Good` / `Acceptable` / `Weak` / `Critical`)
- Line numbers shown only for violations with precise locations (method/class level), not for file-level violations

### Fixed
- Technical debt breakdown now calculated from all violations, not just the truncated display list

### Breaking
- Default `--fail-on` changed from `warning` to `error`. CI pipelines relying on exit code 1 for warnings must add `--fail-on=warning` explicitly

## [0.6.0] - 2026-03-18

### Fixed
- Baseline now correctly matches file-level violations (duplication, code smell, security rules) — previously ~150 violations passed through a freshly generated baseline
- Duplicate code block locations are now sorted deterministically, making baseline entries stable across runs
- File paths are normalized to relative (vs CWD) to prevent mismatches with absolute or `./`-prefixed paths

### Breaking
- Baseline version bumped to 5 — existing v4 baselines must be regenerated with `--generate-baseline`

## [0.5.0] - 2026-03-18

### Changed
- `exclude_namespaces` is now a universal per-rule option available for any rule, not just coupling rules

### Breaking
- `exclude_namespaces` for `coupling.cbo` and `coupling.instability` moves from nested `namespace:` to top-level rule config
- `exclude_namespaces` now filters violations at all levels (class + namespace), not just namespace level

## [0.4.0] - 2026-03-18

### Changed
- **Health scores redesigned**: 5-tier labels (`Excellent` / `Good` / `Fair` / `Poor` / `Critical`), recalibrated formulas for complexity (avg + P95 + sqrt(max) penalties), coupling (efferent-based, P95 + sqrt-scaled max), cohesion (TCC neutral value for small classes), maintainability (MI anchor shifted to 30). `--exclude-health=DIMENSION` to exclude dimensions from scoring
- **Computed metrics**: 6 built-in `health.*` scores plus user-definable `computed.*` metrics via Symfony Expression Language formulas, per-level formulas, threshold-based violations
- **Summary-first CLI**: `--format=summary` is now the default output — health bars, worst offenders, violation summary, and contextual hints in one screen
- **Drill-down navigation**: `--namespace=App\Service` and `--class=App\Service\UserService` for progressive filtering with auto-enabled `--detail`. Namespace/class health scores shown in drill-down headers
- **Interactive HTML report**: `--format=health` — self-contained D3.js treemap, health coloring, search, metric selector, dark mode. Use `--output` / `-o` to write any format to a file
- **JSON output redesigned**: summary-oriented with `meta`, `summary`, `health` decomposition, `worstNamespaces`, `worstClasses`, `violations` (top 50 by default). `--format-opt=violations=all|0|N`, `--format-opt=top=N`
- **New rules**: `code-smell.long-parameter-list`, `code-smell.unreachable-code`, `code-smell.identical-subexpression`, `design.god-class` (Lanza & Marinescu), `design.data-class`, `code-smell.constructor-overinjection`, `code-smell.unused-private`, `design.type-coverage`, `duplication.code-duplication` (Rabin-Karp token hashing), `coupling.class-rank` (PageRank), `security.sql-injection`, `security.xss`, `security.command-injection`, `security.sensitive-parameter`, `security.hardcoded-credentials`
- **New output formats**: `--format=metrics` (raw metric values), `--format=github` (PR annotations)
- **Technical debt**: remediation time estimates per violation, aggregated debt in reports, `--detail` shows per-rule breakdown
- `--fail-on=error` option to allow warnings without failing the build
- `--include-generated` to override automatic `@generated` file skipping
- `--disable-rule=duplication` now skips the memory-intensive detection phase entirely (not just violations). Same for circular dependency detection
- Violation messages improved: actionable recommendations, parameter names in boolean-argument, coupling direction in CBO, CCN divergence hints, top-5 dependencies in coupling violations
- `bin/qmx graph:export --format=json` — dependency graph as aggregated JSON adjacency list
- `composer benchmark:check` regression suite — validates health scores against 15 open-source projects
- `llms.txt` and `llms-full.txt` — machine-readable documentation for AI coding agents

### Fixed
- Metric algorithm corrections: cognitive complexity nesting in closures, cyclomatic complexity for `match` arms, NPath formulas aligned with Nejmeh/PMD standards, Maintainability Index class-level aggregation, WOC formula, RFC for traits/enums, abstractness formula for interfaces
- Anonymous class isolation: methods inside anonymous classes no longer attributed to enclosing class (CCN, NPath, Halstead, ParameterCount, UnreachableCode visitors)
- Suppression system (`@qmx-ignore`): fully wired into pipeline, `@qmx-ignore-next-line` scoped to single line, file-level regex fixed, symbol-level no longer leaks to file-level
- Output formatters: SARIF schema compliance (paths, locations, helpUri), Checkstyle/Text relative paths, GitLab project-level path, JSON NaN/Infinity handling
- Configuration: `--config` now functional, `exclude_paths` accepted, YAML key normalization preserves rule IDs, deep merge for CLI overrides, `fromArray([])` applies defaults
- Security rules: XSS and command injection detect superglobals in interpolated strings
- Infrastructure: cache hit skips AST traversal, runtime state reset between runs, baseline v3 migration errors, parallel worker validation

### Breaking
- `--format=html` renamed to `--format=health`; `--format=metrics-json` renamed to `--format=metrics`
- `--format=summary` is now the default (was `text`). Use `--format=text` for the previous behavior
- `--format=json` redesigned — no longer PHPMD-compatible. See documentation for new schema
- JSON field `humanMessage` renamed to `recommendation` in violation objects
- Health scores: 5-tier labels (was 4-tier), recalibrated formulas — baselines may need regeneration
- NPath values changed due to formula corrections — baselines may need regeneration
- Baseline version 3 no longer supported — regenerate with `--generate-baseline`

## [0.3.0] - 2026-03-08

### Changed
- CLI command renamed from `analyze` to `check`, with aliases for backward compatibility
- Canonical config file name is now `qmx.yaml`
- `exclude_paths` option for violation suppression by file path patterns
- MkDocs Material documentation website (EN/RU)
- Version derived from Composer/git tag instead of hardcoded constant

### Fixed
- LCOM4 calculation aligned with original Hitz & Montazeri specification
- Maintainability Index accuracy: use ELOC instead of physical LOC
- `--workers=0` semantics corrected

## [0.2.2] - 2026-03-05

### Changed
- Rule NAME constants follow `group.rule-name` format (kebab-case)
- `SizeRule` split into `MethodCountRule` and `ClassCountRule`
- `CouplingRule` split into `InstabilityRule` and `CboRule`
- `RuleMatcher` utility for prefix-based rule matching
- ANSI colors, grouping, and `FormatterContext` for formatters
- Baseline v3 format with duplicate NAME validation
- Suppression system updated for dotted rule names and prefix matching

## [0.2.1] - 2026-03-05

### Fixed
- TTY output written line by line to prevent macOS terminal truncation

## [0.2.0] - 2026-03-05

### Changed
- Category filtering for rules
- Default thresholds calibrated

## [0.1.1] - 2026-03-04

### Changed
- `violationCode` field in `Violation` for stable baseline hashing
- Improved violation messages with thresholds and actionable advice

### Fixed
- Namespace-level violation display and `minClassCount` filter

## [0.1.0] - 2026-03-04

Initial release.

- PHP static analysis CLI tool
- Metrics: Cyclomatic Complexity, Cognitive Complexity, NPATH, Halstead, Maintainability Index
- Metrics: RFC, Instability, Abstractness, Distance from Main Sequence
- Metrics: TCC/LCC, LCOM4, WMC, LOC, DIT, NOC
- Rules with configurable thresholds
- Circular dependency detection with DOT graph export
- Output formats: Text, JSON, Checkstyle, SARIF, GitLab Code Quality
- Parallel file processing via amphp/parallel
- Git integration: `--staged`, `--diff`
- Baseline support with `@qmx-ignore` suppression tags
- AST caching, progress bar, PSR-3 logging
- Git hook installation (`hook:install`, `hook:status`)
- Symfony DI with autowiring and autoconfiguration
- GitHub Actions workflow

[0.11.1]: https://github.com/qualimetrix/qualimetrix/compare/v0.11.0...v0.11.1
[0.11.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.10.0...v0.11.0
[0.10.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.9.2...v0.10.0
[0.9.2]: https://github.com/qualimetrix/qualimetrix/compare/v0.9.1...v0.9.2
[0.9.1]: https://github.com/qualimetrix/qualimetrix/compare/v0.9.0...v0.9.1
[0.9.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.8.0...v0.9.0
[0.8.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.7.1...v0.8.0
[0.7.1]: https://github.com/qualimetrix/qualimetrix/compare/v0.7.0...v0.7.1
[Unreleased]: https://github.com/qualimetrix/qualimetrix/compare/v0.24.0...HEAD
[0.24.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.23.0...v0.24.0
[0.23.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.22.0...v0.23.0
[0.22.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.21.0...v0.22.0
[0.21.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.20.1...v0.21.0
[0.20.1]: https://github.com/qualimetrix/qualimetrix/compare/v0.20.0...v0.20.1
[0.20.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.19.0...v0.20.0
[0.19.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.18.0...v0.19.0
[0.18.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.17.0...v0.18.0
[0.17.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.16.0...v0.17.0
[0.16.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.15.0...v0.16.0
[0.15.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.14.0...v0.15.0
[0.14.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.13.0...v0.14.0
[0.13.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.12.0...v0.13.0
[0.12.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.11.2...v0.12.0
[0.11.2]: https://github.com/qualimetrix/qualimetrix/compare/v0.11.1...v0.11.2
[0.7.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.2.2...v0.3.0
[0.2.2]: https://github.com/qualimetrix/qualimetrix/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/qualimetrix/qualimetrix/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/qualimetrix/qualimetrix/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/qualimetrix/qualimetrix/releases/tag/v0.1.0
