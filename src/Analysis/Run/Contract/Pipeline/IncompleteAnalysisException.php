<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Pipeline;

use RuntimeException;

/** Signals that discovered inputs did not all reach a successful terminal state. */
final class IncompleteAnalysisException extends RuntimeException
{
    public function __construct(
        public readonly AnalysisCoverage $coverage,
    ) {
        parent::__construct(self::describe($coverage));
    }

    private static function describe(AnalysisCoverage $coverage): string
    {
        $count = $coverage->failedFilesCount();
        $byKind = [];

        foreach ($coverage->failures as $failure) {
            $byKind[$failure->kind->value] = ($byKind[$failure->kind->value] ?? 0) + 1;
        }

        ksort($byKind);

        $categories = [];
        foreach ($byKind as $kind => $failures) {
            $categories[] = \sprintf('%s: %d', $kind, $failures);
        }

        return \sprintf(
            // "Entries", not "files": three of the five kinds name something
            // that never was a PHP file — a directory, a link, a FIFO.
            'Analysis incomplete: %d of %d discovered entries were not analyzed%s.',
            $count,
            $coverage->discoveredFiles(),
            $categories === [] ? '' : ' (' . implode(', ', $categories) . ')',
        );
    }
}
