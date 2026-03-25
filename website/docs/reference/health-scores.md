# Health Scores

Qualimetrix computes **six health scores** for every class, namespace, and project — each ranging from 0 (worst) to 100 (best). Health scores distill dozens of raw metrics into a quick quality overview, helping you spot problems without reading individual metric values.

---

## Dimensions

| Dimension                | What it measures                            | Key input metrics                                         | Default thresholds (warning / error) |
| ------------------------ | ------------------------------------------- | --------------------------------------------------------- | ------------------------------------ |
| `health.complexity`      | Method and class complexity                 | CCN (avg, max, p95), Cognitive Complexity (avg, max, p95) | 50 / 25                              |
| `health.cohesion`        | How well class methods relate to each other | TCC, LCOM4, method count                                  | 50 / 25                              |
| `health.coupling`        | Dependencies between classes and namespaces | CBO, Distance from Main Sequence, efferent coupling       | 50 / 25                              |
| `health.typing`          | Type declaration coverage                   | Parameter, return, and property type coverage             | 80 / 50                              |
| `health.maintainability` | Ease of safe modification                   | Maintainability Index (avg, p5, min)                      | 50 / 25                              |
| `health.overall`         | Weighted average of all dimensions          | All of the above                                          | 50 / 30                              |

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

### Cohesion

Blends TCC (Tight Class Cohesion) and LCOM4. TCC is square-root-scaled to reward incremental improvement. Classes with few methods (< 6) get a lenient TCC default. Pure methods (no property access) are accounted for to avoid false penalties.

### Coupling

Uses hyperbolic decay (`K / (K + penalty)`) for smooth scoring. At class level, blends package-level and raw efferent coupling. At namespace level, combines Distance from Main Sequence, average CBO, and outlier CBO (p95, max).

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
- **HTML format** (`--format=health`) — interactive treemap colored by selected health dimension

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

Or exclude from display only (scores still computed):

```bash
bin/qmx check src/ --exclude-health=typing
```

### Overriding Formulas

```yaml
computed_metrics:
  health.maintainability:
    # Same formula for all levels
    formula: "clamp(mi__avg, 0, 100)"
```

```yaml
computed_metrics:
  health.maintainability:
    # Different formulas per level
    formulas:
      class: "clamp(mi__avg, 0, 100)"
      namespace: "clamp(mi__avg * 0.7 + mi__p5 * 0.3, 0, 100)"
      project: "clamp(mi__avg * 0.7 + mi__p5 * 0.3, 0, 100)"
```

### Custom Computed Metrics

```yaml
computed_metrics:
  computed.code-density:
    formula: "clamp((lloc ?? 0) / max(loc ?? 1, 1) * 100, 0, 100)"
    description: "Ratio of logical to physical lines (higher = denser code)"
    levels: [class, namespace, project]
    warning: 80
    error: 90
    inverted: false   # Higher values trigger violations
```

!!! note "Metric naming"
    User-defined metrics can use any name except the reserved `health.*` prefix. The recommended convention is `computed.*`.

### Available Variables

Metric names use double underscores in formulas (dots are not allowed in Expression Language identifiers):

| Metric                     | Variable in formula        | Available at              |
| -------------------------- | -------------------------- | ------------------------- |
| `ccn.avg`                  | `ccn__avg`                 | class, namespace, project |
| `ccn.max`                  | `ccn__max`                 | class, namespace, project |
| `ccn.sum`                  | `ccn__sum`                 | namespace, project        |
| `ccn.p95`                  | `ccn__p95`                 | namespace, project        |
| `cognitive.avg`            | `cognitive__avg`           | class, namespace, project |
| `cognitive.max`            | `cognitive__max`           | class, namespace, project |
| `cognitive.sum`            | `cognitive__sum`           | namespace, project        |
| `cognitive.p95`            | `cognitive__p95`           | namespace, project        |
| `tcc`                      | `tcc`                      | class                     |
| `tcc.avg`                  | `tcc__avg`                 | namespace, project        |
| `lcom`                     | `lcom`                     | class                     |
| `lcom.avg`                 | `lcom__avg`                | namespace, project        |
| `cbo.avg`                  | `cbo__avg`                 | namespace, project        |
| `cbo.max`                  | `cbo__max`                 | namespace, project        |
| `cbo.p95`                  | `cbo__p95`                 | namespace, project        |
| `ce`                       | `ce`                       | class                     |
| `ce_packages`              | `ce_packages`              | class                     |
| `mi.avg`                   | `mi__avg`                  | class, namespace, project |
| `mi.min`                   | `mi__min`                  | class, namespace, project |
| `mi.p5`                    | `mi__p5`                   | namespace, project        |
| `distance`                 | `distance`                 | namespace                 |
| `distance.avg`             | `distance__avg`            | project                   |
| `typeCoverage.pct`         | `typeCoverage__pct`        | class                     |
| `methodCount`              | `methodCount`              | class                     |
| `symbolMethodCount`        | `symbolMethodCount`        | namespace, project        |
| `pureMethodCount_cohesion` | `pureMethodCount_cohesion` | class                     |
| `health.complexity`        | `health__complexity`       | class, namespace, project |

Common aggregation suffixes: `__avg`, `__min`, `__max`, `__sum`, `__p5`, `__p95`.

This is not an exhaustive list — any metric collected by Qualimetrix can be referenced in formulas. Use `bin/qmx check src/ --format=metrics-json` to see all available metrics for your project.

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
    Metrics may be missing for some symbols (e.g., a class with no methods has no `ccn`). Always provide defaults with `??`: `(ccn__avg ?? 1)` instead of `ccn__avg`.
