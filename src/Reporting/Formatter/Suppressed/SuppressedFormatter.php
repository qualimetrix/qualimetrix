<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Suppressed;

use Qualimetrix\Core\Version;
use Qualimetrix\Reporting\FindingProjection\InertSuppressor;
use Qualimetrix\Reporting\FindingProjection\SuppressedFinding;
use Qualimetrix\Reporting\FindingProjection\SuppressionComposition;
use Qualimetrix\Reporting\FindingProjection\SuppressionMechanism;
use Qualimetrix\Reporting\Formatter\FormatterInterface;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\Report;

/**
 * The machine-readable composition of what a run suppressed — a separate
 * format rather than a section of `json`, so an ordinary `check` payload
 * never moves for a feature it did not ask for (ADR-to-be, Ш6 decision (а)).
 *
 * Not registered as a `--show-suppressed`-only view: selecting this format
 * is itself the request, and {@see \Qualimetrix\Infrastructure\Console\RuntimeConfigurator}
 * arms the same per-rule ledger capture for it that the flag arms, so the
 * two routes to capture never disagree (decision (д)).
 */
final class SuppressedFormatter implements FormatterInterface
{
    public function format(Report $report, FormatterContext $context): string
    {
        $composition = $report->suppressionComposition ?? new SuppressionComposition([]);

        $byMechanism = [];
        foreach (SuppressionMechanism::cases() as $mechanism) {
            $byMechanism[$mechanism->value] = 0;
        }

        $suppressed = [];
        foreach ($composition->all as $entry) {
            $byMechanism[$entry->mechanism->value]++;
            $suppressed[] = $this->formatEntry($entry, $context);
        }

        $neverMatched = array_map(
            static fn(InertSuppressor $inert): array => [
                'mechanism' => $inert->mechanism->value,
                'suppressor' => $inert->suppressor,
            ],
            $composition->neverMatched,
        );

        $data = [
            'meta' => [
                'version' => Version::get(),
                'package' => 'qmx',
                'timestamp' => gmdate('c'),
            ],
            'note' => 'suppressed is a multiset of mechanism x finding, not a set of findings: one finding '
                . 'can appear under more than one mechanism, so byMechanism counts do not sum to the number '
                . 'of distinct findings suppressed.',
            'mechanisms' => array_map(static fn(SuppressionMechanism $m): string => $m->value, SuppressionMechanism::cases()),
            'byMechanism' => $byMechanism,
            'suppressed' => $suppressed,
            'neverMatched' => $neverMatched,
        ];

        return json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatEntry(SuppressedFinding $entry, FormatterContext $context): array
    {
        $finding = $entry->finding;

        return [
            'mechanism' => $entry->mechanism->value,
            'suppressor' => $entry->suppressor,
            'rule' => $finding->ruleName,
            'channel' => $finding->code,
            'file' => $finding->location->file === null ? null : $context->relativizePath($finding->location->file),
            'line' => $finding->location->line,
            'symbol' => $finding->symbolPath->toString(),
            'severity' => $finding->severity->value,
            'message' => $finding->getDisplayMessage(),
        ];
    }

    public function getName(): string
    {
        return 'suppressed';
    }

    public function getDefaultGroupBy(): GroupBy
    {
        return GroupBy::None;
    }
}
