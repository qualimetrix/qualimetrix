# E1 — every surface that can name a rule or a channel, measured against the late channel

Subject: `annotation.unused-directive`, the one channel a run assembles **after**
rule execution (`src/Analysis/Run/Pipeline/AnalysisPipeline.php:342`), and which
of the tool's naming surfaces reach it.

Machine table: `E1-selection-surfaces.tsv` (59 rows).
Scripts: `measure.py` (batch 1, five selection inputs x seven name forms),
`measure2.py` (batch 2, on a fixture carrying both the late channel and an early
sibling of the same producer), `build_table.py` (derives the verdicts and adds
the inline-ban, baseline and two-selector cases).
Raw runs: `raw.json`, `raw2.json`. Fixtures: `fixture/` (one stale suppression),
`fixture2/` (stale + unresolved), `fixture3/` (a directive naming the banned
channel).

## How this was obtained, and what the method does not see

Every row is a real `bin/qmx check` run over a fixture outside the repository,
compared against a control run of the same fixture; the verdict is derived from
the exit code and from the count of findings on `annotation.unused-directive`
and on `annotation.unresolved-directive` in the JSON report. No cell is written
from reading code, and no cell is written from memory.

The **enumeration of inputs** is not a grep. It was assembled from three
readable sources and then each candidate was executed:

- the CLI option table, `src/Infrastructure/Console/CheckCommandDefinition.php`
  (every `addOption`, including the dynamically generated per-rule aliases at
  `:320`) and its mapping into configuration keys,
  `src/Infrastructure/Console/ConfigurationInputAdapter.php:73-82`;
- the configuration key registry, `src/Analysis/Configuration/ConfigSchema.php:36-39`
  and `:108-112`, which is declared to be the single source of truth for keys;
- the configuration stages that may contribute a layer at all —
  `Defaults` (0), `ComposerDiscovery` (10), `Preset` (15), `ConfigFile` (20),
  `Cli` (30) in `src/Analysis/Configuration/Pipeline/Stage/`.

**What this method does not see.** (1) A rule-specific option that silences the
channel through its own Options class rather than through a shared key — I read
`InlineDirectiveOptions` in full and it has exactly two options, but I did not
audit other producers' Options classes for a cross-producer switch. (2) Any
surface reachable only from a command other than `check` / `baseline:generate` /
`directives` / `debug:layer-assignment` — those four share one validator, and I
did not enumerate the remaining commands. (3) Environment variables and
`composer.json`-carried configuration: `ComposerDiscoveryStage` contributes
paths, and I confirmed it contributes no selectors, but I did not prove the
absence of any env-var override. (4) The dynamic per-rule CLI aliases: I
confirmed `UnusedDirectiveRule` declares none, so none of them can name this
channel; I did not enumerate the ~N aliases other rules declare.

## 1. The inputs, and whether each reaches the late channel

Five inputs carry a *selector* (a name that may be a rule, a channel, or a
group). They are not five decisions — see §3 — they are five **carriers** of one
merged list:

| carrier                          | key / flag                                           | file                                                              |
| -------------------------------- | ---------------------------------------------------- | ----------------------------------------------------------------- |
| CLI                              | `--disable-rule`, `--only-rule`                      | `CheckCommandDefinition.php:401,407`; also on `baseline:generate` |
| config file                      | `disabled_rules`, `only_rules`                       | `ConfigSchema.php:36-37,108-109`                                  |
| preset (built-in or a YAML path) | `disabledRules` / `onlyRules` inside the preset file | `Pipeline/Stage/PresetStage.php:45`                               |

Presets are reachable only through `--preset` (`PresetStage::extractPresetNames`,
`:66`); a config file cannot pull one in. Built-in `legacy.yaml` uses
`disabledRules`, so the key is live in that carrier.

Six further inputs name a rule or a channel without being selectors:

- `rules.<rule>.exclude_paths` — **gates the early sibling channel, not the late
  one** (measured: early 1 -> 0, late stays 1);
- `rules.<rule>.exclude_namespaces` — inert on both (this producer's findings
  carry a file subject);
- `rules.<rule>.exclude_namespace_channels` — see §6;
- `rules.<rule>.enabled: false` — reaches (the rule never arms the channel);
- `--rule-opt <rule>:enabled=false` — reaches; `--rule-opt <channel>:...` is
  **refused today** ("Rule option owner ... does not match any registered
  producer rule");
- inline `@qmx-ignore <channel>` — refused in place by `DirectiveChannelBan`
  and republished as an `annotation.unresolved-directive` finding.

Three inputs name no rule at all and still reach the channel: top-level
`exclude_paths` / `--exclude-path`, the discovery-level `--exclude`, and the
baseline (`--baseline`; the channel is recorded and accepted). Top-level
`exclude_namespaces` / `--exclude-namespace` do not reach it.

## 2. Name forms x inputs

Seven forms exist; the level separator in this tree is **`:`**, and `#` is the
*retired* pair spelling, refused by name. Measured against every one of the five
selector carriers, the five behave **identically** — same verdict, same message.
The form is what decides:

| form               | example                                 | `disable`           | `only`                      |
| ------------------ | --------------------------------------- | ------------------- | --------------------------- |
| exact channel      | `annotation.unused-directive`           | **inert, silently** | inert, silently (see below) |
| producer rule name | `annotation.directive`                  | silences            | silences                    |
| group `X.*`        | `annotation.*`                          | silences            | silences                    |
| `channel:level`    | `annotation.unused-directive:file`      | **inert, silently** | inert, silently             |
| `producer:level`   | `annotation.directive:file`             | refuses, exit 3     | refuses, exit 3             |
| `group:level`      | `annotation.*:file`                     | silences            | silences                    |
| retired pair       | `annotation.directive#unused-directive` | refuses, exit 3     | refuses, exit 3             |

Two results here are not obvious and both matter for where a refusal can stand:

**A single channel selector is inert, but the union of the producer's four
channels is not.** `--disable-rule=annotation.unused-directive` leaves the
finding published. The same flag repeated for all four `annotation.*` channels
stops the producer outright and the finding disappears — because
`RuleSelector::silenceEveryChannelOf()`
(`src/Analysis/Finding/Contract/Rule/RuleSelector.php:219`, called at `:104`)
asks whether the *union* of disable selectors covers every declared level of
every channel the producer emits. Three of four still leaves it published
(measured). `annotation.*:file` reaches the channel by the same route.
So "this selector addresses the late channel" is **not** the same predicate as
"this selection silences the late channel", and a refusal keyed on the first
would refuse a spelling that works today.

**`--only-rule` naming the late channel gives an empty report for a reason that
has nothing to do with channel selection.** With `only_rules: [annotation.unused-directive]`
the producer *is* enabled (the selector matches it through one of its channels),
the rule runs and arms the report — but every other rule is off, so every
authored suppression is unmeasurable and no stale verdict is reached. The empty
report is a statement about measurability, not about the filter. The filter's
real behaviour shows only when the suppression's own rule is kept on:
`--only-rule=annotation.unresolved-directive --only-rule=complexity.cyclomatic`
publishes `annotation.unused-directive` although `only_rules` never names it
(measured, last rows of the TSV). **The `only` half leaks the same way the
`disable` half does.**

## 3. Where the decision is actually made

There is **one** merged selection and **one** validation seam, and they are two
different places.

- Merge: `src/Analysis/Finding/Configuration/FindingConfigurationResolver.php:27-29`.
  `disabled` **accumulates** across every contributing layer
  (`accumulatedStrings`, `:102`); `only` is **last-writer-wins**
  (`lastStringList`, `:83`) — a CLI `--only-rule` replaces a config file's
  `only_rules` rather than adding to it. The result is one
  `RuleSelection{only, disabled}` for the whole run.
- Validation: `src/Infrastructure/Console/RuleInputValidator::validate()`,
  called once from `src/Infrastructure/Console/AnalysisRuntimeConfigurator.php:60`,
  shared by `check`, `baseline:generate`, `directives` and
  `debug:layer-assignment`. It validates the concatenation
  `[...$selection->only, ...$selection->disabled]` (`RuleInputValidator.php:66`)
  — i.e. the already-merged list, with no memory of which carrier a selector
  came from.
- Application: `RuleSelector::isProducerEnabled()` decides whether an instance
  runs (`src/Analysis/Finding/RuleExecution.php:390`), and
  `RuleSelector::isChannelEnabled()` decides whether each finding is published
  (`src/Analysis/Finding/RuleExecution.php:243`). The late channel passes
  through neither: it is appended after `execute()` returns
  (`AnalysisPipeline.php:342`).

Consequence for the plan: **a refusal has exactly one place to stand**
(`RuleInputValidator::validate`), and it must be asked of the *set*, not of each
selector in the existing per-selector loop
(`validateSelectionSelectors`, `:90`) — because whether the late channel
survives depends on whether the union stops the producer. The seam already has
everything the question needs: `$this->ruleSelector` with the run's declared
levels installed (`:62`) and `$channels`, the run's own universe.

A second, independent name check exists but does not see selectors:
`RuleNameValidator::validateRuleNames` (`src/Analysis/Configuration/Pipeline/RuleNameValidator.php:43`)
validates only `rules:` keys, from `ConfigFileStage:114` and `PresetStage:110`.
It is why `rules: { annotation.unused-directive: ... }` is refused as an unknown
rule.

## 4. Is addressing the late CHANNEL distinguishable from addressing the RULE?

**Yes, at the seam where a refusal would go, and the distinction is already used
there for a different option.**

- The producer is `annotation.directive`
  (`src/Analysis/Policy/Inline/Contract/Directive/InlineDirectivePolicyInterface.php:46`);
  the late channel is `annotation.unused-directive` (`:58`). They are different
  strings and different kinds: `ChannelIdentityInterface::ruleNames()` contains
  the first and not the second; `hasChannel()` the reverse. Nothing is ever
  emitted under the producer name (its own docblock says so, and the four
  channels confirm it in every measured report).
- `RuleInputValidator::validateOptionOwners` already draws exactly this line:
  it uses `matchesKnownProducer` (exact producer-name equality,
  `RuleSelector.php:169`) and refuses `--rule-opt annotation.unused-directive:...`
  with "does not match any registered producer rule" (measured, exit 3). So the
  precedent, the object and the vocabulary are all in place at that seam.
- `ChannelIdentityInterface::expand()` resolves a group selector into concrete
  channels; `DirectiveChannelBan::problemWith()`
  (`src/Analysis/Policy/Inline/Directive/DirectiveChannelBan.php:62`) already
  uses precisely that to decide "would this target reach the banned channel",
  including through `X.*`. A selection-side refusal can ask the same object the
  same question.

**But the distinction alone is not the refusal condition.** Measured, the
following all reach the late channel and must stay working: the producer name,
`annotation.*`, `annotation.*:file`, the union of all four channels,
`rules.annotation.directive.enabled: false`, `--rule-opt annotation.directive:enabled=false`.
Meanwhile `rules.annotation.directive.exclude_paths` addresses the *rule* and
gates only the early sibling — it is the working case a producer-keyed refusal
would break. The condition that separates "silently inert" from "works" is
therefore: *the selection addresses the late channel **and** leaves the producer
running*, which is `RuleSelector::isProducerEnabled()` evaluated on the merged
selection. That is computable at the seam.

## 5. The set of channels assembled after selection

**One, today: `annotation.unused-directive`.**

Derived, not taken on faith, by two independent walks:

1. From the pipeline outwards. `AnalysisPipeline::analyze()` builds its finding
   list at `:82` from `reportedFindings()` alone; `reportedFindings()` (`:335`)
   returns `$ruleExecution->published` plus `auditInlineDirectives()`, and there
   is exactly one `array_merge` in the file (`:342`). That call reaches
   `RuleProducerPreparation::auditInlineDirectives` (`:137`) ->
   `InlineDirectivePolicy::auditDirectiveUsage` (`:153`) ->
   `DirectiveUsage::stale` -> `StaleDirectiveFinding::of`, whose `ruleName` and
   `code` are both the literal `UNUSED_DIRECTIVE_NAME`
   (`Audit/StaleDirectiveFinding.php:49-50`). No other channel can come out of
   that branch.
2. From the finding constructors inwards. `grep -rl "new Finding("` over `src/`
   returns 36 files; 35 of them are rule classes, configuration validators or
   their finding factories, all of which are invoked from inside
   `RuleExecution::execute()`. The single exception is
   `Audit/StaleDirectiveFinding.php`. No file under `src/Reporting/` or
   `src/Infrastructure/` constructs a `Finding` at all.

What walk 2 does not see: a finding produced by cloning or by a factory that
does not spell `new Finding(` — e.g. a `with*()` copy on an existing finding. I
did not search for that shape, so treat "exactly one late channel" as
high-confidence rather than proven.

**Is the set programmatically reachable at validation time? No — not as a
declared property.** `ChannelDeclaration`
(`src/Analysis/Finding/Contract/ChannelDeclaration.php`) carries direction,
`configurationError` and levels, and nothing about *when* a channel is
assembled; `ChannelIdentityInterface` exposes no such query. The only handles
that exist are two literals: `InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME`
and `DirectiveChannelBan::covers()` (`DirectiveChannelBan.php:46`), which is a
hardcoded string comparison. So a refusal written today would either hardcode
the name a second time, or reuse `DirectiveChannelBan::covers()` — which is
Policy\Inline-owned and already read from two seams, and is the one existing
spelling of this channel's identity. Making the property declared (an
`assembled-late` flag on the declaration, or a producer-level answer) is
available as a design option but does not exist yet.

## 6. `ChannelExclusionKeyValidator`: production, not applicability — confirmed

The validator checks **production**, and says so in its own docblock ("Production,
not applicability", `src/Infrastructure/Console/ChannelExclusionKeyValidator.php:55-60`):
a level-free key passes as soon as the named channel is one the rule produces.
`annotation.unused-directive` *is* produced by `annotation.directive`, so the key
would pass.

Today it does not get that far, and for an unrelated reason: `rules` is
`PRESERVE_IMMEDIATE_CHILDREN`
(`src/Analysis/Configuration/Loader/SectionNormalizationPolicy.php:53`), so the
rule slug survives but level-3 keys are camelCased — the measured refusal names
the key `"annotation.unusedDirective"`, not what was written. **So the current
refusal is an accident of normalization, not a judgement about the channel.**

Once the neighbouring package removes that camelCasing, the key passes the
validator and then excludes nothing, for **two** independent reasons:

1. the exclusion ledger runs inside `RuleExecution::published()`
   (`src/Analysis/Finding/RuleExecution.php:238`), which the late finding never
   enters — it is appended afterwards;
2. even inside, `FindingExclusionLedger` applies `exclude_namespace_channels`
   only to findings whose symbol path type is `namespace`
   (`src/Analysis/Finding/FindingExclusionLedger.php:146`), and this finding's
   subject is deliberately the **file**
   (`Audit/StaleDirectiveFinding.php:43`, with the reasoning in its docblock).

Confirmed, therefore, with one correction to the premise: the key will pass the
validator *and* be silent, and it would have been silent even if the channel
reported at namespace level, because of (1).

## Is the subject wider or narrower than posed?

**Wider in two ways.**

- The brief frames the defect as "the exact-channel selector is inert". Measured,
  the same inertness is reachable through the `only` half
  (`--only-rule` naming a sibling channel of the same producer publishes the
  late channel anyway), and the *cure* is not "refuse a selector that addresses
  the channel", because four spellings that address it do work today — three of
  them only as a property of the whole selector set. The refusal predicate is
  set-valued.
- `--only-rule=annotation.unused-directive` producing an empty report is **not**
  evidence about channel selection at all; it is unmeasurability. Any plan that
  cites it as the symptom is citing the wrong mechanism.

**Narrower in one way.** The per-channel `exclude_*` family is not really a
second surface to refuse: `exclude_namespace_channels` is the only one keyed by
a channel, and it is silent for a reason (the ledger's position in the pipeline)
that a key-level refusal cannot state honestly. `exclude_paths` under the same
rule *works* on the sibling channel and must not be touched.

## What I did not measure, and why

- **`composer check` / the test suite.** Nothing in the tree was modified, so no
  validation was owed; every fixture and script lives outside the repository.
- **`--report=git:...` scoping against the late channel.** The ban's docblock
  claims it applies like any other finding; I did not build a git fixture to
  confirm it, so that claim is unverified here.
- **The other CLI aliases.** I confirmed `UnusedDirectiveRule` declares none, so
  no alias can name this channel; I did not enumerate the aliases of the other
  producers to confirm none of them collides with the name.
- **Whether any other command than the four sharing `RuleInputValidator` accepts
  a selector.** Out of the enumeration.
- **A control proving `exclude_namespace_channels` works at all somewhere.**
  Every `annotation.*` channel name contains a hyphen and is mangled by the
  normalizer, so I could not exercise the option end-to-end on this producer;
  conclusion (2) in §6 is read from code rather than measured.
- **A cloned/`with*()`-produced finding.** See §5: my constructor walk searches
  for `new Finding(` only.
