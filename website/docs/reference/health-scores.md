# Health Scores

Qualimetrix defines six health dimensions for classes, namespaces and projects. A score ranges from 0 (worst) to 100 (best) when the selected formula has applicable measured inputs. A dimension without those inputs has no numeric score; numeric 0 remains a measurement.

Definitions are resolved per analysis run and evaluated after raw metric
aggregation. Reusing a process for multiple runs replaces the prior definition
set atomically, so configuration from an earlier run cannot leak into the next.

**Rule ID:** `computed` — user-defined computed metrics.

Each built-in dimension is its own producer, and publishes its findings under
its own rule ID:

- **Rule ID:** `health.complexity`
- **Rule ID:** `health.cohesion`
- **Rule ID:** `health.coupling`
- **Rule ID:** `health.typing`
- **Rule ID:** `health.maintainability`
- **Rule ID:** `health.overall`

---

## Dimensions

| Dimension                | What it measures                            | Key input metrics                                                                     | Default thresholds (warning / error) |
| ------------------------ | ------------------------------------------- | ------------------------------------------------------------------------------------- | ------------------------------------ |
| `health.complexity`      | Method and class complexity                 | CCN (avg, max, p95), Cognitive Complexity (avg, max, p95)                             | 50 / 25                              |
| `health.cohesion`        | How well class methods relate to each other | TCC, LCOM4, method count                                                              | 50 / 25                              |
| `health.coupling`        | Dependencies between classes and namespaces | Efferent coupling (Ce, Ce packages), Distance from Main Sequence, CBO (project level) | 50 / 25                              |
| `health.typing`          | Type declaration coverage                   | Parameter, return, and property type coverage                                         | 80 / 50                              |
| `health.maintainability` | Ease of safe modification                   | Maintainability Index (avg, p5, min)                                                  | 50 / 25                              |
| `health.overall`         | Weighted average of all dimensions          | All of the above                                                                      | 50 / 30                              |

---

## Score Labels

Every health score is assigned a human-readable label based on the score value relative to the warning (W) and error (E) thresholds:

- **Excellent**: score > W + (100 - W) x 0.6
- **Good**: score > W + (100 - W) x 0.3
- **Fair**: score > W
- **Poor**: score > E
- **Critical**: score <= E

For the most common defaults (W=50, E=25):

| Label     | Score range |
| --------- | ----------- |
| Excellent | > 80        |
| Good      | 65 -- 80    |
| Fair      | 50 -- 65    |
| Poor      | 25 -- 50    |
| Critical  | <= 25       |

!!! note
    `health.typing` uses different thresholds (W=80, E=50), so its label boundaries shift accordingly: Excellent > 92, Good > 86, Fair > 80, Poor > 50, Critical <= 50.

---

<!-- llms:skip-begin -->
## How Scores Work

Built-in dimensions use level-specific measured inputs, and overall health is their weighted mean. Missing or inapplicable inputs do not become invented penalty values. Namespace and project formulas use aggregated statistics (`.avg`, `.p95`, `.max`, `.min`, `.p5`); class formulas use class values.

Formulas are written in [Symfony Expression Language](https://symfony.com/doc/current/components/expression_language.html) syntax.

### Complexity

Penalizes high average CCN and cognitive complexity, plus square-root-scaled penalties for outlier methods (max values at class level, p95 at namespace level). Well-structured code with simple methods scores near 100.

!!! info "Interface methods are included in aggregation"
    Interface methods have minimal complexity (CCN=1, cognitive=0, NPath=1) and are included in namespace-level `.avg` and `.p95` calculations. Projects with many interfaces may see lower average complexity than expected. This is by design — interfaces are part of the codebase — but means adding interfaces can slightly improve complexity scores without changing actual logic.

### Cohesion

Combines available TCC (Tight Class Cohesion) and LCOM4 contributions and divides by their participating weight sum. An absent half contributes no default, including for classes with fewer than six methods. TCC retains its square-root scaling and the existing purity adjustment remains unchanged; measured LCOM can still contribute for stateless classes.

The unadjusted LCOM contribution uses the same span of five at class and namespace levels: one component receives no penalty, and six exhaust the contribution. The class purity adjustment remains separate; matching this scale does not guarantee monotonicity between every namespace and its member classes.

The built-in project cohesion score inherits the namespace formula and uses project aggregate inputs directly; it does not average namespace health scores. The change from span two to five therefore also changes project cohesion and its contribution to overall. Review namespace/project health limits and accepted benchmark ranges.

### Coupling

Uses hyperbolic decay (`K / (K + penalty)`) for smooth scoring.

- **Class level** blends package-level (`coupling.ce-packages`) and dampened raw efferent coupling (`coupling.ce`).
- **Namespace level** also relies on **efferent-only** signals: per-class average outgoing coupling (`coupling.ce.avg`, `coupling.ce-packages.avg`), worst-case class outlier (`coupling.ce.max`), and namespace-level outgoing breadth (`coupling.ce`), plus Distance from Main Sequence. Bidirectional CBO is intentionally avoided here because it conflates afferent (Ca) with efferent (Ce) and would unfairly penalize stable contracts namespaces (high Ca, low Ce by design).
- **Project level** keeps bidirectional CBO aggregates (`coupling.cbo.avg`, `coupling.cbo.p95`, `coupling.cbo.max`): at project level Σ Ca = Σ Ce because every internal edge contributes to both sides, so CBO is symmetric and proportional to Ce. Its Distance term reads `coupling.distance-own.avg`, not `coupling.distance.avg` — see [Distance from Main Sequence](../rules/coupling.md#distance-from-main-sequence) for why the project fold is taken over own scopes.

Class coupling remains applicable at measured 0. Namespace coupling requires at least one finite measured input among `coupling.distance`, `coupling.ce-packages.avg`, `coupling.ce.avg`, `coupling.ce.max` and `coupling.ce`. A function-only namespace with all five absent has no built-in coupling score. Graph-covered Ce=0 and distance=1 retain the formula result 75.

### Typing

Uses a class percentage when there is something to type. Namespace and project scores divide the summed typed counters by the actual summed parameter, return and property totals. A zero total produces no typing score; a positive total with zero typed declarations produces 0.

### Maintainability

Three-term penalty on MI average (base quality), MI 5th percentile (main differentiator), and MI minimum (extreme outliers). The knees sit at Coleman's published lines: 85, above which a codebase is "highly maintainable", and 65, below which it is "difficult to maintain". The previously measured seventeen-project calibration corpus ranged from 33.7 to 100.0 at project level; this is historical calibration evidence.

### Overall

Weighted average of the other five dimensions. At class level, maintainability is excluded (its signal is already captured by complexity and cohesion). Weights:

- **Class:** complexity 35%, cohesion 25%, coupling 25%, typing 15%
- **Namespace / Project:** complexity 30%, cohesion 20%, coupling 20%, typing 10%, maintainability 20%
Overall averages only available dimensions, preserving the listed order and original weights and dividing by their participating weight sum. Missing dimensions receive no neutral 75-point value; measured 0 participates. With no participating dimension, overall has no value.

<!-- llms:skip-end -->

---

## Reading Health Scores

Health scores appear in several output formats:

- **Summary format** (`--format=summary`, default) — progress bars with color coding and labels
- **JSON format** (`--format=json`) — `health` object in the output document
- **Health format** (`--format=health`) — text table of health dimensions with scores, status, and decomposition
- **HTML format** (`--format=html`) — interactive treemap colored by selected health dimension

See [Output Formats](../usage/output-formats.md) for details.

### What a Score Covers

A score describes the part of the codebase its participating inputs cover. If
TCC is absent and measured LCOM supplies cohesion, the score's coverage follows
LCOM rather than the missing TCC half. Coverage and decomposition follow the
selected effective formula, including the inputs an authored formula actually
reads; a builtin name alone does not establish its evidence.

Every health dimension therefore publishes a `coverage` beside its score: the
`.count` the narrowest participating input aggregate reported, the population that count is a
share of, and which `.count` was reported (`basis`). Scores are **not** damped
by coverage; the number is published so a reader can judge it, not folded into
it (see [ADR 0062](https://github.com/qualimetrix/qualimetrix/blob/main/docs/adr/0062-health-scores-measure-what-they-cover.md)).

The states are `measured`, `not-measured` and `not-applicable`. A positive
eligible population with no measured input reports not-measured as 0/N; an
empty population reports not-applicable with a reason. Numeric 0 is a measured
value. Enabled builtin project dimensions remain visible with a null score
when unavailable; disabled dimensions are omitted. Missing decomposition
values are null and retain their own coverage, rather than displaying numeric
0. Selecting a class or namespace never replaces its absent score with a
project score.

Where coverage is undefined the field says so explicitly, with a reason, rather
than reporting zero: `health.overall` composes the other dimensions, `health.typing`
is computed from typed/total sums that publish no `.count`, and a class-level or
namespace-filtered score is not an aggregate over symbols at all.

Class inputs and `size.symbol-class-count` count declarations, including
separate declarations of the same name. Their sample counts use that same
population; coverage is not clamped to hide a mismatch.

!!! info "Deviation from original spec"
    Coupling graph metrics remain defined over logical names. When a name has several declarations, its graph values are sampled once per declaration in namespace and project aggregates. This is a declaration-weighted extension of the logical graph metrics.

Coverage appears in `--format=json` (a `coverage` object per dimension), in
`--format=health` (a `Coverage` column plus one line per dimension in the
decomposition), in `--format=summary` (one line under each score) and in
`--format=html` (`summary.healthCoverage` and prepared
`summary.healthDecomposition` beside `summary.healthScores`). The viewer
renders this PHP evidence without evaluating formulas in JavaScript.

Cohesion contributors use TCC for the whole selected scope when any candidate
has it, including measured zero; otherwise they rank by LCOM, larger values
first. Candidates missing the selected metric are omitted. A run with no
aggregated project metrics publishes no health projection: its populations are
unknown rather than zero.

A coverage short of 100% is not automatically a fault in the run. Some gaps are
permanent by construction: TCC can be absent on small classes, and a namespace
that declares nothing but bare enums has no abstractness
of its own — a bare enum is deliberately outside that denominator (see
[Distance from Main Sequence](../rules/coupling.md#distance-from-main-sequence))
— so no own-scope distance is published for it, while the population counts it
because it declares a type. The denominator is deliberately *not* narrowed to the
namespaces the aggregate reached: a denominator that is the aggregate's own walk
prints 100% by construction and cannot show what the walk did not reach, which is
the whole point of the line.


---

## Configuration

### Accepted Keys

Each `computed_metrics:` entry accepts exactly nine keys: `formula`, `formulas`,
`levels`, `description`, `inverted`, `threshold`, `warning`, `error`, and
`enabled`. `threshold` sets both `warning` and `error` to the same value and
cannot be combined with either of them in the same layer. Inside `formulas:`, the only accepted
keys are the three report levels: `class`, `namespace`, and `project`.

An unknown key, a value of the wrong type, or a `health.*` name outside the
six built-in dimensions (`health.complexity`, `health.cohesion`,
`health.coupling`, `health.typing`, `health.maintainability`,
`health.overall`) is refused with exit code 3 and a message naming what was
written and what is accepted — none of these are ignored silently. A key is
recognised even when its value is `~`, so `warnign: ~` is refused too.

### Across presets and your file

`computed_metrics` merges metric by metric, and inside one metric key by key:
a preset's metric and your file's keys for the same name combine, and a metric
another layer never names is left as it is.

- `threshold` is expanded into `warning` and `error` in the layer that wrote
  it, so a file that writes only `warning` over a preset's `threshold` keeps
  the preset's value as `error`.
- `formula` sets every level and `formulas.<level>` refines one level,
  whichever layers wrote them: a file's `formulas: {class: …}` over a preset's
  `formula` changes the class level only.
- A metric is removed only by `enabled: false`. `computed_metrics: {}` and
  `health.complexity: ~` leave the lower layer's values standing — neither
  resets anything to the built-in defaults.
- `exclude_health` accumulates across layers.

The rules every configuration key follows are in
[How layers combine](../getting-started/configuration.md#how-layers-combine).

### Customizing Thresholds

```yaml
# qmx.yaml
computed_metrics:
  health.complexity:
    warning: 60    # Stricter than default 50
    error: 30      # Stricter than default 25
```

### Disabling a Dimension

```yaml
computed_metrics:
  health.typing:
    enabled: false
```

Or via CLI:

```bash
bin/qmx check src/ --exclude-health=typing
```

Both paths remove the dimension and its canonical overall term. Original weights and term order remain unchanged; the weighted mean divides by the participating weight sum. An unsupported authored overall formula refuses with its selected formula source. Rewrite it as a supported canonical weighted mean or explicitly handle availability in the custom formula.

!!! warning "Two switches that look alike, and do different things"
    Each built-in dimension is its own producer, so it can be turned off two ways that read almost the same:

    - `rules: { health.cohesion: { enabled: false } }` stops the `health.cohesion` producer from **publishing findings**. The dimension is still computed and still contributes to `health.overall`.
    - `computed_metrics: { health.cohesion: { enabled: false } }` **removes the dimension itself** — this is the "Disabling a Dimension" switch above. `health.overall`'s weights are renormalized across what remains.

    A dimension removed the second way leaves its producer with no channel at all. An `suppress_namespace_channels` key that used to address `health.cohesion` is then rejected: the key must name a channel the rule under it actually emits, and after removal it emits none.

### Overriding Formulas

```yaml
computed_metrics:
  health.maintainability:
    # Same formula for all levels
    formula: "clamp(m['maintainability.mi.avg'], 0, 100)"
```

A formula is an expression written as a string, and a constant is an
expression: `formula: "80"` is a metric that is 80 everywhere. It has to be
quoted — an unquoted `80` is a number, and a number is not a formula.

An authored effective formula remains authored even when copied verbatim from a
built-in. It uses Always for the selected formula and does not require builtin
inputs for a constant 80. Metadata-only overrides retain builtin applicability.
The constant cannot claim builtin decomposition or coverage; without an
identifiable measured symbol population its coverage is not-applicable.

```yaml
computed_metrics:
  health.maintainability:
    # Different formulas per level
    formulas:
      class: "clamp(m['maintainability.mi.avg'], 0, 100)"
      namespace: "clamp(m['maintainability.mi.avg'] * 0.7 + m['maintainability.mi.p5'] * 0.3, 0, 100)"
      project: "clamp(m['maintainability.mi.avg'] * 0.7 + m['maintainability.mi.p5'] * 0.3, 0, 100)"
```

### Custom Computed Metrics

```yaml
computed_metrics:
  computed.code-density:
    formula: "clamp((m['size.lloc'] ?? 0) / max(m['size.loc'] ?? 1, 1) * 100, 0, 100)"
    description: "Ratio of logical to physical lines (higher = denser code)"
    levels: [namespace]   # size.lloc / size.loc are only raw keys at namespace level
    warning: 80
    error: 90
    inverted: false   # Higher values trigger violations
```

!!! note "Metric naming"
    A user-defined metric name must start with `health.` or `computed.` — no other prefix is accepted. The recommended convention for custom metrics is `computed.*`; `health.*` is reserved for the six built-in dimensions. Both prefixes require lower-case kebab-case segments after the dot (e.g. `computed.code-density`); underscores and upper-case letters are rejected, and the last segment cannot be the name of an aggregation strategy (`sum`, `avg`, `max`, `min`, `count`, `p95`, `p5` — e.g. `computed.sum` is refused).

### Available Variables

Formulas read every metric through a single `m` array, indexed by the metric's real key: `m["complexity.ccn.avg"]`. There is no separate "variable name" to memorize — the key you see in `--format=metrics`/`--format=json` output is the key you index with.

| Metric key                                | Available at              |
| ----------------------------------------- | ------------------------- |
| `complexity.ccn.avg`                      | class, namespace, project |
| `complexity.ccn.max`                      | class, namespace, project |
| `complexity.ccn.sum`                      | namespace, project        |
| `complexity.ccn.p95`                      | namespace, project        |
| `complexity.cognitive.avg`                | class, namespace, project |
| `complexity.cognitive.max`                | class, namespace, project |
| `complexity.cognitive.sum`                | namespace, project        |
| `complexity.cognitive.p95`                | namespace, project        |
| `cohesion.tcc`                            | class                     |
| `cohesion.tcc.avg`                        | namespace, project        |
| `cohesion.lcom`                           | class                     |
| `cohesion.lcom.avg`                       | namespace, project        |
| `coupling.cbo.avg`                        | namespace, project        |
| `coupling.cbo.max`                        | namespace, project        |
| `coupling.cbo.p95`                        | namespace, project        |
| `coupling.ce`                             | class, namespace          |
| `coupling.ce.avg`                         | namespace, project        |
| `coupling.ce.max`                         | namespace, project        |
| `coupling.ce-packages`                    | class                     |
| `coupling.ce-packages.avg`                | namespace, project        |
| `coupling.abstractness`                   | namespace                 |
| `coupling.distance`                       | namespace                 |
| `coupling.ca-own`                         | namespace                 |
| `coupling.ce-own`                         | namespace                 |
| `coupling.instability-own`                | namespace                 |
| `coupling.abstractness-own`               | namespace                 |
| `coupling.distance-own`                   | namespace                 |
| `coupling.distance-own.avg`               | project                   |
| `size.symbol-declaring-namespace-count`   | project                   |
| `maintainability.mi.avg`                  | class, namespace, project |
| `maintainability.mi.min`                  | class, namespace, project |
| `maintainability.mi.p5`                   | namespace, project        |
| `design.type-coverage.all`                | class                     |
| `design.type-coverage.param.total.sum`    | namespace, project        |
| `design.type-coverage.param.typed.sum`    | namespace, project        |
| `design.type-coverage.return.total.sum`   | namespace, project        |
| `design.type-coverage.return.typed.sum`   | namespace, project        |
| `design.type-coverage.property.total.sum` | namespace, project        |
| `design.type-coverage.property.typed.sum` | namespace, project        |
| `size.method-count`                       | class                     |
| `size.symbol-method-count`                | class, namespace, project |
| `cohesion.pure-method-count`              | class                     |
| `size.loc`                                | namespace                 |
| `size.lloc`                               | namespace                 |
| `health.complexity`                       | class, namespace, project |
| `health.cohesion`                         | class, namespace, project |
| `health.coupling`                         | class, namespace, project |
| `health.typing`                           | class, namespace, project |
| `health.maintainability`                  | class, namespace, project |

Common aggregation suffixes on a key: `.avg`, `.min`, `.max`, `.sum`, `.p5`, `.p95`.

This is not an exhaustive list — any metric collected by Qualimetrix can be referenced in formulas by its key. Use `bin/qmx check src/ --format=metrics` to see all available metrics and their exact keys for your project.

!!! warning "Unknown metric references"
    If a formula references a metric key that does not exist (e.g., a typo like `m["complexity.ccn.abg"]` instead of `m["complexity.ccn.avg"]`), Qualimetrix will report a clear error instead of silently returning zero. Use `??` when a fallback is meaningful for your formula; absent inputs differ from choosing 0 or a neutral value.

!!! warning "Metrics a level does not carry"
    A formula runs at each of its `levels:`, and a key is judged at that level:

    - **No symbol at the level carries the key**, and the formula reads it without `??` — a configuration error (exit code 3). This includes another computed metric read at a level missing from its own `levels:`: `computed.a` with `levels: [class]` cannot be read bare by a `project` formula, and the error says where `computed.a` is published. A `project` level that inherits the `namespace` formula is checked at `project`.
    - **Some symbols carry the key and others do not** — the symbols without it get no value rather than a fabricated 0, and the successful report carries a structural summary by metric and level, with separate missing-input and null-result counts, exact missing keys and at most three deterministic exact subject samples.

    `m["a"] ?? m["b"]` reads `b` only where `a` is absent, so a symbol is skipped only when it carries neither. End the chain with a literal — `m["a"] ?? m["b"] ?? 0` — to give every symbol a value.

    A ternary reads only the branch it takes. `m["size.method-count"] > 0 ? 7 : m["cohesion.tcc"]` reads `cohesion.tcc` only on a class without methods, so a key only one branch reads is never a configuration error: each symbol is judged by the branch its own values select, and a symbol whose branch reads a key it lacks enters the same absence summary. The right side of `and` / `or` is judged the same way. The condition always runs, so a bare read there counts like one in arithmetic — an absent metric would pick the branch on nothing (`null > 0` is false); guard it with `??` as well. A key both branches read counts as read.

`weighted_mean` takes ordered alternating nullable values and positive finite numeric weights. It skips only null values, preserves 0 and returns null when no value participates. Only the exact enclosing clamp of a weighted mean preserves that null result; ordinary clamp of null fails. Other functions retain native PHP semantics. Builtin inapplicability is quiet; applicable builtin absence and evaluation failures refuse with the effective formula source and exit 3, without a successful partial report.

### Available Functions

| Function                            | Description                                                        |
| ----------------------------------- | ------------------------------------------------------------------ |
| `min(a, b)`                         | Minimum of two values                                              |
| `max(a, b)`                         | Maximum of two values                                              |
| `abs(x)`                            | Absolute value                                                     |
| `sqrt(x)`                           | Square root                                                        |
| `log(x)`                            | Natural logarithm                                                  |
| `log10(x)`                          | Base-10 logarithm                                                  |
| `clamp(value, min, max)`            | Constrain value to [min, max] range                                |
| `weighted_mean(value, weight, ...)` | Mean of participating nullable values with positive finite weights |
| `??`                                | Null coalescing (default value if metric is missing)               |
| `**`                                | Exponentiation                                                     |

!!! tip "Choose meaningful defaults"
    Use `??` when the fallback has meaning for your formula. Leaving an input absent is distinct from choosing 0 or a neutral value; nullable weighted-mean values can remain absent.
