<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting\Formatter\Json;

use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\Debt\RemediationTimeRegistry;
use AiMessDetector\Reporting\FormatterContext;

final class JsonViolationSection
{
    public function __construct(
        private readonly RemediationTimeRegistry $remediationTimeRegistry,
        private readonly JsonSanitizer $sanitizer,
    ) {}

    /**
     * Formats an array of violations for JSON output.
     *
     * @param list<Violation> $violations
     *
     * @return list<array<string, mixed>>
     */
    public function format(array $violations, FormatterContext $context): array
    {
        return array_map(
            fn(Violation $v): array => $this->formatViolation($v, $context),
            $violations,
        );
    }

    /**
     * Sorts violations by severity (errors first), then by exceedance (descending),
     * then by file/line/code.
     *
     * @param list<Violation> $violations
     *
     * @return list<Violation>
     */
    public function sort(array $violations): array
    {
        usort($violations, static function (Violation $a, Violation $b): int {
            $severityOrder = ($a->severity === Severity::Error ? 0 : 1) <=> ($b->severity === Severity::Error ? 0 : 1);
            if ($severityOrder !== 0) {
                return $severityOrder;
            }

            $exceedA = self::getExceedance($a);
            $exceedB = self::getExceedance($b);
            $exceedOrder = $exceedB <=> $exceedA;
            if ($exceedOrder !== 0) {
                return $exceedOrder;
            }

            return ($a->location->file <=> $b->location->file)
                ?: ($a->location->line <=> $b->location->line)
                ?: ($a->violationCode <=> $b->violationCode);
        });

        return $violations;
    }

    /**
     * Counts violations grouped by rule name.
     *
     * @param list<Violation> $violations
     *
     * @return array<string, int>
     */
    public function countByRule(array $violations): array
    {
        $counts = [];

        foreach ($violations as $violation) {
            $rule = $violation->ruleName;
            $counts[$rule] = ($counts[$rule] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatViolation(Violation $violation, FormatterContext $context): array
    {
        $ns = $violation->symbolPath->namespace ?? '';
        $file = $violation->location->isNone()
            ? null
            : $context->relativizePath($violation->location->file);

        return [
            'file' => $file,
            'line' => $violation->location->line,
            'symbol' => $violation->symbolPath->toString(),
            'namespace' => $ns !== '' ? $ns : null,
            'rule' => $violation->ruleName,
            'code' => $violation->violationCode,
            'severity' => $violation->severity->value,
            'message' => $violation->message,
            'recommendation' => $violation->recommendation,
            'metricValue' => $this->sanitizer->sanitizeNumeric($violation->metricValue),
            'threshold' => $this->sanitizer->sanitizeNumeric($violation->threshold),
            'techDebtMinutes' => $this->remediationTimeRegistry->getMinutesForViolation($violation),
        ];
    }

    private static function getExceedance(Violation $v): float
    {
        if ($v->metricValue === null || $v->threshold === null) {
            return 0.0;
        }

        $val = (float) $v->metricValue;
        $thr = (float) $v->threshold;

        if (!is_finite($val) || !is_finite($thr)) {
            return 0.0;
        }

        return abs($val - $thr);
    }
}
