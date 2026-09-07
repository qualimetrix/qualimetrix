# P1 follow-up — three naming questions answered by reading, and where a run was cheaper than a read

Written for `01-decision-table.md`'s Q4, Q2 and Q1. Nothing is decided here; the
file states facts with `file:line`, and marks each one **[read]** or
**[measured]**. A **[measured]** claim was produced by executing something on
this branch (`claude/x11-final-vocabulary`, 2026-09-07) and is quoted from that
output; scratch harnesses lived in `/private/tmp/claude-501/`, never in the
repository, and nothing under `src/`, `tests/`, `finding-gate/` or the plan was
modified. This package makes two repository edits, both about this file's own existence:
its `documentationDisposition()` line
(`scripts/generate-modular-architecture-production-inventory.php:2671`) and the
one row that `composer architecture:generate` then adds for it to
`docs/internal/generated/modular-architecture/documentation-ownership.tsv:117`.
Without the second, `composer architecture:check` is red; the diff is that one
row and nothing else.

---

## Q4 — which gate map declares a rename of a PRODUCER RULE NAME

### The answer, stated flatly

**`inputs.tsv` declares it, and the code names that shape explicitly.** The
claim in `enumeration-gate-map-shapes.tsv` is correct on this point, and — unlike
its `assertPlainMetricKey()` line — it is correct for the right mechanical
reason.

`RenameMaps::assertWholeInputToken()` enumerates the four token shapes an
`inputs.tsv` row may name, and the third is a producer name:

```php
$dottedName = '[A-Za-z0-9][A-Za-z0-9_-]*(?:\.[A-Za-z0-9][A-Za-z0-9_-]*)+';
$shapes = [
    'a rule and its option key'                     => '~^' . $dottedName . ':[A-Za-z0-9][A-Za-z0-9._-]*$~',
    'a flag with its two dashes'                    => '~^--[A-Za-z0-9][A-Za-z0-9._-]*$~',
    'a dotted producer name as a selector writes it' => '~^' . $dottedName . '$~',
    'a configuration key as a document writes it'   => '~^[A-Za-z0-9][A-Za-z0-9_-]*:$~',
];
```

— `scripts/finding-gate/RenameMaps.php:1065-1071` (the third entry, at `:1069`),
with its docblock at `:1035-1061` saying "a dotted producer name as a selector writes it
(`--disable-rule=`, `only_rules:`)". **[read]**

For the `inputs.tsv` and `channels.tsv` roles there is no membership check of any
kind against the channel registry, against `MetricName`, or against the producer
list: `load()` reads the rows (`RenameMaps.php:264-276`) and hands them to
`normalize()`, which applies only per-role *shape* assertions
(`RenameMaps.php:859-889`). The product's own key list is read — but only by
`assertNoSuffixOverlap()`, and only as one of three collision populations, never
as a membership test (`RenameMaps.php:1233-1240`, whose docblock at `:1217-1220`
calls that population "partial" by construction). A row whose token merely
*looks* like a dotted name is accepted. **[read]** Measured directly: probe 4
below loaded `annotation.checker` and `annotation.unknown-name`, neither of
which the product declares anywhere, with no complaint. **[measured]**

### On which surfaces the translation actually fires

`inputs.tsv` is absent from the `SURFACES` constant
(`RenameMaps.php:164-167` lists only `METRIC_KEYS` at `:165` and
`REPORT_VALUES` at `:166`), and `appliesToSurface()` returns `true` when a
source has no entry (`RenameMaps.php:550-565`). So an `inputs.tsv` row applies
to **every** surface, in **both** directions
(`FILES[self::INPUTS] = true`, `RenameMaps.php:174`), and takes the bare
spelling plus the `qmx.` prefixed spelling, because it is an "other role"
rather than the key or report-value role (`RenameMaps.php:626-637`). **[read]**

Measured with one real row (`code-smell.eval → code-smell.dynamic-eval` written
into a scratch copy of `finding-gate/maps/`, loaded through
`RenameMaps::load()` with the tree's real `MetricVocabulary`) — **[measured]**:

| direction, call site                                                                                                     | input                                                                                   | output                                              |
| ------------------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------- | --------------------------------------------------- |
| `reverse()` — `TreeRun.php:48` (`reverseArguments($case->args)`)                                                         | `--disable-rule=code-smell.dynamic-eval`                                                | `--disable-rule=code-smell.eval`                    |
| `reverse()` — `TreeRun.php:201` (the case's config file, `configurationArgument()` at `:194-208`)                        | `only_rules:\n  - code-smell.dynamic-eval`                                              | `only_rules:\n  - code-smell.eval`                  |
| `reverse()` — same, `--rule-opt` owner half                                                                              | `--rule-opt=code-smell.dynamic-eval:warning=1`                                          | `--rule-opt=code-smell.eval:warning=1`              |
| `forward()` on `format:json`, `format:metrics`, `format:sarif`, `format:checkstyle`, `rules`, `baseline-file`, `explain` | `"rule": "code-smell.eval"`, `src="qmx.code-smell.eval"`, `Rule "code-smell.eval" says` | all three translated on every one of those surfaces |

`staleRows()` returned empty for that row on that text, i.e. the row was credited
as having fired. **[measured]**

The third reverse call site is `TreeRun.php:79`
(`$this->maps->reverse($subject)` for each `explainSubjects` entry). **[read]**

### The one spelling `inputs.tsv` does NOT reach, and why it does not matter alone

SARIF publishes a title-cased display name, and only the **channels** role gets
that spelling (`RenameMaps.php:674-681`, guarded by
`self::applies(self::CHANNELS, …)` at `:676`). Measured on synthetic rows
(`RenameMaps::fromPairs()`) against the string
`sarif name: "Code Smell Eval" | code-smell.eval | qmx.code-smell.eval` on
`format:sarif` — **[measured]**:

- `inputs.tsv` row alone → `Code Smell Eval` **untranslated**; bare and `qmx.`
  spellings translated.
- `channels.tsv` row alone → `Code Smell Dynamic Eval`, all spellings
  translated; but `reverse('--disable-rule=code-smell.dynamic-eval')` returned
  the argument **unchanged** (channels is forward-only, `FILES`
  `RenameMaps.php:171`).
- Both rows, same `(old, new)` pair → title case translated **and** the input
  reversed, and `declaredRows()` reported **one** row:
  `channels.tsv+inputs.tsv: "code-smell.eval" -> "code-smell.dynamic-eval"`.

That is the "two roles, one declaration" merge, grouped by `(old, new)` before
any check (`RenameMaps::normalize()`, `RenameMaps.php:808-829`, docblock at `:794-802`). **Consequence for П4:** the 42 producer names that are spelled
identically to their channel code need **both** rows, and the pair costs one
declaration, not two. The title-cased SARIF `name` is the surface that makes the
`channels.tsv` half non-optional; the reference's own CLI/config input is the
surface that makes the `inputs.tsv` half non-optional. **[read + measured]**

The title-case is over the SARIF rule **id**, which is `$finding->code`, i.e. the
channel — `SarifRuleCollector.php:78-79` builds `'id' => $code` (`:78`) and
`'name' => $this->formatRuleName($code)` (`:79`; the method itself at `:99-106`). A producer name that is not also a
channel code is never title-cased anywhere. **[read]**

### The nine names whose producer ≠ channel code

The registry itself states the situation:
`ChannelDeclarationCompilerPass.php:492-499` — "A finding's published `rule` does
not always equal its producer's NAME: the architecture and annotation
diagnostics are emitted under their own identity (e.g. `architecture.coverage`)
by a producer whose own name is different (`architecture.layer-violation`)".
`producerByCode[$code] = $producerRuleName` at `:512` is the mapping. **[read]**

The four `annotation.*` codes sit under producer `annotation.directive`
(`bin/qmx rules --no-ansi` lists `annotation.directive` and none of its four
codes) and the five layer-policy diagnostics under
`architecture.layer-violation`
(`LayerDeclarationValidator::channelDeclarations()`,
`src/Analysis/Policy/Architecture/LayerViolation/LayerDeclarationValidator.php:81-97`
declares `architecture.coverage`, `…unreachable-layer`, `…potential-shadow`,
`…empty-template`, `…pending-layer-matched` as five `occurrence` channels).
**[read + measured — the `rules` listing was executed]**

Are the nine **visible** to the maps? Three separate answers:

1. **A rename of one of the nine CHANNEL CODES** is an ordinary
   `channels.tsv` row in bare-code form. `expandChannelRow()` returns
   `[[old, new, false]]` for a single-half row (`RenameMaps.php:1321-1325`, `expandChannelRow()` at `:1297`).
   Nothing special about them. **[read]**

2. **A rename of the PRODUCER of the nine** is declarable two ways, and the two
   are not equivalent:

   - **Through `channels.tsv` in the `rule#code` whole-key form.**
     `expandChannelRow()` splits on `#` and emits, besides the whole key, one
     *ambiguous* half pair per differing half (`RenameMaps.php:1327-1331`).
     Measured with two rows renaming only the producer half: the forward pass
     turned `Rule "annotation.directive" declares no support` into
     `Rule "annotation.checker" …` on `format:json`, and `splits()` was empty
     (both rows agree on one target for the half, so it is not a split).
     But `reverse('--disable-rule=annotation.checker')` came back
     **unchanged** — channels is forward-only. **[measured]**
   - **Through `inputs.tsv` alone**, with the codes (if they also move)
     declared as ordinary bare-code `channels.tsv` rows. Measured with
     `inputs: annotation.directive → annotation.checker` plus
     `channels: annotation.unresolved-directive → annotation.unknown-name`: the
     load succeeded, the forward pass translated **both** halves on
     `format:json`, and `reverse('--disable-rule=annotation.checker')` returned
     `--disable-rule=annotation.directive`. **[measured]**

3. **The two cannot be combined.** A `rule#code` channels row whose producer
   half moves, plus an `inputs.tsv` row for the same producer name, is refused
   at load time — **[measured]**, verbatim:

   > `channels.tsv: "annotation.directive#annotation.unresolved-directive" -> "annotation.checker#annotation.unresolved-directive" and inputs.tsv: "annotation.directive" -> "annotation.checker" both rename "annotation.directive", so what the reference means by it is undecidable.`

   The half is deliberately keyed apart from a declared pair
   (`$slot = … . ($ambiguous ? 'half' : 'declared')`, `RenameMaps.php:917`; the "kept apart" rationale at `:908-916`) so
   that the collision is reported rather than silently resolved
   (`buildSubstitutions()` conflict guard, `RenameMaps.php:691-701`). **[read]**

   **So for the nine, П4's writable shape is `inputs.tsv` for the producer plus
   bare-code `channels.tsv` rows for whichever codes move — not the `rule#code`
   form.** This is a mechanical constraint, not a preference: the `rule#code`
   form forfeits the reverse direction, which is the only direction that lets a
   case address the reference binary.

### Surfaces where a producer name moves and NO map reaches

Three, all inside the gate's own corpus. The first is live and is not in the
plan's list of three structural limits; the second is latent; the third the plan
already names.

1. **Inline `@qmx-*` directives written into the corpus fixtures' PHP sources.**
   Measured by grep over `finding-gate/cases/`: seven fixture files carry
   directives naming a rule or channel — e.g.
   `finding-gate/cases/smells/src/Suppression.php:13`
   (`@qmx-ignore code-smell.eval`),
   `finding-gate/cases/applied-threshold/src/Accepted.php:15`
   (`@qmx-threshold code-smell.long-parameter-list warning=20 error=30`),
   `finding-gate/cases/applied-threshold/src/ClassScoped.php:12` and
   `…/Retuned.php:15` (`@qmx-threshold complexity.cyclomatic …`),
   `finding-gate/cases/annotations/src/Directives.php:8,15,22,29,42,56,67`, and
   `finding-gate/cases/rule-exclusion-ledger/src/Excluded/SuppressedInsideExcluded.php`.
   **[measured]**

   The corpus always belongs to the candidate — `Corpus.php:10-12`: "The corpus
   always comes from the candidate tree: only product code may differ between
   the two sides" — and `TreeRun::forCase()` rewrites, for the reference,
   exactly three things and no more: `$case->args` (`TreeRun.php:48`), the
   `explainSubjects` (`:79`) and the **configuration file** content
   (`configurationArgument()`, `TreeRun.php:194-208`). The analysed source files
   are passed as paths and read from disk unmodified by both trees. **[read]**

   Effect after П4: the fixture text must be hand-edited to the new name in the
   same commit (as `case.json` must), and then the **reference** tree sees a
   name it does not know. That is the plan's `inert-detected` class — an extra
   `annotation.unresolved-directive` finding, and the suppression the directive
   was performing is lost, so the reference reports the finding it was hiding.
   Both are finding-count and finding-content differences, which is what
   `Gate::compareFindingCounts()` (`Gate.php:1103`) and the surface
   comparison measure. No map row states it: `channels.tsv`/`inputs.tsv`
   translate artifacts and *the reference's arguments and config*, never the
   analysed source. **[read]**

   `declared-delta.tsv` is a mechanism that can state a structural surface
   change (`DeclaredDelta.php:8-30`), but it is guarded by `delta-overreach` —
   "a diff line may not change a field the equivalence tuple compares unless a
   declared split already explains that record" (`DeclaredDelta.php:22-24`) —
   which is exactly what a moved finding does. So: a mechanism exists, but it is
   not a map and it is not obviously usable here. **This is the one surface
   where a producer/channel name moves inside the gate and nothing declares the
   move.** [read]

2. **Latent, not live: an ADR 0025 `rule#code` pair-form selector key written
   into a case's own `qmx.yaml`.** `assertWholeInputToken()` admits no `#` — it
   is absent from `$dottedName` (`RenameMaps.php:1065`) and from the
   configuration-key shape (`:1070`) — so such a token cannot be declared in
   `inputs.tsv`; and `channels.tsv`, which does accept the `rule#code` form,
   is never applied backwards (`FILES`, `RenameMaps.php:171`). A case writing
   that form would therefore hand the reference a token nothing translates.
   Measured: no case writes it today —
   `grep -n '^[^#]*[A-Za-z0-9.-]#[A-Za-z0-9.-]' finding-gate/cases/*/qmx.yaml
   finding-gate/cases/*/case.json` returns nothing (every `#` in those files is
   a YAML comment), and the `annotations` fixture's own pair-shaped directive at
   `finding-gate/cases/annotations/src/Directives.php:67`
   (`@qmx-ignore duplication.code-duplication:class`) uses the `:` level form,
   not `#`. So this is a shape the gate cannot translate, not a hole П4
   currently falls into. **[read + measured]**

3. **`case.json`'s hand-written `channels` claims.** Already named in
   `00-overview.md`'s third structural limit and confirmed here:
   `finding-gate/cases/annotations/case.json:14-19` claims four
   `annotation.*@file` pairs and `finding-gate/cases/layers/case.json:15-21`
   claims six, `architecture.layer-violation@class` at `:18` among them. `ChannelCoverage` compares declared
   pairs "from the declaration witnesses, never from a claim"
   (`ChannelCoverage.php:30`) of the candidate tree. Loud refusal, not a
   silent hazard. **[read + measured — the claims were read out of the files]**

Everything else in `enumeration-migration-surfaces.tsv` that carries a producer
name is reached: `rules:` keys, `only_rules`/`disabled_rules`,
`--disable-rule`/`--only-rule`, the `--rule-opt` owner half and selector-shaped
config keys all go through `reverse()` at one of the three `TreeRun` call sites
above; every published spelling (bare, `qmx.`-prefixed, JSON-escaped) goes
through `forward()`. The producer name is additionally published **in prose** —
measured on `finding-gate/cases/annotations`:
`Rule "annotation.directive" declares no @qmx-threshold support, so this
annotation can never do anything.` — and an `inputs.tsv` row translates that
too, because the role has no surface restriction. **[measured]**

### Correction to `enumeration-gate-map-shapes.tsv`

Its `inputs.tsv` row says the map applies to "CLI/config input surfaces". That
undersells it: the map has **no** surface restriction at all and applies forward
to every artifact as well (`SURFACES` omission + `appliesToSurface()`,
`RenameMaps.php:164-167`, `:550-565`). The row's *`cannot_declare`* column and
its direction claims are accurate as written. **[read]**

---

## Q2 — has `health.<dimension>` already been decided by an ADR?

**Yes, twice — and one of the two is a number the brief named (0032), while the
ADR that actually introduced the form is not (0001).** Checked against `docs/adr/README.md`'s index and the ADR
bodies. **[read]**

- **ADR 0001 — Computed Metrics (Health Scores)**, Accepted, dated 2026-03-14
  (`docs/adr/0001-computed-metrics.md:3-4`). Decision point 2
  (`docs/adr/0001-computed-metrics.md:16`) names the six spellings outright:
  "**6 default health scores** (0–100, higher is better): `health.complexity`,
  `health.cohesion`, `health.coupling`, `health.typing`,
  `health.maintainability`, `health.overall`." This is where the form enters
  the product. Its stated basis is the feature (composite scores shipped as
  `ComputedMetricDefaults`), **not** an argument comparing this naming form
  against another. **[read]**

- **ADR 0032 — Computed-Metric Producer Split: One Name per Closed Definition**,
  Accepted, dated 2026-08-26
  (`docs/adr/0032-computed-metric-producer-split.md:3-4`). Its Decision
  (`:46-50`) makes the six spellings *producer* names: "the six built-in health
  dimensions become producers of their own — `health.complexity`,
  `health.cohesion`, `health.coupling`, `health.typing`,
  `health.maintainability`, `health.overall` — **each named exactly as its one
  channel is**." The basis is argued at length and is a real constraint, not a
  taste: the closed half can be split by name because the build knows every name
  in advance, while a user-defined metric's name is not known when
  `RuleNameValidator` runs (`:19-30`), and
  `RuleRegistryCompilerPass::validateNoDuplicateNames()` rules out six services
  of one class (`:31-39`). **[read]**

- **ADR 0035 — A Metric Key Names Its Family, in Kebab**, which sets the general
  grammar `family.metric` in lower-case kebab
  (`docs/adr/0035-a-metric-key-names-its-family-in-kebab.md`, Decision). The six
  names satisfy it with family `health`. It does not mention them by name (grep
  for `health` in that file returns nothing). **[measured — grep]**

- **ADR 0033** touches them only as a *dependency*, not as a decision about the
  form: `docs/adr/0033-display-family-is-derived-from-the-producer-name.md:41-45`
  records that the display family is read off "the first dot-separated segment
  of a producer's name", and that ADR 0032 "retired `computed.health` and
  introduced the `health` and `computed` cases". **Consequence worth naming in
  the ADR:** renaming the `health.` prefix moves the group heading and the
  `--group` value in `bin/qmx rules` by construction. **[read]**

- **ADR 0036** does not mention them (one occurrence of the word "health", at
  `:17`, about "the health formula excluder", unrelated to the naming form).
  **[measured — grep]**

**Verdict for the decision table.** The ADR of П3 must *cite* ADR 0032 for the
six names as producer/channel identities and ADR 0001 for their origin as metric
keys, and must not re-litigate either. What no ADR states, and what Q2's row is
actually for, stands as `01-decision-table.md` already says: these six are
**run-time-declared** channels, absent from `staticDeclarations()` by
construction. Confirmed in code — the enum is
`src/Analysis/Evidence/ComputedMetrics/Contract/Definition/HealthDimension.php:16-21`,
and none of the six appears in `enumeration-metric-key-naming.tsv` (0 rows match
`^health\.`), because that file's oracle is `MetricName`, which carries no
`health` constant (`grep -n health src/Analysis/Evidence/Measurement/Contract/MetricName.php`
returns only a prose line at `:128`). **[measured — grep on both files]**

---

## Q3 — what each of the nine rules actually reports

Every row below was read in `src/`; the "consumer sees" column is quoted from
the emission site, and where the table says **[measured]** the text came out of
an actual run. Two runs were used: `finding-gate/cases/annotations` with its own
`case.json` arguments, and a three-declaration scratch fixture under
`/private/tmp/claude-501/` built to fire the parameter-count pair. No decisions
are taken here.

### 1. `coupling.class-rank` — judges a magnitude; the declaration says otherwise on purpose

- **Judges:** ClassRank against a **project-size-scaled** warning/error pair.
  `ClassRankRule.php:104-111` — `$effectiveScaledWarning = $effectiveOptions->warning / $scaleFactor;` and
  `$threshold = $severity === Severity::Error ? $effectiveScaledError : $effectiveScaledWarning;`.
  `#[CliAlias('class-rank-warning', 'warning')]` / `('class-rank-error', 'error')`
  at `:28-29`. **[read]**
- **Consumer sees:** `ClassRank is 1.0000, exceeds threshold of 0.5000 (scaled
  for 1 classes). This class is a critical hub — changes have wide impact`
  **[measured]**.
- **Declaration:** `ChannelDeclaration::occurrence(SymbolLevel::Class_)`
  (`ClassRankRule.php:202-207`, the declaration at `:205`) — i.e. it declares **no** judged metric, which is
  why `bin/qmx rules` prints no `judges` line for it **[measured]** while it does
  print two `--rule-opt` threshold aliases.
- **Name vs behaviour:** the name matches the behaviour exactly — it judges
  ClassRank and says so. The mismatch is between behaviour and *declaration*,
  and it is deliberate and closed: `ClassRankRule.php:173-198` states that
  `ADR 0017` point 5 settles it (`:192-194`: "a project-normalised rank can change meaning
  while the channel does not, and a baseline entry bound to it would
  over-accept"), followed at `:195-196` by "The question is settled — do not
  reopen it by 'fixing' the declaration to match the reading." ADR 0046's index entry names it
  as one of six channels the declared-metric check does not cover
  (`docs/adr/README.md:90`). **[read]**
- **Bearing on Q1:** the plan's row "subject form, **judges nothing**" is a fact
  about `judging()`, not about what the rule does. Read as evidence for a naming
  rule of the form "a channel that judges a magnitude names the magnitude", this
  row is a **false negative**: it judges a magnitude and it does name it.

### 2 & 3. `code-smell.constructor-overinjection` and `code-smell.long-parameter-list` — the same metric, and the scopes OVERLAP

- Both judge `MetricName::CODE_SMELL_PARAMETER_COUNT`, `WorseDirection::Higher`,
  `SymbolLevel::Callable`
  (`ConstructorOverinjectionRule.php:65-73`, the `judging()` call at `:68`;
  `LongParameterListRule.php:78-87`, the call at `:81`).
  `bin/qmx rules` prints both `judges code-smell.parameter-count` lines
  **[measured]**.
- **Overinjection** filters to constructors and skips global functions:
  `if ($declaration->logical->member !== '__construct') { return null; }`
  (`ConstructorOverinjectionRule.php:105-107`, the test at `:106`) and the
  `type === null` guard at `:110-112`. Message template at `:141-143`; as it came out of the run:
  `Constructor of Fat has 8 parameters (threshold 8). Consider using a parameter
  object or splitting responsibilities` **[measured]**.
- **Long parameter list** filters to `SymbolType::Method` or `Function_`
  (`LongParameterListRule.php:115-117`) — it does **not** exclude
  `__construct`. A constructor that is not a value-object constructor
  (`MetricName::CODE_SMELL_IS_VO_CONSTRUCTOR`, `:126`) goes down the ordinary
  method branch, and the message says `Method`
  (`$kind = $symbolType === SymbolType::Function_ ? 'Function' : 'Method'`,
  `LongParameterListRule.php:156`, used in the message at `:164`).
- **Measured overlap.** One 8-parameter, non-VO constructor produced **both**
  findings at the same line **[measured]**:

  ```
  code-smell.constructor-overinjection | 5 | Constructor of Fat has 8 parameters (threshold 8). …
  code-smell.long-parameter-list       | 5 | Method has 8 parameters, exceeds threshold of 6. …
  code-smell.long-parameter-list       | 6 | Method has 8 parameters, exceeds threshold of 6. …
  ```

- **Name vs behaviour:** each name reads as a promise the rule keeps. What the
  measurement **refutes** is the framing behind the orchestrator's prior: these
  are not "two different positions of one metric" in the sense of a partition.
  The constructor case is a **subset with its own, looser threshold pair** that
  is reported twice — once as a constructor problem and once as a method
  problem. Whether that is a naming question or a behaviour question is a
  decision this file does not take, but "legitimate many-to-one because the
  situations are disjoint" is not supported by the code.

### 4. `code-smell.unreachable-code` — judges a count whose default warning is 1

- Judges `MetricName::CODE_SMELL_UNREACHABLE_CODE`, higher-is-worse
  (`UnreachableCodeRule.php:67-76`, the `judging()` call at `:70`). Defaults
  `warning: 1`, `error: 2` (`UnreachableCodeOptions.php:28-29`, documented at
  `:19-20`), so at defaults the warning fires on
  the **first** unreachable statement — a threshold that is a presence test.
  **[read]**
- **Consumer sees:** `Found %d unreachable statement(s) after terminal statement
  (return/throw/exit/break/continue). Dead code should be removed`
  (`UnreachableCodeRule.php:138-141`), `metricValue: $unreachableCountValue`
  (`:143`). **[read]**
- **Name vs behaviour:** the name names the phenomenon; the rule reports the
  phenomenon and counts it. No mismatch. The channel code and the metric key are
  the same string, which is ADR 0035's explicitly permitted case.

### 5. `code-smell.unused-private` — reports a fact per member; the magnitude is a class-wide total

- **No threshold at all.** One finding per unused private member, severity
  hard-coded `Severity::Warning` (`UnusedPrivateRule.php:103`), fired on any
  nonzero `$total` (`:86-89`). The docblock says so:
  "There is no gating threshold comparison to read a direction from (the rule
  fires on any nonzero `$total`, and severity is the fixed constant
  `Severity::Warning`; `UnusedPrivateOptions::getSeverity()` exists but is never
  called)" (`UnusedPrivateRule.php:127-136`). **[read]**
- **Consumer sees:** the member, not a number — `entryMessage()` renders
  ``sprintf('%s `%s`', $label, $entry['name'])``
  (`UnusedPrivateRule.php:116-119`), e.g. "Private method `foo`".
- **The quirk, pinned in the source:** every finding of the group carries the
  same class-wide `metricValue: $total` — "a class with three unused private
  members emits three findings that each report `metricValue: 3`"
  (`UnusedPrivateRule.php:139-146`), declared
  `judging(Higher, CODE_SMELL_UNUSED_PRIVATE_TOTAL, Class_)` at `:151-153`.
- **Name vs behaviour:** the name promises a fact of presence, and that is what
  the finding text delivers. The judged `…​.total` key is a property of the
  declaration, not of what the reader is told.

### 6. `design.data-class` — a two-criterion pattern verdict, worse LOWER

- Fires when **both** `woc <= wocThreshold` **and** `wmc <= wmcThreshold`
  — implemented as the negated guard
  `if ($wocValue > …->wocThreshold || $wmcValue > …->wmcThreshold) { return null; }`
  (`DataClassRule.php:120`), after an exclusion check
  (`DataClassExclusionCheck::isExcluded()`, `:107-109`) with six configurable
  options (`bin/qmx rules` prints all six `--rule-opt` aliases) **[measured]**.
- **Consumer sees:** `Data Class detected: only %d%% of the public interface is
  behavior (WOC, threshold %d%%) and complexity is low (WMC=%d, threshold %d).
  Consider encapsulating behavior or using a DTO pattern`
  (`DataClassRule.php:130-136`); severity fixed `Severity::Warning` (`:137`);
  `metricValue: $wocValue` (`:138`); declared `judging(WorseDirection::Lower, DESIGN_WOC, Class_)` at `:170-174`. **[read]**
- **Name vs behaviour:** it judges no single magnitude — it is a conjunction of
  two thresholds on two metrics plus five exclusions, publishing a named
  anti-pattern. A "name the magnitude" rule has no single magnitude to offer
  here. The name matches what the rule concludes.

### 7. `architecture.coverage` — a configuration diagnostic, not a coverage figure

- **Not a magnitude and not a threshold.** One project-level finding with **no
  `metricValue` and no `threshold`**
  (`DeclaredLayerReachability.php:89-104` — the `Finding` constructor call names
  `location`, `subject`, `symbolPath`, `ruleName`, `code`, `message`,
  `severity`, `recommendation`, and nothing else). Declared
  `ChannelDeclaration::occurrence(SymbolLevel::Project)` along with its four
  siblings (`LayerDeclarationValidator.php:81-97`). **[read]**
- **Severity comes from a three-state config mode**, not an option:
  `match ($mode) { CoverageMode::Warn => Severity::Warning, CoverageMode::Error
  => Severity::Error }` (`DeclaredLayerReachability.php:82-85`), with
  `CoverageMode::Ignore` returning no finding at all (`:73-75`; the method is
  `coverage()` at `:71`). The values are
  validated as `'ignore'|'warn'|'error'`
  (`Configuration/CoverageValidator.php:30,42`), and
  `LayerViolationOptions.php:43` records that "`architecture.coverage` never had
  such a key" — it takes no `--rule-opt`. Confirmed: `bin/qmx rules` lists no
  such producer and no such option **[measured]**.
- **Consumer sees:** `Architecture coverage: %d edge(s) with unmatched source
  layer, %d edge(s) with unmatched target layer, %d class(es) outside all
  declared layers.` (`DeclaredLayerReachability.php:95-98`, the format string at `:96`).
- **What it is for:** the file's own class docblock groups the five diagnostics
  under one question — "does the declaration still describe the code?" — and one
  answer shape: "a project-subject finding that **reports a mistake in the
  configuration rather than debt in the code**. None of them can be accepted by a
  baseline." (`DeclaredLayerReachability.php:20-23`). It further warns that the
  number's breadth "is what makes the number unusable as a gate on one's own
  code" and points at `architecture.unassigned-class` as the narrow one
  (`:28-32`). **[read]**
- **Name vs behaviour — mismatch, and a specific one.** "Coverage" is the word
  the rest of the product uses for a *ratio judged against a minimum*: measured
  in the same run, `design.type-coverage.param` says `Parameter type coverage is
  0.0% (minimum: 50.0%)` **[measured]**. `architecture.coverage` publishes no
  ratio, no minimum and no metric, and is a configuration-error report rather
  than a code-quality one. A reader who transfers the meaning of `coverage` from
  one to the other is wrong about all three.

### 8. `code-smell.error-suppression` — a pure occurrence of a language mechanism

- Inherits the shared base emission: one finding per collected entry, no
  threshold, fixed severity, `ChannelDeclaration::occurrence(SymbolLevel::Callable)`
  (`AbstractCodeSmellRule.php:106-111`, the declaration at `:108`; emission at
  `:134-141`). The rule class
  is declaration-only: `SMELL_TYPE = 'error_suppression'`,
  `SEVERITY = Severity::Warning`,
  `MESSAGE_TEMPLATE = 'Error suppression operator (@) detected - handle errors
  explicitly'`, plus a with-extra template
  `'Error suppression (@) on %s() - handle errors explicitly'`
  (`ErrorSuppressionRule.php:20-28`: `SMELL_TYPE` at `:24`, `SEVERITY` at `:25`,
  the two message templates at `:26-27`). The only configuration is an
  `allowed_functions` whitelist routed through `shouldIncludeEntry()`
  (`ErrorSuppressionRule.php:14-15`, `AbstractCodeSmellRule.php:157-167`). **[read]**
- **Name vs behaviour:** the finding text leads with the operator `@`, so the
  reader is told about a mechanism. The name reads as the mechanism under its
  ordinary PHP name (the "error suppression operator"), which is what the rule
  reports. On behaviour it sits with `code-smell.eval` / `code-smell.goto`, not
  with the threshold rules; the enumeration's "mechanism or judgment" ambiguity
  is about the English word, not about a divergence between name and behaviour.
- Note for П4: its `SMELL_TYPE = 'error_suppression'`
  (`ErrorSuppressionRule.php:24`) is one of P0's twelve bag-keyed frozen
  occurrence discriminators. It must not be tidied to follow a renamed channel.

### 9. `duplication.code-duplication` — reports a block; the number only picks the severity

- **Emission is unconditional.** One finding per `DuplicateBlock`, no size gate:
  `foreach ($this->resultProvider->all() as $block) { $findings[] = $this->createFinding(…); }`
  (`CodeDuplicationRule.php:65-68`). The source states it: "Emission itself is
  unconditional — every `DuplicateBlock` produces a `Finding` regardless of size
  (`$severity ?? Severity::Warning` at line 102 is only ever a fallback)"
  (`CodeDuplicationRule.php:85-89`). The threshold pair gates **severity only**
  (`:126`, `getEffectiveSeverity(…, $block->lines)`). **[read]**
- Declared `ChannelDeclaration::magnitude(WorseDirection::Higher,
  SymbolLevel::Project)` (`:97-99`, the call at `:98`) — `magnitude()`, not `judging()`, so it names
  **no** judged metric key and `bin/qmx rules` prints no `judges` line and no
  `--rule-opt` alias for it **[measured]**.
- **Consumer sees:** `Duplicated code block (%d lines, %d occurrences)%s — also
  at %s` (`CodeDuplicationRule.php:116-121`, the format string at `:117`), at project level.
- **Name vs behaviour — the name is the weakest of the nine on two counts.**
  (a) It is tautological in spelling: the group segment and the leaf segment say
  the same word, so the leaf carries no information at all — the only such case
  among the 52 (`enumeration-channel-naming.tsv`, its `duplication.code-duplication`
  row). (b) Neither reading it admits is what the rule does: it does not report a
  *duplication ratio* (the magnitude it publishes is one block's **line count**,
  and there is no catalog metric behind it), and its unit is not "duplication"
  but "one duplicated block, with its copies listed". A name naming either the
  judged magnitude or the reported occurrence would say something the current one
  does not.
- Note for П4: `private const string OCCURRENCE_KIND = 'duplication.code-duplication'`
  (`CodeDuplicationRule.php:37`) is frozen with an explicit warning at `:32-36`
  — "Frozen to today's channel spelling on purpose — it does not follow a future
  rename of `NAME`."

### On the orchestrator's prior

- `duplication.code-duplication` and `architecture.coverage` **are** the two
  weakest names of the set read here, and for reasons the code supports rather
  than for their shape: the first names neither what it judges nor what it
  reports and repeats its own group; the second borrows a word the product uses
  elsewhere for a judged ratio and applies it to a configuration-error report
  with no ratio, no minimum and no metric. **Confirmed by reading.**
- The `parameter-count` pair is **not** the clean many-to-one the prior
  describes. The scopes overlap and one fat constructor is reported by both
  channels at once (**measured**). The two names are each accurate; what the
  prior got wrong is the premise that they carve one metric into disjoint
  situations.

---

## What this reading does not see

- **Whether the gate behaves this way with two real trees.** Every Q4 claim
  about `RenameMaps` was measured by loading the class directly with synthetic
  or scratch map rows and calling `forward()`/`reverse()` on strings. That
  exercises the substitution engine and the load-time refusals, and it does not
  exercise `Gate::run()`, `checkReferenceInput()`, `ChannelSplit`, staleness
  accounting over a real corpus, or `TreeRun` against a checked-out reference.
  A П4 step is still the first thing that will run a populated `maps/` end to
  end.
- **Whether the corpus-fixture-directive difference is actually undeclarable, or
  merely awkward.** Established: no map reaches the fixture source, and
  `delta-overreach` guards the finding fields. **Not** established: what a real
  run reports when it happens — that needs a reference tree and a rename to
  exist. The claim here is about the mechanism, not about an observed verdict.
- **The reverse-direction accounting of a merged two-role declaration.**
  `RenameMaps.php:98-108` says explicitly that per-direction accounting is not
  claimed: a declaration is live once it fired anywhere. So a П4 row that is
  correct forwards and dead backwards will not be reported stale. Reading cannot
  close that; only a step that exercises both directions can.
- **The 27 option keys and the 80 CLI aliases were not re-enumerated.** Q5 is
  out of this file's scope, and the `warn-and-default` migration class it turns
  on was taken from `00-overview.md`'s measurement rather than re-measured.
- **Q3's readings are about default configuration.** Thresholds were read from
  the Options classes' declared defaults; a consumer's `qmx.yaml`,
  `@qmx-threshold` or `--rule-opt` changes the numbers, and for
  `code-smell.unreachable-code` in particular the "warning at 1" reading is a
  default, not an invariant.
- **`design.god-class` was not read**, though it shares `design.data-class`'s
  multi-criterion shape and shows the same "thresholds but no `judges` line" in
  `bin/qmx rules`. It is not one of the nine and no claim here depends on it.
- **The `bin/qmx rules` run and the fixture runs used this working tree's
  product code.** They are evidence about this branch, not about a released
  version.
