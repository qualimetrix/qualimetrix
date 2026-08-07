<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Baseline\Filter;

use DateTimeImmutable;
use Qualimetrix\Baseline\Baseline;
use Qualimetrix\Baseline\BaselineEntry;
use Qualimetrix\Baseline\BaselineEntryMode;
use Qualimetrix\Baseline\BaselineIdentity;
use Qualimetrix\Baseline\Filter\BaselineCeilingStage;
use Qualimetrix\Baseline\InertBaselineEntry;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Tests\Support\Violation\StubChannelDeclarationRegistry;

/**
 * The scaffolding the ceiling-stage tests share, so each case states only
 * the entry, the group and the verdict it is actually about.
 */
trait CeilingStageFixtures
{
    /**
     * @param list<BaselineEntry> $entries
     * @param list<InertBaselineEntry> $inertEntries
     */
    private static function baselineOf(array $entries, array $inertEntries = []): Baseline
    {
        return new Baseline(
            generated: new DateTimeImmutable('2026-08-05T12:00:00+03:00'),
            scope: ['src'],
            entries: $entries,
            inertEntries: $inertEntries,
        );
    }

    /**
     * A `magnitude` entry bounding the group the given finding belongs to.
     *
     * @param list<int|float> $magnitudes
     */
    private static function magnitudeEntry(
        Violation $member,
        array $magnitudes,
        ?BaselineEntryMode $mode = null,
    ): BaselineEntry {
        return new BaselineEntry(
            BaselineIdentity::forViolation($member),
            $magnitudes,
            \count($magnitudes),
            $mode,
        );
    }

    /**
     * An `occurrence` entry bounding the group the given finding belongs to.
     */
    private static function occurrenceEntry(
        Violation $member,
        int $count,
        ?BaselineEntryMode $mode = null,
    ): BaselineEntry {
        return new BaselineEntry(BaselineIdentity::forViolation($member), null, $count, $mode);
    }

    private static function stageOver(
        Baseline $baseline,
        ?StubChannelDeclarationRegistry $declarations = null,
    ): BaselineCeilingStage {
        return new BaselineCeilingStage(
            $baseline,
            $declarations ?? StubChannelDeclarationRegistry::withDefaults(),
        );
    }

    /**
     * A finding carrying an explicit magnitude on an explicit channel, for
     * the cases the shared factory's defaults do not cover.
     */
    private static function findingOn(
        string $ruleName,
        string $violationCode,
        SymbolPath $symbolPath,
        int|float|null $metricValue,
        int $line = 1,
        Severity $severity = Severity::Warning,
    ): Violation {
        return new Violation(
            location: new Location(RelativePath::fromString('src/Foo.php'), $line),
            symbolPath: $symbolPath,
            ruleName: $ruleName,
            violationCode: $violationCode,
            message: 'finding',
            severity: $severity,
            metricValue: $metricValue,
        );
    }

    /**
     * @param list<Violation> $violations
     *
     * @return list<Severity>
     */
    private static function severitiesOf(array $violations): array
    {
        return array_map(static fn(Violation $violation): Severity => $violation->severity, $violations);
    }
}
