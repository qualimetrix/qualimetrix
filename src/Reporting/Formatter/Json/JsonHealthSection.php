<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Json;

use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\Health\DecompositionItem;
use Qualimetrix\Reporting\Health\HealthScore;
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
        if ($context->partialAnalysis) {
            return null;
        }

        $healthScores = $this->healthResolver->resolve($report, $context);
        if ($healthScores === []) {
            if ($context->namespace !== null) {
                return null;
            }
        }

        if ($context->namespace !== null && $report->metrics !== null) {
            $nsPath = SymbolPath::forNamespace($context->namespace);
            $flatOverall = $report->metrics->get($nsPath)->get('health.overall');
            $result = $this->formatHealthScores($healthScores, $context);
            if ($result !== null && $flatOverall !== null) {
                $recursiveScore = $result['overall']['score'] ?? null;
                $flatScore = $this->sanitizer->sanitizeFloat((float) $flatOverall);
                if ($recursiveScore !== null && abs($recursiveScore - $flatScore) > 5.0) {
                    $result['overall']['scope'] = 'recursive';
                    $result['overall']['directScore'] = $flatScore;
                }
            }

            return $result;
        }

        return $this->formatHealthScores($healthScores, $context);
    }

    /**
     * @param array<string, HealthScore> $healthScores
     *
     * @return array<string, array<string, mixed>>|null
     */
    private function formatHealthScores(array $healthScores, FormatterContext $context): ?array
    {
        if ($context->partialAnalysis || $healthScores === []) {
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
            ];
        }

        return $result;
    }
}
