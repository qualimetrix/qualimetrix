<?php

declare(strict_types=1);

namespace AiMessDetector\Core\ComputedMetric;

use AiMessDetector\Core\Symbol\SymbolType;

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
                    'class' => 'clamp(100 - max(ccn__avg - 4, 0) * 2.0 - max(cognitive__avg - 5, 0) * 2.0 - max((ccn__max ?? 0) - 10, 0) ** 0.5 * 2.0 - max((cognitive__max ?? 0) - 10, 0) ** 0.5 * 2.0, 0, 100)',
                    // Namespace: avg (base quality) + p95 (main differentiator) + sqrt(max) (extreme outliers).
                    // Calibrated against 11 benchmarks: Flysystem→100, PHPUnit→87, AIMD→76, Doctrine→64, Composer→39.
                    'namespace' => 'clamp(100 - max((ccn__sum ?? 0) / max(symbolMethodCount, 1) - 3, 0) * 1.5 - max((cognitive__sum ?? 0) / max(symbolMethodCount, 1) - 4, 0) * 1.5 - max((ccn__p95 ?? 0) - 25, 0) ** 0.5 * 2.0 - max((cognitive__p95 ?? 0) - 20, 0) ** 0.5 * 2.0 - max((ccn__max ?? 0) - 80, 0) ** 0.5 * 0.4, 0, 100)',
                    // Project: same structure as namespace, explicit to avoid inherited formula drift.
                    'project' => 'clamp(100 - max((ccn__sum ?? 0) / max(symbolMethodCount, 1) - 3, 0) * 1.5 - max((cognitive__sum ?? 0) / max(symbolMethodCount, 1) - 4, 0) * 1.5 - max((ccn__p95 ?? 0) - 25, 0) ** 0.5 * 2.0 - max((cognitive__p95 ?? 0) - 20, 0) ** 0.5 * 2.0 - max((ccn__max ?? 0) - 80, 0) ** 0.5 * 0.4, 0, 100)',
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
                    // sqrt(tcc) rescales typical TCC range (0.2–0.6) into a wider health range.
                    // Linear tcc*50 capped real projects at ~73; sqrt allows Gold tier to reach 80+.
                    'class' => 'clamp(((methodCount ?? 0) < 6 ? (tcc ?? 0.5) : (tcc ?? 0)) ** 0.5 * 50 + (1 - clamp(((lcom ?? 0) - 1) / 5, 0, 1)) * 50, 0, 100)',
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
                    'class' => 'clamp(100 * 15 / (15 + max((ce ?? 0) - 5, 0)), 0, 100)',
                    // K=18, cbo_avg threshold=8, cbo_p95 threshold=15, sqrt-scaled max penalty.
                    // Calibrated against 11 benchmark projects (Guzzle→92, Sf Console→64, AIMD→53, Laravel→53, Composer→32).
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
                    'namespace' => 'clamp((typeCoverage__paramTyped__sum + typeCoverage__returnTyped__sum + typeCoverage__propertyTyped__sum) / max(typeCoverage__paramTotal__sum + typeCoverage__returnTotal__sum + typeCoverage__propertyTotal__sum, 1) * 100, 0, 100)',
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
                    // Calibrated against 13 benchmarks: Flysystem→100, PHPUnit→73, Sf-DI→48, Composer→51.
                    'namespace' => 'clamp(100 - max(82 - (mi__avg ?? 75), 0) * 2.0 - max(65 - (mi__p5 ?? 65), 0) ** 0.5 * 6.0 - max(40 - (mi__min ?? 40), 0) ** 0.4 * 2.0, 0, 100)',
                    // Project: same structure as namespace, explicit to avoid inherited formula drift.
                    'project' => 'clamp(100 - max(82 - (mi__avg ?? 75), 0) * 2.0 - max(65 - (mi__p5 ?? 65), 0) ** 0.5 * 6.0 - max(40 - (mi__min ?? 40), 0) ** 0.4 * 2.0, 0, 100)',
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
                    'class' => 'clamp((health__complexity ?? 75) * 0.30 + (health__cohesion ?? 75) * 0.25 + (health__coupling ?? 75) * 0.25 + (health__typing ?? 75) * 0.20, 0, 100)',
                    'namespace' => 'clamp((health__complexity ?? 75) * 0.25 + (health__cohesion ?? 75) * 0.20 + (health__coupling ?? 75) * 0.20 + (health__typing ?? 75) * 0.15 + (health__maintainability ?? 75) * 0.20, 0, 100)',
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
