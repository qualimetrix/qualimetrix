<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Json;

use Qualimetrix\Analysis\Evidence\Prioritization\Debt\RemediationTimeRegistry;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Reporting\FormatterContext;

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
     * Sorts violations by their stable identity projection.
     *
     * @param list<Violation> $violations
     *
     * @return list<Violation>
     */
    public function sort(array $violations): array
    {
        usort($violations, static fn(Violation $a, Violation $b): int => self::identitySortKey($a) <=> self::identitySortKey($b));

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
        $file = $violation->location->file === null
            ? null
            : $context->relativizePath($violation->location->file);

        return [
            'file' => $file,
            'line' => $violation->location->line,
            'subject' => $violation->subject->toCanonical(),
            'symbol' => $violation->symbolPath->toString(),
            'channel' => $violation->channel()->toKey(),
            'occurrence' => $violation->occurrenceKey?->value,
            'edge' => self::formatEdge($violation),
            'namespace' => $ns !== '' ? $ns : null,
            'rule' => $violation->ruleName,
            'code' => $violation->violationCode,
            'severity' => $violation->severity->value,
            'message' => $violation->message,
            'recommendation' => $violation->recommendation,
            'metricValue' => $this->sanitizer->sanitizeNumeric($violation->metricValue),
            'threshold' => $this->sanitizer->sanitizeNumeric($violation->threshold),
            'techDebtMinutes' => $this->remediationTimeRegistry->getMinutesForViolation($violation),
            'acceptedLevel' => $this->formatAcceptedLevel($violation),
        ];
    }

    /**
     * Structured form of the accepted level a measured breach carries under ADR 0017 —
     * `null` on every other violation, including one no baseline ever
     * judged. `describe` is the human string (e.g. "25" or "3 occurrences");
     * `now` reuses the sibling `metricValue` field on purpose — an
     * `occurrence` channel has no per-finding "now" to report, so this
     * object never fabricates one.
     *
     * @return ?array{shape: string, describe: string, count: int}
     */
    private function formatAcceptedLevel(Violation $violation): ?array
    {
        $accepted = $violation->acceptedLevel;

        if ($accepted === null) {
            return null;
        }

        return [
            'shape' => $accepted->shape()->value,
            'describe' => $accepted->describe(),
            'count' => $accepted->count,
        ];
    }

    /**
     * @return ?array{target: string, type?: string}
     */
    private static function formatEdge(Violation $violation): ?array
    {
        if ($violation->dependencyTarget === null) {
            return null;
        }

        $target = $violation->dependencyTarget->toCanonical();
        if ($violation->dependencyType === null) {
            return ['target' => $target];
        }

        return [
            'type' => $violation->dependencyType->value,
            'target' => $target,
        ];
    }

    /**
     * @return array{string, string, string, int, string, string}
     */
    private static function identitySortKey(Violation $violation): array
    {
        $edge = self::formatEdge($violation);

        return [
            $violation->channel()->toKey(),
            $violation->subject->toCanonical(),
            $violation->occurrenceKey === null ? '' : $violation->occurrenceKey->value,
            $edge === null ? 0 : 1,
            $edge['type'] ?? '',
            $edge['target'] ?? '',
        ];
    }
}
