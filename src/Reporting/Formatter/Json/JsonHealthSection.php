<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Json;

use LogicException;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\HealthDimension;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\DecompositionItem;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthContributor;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthCoverage;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthScore;
use Qualimetrix\Core\Pattern\SelectorKind;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\Health\HealthScoreResolver;
use Qualimetrix\Reporting\Report;

final class JsonHealthSection
{
    public function __construct(
        private readonly HealthScoreResolver $healthResolver,
        private readonly JsonSanitizer $sanitizer,
    ) {}

    /**
     * Resolves and formats health scores for JSON output.
     *
     * @return array<string, array<string, mixed>>|null
     */
    public function format(Report $report, FormatterContext $context): ?array
    {
        $healthScores = $this->healthResolver->resolve($report, $context);
        if ($healthScores === []) {
            if ($context->namespace !== null) {
                return null;
            }
        }

        if ($context->namespace !== null
            && $context->namespace->definition->kind === SelectorKind::Exact
            && $report->metrics !== null) {
            return $this->formatExactNamespaceHealth($report, $context, $healthScores);
        }

        return $this->formatHealthScores($healthScores, $context);
    }

    /**
     * @param array<string, HealthScore> $healthScores
     *
     * @return array<string, array<string, mixed>>|null
     */
    private function formatExactNamespaceHealth(Report $report, FormatterContext $context, array $healthScores): ?array
    {
        $namespace = $context->namespace ?? throw new LogicException('Exact namespace health requires a namespace selector');
        $metrics = $report->metrics ?? throw new LogicException('Exact namespace health requires metrics');
        $flatOverall = $metrics
            ->get(SymbolPath::forNamespace($namespace->definition->value))
            ->get(HealthDimension::Overall->value);
        $result = $this->formatHealthScores($healthScores, $context);

        if ($result === null || $flatOverall === null) {
            return $result;
        }

        $recursiveScore = isset($result['overall']['score']) ? (float) $result['overall']['score'] : null;
        $flatScore = $this->sanitizer->sanitizeFloat((float) $flatOverall);
        if ($recursiveScore !== null && $flatScore !== null && abs($recursiveScore - $flatScore) > 5.0) {
            $result['overall']['scope'] = 'recursive';
            $result['overall']['directScore'] = $flatScore;
        }

        return $result;
    }

    /**
     * @param array<string, HealthScore> $healthScores
     *
     * @return array<string, array<string, mixed>>|null
     */
    private function formatHealthScores(array $healthScores, FormatterContext $context): ?array
    {
        if ($healthScores === []) {
            return null;
        }

        $result = [];
        foreach ($healthScores as $name => $hs) {
            $result[$name] = [
                'score' => $hs->score !== null ? $this->sanitizer->sanitizeFloat($hs->score) : null,
                'label' => $hs->label,
                'threshold' => [
                    'warning' => $this->sanitizer->sanitizeFloat($hs->warningThreshold),
                    'error' => $this->sanitizer->sanitizeFloat($hs->errorThreshold),
                ],
                'coverage' => $this->formatCoverage($hs->coverage),
                'decomposition' => array_map(
                    fn(DecompositionItem $item): array => [
                        'metric' => $item->metricKey,
                        'humanName' => $item->humanName,
                        'value' => $this->sanitizer->sanitizeFloat($item->value),
                        'good' => $item->goodValue,
                        'direction' => $item->direction,
                    ],
                    $hs->decomposition,
                ),
                'worstContributors' => array_map(
                    fn(HealthContributor $c): array => [
                        'className' => $c->className,
                        'symbolPath' => $c->symbolPath,
                        'metrics' => $this->sanitizeMetricValues($c->metricValues),
                    ],
                    $hs->worstContributors,
                ),
            ];
        }

        return $result;
    }

    /**
     * What share of the subject the score was computed over.
     *
     * The two states carry the same keys, so a consumer reads `state` instead
     * of inferring absence from a zero: an undefined coverage and a coverage
     * of nothing are different claims about the subject.
     *
     * @return array<string, mixed>
     */
    private function formatCoverage(HealthCoverage $coverage): array
    {
        return [
            'state' => $coverage->applicable ? 'measured' : 'not-applicable',
            'measured' => $coverage->measured,
            'eligible' => $coverage->eligible,
            'ratio' => $coverage->ratio !== null ? $this->sanitizer->sanitizeFloat($coverage->ratio) : null,
            'unit' => $coverage->unit?->value,
            'basis' => $coverage->basis,
            'reason' => $coverage->reason,
        ];
    }

    /**
     * @param array<string, float|int> $values
     *
     * @return array<string, float|int|null>
     */
    private function sanitizeMetricValues(array $values): array
    {
        $result = [];

        foreach ($values as $key => $value) {
            $result[$key] = \is_float($value) ? $this->sanitizer->sanitizeFloat($value) : $value;
        }

        return $result;
    }
}
