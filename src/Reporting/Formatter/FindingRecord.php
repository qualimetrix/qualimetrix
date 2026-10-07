<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter;

use Qualimetrix\Analysis\Evidence\Prioritization\Debt\RemediationTimeRegistry;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Reporting\Formatter\Json\JsonSanitizer;
use Qualimetrix\Reporting\FormatterContext;

/**
 * The finding fields shared by structured reports and ranked issues.
 *
 * @phpstan-type PublishedRecord array{file: ?string, line: ?int, subject: string, symbol: string, channel: string, occurrence: ?string, edge: ?array{target: string, type?: string}, namespace: ?string, rule: string, code: string, severity: string, message: string, recommendation: ?string, metricValue: int|float|null, threshold: int|float|null, techDebtMinutes: int, acceptedLevel: ?array{shape: string, describe: string, count: int}, baselineVerdict: ?string, baselineReason: ?string}
 */
final readonly class FindingRecord
{
    public function __construct(
        private RemediationTimeRegistry $remediationTimeRegistry,
        private JsonSanitizer $sanitizer,
    ) {}

    /** @return PublishedRecord */
    public function of(Finding $finding, FormatterContext $context): array
    {
        $ns = $finding->symbolPath->namespace ?? '';
        $file = $finding->location->file === null
            ? null
            : $context->relativizePath($finding->location->file);
        $baseline = PublishedFinding::baselineFields($finding);

        return [
            'file' => $file,
            'line' => $finding->location->line,
            'subject' => $finding->subject->toCanonical(),
            'symbol' => $finding->symbolPath->toString(),
            'channel' => $finding->channel()->code,
            'occurrence' => $finding->occurrenceKey?->value,
            'edge' => PublishedFinding::edge($finding),
            'namespace' => $ns !== '' ? $ns : null,
            'rule' => $finding->ruleName,
            'code' => $finding->code,
            'severity' => $finding->severity->value,
            'message' => $finding->message,
            'recommendation' => $finding->recommendation,
            'metricValue' => $this->sanitizer->sanitizeNumeric($finding->metricValue),
            'threshold' => $this->sanitizer->sanitizeNumeric($finding->threshold),
            'techDebtMinutes' => $this->remediationTimeRegistry->getMinutesForFinding($finding),
            'acceptedLevel' => $baseline['acceptedLevel'],
            'baselineVerdict' => $baseline['baselineVerdict'],
            'baselineReason' => $baseline['baselineReason'],
        ];
    }

}
