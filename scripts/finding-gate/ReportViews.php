<?php

declare(strict_types=1);

namespace QmxFindingGate;

/** A report publication has exact invocation provenance, even when its records are identical. */
final class ReportViews
{
    public const array REPORTS = [
        'json' => ['format:json', 'check:baseline-source', 'check:baseline'],
        'suppressed' => ['format:suppressed'],
        'metrics' => ['format:metrics'],
        'directives' => ['directives'],
    ];

    public static function assert(string $report, string $view): void
    {
        if (!\in_array($view, self::REPORTS[$report] ?? [], true)) {
            throw new GateError('Unknown authoritative report/view publication: ' . $report . ' / ' . $view);
        }
    }

    public static function assertFieldsView(string $report, string $view): void
    {
        if ($report === 'json' && $view === 'ranking') {
            return;
        }
        self::assert($report, $view);
    }

    /** @return list<string> */
    public static function views(string $report): array
    {
        return self::REPORTS[$report] ?? throw new GateError('Unknown authoritative report: ' . $report);
    }

    /** @return array<string,string> view => report */
    public static function forCase(CaseDefinition $case): array
    {
        $views = [];
        foreach (self::REPORTS as $report => $publications) {
            foreach ($publications as $view) {
                if (!\in_array($view, ['check:baseline-source', 'check:baseline'], true) || $case->baselineSource() !== null) {
                    $views[$view] = $report;
                }
            }
        }
        return $views;
    }

    public static function main(string $report): string
    {
        return self::REPORTS[$report][0] ?? throw new GateError('Unknown authoritative report: ' . $report);
    }

    public static function reportOf(string $view): string
    {
        foreach (self::REPORTS as $report => $views) {
            if (\in_array($view, $views, true)) {
                return $report;
            }
        }
        throw new GateError('Unknown authoritative view: ' . $view);
    }
}
