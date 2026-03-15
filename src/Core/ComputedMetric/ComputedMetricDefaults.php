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
                    'class' => 'clamp(100 - max(ccn__avg - 4, 0) * 2.0 - max(cognitive__avg - 5, 0) * 2.5 - min(max(npath__avg ?? 0, 0) / 20, 20) * 0.5, 0, 100)',
                    'namespace' => 'clamp(100 - max(ccn__avg - 4, 0) * 2.0 - max(cognitive__avg - 5, 0) * 2.5 - min(max(npath__avg ?? 0, 0) / 20, 20) * 0.5, 0, 100)',
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
                    'class' => 'clamp((tcc ?? 0) * 50 + (1 - clamp(((lcom ?? 0) - 1) / 5, 0, 1)) * 50, 0, 100)',
                    'namespace' => 'clamp((tcc__avg ?? 0) * 50 + (1 - clamp(((lcom__avg ?? 0) - 1) / 5, 0, 1)) * 50, 0, 100)',
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
                    'namespace' => 'clamp(100 * 12 / (12 + (distance ?? 0) * 6.5 + max((cbo__avg ?? 0) - 7, 0) * 4 + max((cbo__max ?? 0) - 20, 0) * 0.15), 0, 100)',
                    'project' => 'clamp(100 * 12 / (12 + (distance__avg ?? 0) * 6.5 + max((cbo__avg ?? 0) - 7, 0) * 4 + max((cbo__max ?? 0) - 20, 0) * 0.15), 0, 100)',
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
                    'class' => 'clamp(mi__avg ?? 0, 0, 100)',
                    'namespace' => 'clamp(mi__avg ?? 0, 0, 100)',
                ],
                description: 'Maintainability health score (0-100, higher is better)',
                levels: [SymbolType::Class_, SymbolType::Namespace_, SymbolType::Project],
                inverted: true,
                warningThreshold: 65.0,
                errorThreshold: 50.0,
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
