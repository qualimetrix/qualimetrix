<?php

declare(strict_types=1);

namespace Qualimetrix\Core\ComputedMetric;

use Qualimetrix\Core\Symbol\SymbolType;

final class ComputedMetricDefaults
{
    /**
     * @return array<string, ComputedMetricDefinition>
     */
    public static function getDefaults(): array
    {
        return [
            'health.complexity' => new ComputedMetricDefinition(
                name: 'health.complexity',
                formulas: [
                    // Class: avg + max-method penalties. avg detects uniformly complex classes,
                    // sqrt(max) penalizes single monster methods that hide behind a low average.
                    // Defaults: ccn=1 (baseline for methodless classes), cognitive=0 (no penalty).
                    'class' => 'clamp(100 - max((ccn__avg ?? 1) - 4, 0) * 2.0 - max((cognitive__avg ?? 0) - 5, 0) * 2.0 - max((ccn__max ?? 0) - 10, 0) ** 0.5 * 2.0 - max((cognitive__max ?? 0) - 10, 0) ** 0.5 * 2.0, 0, 100)',
                    // Namespace: avg (base quality) + p95 (main differentiator) + sqrt(max) (extreme outliers).
                    // p95/max thresholds calibrated for per-method values (not per-class sums).
                    'namespace' => 'clamp(100 - max((ccn__sum ?? 0) / max(symbolMethodCount, 1) - 3, 0) * 1.5 - max((cognitive__sum ?? 0) / max(symbolMethodCount, 1) - 4, 0) * 1.5 - max((ccn__p95 ?? 0) - 5, 0) ** 0.5 * 3.0 - max((cognitive__p95 ?? 0) - 6, 0) ** 0.5 * 3.0 - max((ccn__max ?? 0) - 20, 0) ** 0.5 * 0.8, 0, 100)',
                    // Project: same structure as namespace, explicit to avoid inherited formula drift.
                    'project' => 'clamp(100 - max((ccn__sum ?? 0) / max(symbolMethodCount, 1) - 3, 0) * 1.5 - max((cognitive__sum ?? 0) / max(symbolMethodCount, 1) - 4, 0) * 1.5 - max((ccn__p95 ?? 0) - 5, 0) ** 0.5 * 3.0 - max((cognitive__p95 ?? 0) - 6, 0) ** 0.5 * 3.0 - max((ccn__max ?? 0) - 20, 0) ** 0.5 * 0.8, 0, 100)',
                ],
                description: 'Complexity health score (0-100, higher is better)',
                levels: [SymbolType::Class_, SymbolType::Namespace_, SymbolType::Project],
                inverted: true,
                warningThreshold: 50.0,
                errorThreshold: 25.0,
            ),
            'health.cohesion' => new ComputedMetricDefinition(
                name: 'health.cohesion',
                formulas: [
                    // Pure methods (no property access, e.g. interface contract getters) inflate
                    // TCC denominator and LCOM. Adjust both: boost TCC proportionally, reduce LCOM.
                    // D_tcc=0.4, D_lcom=0.7. Classes with no pure methods: formula unchanged.
                    'class' => 'clamp((((methodCount ?? 0) < 6 ? (tcc ?? 0.5) : (tcc ?? 0)) + (1 - ((methodCount ?? 0) < 6 ? (tcc ?? 0.5) : (tcc ?? 0))) * ((pureMethodCount_cohesion ?? 0) / max(methodCount ?? 1, 1)) * 0.4) ** 0.5 * 50 + (1 - clamp((max((lcom ?? 0) - (pureMethodCount_cohesion ?? 0) * 0.7, 1) - 1) / 5, 0, 1)) * 50, 0, 100)',
                    'namespace' => 'clamp((tcc__avg ?? 0.5) ** 0.5 * 50 + (1 - clamp(((lcom__avg ?? 0) - 1) / 5, 0, 1)) * 50, 0, 100)',
                ],
                description: 'Cohesion health score (0-100, higher is better)',
                levels: [SymbolType::Class_, SymbolType::Namespace_, SymbolType::Project],
                inverted: true,
                warningThreshold: 50.0,
                errorThreshold: 25.0,
            ),
            'health.coupling' => new ComputedMetricDefinition(
                name: 'health.coupling',
                formulas: [
                    // Blend ce_packages (dependency breadth) with dampened ce (volume).
                    // K=15, W_pkg=3.0, W_raw=0.5, threshold=5.
                    // HalsteadVisitor (ce=127, pkg≈1): ~80. ShowCommand (ce=43, pkg≈15): ~26.
                    'class' => 'clamp(100 * 15 / (15 + max((ce_packages ?? 0) * 3.0 + (ce ?? 0) ** 0.5 * 0.5 - 5, 0)), 0, 100)',
                    // K=18, cbo_avg threshold=8, cbo_p95 threshold=15, sqrt-scaled max penalty.
                    // Calibrated against 11 benchmark projects (Guzzle→92, Sf Console→64, Qualimetrix→53, Laravel→53, Composer→32).
                    'namespace' => 'clamp(100 * 18 / (18 + (distance ?? 0) * 6 + max((cbo__avg ?? 0) - 8, 0) * 3 + max((cbo__p95 ?? 0) - 15, 0) * 0.4 + max((cbo__max ?? 0) - 30, 0) ** 0.5 * 0.8), 0, 100)',
                    'project' => 'clamp(100 * 18 / (18 + (distance__avg ?? 0) * 6 + max((cbo__avg ?? 0) - 8, 0) * 3 + max((cbo__p95 ?? 0) - 15, 0) * 0.4 + max((cbo__max ?? 0) - 30, 0) ** 0.5 * 0.8), 0, 100)',
                ],
                description: 'Coupling health score (0-100, higher is better)',
                levels: [SymbolType::Class_, SymbolType::Namespace_, SymbolType::Project],
                inverted: true,
                warningThreshold: 50.0,
                errorThreshold: 25.0,
            ),
            'health.typing' => new ComputedMetricDefinition(
                name: 'health.typing',
                formulas: [
                    'class' => 'clamp(typeCoverage__pct ?? 0, 0, 100)',
                    'namespace' => 'clamp(((typeCoverage__paramTyped__sum ?? 0) + (typeCoverage__returnTyped__sum ?? 0) + (typeCoverage__propertyTyped__sum ?? 0)) / max((typeCoverage__paramTotal__sum ?? 0) + (typeCoverage__returnTotal__sum ?? 0) + (typeCoverage__propertyTotal__sum ?? 0), 1) * 100, 0, 100)',
                ],
                description: 'Type coverage health score (0-100, higher is better)',
                levels: [SymbolType::Class_, SymbolType::Namespace_, SymbolType::Project],
                inverted: true,
                warningThreshold: 80.0,
                errorThreshold: 50.0,
            ),
            'health.maintainability' => new ComputedMetricDefinition(
                name: 'health.maintainability',
                formulas: [
                    // Penalty-based: avg detects uniformly poor MI, sqrt(min) penalizes worst methods.
                    // MI=85→100, MI=75/min=50→85, MI=65/min=30→57.
                    'class' => 'clamp(100 - max(85 - (mi__avg ?? 75), 0) * 1.5 - max(50 - (mi__min ?? 50), 0) ** 0.5 * 3.0, 0, 100)',
                    // avg (base quality) + p5 (main differentiator) + dampened min (extreme outliers).
                    // p5/min thresholds calibrated for per-method MI values (not class-level averages).
                    'namespace' => 'clamp(100 - max(82 - (mi__avg ?? 75), 0) * 2.0 - max(55 - (mi__p5 ?? 55), 0) ** 0.5 * 4.5 - max(5 - (mi__min ?? 5), 0) ** 0.4 * 1.5, 0, 100)',
                    // Project: same structure as namespace, explicit to avoid inherited formula drift.
                    'project' => 'clamp(100 - max(82 - (mi__avg ?? 75), 0) * 2.0 - max(55 - (mi__p5 ?? 55), 0) ** 0.5 * 4.5 - max(5 - (mi__min ?? 5), 0) ** 0.4 * 1.5, 0, 100)',
                ],
                description: 'Maintainability health score (0-100, higher is better)',
                levels: [SymbolType::Class_, SymbolType::Namespace_, SymbolType::Project],
                inverted: true,
                warningThreshold: 50.0,
                errorThreshold: 25.0,
            ),
            'health.overall' => new ComputedMetricDefinition(
                name: 'health.overall',
                formulas: [
                    // Maintainability excluded at class level: MI is method-level,
                    // and its signal is already captured by complexity and cohesion sub-scores.
                    // Typing weight reduced from 0.20→0.15 (inflates legacy code scores).
                    'class' => 'clamp((health__complexity ?? 75) * 0.35 + (health__cohesion ?? 75) * 0.25 + (health__coupling ?? 75) * 0.25 + (health__typing ?? 75) * 0.15, 0, 100)',
                    'namespace' => 'clamp((health__complexity ?? 75) * 0.30 + (health__cohesion ?? 75) * 0.20 + (health__coupling ?? 75) * 0.20 + (health__typing ?? 75) * 0.10 + (health__maintainability ?? 75) * 0.20, 0, 100)',
                ],
                description: 'Overall health score (0-100, higher is better)',
                levels: [SymbolType::Class_, SymbolType::Namespace_, SymbolType::Project],
                inverted: true,
                warningThreshold: 50.0,
                errorThreshold: 30.0,
            ),
        ];
    }

    private function __construct() {}
}
