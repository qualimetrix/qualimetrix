<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Json;

use Qualimetrix\Analysis\Evidence\Prioritization\Debt\RemediationTimeRegistry;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Reporting\Formatter\FindingRecord;
use Qualimetrix\Reporting\Formatter\PublishedFinding;
use Qualimetrix\Reporting\FormatterContext;

final class JsonFindingSection
{
    private readonly FindingRecord $record;

    public function __construct(
        RemediationTimeRegistry $remediationTimeRegistry,
        JsonSanitizer $sanitizer,
    ) {
        $this->record = new FindingRecord($remediationTimeRegistry, $sanitizer);
    }

    /**
     * Formats an array of findings for JSON output.
     *
     * @param list<Finding> $findings
     *
     * @return list<array<string, mixed>>
     */
    public function format(array $findings, FormatterContext $context, \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex $fileNamespaces): array
    {
        return array_map(
            fn(Finding $v): array => $this->formatFinding($v, $context, $fileNamespaces),
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
     * @param list<Finding> $findings
     *
     * @return array{error: int, warning: int, info: int}
     */
    public function countBySeverity(array $findings): array
    {
        $counts = ['error' => 0, 'warning' => 0, 'info' => 0];
        foreach ($findings as $finding) {
            $counts[$finding->severity->value]++;
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    public function formatFinding(Finding $finding, FormatterContext $context, \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex $fileNamespaces): array
    {
        return $this->record->of($finding, $context, $fileNamespaces);
    }

    /**
     * @return array{string, string, string, int, string, string}
     */
    private static function identitySortKey(Finding $finding): array
    {
        $edge = PublishedFinding::edge($finding);

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
