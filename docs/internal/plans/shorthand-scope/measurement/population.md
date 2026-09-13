# M3 — population «top-level key × nested block form» for the five hierarchical rules

**620 rows in the population; 620 measured by a live `bin/qmx check` run; 620 additionally
resolved by a direct door-side call; 0 unmeasured.**

Of the 620: 560 are the declared population, 60 are refusal probes for a top-level key the
rule does not declare (counted outside the declared population). The live door alone settles
"was the level block consulted?" in 99 cells; in 237 cells the live count is identical to the
block-absent baseline and the call witness settles it; in 168 cells the block writes nothing
so the question does not apply; 116 cells are refused at the door (exit 3).

**`git status --short` at hand-off is NOT empty, and the modification is not mine.** It reads:

```
 M docs/internal/plans/shorthand-scope/00-overview.md
```

Evidence that this work did not produce it: the same command was **empty** when checked
immediately after the 620-run live sweep — i.e. after every command this task runs that could
write anything. The file was modified at 2026-09-13 04:32:47, during the later analysis phase,
in which no repository-writing command was issued at all. Every artefact of this task was
written under the scratchpad directory; `bin/qmx check` was always invoked with
`--working-dir <scratchpad>/fixture` and a scratchpad `--cache-dir`, and a grep for
`m3-enumeration` across the repository's tracked `.md`/`.php`/`.yaml` returns nothing. The diff
is a 134-insertion/116-deletion rewrite of the plan overview for this very subject, which is
consistent with the concurrent package the brief said was occupying the bench. **This needs the
orchestrator's confirmation, not mine** — I did not touch it, and I did not revert it.

Nothing under `src/`, `tests/`, `website/`, `finding-gate/` is modified.

---

## 1. The rules — five, and how that was established

Not taken on faith. `declare.php` autoloads the product, walks every class under `src/`, and
keeps those for which `ReflectionClass::implementsInterface(HierarchicalRuleOptionsInterface::class)`
is true and which are neither abstract nor an interface:

```
php -d error_reporting="E_ALL & ~E_DEPRECATED" <dir>/declare.php
```

Result — exactly **five** (`declare.out`):

| rule slug              | options class                           | levels               |
| ---------------------- | --------------------------------------- | -------------------- |
| `complexity.ccn`       | `Complexity\ComplexityOptions`          | `callable`, `class`  |
| `complexity.cognitive` | `Complexity\CognitiveComplexityOptions` | `callable`, `class`  |
| `complexity.npath`     | `Complexity\NpathComplexityOptions`     | `callable`, `class`  |
| `coupling.cbo`         | `Coupling\CboOptions`                   | `class`, `namespace` |
| `coupling.instability` | `Coupling\InstabilityOptions`           | `class`, `namespace` |

Levels come from `levelOptionsClasses()` (the declaration), cross-checked against
`getSupportedLevels()` — they agree for all five. Top-level and per-level keys come from
`acceptedOptionKeys()`; per-level defaults from `ReflectionParameter::getDefaultValue()` on each
level-options constructor. No regular expression derived any of this.

**Declared top-level keys, measured not assumed** — this is where "all five are alike" first breaks:

| rule                                       | top-level keys                                      |
| ------------------------------------------ | --------------------------------------------------- |
| `complexity.ccn` / `.cognitive` / `.npath` | `enabled`, `threshold`                              |
| `coupling.cbo`                             | `enabled`, `error`, `scope`, `threshold`, `warning` |
| `coupling.instability`                     | `enabled`, `max-error`, `max-warning`, `threshold`  |

**Per-level defaults differ between rules at the same level name — including `enabled`:**

| rule                   | level       | declared level keys                                                       | defaults                                                                    |
| ---------------------- | ----------- | ------------------------------------------------------------------------- | --------------------------------------------------------------------------- |
| `complexity.ccn`       | `callable`  | enabled, error, threshold, warning                                        | enabled=**true**, warning=10, error=20                                      |
| `complexity.ccn`       | `class`     | enabled, max-error, max-warning, threshold                                | enabled=**true**, maxWarning=30, maxError=50                                |
| `complexity.cognitive` | `callable`  | enabled, error, threshold, warning                                        | enabled=**true**, warning=15, error=30                                      |
| `complexity.cognitive` | `class`     | enabled, max-error, max-warning, threshold                                | enabled=**true**, maxWarning=30, maxError=50                                |
| `complexity.npath`     | `callable`  | enabled, error, threshold, warning                                        | enabled=**true**, warning=200, error=1000                                   |
| `complexity.npath`     | `class`     | enabled, max-error, max-warning, threshold                                | enabled=**false**, maxWarning=500, maxError=1000                            |
| `coupling.cbo`         | `class`     | enabled, error, scope, threshold, warning                                 | enabled=true, warning=14, error=20, scope='all'                             |
| `coupling.cbo`         | `namespace` | enabled, error, min-class-count, threshold, warning                       | enabled=true, warning=14, error=20, minClassCount=3                         |
| `coupling.instability` | `class`     | enabled, max-error, max-warning, min-afferent, threshold                  | enabled=true, maxWarning=0.8, maxError=0.95, minAfferent=1                  |
| `coupling.instability` | `namespace` | enabled, max-error, max-warning, min-afferent, min-class-count, threshold | enabled=true, maxWarning=0.8, maxError=0.95, minClassCount=3, minAfferent=1 |

`complexity.npath / class` is the only level in the set **off by default**, and
`ClassNpathComplexityOptions::fromArray()` matches it with `(bool) ($config['enabled'] ?? false)`
while every sibling uses `?? true`. This asymmetry produces the sharpest surprise in §5.

---

## 2. The axes, and the one narrowing

Row = (rule × top-level form × level × level-block form).

**Top-level forms (7).** `T0_none`, `T1_threshold_value`, `T2_threshold_tilde` (`threshold: ~`),
`T3_top_graduated_pair`, `T4_enabled_false`, `T5_enabled_true`, `T6_scope`.
`T6_scope` exists only for `coupling.cbo` (the only rule declaring `scope`) and is not generated
for the other four. `T3` uses each rule's own graduated spelling: `warning`/`error` for cbo and the
complexity trio, `max_warning`/`max_error` for instability. For the complexity trio `T3` is **not a
declared key**, so those 60 rows are `in_declared_population = no` — refusal probes outside the 560.

`T2_threshold_tilde` is not in the brief's list. It was added because three `fromArray()` docblocks
make an explicit claim about it ("`threshold: ~` … takes no branch, so a `class:` block beside it is
still read"), and an unmeasured claim in a docblock is exactly the kind of cell a reviewer catches.

**Levels (2 per rule)** — from `levelOptionsClasses()`.

**Level-block forms (10).** `B0_absent`, `B1_tilde` (`level: ~`), `B2_empty_map` (`level: {}`),
`B3_enabled_tilde`, `B4_enabled_true`, `B5_enabled_false`, `B6_half_band` (only the group's
warning-side key), `B7_full_band` (both), `B8_threshold_in_block` (`threshold:` inside the block —
the same slot written differently), `B9_unknown_key`.

**Count.** 3 × (2×10×5) + 1 × (2×10×7) + 1 × (2×10×6) = 560 declared, + 60 probes = **620 configs**.

### The narrowing, named

620 exceeds the brief's ~300 trigger. **One axis was narrowed: the magnitude relation.** Instead of
three relations, a single one is used throughout — the **top-level value is chosen never to fire**
and the **in-block value always to fire**. A level that reports therefore proves its block was
consulted. This is precisely the narrowing the brief names as its example.

**No second narrowing was applied**, and the reason is a number: one live run costs 0.28 s, so the
whole 620-row grid costs ~3 min wall clock. Cutting further would have bought nothing.

The narrowing has a cost, and it bit: "top value never fires" makes the live door blind to whether a
dropped `enabled: false` re-enables a level, because nothing fires either way. That blind spot is
closed by a separate discriminating probe (§5, D1/D2), not by the grid.

---

## 3. How today's outcome was measured — commands to repeat verbatim

### Witness 1 — the live door (all 620 rows)

Fixture: `fixture/` here — `Fx\Alpha` (4 classes, one with a CCN-19 / cognitive-27 / NPath-2880
method), `Fx\Beta` (4 classes), `Fx\Gamma` (3 classes), with Alpha → Beta → Gamma. Gamma exists
specifically so one namespace (`Beta`) has both Ca>0 and Ce>0; without it
`coupling.instability / namespace` has no positive control at the default `min_afferent: 1`, and
every instability×namespace cell would read "0 findings" whether or not the block was read.
`fixture/composer.json` declares the PSR-4 root so the run is not degraded by the
"analysed paths do not cover all autoload entries" warning.

```
python3 <dir>/gen_rows.py      # writes configs/*.yaml + rows.json
python3 <dir>/run_live.py      # 620 runs -> live.json  (~3 min)
```

Each run is exactly:

```
bin/qmx check src --working-dir <dir>/fixture --config <dir>/configs/row_NNNN.yaml \
    --format json --workers 0 --cache-dir <unique> --only-rule <rule-slug>
```

The cache directory is removed **before and after every single run**. Findings are read from the
`violations` key (not `findings`). Level is attributed from each violation's `subject` prefix —
`ns:` → namespace, `declaration:callable:` → callable, otherwise class — not from message text.
`--only-rule` is used so the exit code is about the rule under test; it was verified not to mask a
configuration refusal (same exit 3, same message, with and without it).

### Witness 2 — door-side resolution without analysis (all 620 rows)

```
php -d error_reporting="E_ALL & ~E_DEPRECATED" <dir>/call_witness.php
```

This is **not** a bare `fromArray()`. It uses the product's own `YamlConfigLoader` (so the `rules:`
section's real key folding applies — that section is `PRESERVE_IMMEDIATE_CHILDREN`, so the rule slug
survives verbatim while `max_warning` inside a level block becomes `maxWarning`), then
`RuleOptionsRegistry` + `RuleOptionsFactory::create()` (so top-level key normalization and
unknown-key refusal are the real ones). It then reads back every constructor-backed property of both
level-options objects by reflection. This fills `call_level_values` / `call_level_enabled`.

### Merge

```
python3 <dir>/build_population.py   # -> population.tsv
```

### Controls, run before the grid was trusted

For all 10 rule×level pairs: `T0_none + B7_full_band` must report (positive) and
`T1_threshold_value + B0_absent` must be silent (negative). **All 10 negative controls pass. 9 of 10
positive controls pass; `complexity.npath / class` fails** — and that failure is a product property,
not a fixture property: the same fixture yields 11 class-level NPath findings once `enabled: true` is
added to the block (`ctrl_npath.yaml`). See D3.

---

## 4. Чем получено и чего этот способ не видит

**The two witnesses never contradict each other**: 0 cells where the call witness says the block was
dropped and the live count nevertheless differs from the block-absent baseline. Where they differ in
*informativeness* (237 cells), it is always the live door being blind, never disagreement.

Channels this method does **not** cover:

1. **Every configuration source except a YAML file.** `--rule-opt`, `--preset`, preset files, and
   dot-notation CLI keys (`callable.warning=…`, handled by `RuleOptionsFactory::expandDotNotation()`
   on a different path from the YAML one) are untouched. `rule-opt` has a known moving refusal surface.
2. **Layered documents.** Every row is a single config file. Nothing here says what happens when a
   preset writes the top-level shorthand and the user's file writes the level block, or vice versa —
   which is the shape the whole promise/effect line of work is about.
3. **Inline directives.** `@qmx-threshold` / `@qmx-ignore` can override the same numbers and never
   enter this grid.
4. **Combinations of top-level keys.** Each row writes exactly one top-level form. `scope` *together
   with* `threshold` on cbo, `enabled: true` together with `threshold`, and `threshold` together with
   `max_warning` at the top are all unmeasured. The last is expected to be a `ThresholdParser`
   mixed-mode refusal, but that is a reading, not a measurement.
5. **Mixed spellings inside one block.** `{threshold: 1, max_warning: 1}` in a level block should be
   the mixed-mode refusal; not exercised.
6. **Both level blocks written at once.** Each row writes one level's block and leaves the sibling
   absent. The sibling's resolved state *is* recorded (`live_sibling_findings`,
   `call_sibling_enabled`), but a document writing both blocks is not a row.
7. **Key-spelling variants at the level depth.** Only `snake_case` is written. `camelCase` and
   `kebab-case` at that depth are accepted by the aliasing in `ThresholdParser` and in
   `ClassInstabilityOptions`/`Namespace*Options`, but were not run.
8. **Non-`check` doors.** `baseline:explain`, `baseline:generate`, `qmx rules` resolve the same
   options and are not in this grid; a defect visible only there would not appear here.
9. **Magnitude-dependent behaviour**, by construction of the narrowing (§2) — mitigated but not
   eliminated by the D1/D2 probe.
10. **The fixture's own shape.** Counts like "11 findings" are properties of this fixture. Only the
    0-vs-nonzero distinction is load-bearing anywhere in the analysis.

---

## 5. Клетки, где сегодняшний исход удивителен

Cells where the product does something that does not follow from its neighbours on the same axis.
Each is stated with the measurement that shows it.

### D1 — a level's `enabled: false` is silently overridden to ON by a top-level shorthand

A top-level shorthand rebuilds both level configs from scratch with
`enabled: (bool) ($config['enabled'] ?? true)` — the rule's *own* top-level `enabled`, never the
level block's. The level block's `enabled: false` is discarded.

The grid marks these cells `dropped`, but with the "top never fires" magnitude it cannot show the
consequence. `probes.txt` closes that with a firing top value:

| document                                                               | level findings  |
| ---------------------------------------------------------------------- | --------------- |
| `complexity.ccn: {threshold: 1}`                                       | callable **13** |
| `complexity.ccn: {threshold: 1, callable: {enabled: false}}`           | callable **13** |
| `coupling.cbo: {threshold: 1, class: {enabled: false}}`                | class **10**    |
| `coupling.cbo: {warning: 1, error: 2, class: {enabled: false}}`        | class **10**    |
| `coupling.cbo: {threshold: 1, namespace: {enabled: false}}`            | namespace **3** |
| `coupling.instability: {threshold: 0.01, class: {enabled: false}}`     | class **5**     |
| `coupling.instability: {threshold: 0.01, namespace: {enabled: false}}` | namespace **1** |

The user writes "this level is off" and the level reports. Exit code 2 in every case. This affects
four of the five rules and both of their levels, and — for `coupling.cbo` — is reachable through the
*graduated* top pair as well as through `threshold`.

### D2 — the mirror: a level's `enabled: true` cannot switch a level back on

`complexity.ccn: {threshold: 1, class: {enabled: true, max_warning: 1, max_error: 2}}` → **0** class
findings. The identical block without the top-level `threshold` gives **11**. The complexity trio's
shorthand branch hard-codes `class: new Class…Options(enabled: false)`, so the most explicit thing a
user can write at the class level is inert. D1 and D2 together mean the level `enabled` key does
nothing in either direction once a top-level shorthand is present — neither honoured nor refused.

### D3 — `complexity.npath / class`: writing a *stricter* threshold produces *fewer* findings

Same fixture, same rule, top level empty:

| document                                                                   | class findings |
| -------------------------------------------------------------------------- | -------------- |
| `complexity.npath: {class: {enabled: true}}` (defaults 500/1000)           | **1**          |
| `complexity.npath: {class: {max_warning: 1, max_error: 2}}`                | **0**          |
| `complexity.npath: {class: {enabled: true, max_warning: 1, max_error: 2}}` | **11**         |

Tightening the threshold by three orders of magnitude silences the level, because
`ClassNpathComplexityOptions::fromArray()` defaults `enabled` to `false` and writing a threshold does
not imply enabling. The call witness confirms the values *did* land (`maxWarning=1, maxError=2`) with
`enabled=false` — the block was read and then made inert.

The cross-rule discontinuity is the sharp part: the byte-identical document
`{class: {max_warning: 1, max_error: 2}}` gives **11** findings on `complexity.ccn`, **1** on
`complexity.cognitive`, and **0** on `complexity.npath`. Nothing in the document distinguishes them.

### D4 — the same document shape is refused for three rules and silently reshapes the config for two

A bare graduated pair at the rule's own top level:

- `complexity.ccn` / `.cognitive` / `.npath` → **exit 3**,
  `Option "warning" is not an option of rule "complexity.ccn". Options here: callable, class, enabled, …`
- `coupling.cbo` → accepted, applied uniformly to **both** levels, and the level block beside it is
  dropped (identical `dropped` pattern to `T1_threshold_value`).
- `coupling.instability` → same as cbo, with `max_warning`/`max_error`.

The `fromArray()` docblocks acknowledge the asymmetry; the grid measures it as 60 refusals against 16
silent drops. A user moving a `warning:`/`error:` pair between two rules gets a hard error in one
direction and silence in the other.

### D5 — `threshold: ~` is a no-op, exactly as documented, for all five rules

`T2_threshold_tilde` is cell-for-cell identical to `T0_none` across all 5 rules × 2 levels × 10 block
forms — same effect, same finding count, same exit code. Listed not as a defect but because it is the
one place the docblock's claim was checked rather than trusted, and it holds. It is also the contrast
that makes D1/D2 legible: what opens the destructive branch is a written *value*, not a written key.

### D6 — an unknown key inside a level block is refused even when the rule is switched off

`B9_unknown_key` yields exit 3 in **all 62** cells, including under `T4_enabled_false`. Defensible as
fail-closed, but worth naming: `rules: {complexity.ccn: {enabled: false, class: {no_such_option: 1}}}`
fails the run for a rule that is not going to execute. Note also that the refusal names the level
(`… at level "class". Options at that level: enabled, max-error, max-warning, threshold`) in the
*declared* spelling — kebab-case — while the document must be written snake_case or camelCase. The
message's spelling is not a spelling the user can paste back.

### D7 — exit code tracks severity, not whether the block was honoured

`B6_half_band` and `B7_full_band` report the *same* finding counts at `T0_none` (e.g. 13 and 13 for
`complexity.ccn / callable`) but exit **0** and **2** respectively, because the half band leaves the
error side at its default so nothing reaches error severity. Not a defect; recorded because any
future gate reading the exit code as "the config took effect" would be reading the wrong bit.

---

## 6. Files in this directory

| file                               | what it is                                                                       |
| ---------------------------------- | -------------------------------------------------------------------------------- |
| `population.tsv`                   | **the deliverable** — 620 rows × 23 columns, one row per cell                    |
| `report.md`                        | this file                                                                        |
| `matrix.txt`                       | the same data as a compact per-(rule,level) effect matrix; fastest way to see §5 |
| `declare.php` / `declare.out`      | reflection-based extraction of rules, levels, keys, defaults                     |
| `gen_rows.py`                      | the generator: builds `configs/*.yaml` + `rows.json`                             |
| `configs/`                         | 620 generated YAML documents, one per row                                        |
| `run_live.py` / `live.json`        | witness 1 — the live door, raw per-row output                                    |
| `call_witness.php` / `call.json`   | witness 2 — door-side resolution, raw per-row output                             |
| `build_population.py`              | merges the two witnesses into `population.tsv`                                   |
| `probe_enabled.py` / `probes.txt`  | the D1/D2 discriminating probe (firing top value)                                |
| `ctrl_npath.yaml`, `ctrl_ccn.yaml` | the D3 cross-rule control pair                                                   |
| `calib.yaml`, `calib.json`         | initial fixture calibration (all 10 rule×level pairs nonzero)                    |
| `fixture/`                         | the analysed tree (`Fx\Alpha` → `Fx\Beta` → `Fx\Gamma`)                          |

### `population.tsv` columns

`idx`, `rule`, `top_form`, `top_yaml`, `level`, `block_form`, `block_yaml`,
`level_keys_declared`, `level_defaults`, `live_exit`, `live_level_findings`,
`live_level_severities`, `live_sibling_findings`, `live_refusal`, `call_level_values`,
`call_level_enabled`, `call_sibling_enabled`, `call_refusal`, `block_written`,
`block_effect` (`applied` / `partial` / `dropped` / `no-write` / `refused`),
`live_vs_block_absent`, `witness`, `in_declared_population`.
