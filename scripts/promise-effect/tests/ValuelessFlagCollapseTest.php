<?php

declare(strict_types=1);

namespace Qualimetrix\PromiseEffect\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A valueless `cli-root` flag writes the same argv for a form's comparand as
 * for the form itself, so a collapse probe on it is the value probe again and
 * reads COLLAPSED whenever the flag has any effect at all. The comparison is a
 * question the door cannot carry; no cell on such a door may be decided by it.
 */
final class ValuelessFlagCollapseTest extends TestCase
{
    private const string COLLAPSE_DECISION = 'equal to the canonical write of another form';

    #[Test]
    public function itDecidesNoValuelessFlagCellByTheCollapseComparison(): void
    {
        $root = \dirname(__DIR__, 3);
        $valueless = [];

        foreach (self::rows($root . '/promise-effect/cli-root-flags.tsv') as $row) {
            if (($row[2] ?? '') === 'yes') {
                $valueless[$row[0]] = true;
            }
        }

        self::assertNotSame([], $valueless, 'the flag table declares no valueless flag; the question is vacuous');

        $judged = [];
        $collapsed = [];

        foreach (self::rows($root . '/docs/internal/generated/promise-effect/verdicts.tsv') as $row) {
            if ($row[0] !== 'D' || preg_match('/^form\|cli-root\|(.+)\|[^|]+$/', $row[1], $match) !== 1 || !isset($valueless[$match[1]])) {
                continue;
            }

            $judged[] = $row[1];

            if ($row[7] === self::COLLAPSE_DECISION) {
                $collapsed[] = $row[1];
            }
        }

        self::assertNotSame([], $judged, 'no valueless-flag cell in the verdicts; the question is vacuous');
        self::assertSame([], $collapsed);
    }

    /** @return list<list<string>> */
    private static function rows(string $file): array
    {
        $lines = file($file, \FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines, $file);
        $rows = [];

        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $rows[] = explode("\t", $line);
        }

        return $rows;
    }
}
