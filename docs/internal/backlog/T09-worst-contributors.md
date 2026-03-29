# T09: Worst Contributors per Health Dimension

**Proposal:** #11 | **Priority:** Batch 4 (health drill-down) | **Effort:** ~4h | **Dependencies:** T03

## Motivation

Health report says "Namespace X cohesion = 46.7" but doesn't identify which class drags the score
down. Tech lead must manually investigate. Showing top 3 worst contributors per dimension per
namespace eliminates one investigation round.

## Design

### Data source

`SummaryEnricher` already computes health scores from aggregated metrics. The per-class metric
values are available in `AggregatedMetrics`. Need to:

1. For each namespace health dimension, find which classes contribute worst values
2. Store as `worstContributors` in `HealthScore`

### Contributor identification

For each health dimension, identify the underlying metrics (via `MetricHintProvider::getDecomposition()`).
For each metric, find top 3 classes with worst values within the namespace.

**"Worst" definition:** For each decomposition metric, use the `direction` field:
- `lower_is_better` → worst = highest value (e.g., LCOM=5 worse than LCOM=2)
- `higher_is_better` → worst = lowest value (e.g., TCC=30% worse than TCC=80%)

**Ranking across metrics:** Don't try to normalize across different scales. Instead, for each
dimension, pick the primary metric (first in decomposition list) and rank by that. Show other
metric values as context, not as ranking criteria.

**Number of contributors:** Default 3, configurable via `--format-opt=contributors=N`.

### Output in health text formatter (T03)

```
  Cohesion: 46.7 (warning)
    Worst contributors:
      ComputedMetricDefinition   TCC=30.0  LCOM=5
      FormulaParser              TCC=42.1  LCOM=3
      ExpressionValidator        TCC=45.8  LCOM=2
```

### Data model

Add to `HealthScore`:
```php
/** @var list<HealthContributor> */
public readonly array $worstContributors;
```

New VO `HealthContributor`:
```php
final readonly class HealthContributor {
    public function __construct(
        public string $className,
        public string $symbolPath,
        public array $metricValues, // ['tcc' => 30.0, 'lcom' => 5]
    ) {}
}
```

## Files to modify

**Note:** `HealthScore` is a readonly class. Adding `$worstContributors` requires updating the
constructor and ALL instantiation sites (primarily in `SummaryEnricher`).

| File                                                     | Change                                              |
| -------------------------------------------------------- | --------------------------------------------------- |
| `src/Reporting/Health/HealthScore.php`                   | Add `$worstContributors` field (update constructor) |
| `src/Reporting/Health/HealthContributor.php`             | **New VO**                                          |
| `src/Reporting/Health/SummaryEnricher.php`               | Compute worst contributors during enrichment        |
| `src/Reporting/Formatter/Health/HealthTextFormatter.php` | Render contributors (from T03)                      |
| `src/Reporting/Formatter/Json/JsonFormatter.php`         | Include contributors in JSON health output          |
| Tests                                                    | Contributor computation tests                       |

## Acceptance criteria

- [ ] Each health dimension includes top 3 worst contributors (configurable via `--format-opt=contributors=N`)
- [ ] Contributors show the specific metric values that make them worst
- [ ] Works for namespace-level and project-level health
- [ ] `--format=health` shows contributors in text output
- [ ] JSON output includes contributors in health section
- [ ] PHPStan passes, tests pass

## Edge cases

- Namespace with fewer than 3 classes → show all
- Class with null metric value (metric not applicable) → skip for that dimension
- Tie in metric values → deterministic ordering (alphabetical by class name)
- Project-level contributors → worst across all namespaces
