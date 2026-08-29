<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Json;

use Qualimetrix\Analysis\Evidence\Prioritization\Debt\RemediationTimeRegistry;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Reporting\FormatterContext;

final class JsonFindingSection
{
    public function __construct(
        private readonly RemediationTimeRegistry $remediationTimeRegistry,
        private readonly JsonSanitizer $sanitizer,
    ) {}

    /**
     * Formats an array of findings for JSON output.
     *
     * @param list<Finding> $findings
     *
     * @return list<array<string, mixed>>
     */
    public function format(array $findings, FormatterContext $context): array
    {
        return array_map(
            fn(Finding $v): array => $this->formatFinding($v, $context),
            $findings,
        );
    }

    /**
     * Sorts findings by their stable identity projection.
     *
     * @param list<Finding> $findings
     *
     * @return list<Finding>
     */
    public function sort(array $findings): array
    {
        usort($findings, static fn(Finding $a, Finding $b): int => self::identitySortKey($a) <=> self::identitySortKey($b));

        return $findings;
    }

    /**
     * Counts findings grouped by rule name.
     *
     * @param list<Finding> $findings
     *
     * @return array<string, int>
     */
    public function countByRule(array $findings): array
    {
        $counts = [];

        foreach ($findings as $finding) {
            $rule = $finding->ruleName;
            $counts[$rule] = ($counts[$rule] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatFinding(Finding $finding, FormatterContext $context): array
    {
        $ns = $finding->symbolPath->namespace ?? '';
        $file = $finding->location->file === null
            ? null
            : $context->relativizePath($finding->location->file);

        return [
            'file' => $file,
            'line' => $finding->location->line,
            'subject' => $finding->subject->toCanonical(),
            'symbol' => $finding->symbolPath->toString(),
            'channel' => $finding->channel()->code,
            'occurrence' => $finding->occurrenceKey?->value,
            'edge' => self::formatEdge($finding),
            'namespace' => $ns !== '' ? $ns : null,
            'rule' => $finding->ruleName,
            'code' => $finding->code,
            'severity' => $finding->severity->value,
            'message' => $finding->message,
            'recommendation' => $finding->recommendation,
            'metricValue' => $this->sanitizer->sanitizeNumeric($finding->metricValue),
            'threshold' => $this->sanitizer->sanitizeNumeric($finding->threshold),
            'techDebtMinutes' => $this->remediationTimeRegistry->getMinutesForFinding($finding),
            'acceptedLevel' => $this->formatAcceptedLevel($finding),
        ];
    }

    /**
     * Structured form of the accepted level a measured breach carries under ADR 0017 —
     * `null` on every other finding, including one no baseline ever
     * judged. `describe` is the human string (e.g. "25" or "3 occurrences");
     * `now` reuses the sibling `metricValue` field on purpose — an
     * `occurrence` channel has no per-finding "now" to report, so this
     * object never fabricates one.
     *
     * @return ?array{shape: string, describe: string, count: int}
     */
    private function formatAcceptedLevel(Finding $finding): ?array
    {
        $accepted = $finding->acceptedLevel;

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
    private static function formatEdge(Finding $finding): ?array
    {
        if ($finding->dependencyTarget === null) {
            return null;
        }

        $target = $finding->dependencyTarget->toCanonical();
        if ($finding->dependencyType === null) {
            return ['target' => $target];
        }

        return [
            'type' => $finding->dependencyType->value,
            'target' => $target,
        ];
    }

    /**
     * @return array{string, string, string, int, string, string}
     */
    private static function identitySortKey(Finding $finding): array
    {
        $edge = self::formatEdge($finding);

        return [
            $finding->channel()->code,
            $finding->subject->toCanonical(),
            $finding->occurrenceKey === null ? '' : $finding->occurrenceKey->value,
            $edge === null ? 0 : 1,
            $edge['type'] ?? '',
            $edge['target'] ?? '',
        ];
    }
}
