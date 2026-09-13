# M5 — the two forms `population.tsv` does not carry

**137 new cells added; 137 measured by a live `bin/qmx check` run (and by the door-side call
witness); 51 of them are outcomes that do not follow from their neighbours on the same axis —
three phenomena, of which one (U1) accounts for 41 rows.**

A further 60 cells are refusals (`exit 3`) that *do* follow from a neighbour — the complexity trio
refusing a top half band exactly as it already refuses the top full pair (`T3`). They are reported,
not counted as surprises.

**`git status --short`** was **empty** at start and stayed empty through every measurement (checked
again immediately after the 137-row live sweep, after the baseline sweep, and after `report.md` was
written). At the final check it reads:

```
 M docs/internal/plans/shorthand-scope/01-contract.md
```

**The modification is not mine, and it is checkable rather than merely asserted.** Every artefact of
this task is under this directory; `bin/qmx` was always invoked with `--working-dir <this
dir>/fixture…` and a scratchpad `--cache-dir`. The file changed at 05:34:01, after the last of this
task's repository-reading commands, and its new text already **names `population-gap.tsv`** — this
task's deliverable, which did not exist when the task began — at lines 173 and 240. It is the
concurrent package writing the contract against this measurement. I did not touch it and did not
revert it; **the orchestrator should confirm it owns that edit.** (M3 reported the same shape for
`00-overview.md`.)

**Fixture integrity, checked after every run:** `diff -r fixture/ <m3>/fixture/` is still clean, so
the comparability claim of §3 holds at hand-off; no stray `.qmx-cache` is left in any of the four
fixture trees.

---

## 1. What was missing, derived — not remembered

`declare_gap.php` (output: `declare_gap.out`, `declare_gap.json`) walks `src/`, keeps the classes
implementing `HierarchicalRuleOptionsInterface` (five, the same five as M3), resolves each rule slug
from the *rule* class's `NAME` constant reached through `RuleDefinitionInterface::getOptionsClass()`,
and then, per level:

```
non-band keys(rule, level) = acceptedOptionKeys(level class)
                             MINUS  warning u error u threshold spellings of
                                    RuleThresholdKeyGroupRegistry::groupsFor(rule, level)
                             MINUS  `enabled`
```

`threshold` counts as a band key because the registry group names it as one, and writing it inside a
level block is already block form `B8`. `enabled` is subtracted because a block writing only
`enabled` is already block forms `B3`/`B4`/`B5`. Spellings are compared case- and
separator-insensitively. `GROUPS` is private, so it is read through reflection rather than
transcribed.

### (1) Non-band level keys

| rule                   | level       | accepted at that level                                                    | band spellings               | non-band, minus `enabled`         |
| ---------------------- | ----------- | ------------------------------------------------------------------------- | ---------------------------- | --------------------------------- |
| `complexity.ccn`       | `callable`  | enabled, error, threshold, warning                                        | warning, error, threshold    | **EMPTY**                         |
| `complexity.ccn`       | `class`     | enabled, max-error, max-warning, threshold                                | max_warning, max_error, thr. | **EMPTY**                         |
| `complexity.cognitive` | `callable`  | enabled, error, threshold, warning                                        | warning, error, threshold    | **EMPTY**                         |
| `complexity.cognitive` | `class`     | enabled, max-error, max-warning, threshold                                | max_warning, max_error, thr. | **EMPTY**                         |
| `complexity.npath`     | `callable`  | enabled, error, threshold, warning                                        | warning, error, threshold    | **EMPTY**                         |
| `complexity.npath`     | `class`     | enabled, max-error, max-warning, threshold                                | max_warning, max_error, thr. | **EMPTY**                         |
| `coupling.cbo`         | `class`     | enabled, error, scope, threshold, warning                                 | warning, error, threshold    | `scope`                           |
| `coupling.cbo`         | `namespace` | enabled, error, min-class-count, threshold, warning                       | warning, error, threshold    | `min-class-count`                 |
| `coupling.instability` | `class`     | enabled, max-error, max-warning, min-afferent, threshold                  | max_warning, max_error, thr. | `min-afferent`                    |
| `coupling.instability` | `namespace` | enabled, max-error, max-warning, min-afferent, min-class-count, threshold | max_warning, max_error, thr. | `min-afferent`, `min-class-count` |

**This is itself a finding, and it narrows claude-09.** The form "a block that names a level but does
not write its band" is constructible for the complexity trio **only** as `{enabled: ...}` — which the
existing population already measures three ways. The form the review calls decisive for C2 exists, as
a *new* document, only for the two coupling rules: five (rule, level, key) combinations, not ten.
The complexity trio cannot be interrogated about "block present, band unwritten, something else
written" at all, because there is nothing else to write.

### (2) The top half band

The registry's `''` (top) entry for the complexity trio is `LONE_THRESHOLD_SHAPE`: its `warning` and
`error` lists are **empty**, and `warning` is not in `ComplexityOptions::acceptedOptionKeys()`
(`enabled, threshold, callable, class`) either. So for all three complexity rules the top half band is
**undeclared** — measured as a refusal probe, `in_declared_population = no`, exactly as `T3` was.
For `coupling.cbo` the registry's warning side is `warning`, declared at the top as `warning`; for
`coupling.instability` it is `max_warning`, declared as `max-warning`. Both **declared**.

---

## 2. The axes, and the narrowing — named

New top form **`T7_top_half_band`**: only the warning side of the rule's own top band, carrying
*exactly* the value `T3_top_graduated_pair` writes on its warning side, so `T7` and `T3` differ by one
key and nothing else. `complexity.ccn`/`.cognitive` `warning: 100000`, `complexity.npath`
`warning: 100000000`, `coupling.cbo` `warning: 500`, `coupling.instability` `max_warning: 1.01`.

New block forms **`B10_nonband_scope`** (`scope: application`), **`B11_nonband_min_class_count`**
(`min_class_count: 1`), **`B12_nonband_min_afferent`** (`min_afferent: 0`) — one per (level, key) from
the table above.

**Rows:** group A — `T7` x 5 rules x 2 levels x the 10 existing block forms = **100**. Group B — every
applicable top form (`T6_scope` only for cbo) x the new block forms = **37**. Total **137**;
77 declared, 60 refusal probes.

**The magnitude narrowing is M3's, unchanged and deliberately inherited:** one relation only — the
top-level value never fires, the in-block band value always fires. `T7`'s value is M3's `T3` warning
value for the same reason. For the three non-band keys the analogue of "always fires" is "maximally
permissive": `min_afferent: 0` (default 1), `min_class_count: 1` (default 3), `scope: application`
(the only declared alternative to the default `all`).

**No second narrowing.** 137 live runs cost 38 s; cutting further would buy nothing.

**Where `T7` bends the inherited premise, and why the grid stayed silent anyway.** "The top value
never fires" holds for the half the author writes. It does **not** hold for the half the author does
not write: `T7` resolves to `(written warning, call-site default error)` = `500 / 20` for cbo and
`1.01 / 0.95` for instability, and the *error* side there is an ordinary, firing threshold. Nothing
fired on `fixture/` only because its maximum CBO is 5 and `min_afferent: 1` filters every instability
subject out — a property of the fixture, not of the premise. Probe P2 measures the consequence on a
fixture where it does fire. Treat every `T7` count in the TSV as "this fixture reached neither side",
not as "the top value cannot fire".

**Not in scope, named rather than left silent:** the *lower* half band (`error` alone, `max_error`
alone) at the top. The brief asks for the upper half; the lower half is a symmetric, unmeasured cell.

---

## 3. Commands — repeatable verbatim

```
DIR=<this directory>

# the declarative half (rules, levels, keys, band groups, non-band keys, top half band)
php -d error_reporting="E_ALL & ~E_DEPRECATED" $DIR/declare_gap.php      # -> declare_gap.out/.json

# the grid
python3 $DIR/gen_rows.py          # 137 configs -> configs/gap_NNNN.yaml + rows.json
python3 $DIR/run_live.py          # witness 1: 137 live runs -> live.json          (~38 s)
python3 $DIR/run_baseline.py      # the block-absent baseline of every (rule, top, level) used here
php -d error_reporting="E_ALL & ~E_DEPRECATED" $DIR/call_witness.php     # witness 2 -> call.json
python3 $DIR/xcheck.py            # the 26 baselines M3 also measured must agree
python3 $DIR/build_population.py  # -> population-gap.tsv
python3 $DIR/classify.py          # -> surprising.json, the counts in the first line

# witness 3 and the probes
python3 $DIR/run_directives.py    # -> directives.json
python3 $DIR/probes.py            # -> probes.txt, probes.json
```

Each live run is exactly M3's:

```
bin/qmx check src --working-dir $DIR/fixture --config $DIR/configs/gap_NNNN.yaml \
    --format json --workers 0 --cache-dir <unique> --only-rule <rule-slug>
```

The cache directory is removed before and after every single run. Findings are read from the
`violations` key (**not** `findings`). Level is attributed from the violation's `subject` prefix
(`ns:` -> namespace, `declaration:callable:` -> callable, else class), never from message text. Exit
code is recorded per row.

`run_live.py` and `call_witness.php` are M3's files with one substitution — the directory — and that
was verified with `diff`; `build_population.py` differs only in `written()`, which now also knows what
the three non-band forms write, and in reading the baseline from this run's own `baseline.json`
instead of from its own `B0_absent` rows.

**The fixture is byte-identical to M3's** (`diff -r` clean at copy time), and `xcheck.py` confirms it
stays comparable: of the 36 block-absent baselines this round measured, **26 overlap M3's and all 26
agree; 0 disagree**; the other 10 are `T7`, new here.

`population-gap.tsv` has the **same 23-column header as `population.tsv`**, verified by `diff` against
its first line.

---

## 4. Чем получено и чего этот способ не видит

Three doors, and they never contradict each other on this grid.

1. **The live door** (`bin/qmx check`) — 137 rows. Sees exit code, finding count and severity per
   level. Blind wherever the block's write cannot move a count on this fixture: 55 of 137 rows read
   `live door (blind here)`.
2. **The call witness** (`YamlConfigLoader` -> `RuleOptionsRegistry` -> `RuleOptionsFactory::create()`,
   then every constructor-backed property read by reflection) — 137 rows. Settles *what landed in the
   options object*. It does **not** run the configuration pipeline stages (defaults / CLI / preset),
   rule selection, or the analysis.
3. **The directives door** (`bin/qmx directives --format=json`) — 26 targeted runs. Its `unmeasured` /
   `producer-disabled` verdict is read off the run's own `LevelActivity`
   (`ThresholdDirectiveAudit::unmeasurableReason()` -> `AbstractRule::levelActivity()` ->
   `isLevelEnabled($level)`), so it observes **the level flag of the real run**, not an absence of
   findings and not a reflected object. Both controls bite, both ways: with the class block explicitly
   `enabled: false` the verdict is `producer-disabled`; with a plain rule it is `effective` — for both
   coupling rules.

**What this method does not see** — items 1-10 are M3's list and are unchanged, because the method is
unchanged (any configuration source but a YAML file; layered documents; inline directives as a
configuration source; combinations of top-level keys; mixed spellings inside one block; both level
blocks written at once; camelCase/kebab-case at level depth; non-`check` doors; magnitude-dependent
behaviour; the fixture's own counts). On top of them, this round adds:

11. **The directives door sees the level flag only where a directive exists, and a directive binds to
    a declaration.** Measured, not assumed: a file-level `@qmx-threshold coupling.cbo warning=1
    error=2` written in a docblock above the `namespace` statement (`fixture-nsdir/`) yields
    `"directives": []` — no row at all, so there is nothing whose verdict could report the namespace
    flag. Every namespace cell therefore stays call-witness-only. The door also needs the directive's
    payload to be valid and its row to be selected by `target` — see §6.
12. **`scope: application` and `min_class_count: 1` are live-blind on this fixture by construction.**
    No framework namespaces are configured, so `CBO_APP == CBO`; and no namespace in `fixture/` has
    fewer than 3 classes, so lowering `min_class_count` admits nothing. Probe P3 (§5) closes the
    second on a fixture that *does* carry a 2-class namespace; the first is closed only at the
    resolved-value level (P1), never by a count.
13. **The lower half band is not measured** (§2).
14. **`T7` writes the warning side only; it is not crossed with a written `enabled` at the top.**
    `{warning: 500, enabled: true}` and friends are as unmeasured here as M3's item 4 says.
15. **Two of the three probes use fixtures that are not `fixture/`** — `probe-fixture/` (adds
    `Fx\Heavy\H`, CBO 25, plus its 25 dependencies) and `probe-fixture-small/` (adds the two-class
    namespace `Fx\Duo`). Their counts are therefore not comparable with `population.tsv`; only the
    0-vs-nonzero and severity distinctions are load-bearing, and each probe names its fixture.

---

## 5. Клетки, где исход удивителен

51 rows, in three classes, plus two contrasts that the grid states and a probe makes concrete.

### U1 — the top half band is the only TOP form that installs a half-written band into both levels while discarding their blocks (41 rows)

`coupling.cbo: {warning: 500}` resolves, at **both** levels, to `warning: 500, error: 20` — the
written value on one side, a default on the other. `coupling.instability: {max_warning: 1.01}` ->
`maxWarning: 1.01, maxError: 0.95`. Its neighbours on the top axis are both coherent: `T1`
(`threshold: 500`) -> `500 / 500`, `T3` (`warning: 500, error: 600`) -> `500 / 600`. Of the eight top
forms, `T7` is the only one whose rebuild installs a band the author half-wrote.

**Half of this is generic, and the control says which half.** A half band written *inside the block*
does the same thing to that level's band — `population.tsv`'s `T0 × B6_half_band` on cbo/class already
reads `warning: 1, error: 20`, and M3's D7 recorded it. Probe **P2c** confirms the consequence is
identical, so the "band with one default half" is half-band semantics, not a `T7` property:

| document                                      | class band  | namespace band      | live                   | exit  |
| --------------------------------------------- | ----------- | ------------------- | ---------------------- | ----- |
| `coupling.cbo: {class: {warning: 30}}` (P2c)  | **30 / 20** | 14 / 20 (untouched) | **1 finding, `error`** | **2** |
| `coupling.cbo: {warning: 30}` (T7, P2)        | **30 / 20** | **30 / 20**         | **1 finding, `error`** | **2** |
| `coupling.cbo: {threshold: 30}` (T1)          | 30 / 30     | 30 / 30             | nothing                | 0     |
| `coupling.cbo: {warning: 30, error: 31}` (T3) | 30 / 31     | 30 / 31             | nothing                | 0     |

What is `T7`'s own, and is in none of the other rows, is the two columns on the right of the band: the
half-written band is pushed into the **sibling level too**, and every level block beside it is
discarded (rows U2 and U3). Written at block level the same half band touches one level and leaves the
sibling alone.

The shared part is still worth naming because it is where the damage is: whenever the written warning
exceeds the unwritten half's default, the band is **inverted**, and `getSeverity()` tests the error
side first, so the warning tier becomes unreachable. The user **raised** the warning threshold to 30
and got an `error` at 20, while both neighbouring spellings of the same intent (`threshold: 30`,
`warning: 30, error: 31`) report nothing. At a firing magnitude the severity split shows too:
`{warning: 3}` -> `3 / 20` -> 4 warnings + 1 error; `{threshold: 3}` -> `3 / 3` -> 5 errors.

The unwritten half comes from the `ThresholdParser::parse()` **call-site** defaults at the rule's own
top level (`14, 20` for cbo; `0.8, 0.95` for instability), which today coincide with the level
defaults; the grid cannot tell the two sources apart, and a future divergence between them would not
show here.

`fixture/` has maximum CBO 5, so none of this is visible in the 41 TSV rows themselves; P2/P2c run on
`probe-fixture/`, where `Fx\Heavy\H` has CBO 25.

### U2 — a level block that writes ONLY a non-band key is discarded by a top band shorthand (15 rows)

This is the cell claude-09 called decisive for C2, and it is not a corner case of `enabled`:

| document                                                                               | `minAfferent` resolved | class findings |
| -------------------------------------------------------------------------------------- | ---------------------- | -------------- |
| `coupling.instability: {class: {min_afferent: 0}}`                                     | **0**                  | **2**          |
| `coupling.instability: {threshold: 1.01, class: {min_afferent: 0}}`                    | **1** (default)        | **0**          |
| `coupling.instability: {max_warning: 1.01, max_error: 1.02, class: {min_afferent: 0}}` | 1                      | 0              |
| `coupling.instability: {max_warning: 1.01, class: {min_afferent: 0}}`                  | 1                      | 0              |

Six of the fifteen rows are **live-visible**: the count falls from 2 to 0 at class level and from 1 to
0 at namespace level. The top-level value is a *threshold* shorthand; `min_afferent` is not a
threshold and has no shorthand — yet writing a top threshold silently resets it. The same holds for
`scope` (reverts to `all`) and `min_class_count` (reverts to `3`), both confirmed by the call witness,
and `min_class_count` additionally live on `probe-fixture-small/` (probe P3): `{namespace:
{min_class_count: 1}}` alone judges 4 namespaces, and under `{threshold: 1, namespace:
{min_class_count: 1}}` it judges 3.

So C2's phrasing — "a level whose band carries any written key keeps its own band" — leaves this
document undecided in both halves: the block writes no band key, **and** it writes something that is
not a band key at all and that today disappears with it.

### U3 — the top half band silently overrides a written `enabled: false` (4 rows)

`coupling.cbo: {warning: 500, class: {enabled: false}}` -> the class level is **on**. Three witnesses
agree: the call witness resolves `enabled: true`, the directives door reports the class-level
directive `effective` (not `producer-disabled`), and the block's other writes are gone. This is M3's
D1, and it reaches through the half band exactly as it reaches through `threshold` and the full pair.
The contrast that makes it legible is one row away: `T4_enabled_false` (`enabled: false` **at the
top**) *does* disable the level — directives door `producer-disabled`, call witness `enabled: false` —
so the product honours the rule-level switch and discards the level-level one.

### U4 — the half band: hard refusal for one rule family, silent reshaping for the other (60 probe rows, reported not counted)

`complexity.ccn: {warning: 100000}` -> **exit 3**:

```
Configuration error: Option "warning" is not an option of rule "complexity.ccn".
Options here: callable, class, enabled, suppress-namespace-channels, suppress-namespaces,
suppress-paths, threshold.
```

in all 60 cells, whatever the level block says — including under a block that is itself well-formed.
`coupling.cbo` and `coupling.instability` accept the identical shape and reshape the whole rule with
it. This is M3's D4 holding for the half band as it held for the full pair, which is why it is
reported rather than counted: it follows from a neighbour.

### U5 — at the same top level, `scope` merges and `warning` destroys (probe P1)

Two keys of `coupling.cbo`, both written at the rule's own top level, behave categorically
differently:

| document                                        | class `scope`                                   |
| ----------------------------------------------- | ----------------------------------------------- |
| `{scope: application}`                          | application — the top key **reaches** the level |
| `{scope: all, class: {scope: application}}`     | application — the **block wins**                |
| `{scope: application, class: {scope: all}}`     | all — the block wins again                      |
| `{threshold: 500, class: {scope: application}}` | **all** — the block is **discarded**            |
| `{warning: 500, class: {scope: application}}`   | **all** — the block is **discarded**            |

`scope` at the top is an ordinary merge in which the more specific document wins. `threshold` — and
now `warning` — at the top is a rebuild that throws the more specific document away, `scope` included.
Nothing in the document distinguishes the two cases to a reader.

**Where each half lives.** `CboOptions::fromArray()` opens its flat branch on
`isset($config[THRESHOLD]) || isset($config[WARNING]) || isset($config[ERROR])` — a lone top `warning`
is enough, and the docblock says so in as many words. That branch builds `$levelConfig` from exactly
three keys (`enabled`, `warning`, `error`) and adds `scope` only from the **top** `$config['scope']`,
so a level block's `scope` / `min_class_count` / `min_afferent` are structurally unreachable inside it
— which is U2. The merge U5 contrasts it with is four lines further down, in the hierarchical branch:
`if (isset($config['scope']) && !isset($classConfig['scope'])) { $classConfig['scope'] = ... }` — the
top value fills in only where the block is silent. `InstabilityOptions::fromArray()` is the same shape
(`$hasFlatMaxWarning = isset($config['max_warning']) || isset($config['maxWarning'])`, `$levelConfig`
of three keys), without the `scope` clause.

### Cells that are NOT surprising, stated so the negative is on the record

`T2_threshold_tilde` is cell-for-cell identical to `T0_none` for the new block forms too (M3's D5
holds). `T5_enabled_true` behaves as `T0_none`. `T4_enabled_false` drops the block and disables the
level, for the non-band forms exactly as M3 measured for the band forms. `B9_unknown_key` is still
refused under `T7` (4 rows, exit 3) — M3's D6.

---

## 6. A method note: one measurement of mine was corrupted before it was read

The first directives-door run reported `producer-disabled` for `coupling.instability` in **every**
cell, including one whose own report said the run produced 2 class-level findings. Read at face value
that is a product defect — a third witness contradicting the other two.

It was not. Two faults in my probe, found by isolating instead of by believing:

1. The probe directive was written `@qmx-threshold coupling.instability max_warning=0.01
   max_error=0.02`. The payload spelling is `warning=`/`error=`, not the option spelling, so the
   directive never became a directive row at all.
2. `--only-rule X` does **not** drop the other rules' directive rows — it turns them into
   `producer-disabled`. My runner read `directives[0]`, which was the `coupling.cbo` row, and
   reported it under the instability key.

`run_directives.py` now selects the row by its `target` field and records every target it saw, and
both controls (`CTRL_plain` -> `effective`, `CTRL_class_enabled_false` -> `producer-disabled`) pass for
both rules. The corrected run agrees with the call witness in all 26 cells. The general shape is the
one this project keeps meeting: an instrument checked only on the failing half of its range proves
nothing — the cbo control passed throughout and hid an instrument that was reading the wrong row.

---

## 7. Files

| file                                                     | what it is                                                                |
| -------------------------------------------------------- | ------------------------------------------------------------------------- |
| `population-gap.tsv`                                     | **the deliverable** — 137 rows, the 23 columns of `population.tsv`        |
| `report.md`                                              | this file                                                                 |
| `declare_gap.php` / `.out` / `.json`                     | the declarative derivation of both missing forms                          |
| `gen_rows.py`, `configs/`, `rows.json`                   | the generator and its 137 YAML documents                                  |
| `run_live.py` / `live.json`                              | witness 1, raw per-row output                                             |
| `run_baseline.py` / `baseline.json`, `baseline_configs/` | this run's own block-absent baselines                                     |
| `xcheck.py`                                              | the 26-baseline agreement check against M3                                |
| `call_witness.php` / `call.json`                         | witness 2, raw per-row output                                             |
| `run_directives.py` / `directives.json`, `dir_configs/`  | witness 3 and its two controls, per rule                                  |
| `probes.py` / `probes.txt` / `probes.json`, `probe_cfg/` | P1 (scope), P2 (the T7 band), P3 (min_class_count)                        |
| `build_population.py`, `classify.py` / `surprising.json` | the merge, and the count behind the first line                            |
| `fixture/`                                               | byte-identical copy of M3's analysed tree                                 |
| `fixture-dir/`                                           | `fixture/` + two class-level `@qmx-threshold` directives (witness 3 only) |
| `probe-fixture/`                                         | `fixture/` + `Fx\Heavy\H` (CBO 25) and 25 dependencies (P2 only)          |
| `probe-fixture-small/`                                   | `fixture/` + the two-class namespace `Fx\Duo` (P3 only)                   |
| `calib/`                                                 | the non-band positive control run before the grid was trusted             |
