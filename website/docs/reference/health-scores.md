# Health Scores

Qualimetrix computes **six health scores** for every class, namespace, and project — each ranging from 0 (worst) to 100 (best). Health scores distill dozens of raw metrics into a quick quality overview, helping you spot problems without reading individual metric values.

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

All health scores start from 100 and subtract penalties for metrics that exceed healthy thresholds. Each dimension has **level-specific formulas** — class, namespace, and project levels use different inputs because different aggregation statistics are available. Namespace and project formulas use aggregated statistics (`.avg`, `.p95`, `.max`, `.min`, `.p5`) while class formulas use raw per-class values.

Formulas are written in [Symfony Expression Language](https://symfony.com/doc/current/components/expression_language.html) syntax.

### Complexity

Penalizes high average CCN and cognitive complexity, plus square-root-scaled penalties for outlier methods (max values at class level, p95 at namespace level). Well-structured code with simple methods scores near 100.

!!! info "Interface methods are included in aggregation"
    Interface methods have minimal complexity (CCN=1, cognitive=0, NPath=1) and are included in namespace-level `.avg` and `.p95` calculations. Projects with many interfaces may see lower average complexity than expected. This is by design — interfaces are part of the codebase — but means adding interfaces can slightly improve complexity scores without changing actual logic.

### Cohesion

Blends TCC (Tight Class Cohesion) and LCOM4. TCC is square-root-scaled to reward incremental improvement. Classes with few methods (< 6) get a lenient TCC default. Pure methods (no property access) are accounted for to avoid false penalties.

### Coupling

Uses hyperbolic decay (`K / (K + penalty)`) for smooth scoring.

- **Class level** blends package-level (`coupling.ce-packages`) and dampened raw efferent coupling (`coupling.ce`).
- **Namespace level** also relies on **efferent-only** signals: per-class average outgoing coupling (`coupling.ce.avg`, `coupling.ce-packages.avg`), worst-case class outlier (`coupling.ce.max`), and namespace-level outgoing breadth (`coupling.ce`), plus Distance from Main Sequence. Bidirectional CBO is intentionally avoided here because it conflates afferent (Ca) with efferent (Ce) and would unfairly penalize stable contracts namespaces (high Ca, low Ce by design).
- **Project level** keeps bidirectional CBO aggregates (`coupling.cbo.avg`, `coupling.cbo.p95`, `coupling.cbo.max`): at project level Σ Ca = Σ Ce because every internal edge contributes to both sides, so CBO is symmetric and proportional to Ce.

### Typing

At class level, directly maps type coverage percentage. At namespace and project level, computes the ratio from raw typed/total counters to avoid averaging bias.

### Maintainability

Three-term penalty on MI average (base quality), MI 5th percentile (main differentiator), and MI minimum (extreme outliers). The multi-term approach produces good discrimination across projects — from well-maintained libraries (score ~95) to complex frameworks (score ~48).

### Overall

Weighted average of the other five dimensions. At class level, maintainability is excluded (its signal is already captured by complexity and cohesion). Weights:

- **Class:** complexity 35%, cohesion 25%, coupling 25%, typing 15%
- **Namespace / Project:** complexity 30%, cohesion 20%, coupling 20%, typing 10%, maintainability 20%
<!-- llms:skip-end -->

---

## Reading Health Scores

Health scores appear in several output formats:

- **Summary format** (`--format=summary`, default) — progress bars with color coding and labels
- **JSON format** (`--format=json`) — `healthScores` array in the output object
- **Health format** (`--format=health`) — text table of health dimensions with scores, status, and decomposition
- **HTML format** (`--format=html`) — interactive treemap colored by selected health dimension

See [Output Formats](../usage/output-formats.md) for details.

---

## Configuration

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

Both paths produce the same result: the dimension is removed from the pipeline AND `health.overall` weights are renormalized across the remaining dimensions (the disabled dimension is not silently treated as a neutral 75-point contribution). If you override `health.overall` with a non-canonical formula (e.g. `min(...)` or a conditional), excluding dimensions will throw an explicit error — handle the disabled dimension via `??` fallbacks in your custom formula instead.

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
    levels: [class, namespace, project]
    warning: 80
    error: 90
    inverted: false   # Higher values trigger violations
```

!!! note "Metric naming"
    User-defined metrics can use any name except the reserved `health.*` prefix. The recommended convention is `computed.*`. Both prefixes require lower-case kebab-case segments after the dot (e.g. `computed.code-density`); underscores and upper-case letters are rejected.

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
| `coupling.distance`                       | namespace                 |
| `coupling.distance.avg`                   | project                   |
| `maintainability.mi.avg`                  | class, namespace, project |
| `maintainability.mi.min`                  | class, namespace, project |
| `maintainability.mi.p5`                   | namespace, project        |
| `design.type-coverage.pct`                | class                     |
| `design.type-coverage.param.total.sum`    | namespace, project        |
| `design.type-coverage.param.typed.sum`    | namespace, project        |
| `design.type-coverage.return.total.sum`   | namespace, project        |
| `design.type-coverage.return.typed.sum`   | namespace, project        |
| `design.type-coverage.property.total.sum` | namespace, project        |
| `design.type-coverage.property.typed.sum` | namespace, project        |
| `size.method-count`                       | class                     |
| `size.symbol-method-count`                | namespace, project        |
| `cohesion.pure-method-count`              | class                     |
| `health.complexity`                       | class, namespace, project |
| `health.cohesion`                         | class, namespace, project |
| `health.coupling`                         | class, namespace, project |
| `health.typing`                           | class, namespace, project |
| `health.maintainability`                  | namespace, project        |

Common aggregation suffixes on a key: `.avg`, `.min`, `.max`, `.sum`, `.p5`, `.p95`.

This is not an exhaustive list — any metric collected by Qualimetrix can be referenced in formulas by its key. Use `bin/qmx check src/ --format=metrics` to see all available metrics and their exact keys for your project.

!!! warning "Unknown metric references"
    If a formula references a metric key that does not exist (e.g., a typo like `m["complexity.ccn.abg"]` instead of `m["complexity.ccn.avg"]`), Qualimetrix will report a clear error instead of silently returning zero. Always use the `??` operator to provide a default for metrics that may legitimately be absent: `(m["complexity.ccn.avg"] ?? 0)`.

### Available Functions

| Function                 | Description                                          |
| ------------------------ | ---------------------------------------------------- |
| `min(a, b)`              | Minimum of two values                                |
| `max(a, b)`              | Maximum of two values                                |
| `abs(x)`                 | Absolute value                                       |
| `sqrt(x)`                | Square root                                          |
| `log(x)`                 | Natural logarithm                                    |
| `log10(x)`               | Base-10 logarithm                                    |
| `clamp(value, min, max)` | Constrain value to [min, max] range                  |
| `??`                     | Null coalescing (default value if metric is missing) |
| `**`                     | Exponentiation                                       |

!!! tip "Always use null coalescing"
    Metrics may be missing for some symbols (e.g., a class with no methods has no `complexity.ccn`). Always provide defaults with `??`: `(m["complexity.ccn.avg"] ?? 1)` instead of `m["complexity.ccn.avg"]`.
