<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\HealthVocabulary;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\RankedOffenderLevels;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * The levels the report ranks offenders for, against the levels the drill-down
 * universe trusts with a canonical name.
 *
 * A level added to one side and not the other is the whole defect, in either
 * direction: a missing level refuses a value whose report is not empty, an
 * extra one accepts a value whose report cannot be anything else.
 *
 * It reads a production file's text, which is a claim about this repository
 * rather than about a subject's behaviour, so it lives here rather than beside
 * either of the two classes it compares.
 */
final class RankedOffenderLevelsAgreeWithTheBuilderTest extends TestCase
{
    private const string BUILDER =
        '/src/Analysis/Evidence/ComputedMetrics/Health/Contract/Summary/HealthSummaryBuilder.php';

    #[Test]
    public function itRanksOffendersForExactlyTheLevelsTheUniverseTrustsWithACanonicalName(): void
    {
        $source = file_get_contents(\dirname(__DIR__, 2) . self::BUILDER);
        self::assertIsString($source);

        preg_match_all('/buildWorstOffenders\([^;]*?SymbolLevel::([A-Za-z_]+)/s', $source, $matches);

        self::assertNotSame([], $matches[1], 'The builder call this reads was not found; the regex no longer describes it.');
        self::assertSame(
            array_map(static fn(SymbolLevel $level): string => $level->name, RankedOffenderLevels::LEVELS),
            $matches[1],
            'HealthSummaryBuilder ranks a different set of levels than RankedOffenderLevels names.',
        );
    }
}
