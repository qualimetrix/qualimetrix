<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Fixtures;

use DateTimeImmutable;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Baseline\Baseline;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryMode;
use Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity;
use Qualimetrix\Analysis\Policy\Baseline\Filter\BaselineCeilingStage;
use Qualimetrix\Analysis\Policy\Baseline\InertBaselineEntry;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;

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
        Finding $member,
        array $magnitudes,
        ?BaselineEntryMode $mode = null,
    ): BaselineEntry {
        return new BaselineEntry(
            BaselineIdentity::forFinding($member),
            $magnitudes,
            \count($magnitudes),
            $mode,
        );
    }

    /**
     * An `occurrence` entry bounding the group the given finding belongs to.
     */
    private static function occurrenceEntry(
        Finding $member,
        int $count,
        ?BaselineEntryMode $mode = null,
    ): BaselineEntry {
        return new BaselineEntry(BaselineIdentity::forFinding($member), null, $count, $mode);
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
        string $code,
        SymbolPath $symbolPath,
        int|float|null $metricValue,
        int $line = 1,
        Severity $severity = Severity::Warning,
    ): Finding {
        return new Finding(
            location: new Location(RelativePath::fromString('src/Foo.php'), $line),
            subject: self::subjectFor($symbolPath),
            symbolPath: $symbolPath,
            ruleName: $ruleName,
            code: $code,
            message: 'finding',
            severity: $severity,
            metricValue: $metricValue,
        );
    }

    private static function subjectFor(SymbolPath $symbolPath): MetricSubject
    {
        return match ($symbolPath->getType()) {
            SymbolType::File, SymbolType::Namespace_, SymbolType::Project => MetricSubject::aggregate($symbolPath),
            SymbolType::Class_, SymbolType::Method, SymbolType::Function_ => MetricSubject::declaration(
                DeclarationPath::of($symbolPath, RelativePath::fromString('src/Foo.php'), DeclarationOrdinal::fromRank(0)),
            ),
        };
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<Severity>
     */
    private static function severitiesOf(array $findings): array
    {
        return array_map(static fn(Finding $finding): Severity => $finding->severity, $findings);
    }
}
