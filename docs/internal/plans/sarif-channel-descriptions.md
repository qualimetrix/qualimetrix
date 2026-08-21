# Facts about a channel come from its owner, not from a private copy in a consumer

Status: proposed (not implemented)

## Problem

`SarifRuleCollector` describes SARIF rule descriptors from two private tables
that duplicate facts owned elsewhere:

- `getRuleDescription()` — a `match` over violation codes, duplicating
  `RuleInterface::getDescription()`;
- `CATEGORY_DOCS_MAP` — a prefix→docs-path map, duplicating
  `RuleInterface::getCategory()`.

Both have already drifted. Measured, not asserted — see the two generated
tables under `docs/internal/plans/sarif-channel-descriptions/`, each carrying
its own "how obtained / what this method cannot see" header:

- `channel-inventory.tsv` — every channel of the real container, its producing
  rule and that rule's description (57 static channels, 42 registered rules).
- `sarif-table-coverage.tsv` — the `match` arms joined against those channels.

The measured state of the copy:

- **9 of 24 arms are unreachable.** `complexity.cyclomatic`,
  `complexity.cognitive`, `complexity.npath`, `design.type-coverage`,
  `coupling.cbo` and `coupling.instability` are rule names, never violation
  codes — the product emits `…​.callable` / `…​.class` / `…​.namespace` /
  `…​.param` and so on. `size.loc` and `size.namespace-size` name rules that
  do not exist; `size.long-parameter-list` is spelled
  `code-smell.long-parameter-list` in the product.
- **42 of 57 channels reach no arm** and get the `ucfirst(str_replace(…))`
  fallback: every `security.*`, every `code-smell.*` except two, every
  `architecture.*` diagnostic, every `annotation.*` diagnostic, and every
  sub-code of the six rules above.
- `CATEGORY_DOCS_MAP` sends `duplication.*` to the `architecture/` page while
  `website/docs/rules/duplication.md` exists; has no entry for `annotation.*`
  or `health.*` (both fall back to the repository URL); and carries a
  `cohesion` key no rule name can ever match.
- The existing unit test asserts the arms against **fabricated** violation
  codes (`violationCode: 'complexity.cyclomatic'`), so it validates the copy
  against itself and stays green while the product emits none of them.

The `@qmx-threshold complexity.cyclomatic warning=30 error=40` on
`getRuleDescription()` exists only to hold the copy.

## The same copy, a second time: `RemediationTimeRegistry`

Checked whether SARIF is the only site. Method: grepped `src/` for dotted
rule-name string literals outside the owning capabilities, then inspected every
hit plus every formatter. **What this method cannot see:** a copy keyed by
something other than a dotted rule name — a `RuleCategory`, a metric name, a
class-string, or a key built at run time. `RuleCategory::` has no consumer
outside its owners (checked); metric-name-keyed catalogs are covered below.

Only three files hold rule-name literals outside their owners.

`src/Analysis/Evidence/Prioritization/Debt/RemediationTimeRegistry.php` is the
same defect class, and it has already diverged:

- `INVERTED_RULES` is a hand-kept copy of "lower is worse", which
  `ChannelDeclaration` now owns and a tracked fixture drift-guards.
  `design.data-class` is declared `magnitude:lower` and is **absent** from
  `INVERTED_RULES` — the copy is out of sync with the declaration.
  The divergence is **latent, not yet observable**: `DataClassRule` emits no
  `threshold` at all (`DataClassRule.php:136-152`), so
  `getMinutesForViolation()` returns at the `threshold === null` guard and never
  reaches the inversion branch. An earlier draft of this plan claimed the
  finding's debt was mis-scaled *because of* the missing entry; that is wrong,
  and the DoD built on it ("a regression test fixes the scaling") was not
  writable. What is true is narrower and still worth fixing: a second, silent
  copy of a fact the declaration owns, one `threshold:` argument away from
  becoming a live defect.
- The `computed.health` branch infers inversion from `metricValue < threshold`
  while `ComputedMetricDefinition::$inverted` is the declared fact. The
  heuristic happens to agree because of *when* a violation fires, not because
  it reads the declaration.
- `coupling.class-rank` is the one channel declared `occurrence` — so
  `ChannelDeclaration` forbids it a direction — that nonetheless emits both
  `metricValue` and `threshold` and is therefore scaled. Its declaration is
  **correct and argued** at its emission point: the rank is a project-wide
  normalised PageRank whose threshold is rescaled per class count, so a stored
  rank is not in a later run's units. What it exposes is that the scaling has no
  justification for this channel in the first place — `ChannelDeclaration` welds
  direction to a `magnitude` shape, and the one channel that cannot supply a
  direction is the one whose number should never have been scaled. See P5.

Two sites checked and **cleared**, stated so the next reader does not re-check
them:

- `CheckstyleFormatter`, `GitLabCodeQualityFormatter`, `GithubActionsFormatter`
  derive everything from the violation itself — no table.
- `RuleThresholdKeyGroupRegistry` is a hand-kept copy too, but a *declared* one:
  its docblock argues why it cannot be derived at merge time, and the claim
  "every rule known to this codebase has an entry" verifies — all 20 rules
  behind the 30 `Options` classes that call `ThresholdParser::parse()` are
  present. Leave it alone.
- `MetricHintCatalog` is keyed by metric name and is partial by design; 43
  metric constants have no hint. Nothing states which metrics need one, so its
  completeness is unfalsifiable rather than wrong. Out of scope, worth a
  separate decision.

Two places carry violation codes the product never emits, both hand-written:
`src/Reporting/Template/dev.html` (`complexity.cyclomatic.error`,
`coupling.cbo.warning`, `code-smell.long-parameter-list.warning`, …) and
`website/docs/usage/output-formats.md` (`size.method-count.class`). Same root
cause as the SARIF unit test: a code spelled by hand instead of taken from the
universe.

## Decision

A SARIF rule descriptor's text and `helpUri` are derived from the **producing
rule** of the descriptor's channel. No table in `Reporting`.

Three facts are needed and no single source holds them:

- `violationCode → producing rule name` —
  `ChannelIdentityInterface::producerOf()`, and it is the only thing that
  answers correctly: `architecture.coverage` and the `annotation.*` diagnostics
  carry a `ruleName` half that names no rule class, so no string manipulation
  on the code can find their producer.
- `rule name → description` — `RuleMetadata`, as `qmx rules` already uses it.
- `rule name → documentation URL` — a **declared constant on the rule**, see
  the `helpUri` section below. Deliberately not the rule's `RuleCategory`.

**The second fact is only available at run time, and this is what an earlier
draft got wrong.** That draft proposed extending
`ChannelDeclarationCompilerPass` to collect descriptions alongside channel
declarations. It cannot: the pass reads every fact off a class-string by
reflection without instantiating (`RuleNameReader` takes the `NAME` constant,
and its docblock says why — rules have constructor dependencies beyond their
Options, so "instantiating a rule outside the DI container is never safe"),
while `getDescription()` and `getCategory()` are instance methods of
`RuleInterface`. Rule instances exist in exactly one place: the container.

So the join belongs to a small run-time service, not to a compiler pass.

### Contract

`Analysis\Finding` owns both facts, so it owns the join. One narrow contract,
one implementation registered beside the other channel views in
`RuleConfigurator`:

```
interface ChannelPresentationInterface
{
    // Display text and documentation URL for a channel, or null when no
    // channel carries the code. Resolved at run time from the rule instances
    // the container holds; the URL half comes from the declared constant.
    public function presentationFor(string $violationCode): ?ChannelPresentation;
}
```

`SarifRuleCollector` takes this one view. `Reporting` already imports
`ChannelDeclarationRegistryInterface`, a sibling view on the same instance, so
the edge is not new in kind — but the exact import is new and must be listed in
the manifest.

**Decided: the contract.** The rejected alternative was to inject
`ChannelIdentityInterface` *and* `RuleExecutionInterface` into the collector and
do the join there. Both aliases are already registered public in
`RuleConfigurator`, so it would wire with no new machinery. It is not "one
dependency instead of two" — both shapes need every fact; the difference is only
whether the join lives with the owner of the facts or is re-done by each
consumer, and whether rule *execution* appears on the reporting side of the
boundary. The universe's own docblock names "a declared rule property" as one of
the questions it was built to host, which settles where this belongs.

The implementation is a **small service composing the identity view with the
rule metadata**, not a fourth half bolted onto `ChannelUniverse`. The universe
already has two contributors with two lifecycles (compile-time class-strings and
the live definition catalog); rule *instances* are a third, and they do not
exist when the universe is assembled. A composing service is a derivation, not a
second source of truth, so it does not reintroduce the "two objects, two
answers" hazard the universe was created to end.

### helpUri

Each rule declares the page that documents it, as a class constant read by
reflection — the same idiom `RuleNameReader` already uses for `NAME`, and
therefore available at container build time, unlike the instance methods above.

An earlier draft derived it from the producing rule's `RuleCategory` instead,
claiming "the ten cases map 1:1 onto the ten pages under
`website/docs/rules/`". Both halves are false, and the second one fatally:

- there are **eleven** pages; `cohesion.md` corresponds to no category;
- `computed.health` is documented at `website/docs/reference/health-scores.md`,
  **outside `/rules/` altogether**. No category value can produce that URL, and
  no renaming fixes it.

`RuleCategory`'s own docblock states its scope: "how a rule is grouped for
**display** — and nothing else." Building a URL out of it is the same defect
this plan exists to remove, and the third instance of it caught in this
document: taking a fact from a source that owns a *different*, merely
correlating fact.

Two consequences the constant must carry, both of which an earlier draft got
wrong:

- `DOCS_BASE_URI` currently ends in `/rules/`. It cannot: `computed.health`'s
  page is under `reference/`. The base becomes the site root and the constant
  holds the full relative path.
- The guard must assert **correspondence, not existence**. "The page exists" is
  exactly what today's broken `duplication → architecture/` mapping already
  satisfies. The guard asserts the target page carries this rule's `Rule ID:`
  anchor — the same anchor the inventory table was built from — and that the
  constant is declared on the concrete rule class
  (`getReflectionConstant()->getDeclaringClass()`), so a value inherited from
  `AbstractCodeSmellRule` or `AbstractRule` cannot pass as a declaration.

## Work packages

Sequential. File sets were rebuilt by grep rather than from memory; the earlier
draft's claim that they do not overlap was false, and the overlaps are named.

**P0 — `design.lcom` → `cohesion.lcom`, its own commit, before everything.**
Rationale: every coordinate except the name says cohesion — the class lives in
`src/Analysis/Evidence/Cohesion/`, the documentation is `cohesion.md`, and that
page is titled "Cohesion Rules". The decisive argument is behavioural:
`--only-rule='cohesion.*'` is rejected with "does not match any registered
producer, group, or channel", so cohesion has no group address at all, while
`--only-rule='design.*'` sweeps LCOM in with the design rules. Verified by A/B
after first showing the selector bites (`design.*` → 0 findings, `complexity.*`
→ 1 on the same path). `website/docs/llms.txt:32` already advertises `cohesion`
as a rule group, so the rename makes existing documentation true rather than
breaking it.
It goes first because every later package keys on rule names, and doing it after
P4 would mean rewriting the guard fixtures twice.

Files — **86 occurrences in 38 files** (`git grep -o | wc -l`; an earlier draft
said 83, having summed `git grep -c`, which counts lines, not occurrences).
The sweep is not one search-replace, because the literal is not the only
surface:

- *Code, config, presets, tests, live documentation* — mechanical replacement.
- *`RuleCategory`* — new `Cohesion` case, `LcomRule`'s category follows the
  name. Safe: no exhaustive `match` over the enum exists.
- *Claims the literal grep does not reach.* `website/docs/rules/index.md:48`
  links LCOM to `design.md` (already the wrong page), line 216 groups it under
  **Design**, and line 217 asserts "Cohesion (metrics only, no rule)" — false
  today, plainly false after the rename. Grepping the ID finds lines 48 and 216
  and never reaches 217. Both language mirrors.
- *`docs/adr/**` and completed plans under `docs/internal/plans/`* — excluded
  from the sweep, each hit decided by hand. These are dated records of what was
  decided when; a blanket rewrite would make them claim the project used a name
  it did not have at that date. This is a records-hygiene rule, not a constraint
  on changing architecture: an accepted ADR never blocks a better design, it
  gets superseded by a new one. ADR 0025's single mention is illustrative and
  its argument is about level, not spelling — decision: leave it, and let P0's
  own new ADR record the rename.
- *`docs/internal/generated/**`* — regenerated, never edited; otherwise
  `composer architecture:check` catches the divergence from source.

DoD: `qmx-baseline.json` holds **zero** `design.lcom` entries — verified before
the rename, not assumed — so no stored ratchet entry breaks. A consumer's own
baseline entry for the old channel degrades through
`InertEntryReason::UndeclaredChannel` rather than failing the run; that named
mechanism, not a general claim of gracefulness, is what the migration note
cites. The migration note names the surfaces that actually change under a
consumer: `qmx.yaml` rule keys, `--only-rule` / `--disable-rule` selectors,
`@qmx-ignore` / `@qmx-threshold` directives naming the channel, baseline entries,
and the group a rule appears under in `qmx rules`. `CHANGELOG.md` gets a
`Breaking` entry naming the old and new spelling **and** the group move. EN and
RU docs updated together. `composer check` and `mkdocs build --strict` green.

**P1 — Rules declare their documentation page.**
Files: every rule class (42) — one constant each; the reader beside
`RuleNameReader`; `src/Analysis/Finding/Contract/Rule/`.
DoD: every registered rule declares a path; the two rules whose page is not
`rules/{prefix}` — `cohesion.lcom` after P0, and `computed.health` at
`reference/health-scores` — are the reason the constant exists and are named in
its docblock so the next reader does not "simplify" it back into a prefix rule.
The route into the join is named here rather than left implicit: the reader is
called from the same place that already reads `NAME`, and the resulting map is
handed to the composing service of P2 as a constructor argument, so the
constant reaches `ChannelPresentation` by declaration rather than by a lookup
the service performs on its own.

P1 carries its **own** guard rather than deferring to P4's: a test asserting
that every registered rule declares the constant on its own class, and that the
declared path resolves to a page carrying that rule's `Rule ID:` anchor.
Without it, the invariant "every rule declares a page" holds only by the good
behaviour of whoever adds the 43rd rule between P1 and P4 landing.

**P2 — Finding: the join.**
Files: `src/Analysis/Finding/Contract/ChannelPresentationInterface.php` and
`ChannelPresentation.php` (new), the composing service,
`src/Infrastructure/DependencyInjection/Configurator/RuleConfigurator.php`
(**not** `OutputConfigurator` — the channel views are aliased there),
`docs/internal/modular-architecture-manifest.json` and the regenerated
artefacts under `docs/internal/generated/modular-architecture/`.
DoD: the view answers for all 57 static channels and for a configured
`computed.*` / `health.*` definition, preferring `ComputedMetricDefinition`'s
own description for that family; `composer architecture:check` green.

**P3 — Reporting: the consumer.**
Files: `src/Reporting/Formatter/Sarif/SarifRuleCollector.php`,
`src/Reporting/Formatter/Sarif/SarifFormatter.php` (wiring only),
`src/Infrastructure/DependencyInjection/Configurator/OutputConfigurator.php`,
manifest + generated artefacts (**overlaps P2** — same two files, so P2 and P3
cannot run in parallel), the 6 test files that construct `SarifRuleCollector`
or `SarifFormatter` directly, `src/Reporting/README.md`.
DoD: `getRuleDescription()`, `CATEGORY_DOCS_MAP` and the `@qmx-threshold`
suppression are gone; an unknown code still yields the humanised fallback and
the repository URL rather than throwing.
`composer selfcheck` green, and the criterion is stated rather than left to
judgement: the ratchet holds a live `computed.health#health.cohesion` entry at
40 for `ns:Qualimetrix\Reporting\Formatter\Sarif`, and removing a large method
moves that score. If it moves the good way the entry is tightened in the same
commit; if it moves the bad way the package is not done. `qmx-baseline.json` is
therefore part of this package's file set.

**P4 — The guard that would have caught this.**
Files: `tests/Unit/Reporting/Formatter/Sarif/SarifRuleCollectorTest.php`
(rewritten against real codes), a new integration test.
DoD: the test drives the collector with one violation per channel **taken from
the universe, never spelled by hand**, and asserts that no descriptor falls back
to the humanised default and that every `helpUri` resolves to the page carrying
that rule's `Rule ID:` anchor. A computed metric whose definition carries an
empty `description` must fail the oracle, not pass it.

"Red first" is a procedure here, not a slogan, because a commit that leaves the
suite red is not acceptable and an earlier draft's "write P4 before P3 lands"
demanded exactly that. The procedure: the test is written against the current
`main`, run there, and its failure output is pasted into the package report;
only then is it committed together with P3's fix. What is verified is that the
test discriminates — the artefact is the recorded failure, not the commit
order.

**P5 — `RemediationTimeRegistry`: delete the copy, and stop scaling what cannot
be scaled.**
Files: `src/Analysis/Evidence/Prioritization/Debt/RemediationTimeRegistry.php`,
its DI registration in `OutputConfigurator` (**overlaps P3**), manifest +
generated artefacts (**overlaps P2/P3**), and the 17 test files that construct
the registry directly.

This package has now been designed three times, and the two discarded shapes are
recorded because each was refuted by evidence a reader would otherwise have to
re-derive.

*Discarded shape 1 — "read the direction from `ChannelDeclaration`".* Refuted by
`coupling.class-rank`: declared `occurrence`, so the declaration is forbidden to
carry a direction, yet it emits both a `metricValue` and a threshold and is
therefore scaled today.

*Discarded shape 2 — "keep the table as an audited copy, with a test asserting
it agrees with the declarations".* Refuted twice over. The stated justification
was that `ChannelDeclaration.direction` answers a cross-run question while debt
scaling asks a within-run one; `WorseDirection`'s own docblock refutes that —
"the direction in which a magnitude gets worse" is run-neutral, and the enum
merely *hosts* the two cross-run seam formulas. And the guard itself was
worthless: on every magnitude channel the table and the declarations agree
bit-for-bit today, so the agreement test would be green from birth on the very
case it was written for.

*The actual obstacle*, once both are cleared away, is narrow and structural:
`ChannelDeclaration` welds shape and direction together — a direction is
permitted exactly when the shape is `magnitude` — and `coupling.class-rank`
needs one without the other.

**Decision: it should not need one.** ClassRank is a project-wide normalised
PageRank whose threshold is rescaled per class count; multiplying remediation
minutes by `rank / threshold` is justified by nothing except both numbers being
in scope at the call site. It stops being scaled and takes the flat base time.
With that, the original idea becomes correct: `INVERTED_RULES` is **deleted**,
not audited, and direction is read from
`ChannelDeclarationRegistryInterface::declarationFor()` — the same registry
`FindingProjector` already consumes. The `computed.health` heuristic is replaced
by `ComputedMetricDefinition::$inverted`, and the literal `'computed.health'` at
the call site by the family constant its owner already publishes.

The policy is **fail-closed**, which is what makes it survive a rule nobody has
written yet: a channel whose declaration carries no direction is not scaled.
Today that is `coupling.class-rank` alone; the fourteen other `occurrence`
channels in `MINUTES_BY_RULE` emit no threshold and are already unscaled, so
nothing else moves. A future `occurrence` channel that starts emitting a
threshold inherits "not scaled" rather than silently inheriting
higher-is-worse.

**Not in this package: `design.data-class`.** It is flat because `DataClassRule`
emits no `threshold` at all, which no change here touches. Whether that rule
should report the threshold it compares against is a real question about that
rule, and folding it in here would let a rule fix ride along inside a debt-model
change.

DoD: `INVERTED_RULES` and the `computed.health` heuristic are gone.
A test asserts the fail-closed policy directly — a synthetic violation on a
directionless channel is not scaled — rather than asserting agreement between
two tables. The omission list is read from `MINUTES_BY_RULE`'s keys against the
registered rule names: five rules carry an explicit `15` identical to
`DEFAULT_MINUTES`, so the public `getBaseMinutes()` cannot tell an omission from
a deliberate entry. `CHANGELOG.md` records that ClassRank debt is no longer
scaled, since reported totals change.

**P6 — The hand-spelled codes and the docs.**
Files: `src/Reporting/Template/dev.html`,
`website/docs/usage/output-formats.md` **and its `.ru.md` mirror**,
`CHANGELOG.md` (`Fixed`: SARIF rule descriptors carried placeholder text and
wrong `helpUri`s for most rules), `docs/adr/` for the P2 contract.
DoD: no violation code appears in tracked material that the universe does not
carry; `mkdocs build --strict` green.

## Test plan

- Every channel of the real universe resolves to its producing rule's
  description (P4, enumerated from the universe).
- The three families the old table could not reach at all — architecture
  diagnostics, annotation diagnostics, security rules — are named cases.
- A configured computed metric's descriptor carries the definition's own
  description; an empty definition description fails the oracle.
- Every rule's declared documentation path resolves to the page carrying that
  rule's `Rule ID:` anchor, and the constant is declared on the concrete class
  rather than inherited; the two non-prefix cases (`cohesion.lcom`,
  `computed.health`) are named assertions rather than incidental passes.
- Sub-codes of one rule (`coupling.cbo.class` / `.namespace`) get distinct
  `id`/`name` and the same description.
- An unknown code keeps the current fallback behaviour.
- SARIF schema validation and `JsonShapePreservationTest` still pass; the wire
  shape is unchanged, only the strings inside it.
- After P0: `--only-rule='cohesion.*'` selects the LCOM rule and
  `--only-rule='design.*'` no longer does; both directions asserted, since a
  test that only checks the new spelling would pass on a rule registered twice.
- A violation on a channel whose declaration carries no direction is not scaled
  (P5, fail-closed policy asserted directly, not as table agreement).
- `coupling.class-rank` takes the flat base time; `maintainability.index` still
  scales in the inverted direction, read from its declaration.
- The `MINUTES_BY_RULE` omission list matches the six named rules exactly, read
  from the constant rather than through `getBaseMinutes()`.

## Decisions taken, with their reasons

Recorded here because each reversed something an earlier draft asserted, and a
reader who only sees the current text would otherwise re-open them.

1. **The join gets its own contract**, implemented by a composing service rather
   than by widening `ChannelUniverse`. See Decision.
2. **`coupling.class-rank` keeps its `occurrence` declaration and stops being
   scaled.** The declaration is correct and argued at its emission point. Two
   earlier attempts to work around it — sourcing direction from the declaration
   anyway, then auditing a hand-kept copy — were each refuted by evidence
   recorded in P5. Removing the unjustified scaling is what lets the copy be
   deleted outright.
3. **`design.lcom` is renamed, not merely re-categorised.** The category change
   follows the rename instead of being an exception to it. See P0.
4. **`helpUri` is declared per rule, not derived from `RuleCategory`.**
   `computed.health` is documented outside `/rules/` entirely, so no category
   value can address it. See the `helpUri` section.
