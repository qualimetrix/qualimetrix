<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\HealthDimension;
use Qualimetrix\Core\Symbol\SymbolLevel;

final class ComputedMetricDefaults
{
    /**
     * @return array<string, ComputedMetricDefinition>
     */
    public static function getDefaults(): array
    {
        return [
            HealthDimension::Complexity->value => new ComputedMetricDefinition(
                name: HealthDimension::Complexity->value,
                formulas: [
                    // Class: avg + max-method penalties. avg detects uniformly complex classes,
                    // sqrt(max) penalizes single monster methods that hide behind a low average.
                    // Defaults: ccn=1 (baseline for methodless classes), cognitive=0 (no penalty).
                    SymbolLevel::Class_->value => 'clamp(100 - max((m["complexity.ccn.avg"] ?? 1) - 4, 0) * 2.0 - max((m["complexity.cognitive.avg"] ?? 0) - 5, 0) * 2.0 - max((m["complexity.ccn.max"] ?? 0) - 10, 0) ** 0.5 * 2.0 - max((m["complexity.cognitive.max"] ?? 0) - 10, 0) ** 0.5 * 2.0, 0, 100)',
                    // Namespace: avg (base quality) + p95 (main differentiator) + sqrt(max) (extreme outliers).
                    // p95/max thresholds calibrated for per-method values (not per-class sums).
                    SymbolLevel::Namespace_->value => 'clamp(100 - max((m["complexity.ccn.sum"] ?? 0) / max(m["size.symbol-method-count"], 1) - 3, 0) * 1.5 - max((m["complexity.cognitive.sum"] ?? 0) / max(m["size.symbol-method-count"], 1) - 4, 0) * 1.5 - max((m["complexity.ccn.p95"] ?? 0) - 5, 0) ** 0.5 * 3.0 - max((m["complexity.cognitive.p95"] ?? 0) - 6, 0) ** 0.5 * 3.0 - max((m["complexity.ccn.max"] ?? 0) - 20, 0) ** 0.5 * 0.8, 0, 100)',
                    // Project: same structure as namespace, explicit to avoid inherited formula drift.
                    SymbolLevel::Project->value => 'clamp(100 - max((m["complexity.ccn.sum"] ?? 0) / max(m["size.symbol-method-count"], 1) - 3, 0) * 1.5 - max((m["complexity.cognitive.sum"] ?? 0) / max(m["size.symbol-method-count"], 1) - 4, 0) * 1.5 - max((m["complexity.ccn.p95"] ?? 0) - 5, 0) ** 0.5 * 3.0 - max((m["complexity.cognitive.p95"] ?? 0) - 6, 0) ** 0.5 * 3.0 - max((m["complexity.ccn.max"] ?? 0) - 20, 0) ** 0.5 * 0.8, 0, 100)',
                ],
                description: 'Complexity health score (0-100, higher is better)',
                levels: [SymbolLevel::Class_, SymbolLevel::Namespace_, SymbolLevel::Project],
                inverted: true,
                warningThreshold: 50.0,
                errorThreshold: 25.0,
            ),
            HealthDimension::Cohesion->value => new ComputedMetricDefinition(
                name: HealthDimension::Cohesion->value,
                formulas: [
                    // Pure methods (no property access, e.g. interface contract getters) inflate
                    // TCC denominator and LCOM. Adjust both: boost TCC proportionally, reduce LCOM.
                    // D_tcc=0.4, D_lcom=0.7. Classes with no pure methods: formula unchanged.
                    SymbolLevel::Class_->value => 'clamp((((m["size.method-count"] ?? 0) < 6 ? (m["cohesion.tcc"] ?? 0.5) : (m["cohesion.tcc"] ?? 0)) + (1 - ((m["size.method-count"] ?? 0) < 6 ? (m["cohesion.tcc"] ?? 0.5) : (m["cohesion.tcc"] ?? 0))) * ((m["cohesion.pure-method-count"] ?? 0) / max(m["size.method-count"] ?? 1, 1)) * 0.4) ** 0.5 * 50 + (1 - clamp((max((m["cohesion.lcom"] ?? 0) - (m["cohesion.pure-method-count"] ?? 0) * 0.7, 1) - 1) / 5, 0, 1)) * 50, 0, 100)',
                    SymbolLevel::Namespace_->value => 'clamp((m["cohesion.tcc.avg"] ?? 0.5) ** 0.5 * 50 + (1 - clamp(((m["cohesion.lcom.avg"] ?? 0) - 1) / 5, 0, 1)) * 50, 0, 100)',
                ],
                description: 'Cohesion health score (0-100, higher is better)',
                levels: [SymbolLevel::Class_, SymbolLevel::Namespace_, SymbolLevel::Project],
                inverted: true,
                warningThreshold: 50.0,
                errorThreshold: 25.0,
            ),
            HealthDimension::Coupling->value => new ComputedMetricDefinition(
                name: HealthDimension::Coupling->value,
                formulas: [
                    // Blend ce_packages (dependency breadth) with dampened ce (volume).
                    // K=15, W_pkg=3.0, W_raw=0.5, threshold=5.
                    // HalsteadVisitor (ce=127, pkg≈1): ~80. ShowCommand (ce=43, pkg≈15): ~26.
                    SymbolLevel::Class_->value => 'clamp(100 * 15 / (15 + max((m["coupling.ce-packages"] ?? 0) * 3.0 + (m["coupling.ce"] ?? 0) ** 0.5 * 0.5 - 5, 0)), 0, 100)',
                    // Efferent-only formula mirroring class-level. Bidirectional CBO at namespace
                    // level conflates Ca with Ce, which unfairly penalizes stable-contracts namespaces
                    // (high incoming, low outgoing). We rely on per-class Ce aggregates plus the
                    // namespace's own Ce (count of distinct external classes the namespace as a
                    // whole depends on). Distance from main sequence stays as a structural penalty.
                    // Terms: distance (Martin), per-class breadth (ce_packages.avg + sqrt(ce.avg))
                    // with -4 threshold (more lenient than class-level -5 since avg dilutes),
                    // worst-case class outlier (ce.max), and namespace-level breadth (ce) with a
                    // high threshold (50) so umbrella namespaces aren't disproportionately penalized.
                    SymbolLevel::Namespace_->value => 'clamp(100 * 18 / (18 + (m["coupling.distance"] ?? 0) * 6 + max((m["coupling.ce-packages.avg"] ?? 0) * 3.0 + (m["coupling.ce.avg"] ?? 0) ** 0.5 * 0.5 - 4, 0) * 4 + max((m["coupling.ce.max"] ?? 0) - 30, 0) ** 0.5 * 0.8 + max((m["coupling.ce"] ?? 0) - 50, 0) ** 0.5 * 0.6), 0, 100)',
                    // Project formula keeps bidirectional CBO aggregates: at project level Σ Ca = Σ Ce
                    // (every internal edge contributes to both), so cbo.avg is symmetric and
                    // proportional to ce.avg. Calibrated against 11 benchmark projects
                    // (Guzzle→92, Sf Console→64, Qualimetrix→53, Laravel→53, Composer→32).
                    SymbolLevel::Project->value => 'clamp(100 * 18 / (18 + (m["coupling.distance.avg"] ?? 0) * 6 + max((m["coupling.cbo.avg"] ?? 0) - 8, 0) * 3 + max((m["coupling.cbo.p95"] ?? 0) - 15, 0) * 0.4 + max((m["coupling.cbo.max"] ?? 0) - 30, 0) ** 0.5 * 0.8), 0, 100)',
                ],
                description: 'Coupling health score (0-100, higher is better)',
                levels: [SymbolLevel::Class_, SymbolLevel::Namespace_, SymbolLevel::Project],
                inverted: true,
                warningThreshold: 50.0,
                errorThreshold: 25.0,
            ),
            HealthDimension::Typing->value => new ComputedMetricDefinition(
                name: HealthDimension::Typing->value,
                formulas: [
                    SymbolLevel::Class_->value => 'clamp(m["design.type-coverage.pct"] ?? 0, 0, 100)',
                    // Vacuous truth: a namespace with no typeable declarations (e.g. marker
                    // interfaces) is fully typed by definition. Mirrors class-level behavior
                    // where TypeCoveragePercentCollector returns 100 when totalAll == 0,
                    // and aligns with the convention in sibling formulas where an empty
                    // namespace yields a high (no-problem) score.
                    SymbolLevel::Namespace_->value => '(((m["design.type-coverage.param.total.sum"] ?? 0) + (m["design.type-coverage.return.total.sum"] ?? 0) + (m["design.type-coverage.property.total.sum"] ?? 0)) == 0) ? 100 : clamp(((m["design.type-coverage.param.typed.sum"] ?? 0) + (m["design.type-coverage.return.typed.sum"] ?? 0) + (m["design.type-coverage.property.typed.sum"] ?? 0)) / ((m["design.type-coverage.param.total.sum"] ?? 0) + (m["design.type-coverage.return.total.sum"] ?? 0) + (m["design.type-coverage.property.total.sum"] ?? 0)) * 100, 0, 100)',
                ],
                description: 'Type coverage health score (0-100, higher is better)',
                levels: [SymbolLevel::Class_, SymbolLevel::Namespace_, SymbolLevel::Project],
                inverted: true,
                warningThreshold: 80.0,
                errorThreshold: 50.0,
            ),
            HealthDimension::Maintainability->value => new ComputedMetricDefinition(
                name: HealthDimension::Maintainability->value,
                formulas: [
                    // Penalty-based: avg detects uniformly poor MI, sqrt(min) penalizes worst methods.
                    // MI=85→100, MI=75/min=50→85, MI=65/min=30→57.
                    SymbolLevel::Class_->value => 'clamp(100 - max(85 - (m["maintainability.mi.avg"] ?? 75), 0) * 1.5 - max(50 - (m["maintainability.mi.min"] ?? 50), 0) ** 0.5 * 3.0, 0, 100)',
                    // avg (base quality) + p5 (main differentiator) + dampened min (extreme outliers).
                    // p5/min thresholds calibrated for per-method MI values (not class-level averages).
                    SymbolLevel::Namespace_->value => 'clamp(100 - max(82 - (m["maintainability.mi.avg"] ?? 75), 0) * 2.0 - max(55 - (m["maintainability.mi.p5"] ?? 55), 0) ** 0.5 * 4.5 - max(5 - (m["maintainability.mi.min"] ?? 5), 0) ** 0.4 * 1.5, 0, 100)',
                    // Project: same structure as namespace, explicit to avoid inherited formula drift.
                    SymbolLevel::Project->value => 'clamp(100 - max(82 - (m["maintainability.mi.avg"] ?? 75), 0) * 2.0 - max(55 - (m["maintainability.mi.p5"] ?? 55), 0) ** 0.5 * 4.5 - max(5 - (m["maintainability.mi.min"] ?? 5), 0) ** 0.4 * 1.5, 0, 100)',
                ],
                description: 'Maintainability health score (0-100, higher is better)',
                levels: [SymbolLevel::Class_, SymbolLevel::Namespace_, SymbolLevel::Project],
                inverted: true,
                warningThreshold: 50.0,
                errorThreshold: 25.0,
            ),
            HealthDimension::Overall->value => new ComputedMetricDefinition(
                name: HealthDimension::Overall->value,
                formulas: [
                    // Maintainability excluded at class level: MI is callable-level,
                    // and its signal is already captured by complexity and cohesion sub-scores.
                    // Typing weight reduced from 0.20→0.15 (inflates legacy code scores).
                    SymbolLevel::Class_->value => 'clamp((m["health.complexity"] ?? 75) * 0.35 + (m["health.cohesion"] ?? 75) * 0.25 + (m["health.coupling"] ?? 75) * 0.25 + (m["health.typing"] ?? 75) * 0.15, 0, 100)',
                    SymbolLevel::Namespace_->value => 'clamp((m["health.complexity"] ?? 75) * 0.30 + (m["health.cohesion"] ?? 75) * 0.20 + (m["health.coupling"] ?? 75) * 0.20 + (m["health.typing"] ?? 75) * 0.10 + (m["health.maintainability"] ?? 75) * 0.20, 0, 100)',
                ],
                description: 'Overall health score (0-100, higher is better)',
                levels: [SymbolLevel::Class_, SymbolLevel::Namespace_, SymbolLevel::Project],
                inverted: true,
                warningThreshold: 50.0,
                errorThreshold: 30.0,
            ),
        ];
    }

    private function __construct() {}
}
