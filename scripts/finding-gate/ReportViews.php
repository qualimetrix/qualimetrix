<?php

declare(strict_types=1);

namespace QmxFindingGate;

/** A report publication has exact invocation provenance, even when its records are identical. */
final class ReportViews
{
    /** Every captured baseline file: the generated one and each maintenance command's output. */
    public const array BASELINE_FILE_SURFACES = ['baseline-file', 'baseline:cleanup:file', 'baseline:rename-channels:file', 'baseline:update:file'];

    /** Captured finding reports, baseline entries and baseline explanations. */
    private const array RECORD_BEARING_SURFACES = [
        ...self::BASELINE_FILE_SURFACES,
        'check:baseline', 'check:baseline-source', 'check:output:file', 'check:parallel', 'directives',
        'format:json', 'format:metrics', 'format:suppressed',
    ];

    public static function recordBearingSurface(string $surface): bool
    {
        return \in_array($surface, self::RECORD_BEARING_SURFACES, true);
    }

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
        if ($report === 'json-document' && \in_array($view, ['format:json', 'check:output:file', 'check:parallel', 'check:baseline', 'check:baseline-source'], true)) {
            return;
        }
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
}
