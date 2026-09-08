# 00 — Overview: a rule option key is recognised at every depth, or refused

## The subject, narrowed

The enumeration in `measurement/` covers 133 positions and thirteen mechanisms.
**This plan treats two of them, M1 and M2, and nothing else.** They are the two
that share one seam — `RuleOptionsFactory` — and that seam is the only place in
the product where a key can be recognised, half-recognised, and unrecognised at
the same time.

- **M1** (`measurement/merged-enumeration.md`, mechanism table row M1):
  `RuleOptionsFactory::warnAboutUnknownKeys()` iterates `array_keys($merged)`.
  Depth 1 is compared; depth 2 — everything inside a level slot — is compared
  against nothing.
- **M2** (same table, row M2): the key set *declared* to the product
  (constructor parameters plus `ShorthandOptionKeysInterface` plus
  `AdditionalOptionKeysInterface`) is not the key set `fromArray()` reads.
  `measurement/option-declared-vs-read.tsv` measures the difference over all 35
  options classes: `read_not_declared` is non-empty in seven classes,
  `declared_not_read` is empty everywhere.

The cure is one sentence: **an unrecognised rule option key is refused with
exit 3 at every depth it can be written at, and the set it is compared against
is declared by the class that reads it.**

"Exit 3" is a statement about `check`. `RuleOptionsFactory::create()` is also
reached from `baseline:explain` (`BaselineConfiguredThresholds.php:136`), and
enumeration row 129 measured that a `ConfigLoadException` from the same
document exits **1 on stdout** under `baseline:generate`. That divergence is
mechanism M6 and is out of scope: the new refusal inherits whatever routing its
command already has, and only the `check` route is pinned by a test.

## The three facts this plan is built on, and how each was checked

Every command below is reproducible verbatim. The probe is a two-class PSR-4
tree (`RecNs\Probe::complex` at CCN 15, `RecNs\Probe::simple`,
`RecNs\Sub\Other::f`); each run is
`php bin/qmx -d <probe> check src --workers=0 --no-cache --no-progress
--format=json --fail-on=none --config=<cfg>` with `1>` and `2>` to separate
files, and findings compared as a sorted `rule` multiset.

**Fact 1 — identical warning text, opposite behaviour.**
`rules: {coupling.cbo: {warning: 0, error: 1}}` printed
`Unknown option "warning" … Available options: class, namespace, threshold, scope`
and produced **two extra `coupling.cbo` findings** against the same run with
`{}`. `rules: {complexity.ccn: {warning: 1, error: 2}}` printed the same shape
of sentence and produced a finding multiset **identical** to `{}`.
Structural cause, read in the two files: `CboOptions::fromArray()` has
`warning`/`error` in the **condition** of its flat branch
(`src/Analysis/Evidence/Coupling/CboOptions.php:61-65`), while
`ComplexityOptions::fromArray()` opens its branch on
`warningThreshold`/`errorThreshold`/`threshold` only
(`src/Analysis/Evidence/Complexity/ComplexityOptions.php:43`) and reads
`warning`/`error` inside it, through `ThresholdParser::parse()` at line 44.
This is why "declare all 23 undeclared-but-read keys" is not the cure: for
half of them it would silence a warning about a key that genuinely does
nothing.

**Fact 2 — the slots of one rule take different key sets.**
Four runs against `complexity.ccn` on the same probe:
`{class: {warning: 1, error: 2}}` → no `complexity.ccn` finding, empty stderr;
`{class: {max_warning: 1, max_error: 2}}` → **two** findings, empty stderr;
`{callable: {max_warning: 1, max_error: 2}}` → none, empty stderr;
`{callable: {warnign: 1, error: 2}}` → **one** finding (the `error` half
applied, the `warning` half lost), empty stderr.
`measurement/option-level-slots.tsv` carries the same fact as data, with
`file:line` for each slot's key set: `callable={enabled,error,threshold,warning}`
against `class={enabled,maxError,maxWarning,threshold}`. There is no single
list of "keys allowed at a level"; there is one per (class, slot).

**Fact 3 — two adjacent sentences, "I do not know this key" and "I know it,
here is why you may not write it".**
`rules: {architecture.unassigned-class: {enabled: true}}` prints
`[WARNING] Unknown option "enabled" … Available options: mode` and then the
bespoke refusal from
`UnassignedClassOptions::assertNoContradictoryEnabled()`, exit 3.
The order is structural: `warnAboutUnknownKeys()` is step 5 of
`RuleOptionsFactory::create()` and `fromArray()` is step 7.

**Fact 3 is wider than the enumeration recorded.** The same shape was found on
a second class while checking it: `rules: {architecture.layer-violation:
{unreachable_layer_severity: info}}` prints
`[WARNING] Unknown option "unreachable-layer-severity" …` and then
`LayerViolationOptions::assertNoRemovedSeverityKeys()`'s own refusal, exit 3.
`measurement/option-enumeration-blind-spots.tsv` row
`LayerViolationOptions dynamic-key 2 (line 120)` is exactly this: the AST
reader could not see keys read through a `foreach` over a constant map, so the
three removed severity keys are absent from
`option-declared-vs-read.tsv`'s `read_not_declared` column. The decision table
in stage 02 therefore carries **26 pairs, not 23** — the 23 measured plus these
three.

That run also exhibits the authored-spelling problem in one screen: the
generic warning says `unreachable-layer-severity`, the class's own refusal says
`unreachable_layer_severity`, for one key the author wrote once.

## What reddens in this tree — measured, and nothing does

The cure changes what an unknown key does, so every configuration document this
repository tracks was checked against the strictest form of the cure
(*declared* keys only, no `read_not_declared` grace) before the plan was
written. Two checkers, kept in the session scratchpad, both driven from the
measurement TSVs and `ConfigKeySpelling::normalize()`:

1. YAML documents — depth-1 keys against each rule's declared set plus the
   three framework keys, and depth-2 keys against that (class, slot) set.
   Run over `qmx.yaml`, `qmx.yaml.example`, the three built-in presets
   (`src/Analysis/Configuration/Preset/{ci,legacy,strict}.yaml`) and all
   17 `finding-gate/cases/*/qmx.yaml`.
2. `--rule-opt` tokens — every `--rule-opt=` argument in
   `finding-gate/cases/*/case.json`, split on `:` and `.`, checked at whichever
   depth the token addresses.

**Result: zero depth-1 and zero depth-2 unknown keys, in either checker, under
the strict variant.** Cross-checked against the product: a run of
`bin/qmx check src/Core/Path --workers=0 --no-cache --no-progress` on this tree
emits no `Unknown option` line at all today.

The checker's own gap, stated because a negative result without its method is
not evidence: it maps rule → options class through the `rules` column of the
TSVs, and that column carries the literal `computed` for
`ComputedMetricRuleOptions`. So `health.*` and `computed.*` entries under
`rules:` are unmapped; `qmx.yaml` has one (`health.cohesion`) and it was read by
hand — it carries `suppress_paths` and `suppress_namespace_channels` only, both
framework keys. The gate corpora carry `health.*: {enabled: false}`, and
`enabled` is `ComputedMetricRuleOptions`' single declared key.

Second gap: `qmx.yaml.example` lines 57, 252–258 and 333 are commented-out
examples, which YAML parsing skips. The documentation package (stage 04) owns
uncommenting each and running it.

## The decision: extend an existing refusal, do not open a second one

The named refusal for an unrecognised key inside a rule's options already
exists. ADR 0047 ("Suppression Is Not Exclusion", section *A retired key must
fail loudly, not warn quietly*) established it for the five retired
`exclude_*` spellings, and `RetiredSuppressionOptions` implements it —
`RuleOptionsFactory.php:95` calls `refuseRuleOption()` before anything else,
throwing `ConfigLoadException`, which `CheckCommand.php:172` catches and turns
into exit 3 on stderr.

**Decision: a new ADR, which generalises 0047's clause rather than replacing
it.** The reasons, in order:

- 0047's own subject is a rename of two suppression mechanisms. Its refusal
  clause is scoped to *the five spellings that step retired*, by name. Widening
  it in place to "every unrecognised key at every depth" would make an accepted
  ADR say something its context, decision and consequences were never argued
  for — the failure mode `MEMORY.md`'s *plan patching hazard* names.
- The new decision has two halves 0047 does not touch: the **key set becomes a
  declaration** (following ADR 0038's pattern — a class that holds something
  says what it is, and the reader asks instead of guessing), and the comparison
  **acquires a second depth**, whose allowed set is per (class, slot).
- The new ADR states that `RetiredSuppressionOptions` keeps its own named
  refusal and keeps running **first**: its message carries migration text
  ("write `suppress_paths`") that a generic "unknown key here, allowed keys
  are …" cannot. Specialisation before generalisation, one mechanism.

The new ADR does not reopen level names — settled by ADR 0024 — and does not
reopen ADR 0044's rule that identifier-keyed options keep the author's
spelling.

Backward compatibility is not a constraint (CLAUDE.md, *Backward Compatibility
Policy*), and the owner confirmed it for this subject. The price is paid in
form: a `Breaking` entry in `CHANGELOG.md` naming old and new surface, and
migration steps written from the consumer's side in the ADR.

## Stage map

| stage                               | what it settles                                                         | depends on |
| ----------------------------------- | ----------------------------------------------------------------------- | ---------- |
| `01-key-set-contract.md`            | the contract by which a class states its own accepted keys, per slot    | —          |
| `02-declarations-per-capability.md` | the 26-pair decision table and the declaration on all 35 + 10 classes   | 01         |
| `03-refusal-at-every-depth.md`      | the walk, the refusal's code/stream/text, the CLI and spelling seams    | 02         |
| `04-guard-tests-and-publication.md` | the read ⊆ declared guard, regression cases, docs, ADR, CHANGELOG, gate | 03         |

Order is strict. Stage 03 landing before 02 would refuse keys this tree's own
options classes still read; stage 02 landing before 01 has no contract to
declare into. What each stage leaves uncompensated for the next is stated in
its own file, under *What this stage leaves broken*.

## What this plan deliberately does not do

Named so that nobody reads a silence as an oversight. Each is a mechanism from
`measurement/merged-enumeration.md`, treated whole or not at all:

- **M3** spelling normalisation asymmetry (rows 4, 5, 16, 33, 34, 56, 61, 91).
  Row 56 is closed here as a side effect — an upper-case key inside a slot is
  not folded, so it is genuinely unknown and gets refused — but the mechanism
  is not treated. In particular `Paths:`/`Callable:` in Title-case still fold
  and still apply silently.
- **M4** section sub-key checking, **M5** `computed_metrics` entry keys,
  **M7** value filters that match nothing, **M9** inline directives,
  **M10** baseline record fields, **M12** config-file discovery,
  **M13** messages addressing something the author did not write.
- **M6** exception routing. This matters most because the plan edits the very
  method that owns one of its five routes: `validateNumericFields()` throws
  `RuntimeException`, which falls to `CheckCommand.php:201` and prints
  `Unexpected error:` with exit 1 (enumeration rows 62, 63). Changing that one
  class here would be a partial repair of a mechanism whose whole point is that
  one error class exits under four codes in two streams. **The new refusal
  therefore uses `ConfigLoadException` and rows 62/63 stay as they are.**
- **M8** `--rule-opt` parser losses (rows 71, 75) and **M11** a recognised key
  doing more or less than its name (rows 59-value, 60, 64, 65, 66). Row 66 —
  a top-level `threshold` silently discarding a written `class:` block — is
  adjacent to this work and stays out: it is about what a *recognised* key
  does.
- The three defects the enumeration separates from missing diagnostics, as
  currently recorded in `README.md` of this directory:
  `computed_metrics` with `levels: {class: {…}}` aborting with an internal
  `TypeError` and no report; a non-numeric threshold routed as a tool crash
  (the M6 rows above); and the `suppress_namespace_channels` claim, **which did
  not survive its own remeasure** — the option does suppress, and what remains
  is the narrower ADR 0044 open follow-up: a key naming a channel that never
  publishes at `namespace` level is accepted in silence. None is in scope.
- The level-name vocabulary (ADR 0024) and the three existing Levenshtein
  "did you mean" implementations (`YamlConfigLoader.php:470`,
  `RuleNameValidator.php:117`, `DirectiveNameHints.php:262`). This plan adds no
  fourth: at a rule-option position the allowed set is at most eight keys
  (`GodClassOptions`) and at most six inside a slot
  (`NamespaceInstabilityOptions`), and it is printed in full.
