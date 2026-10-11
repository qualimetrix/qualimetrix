<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\InheritanceDepthCompleteness;
use Qualimetrix\Reporting\Report;
use Qualimetrix\Reporting\ReportBuilder;

#[CoversClass(InheritanceDepthCompleteness::class)]
#[CoversClass(Report::class)]
final class InheritanceDepthCompletenessTest extends TestCase
{
    #[Test]
    public function itCountsExactDeclarationsAndBoundsSortedSamples(): void
    {
        $repository = self::repository();
        foreach (['Z', 'E', 'D', 'C', 'B', 'A'] as $file) {
            self::add($repository, 'Same', 'src/' . $file . '.php', [MetricName::DESIGN_DIT => 1, MetricName::DESIGN_DIT_UNRESOLVED => 1]);
        }
        self::add($repository, 'Same', 'src/A.php', [MetricName::DESIGN_DIT => 1, MetricName::DESIGN_DIT_UNRESOLVED => 1], 1);
        self::add($repository, 'Same', 'src/A.php', [MetricName::DESIGN_DIT => 1, MetricName::DESIGN_DIT_UNRESOLVED => 1], 1);
        self::add($repository, 'LoopZ', 'src/Loop.php', [MetricName::DESIGN_DIT_UNRESOLVED => 1]);
        self::add($repository, 'LoopA', 'src/Loop.php', [MetricName::DESIGN_DIT_UNRESOLVED => 1]);

        $summary = InheritanceDepthCompleteness::fromRepository($repository);
        self::assertSame([
            ['status' => 'lower-bound', 'count' => 7, 'examples' => [
                'declaration:class:App\\Same@src/A.php',
                'declaration:class:App\\Same@src/A.php#1',
                'declaration:class:App\\Same@src/B.php',
                'declaration:class:App\\Same@src/C.php',
                'declaration:class:App\\Same@src/D.php',
            ]],
            ['status' => 'absent-depth', 'count' => 2, 'examples' => ['declaration:class:App\\LoopA@src/Loop.php', 'declaration:class:App\\LoopZ@src/Loop.php']],
        ], $summary->outcomes);
        self::assertStringContainsString('lower-bound for 7 class declaration(s)', $summary->lines()[0]);
    }

    #[Test]
    public function itOmitsCompleteAndUncollectedDepths(): void
    {
        $repository = self::repository();
        self::add($repository, 'Exact', 'src/Exact.php', [MetricName::DESIGN_DIT => 0, MetricName::DESIGN_DIT_UNRESOLVED => 0]);
        self::add($repository, 'Uncollected', 'src/Uncollected.php', []);
        self::add($repository, 'NoOutcome', 'src/NoOutcome.php', [MetricName::DESIGN_DIT => 1]);
        self::assertSame([], InheritanceDepthCompleteness::fromRepository($repository)->outcomes);
        self::assertSame([], InheritanceDepthCompleteness::fromRepository(null)->lines());
    }

    #[Test]
    public function itCapturesTheReportSnapshotWithoutCreatingFindings(): void
    {
        $repository = self::repository();
        $subject = self::add($repository, 'Floor', 'src/Floor.php', [MetricName::DESIGN_DIT => 1, MetricName::DESIGN_DIT_UNRESOLVED => 1]);
        $report = ReportBuilder::create()->metrics($repository)->build();
        $repository->addSubjectScalar($subject, MetricName::DESIGN_DIT_UNRESOLVED, 0);
        self::assertSame([['status' => 'lower-bound', 'count' => 1, 'examples' => ['declaration:class:App\\Floor@src/Floor.php']]], $report->inheritanceDepth->outcomes);
        self::assertSame([], $report->findings);
        self::assertSame(0, $report->errorCount);
        self::assertSame(0, $report->warningCount);
        self::assertSame(0, $report->techDebtMinutes);
        self::assertSame([], $report->population->abstentions());
        self::assertSame([], InheritanceDepthCompleteness::fromRepository($repository)->outcomes);
    }

    private static function repository(): InMemoryMetricRepository
    {
        return new InMemoryMetricRepository([
            new MetricDefinition(MetricName::DESIGN_DIT, SymbolLevel::Class_),
            new MetricDefinition(MetricName::DESIGN_DIT_UNRESOLVED, SymbolLevel::Class_),
        ]);
    }

    /** @param array<string, int|float> $values */
    private static function add(InMemoryMetricRepository $repository, string $name, string $path, array $values, int $rank = 0): MetricSubject
    {
        $file = RelativePath::fromString($path);
        $subject = MetricSubject::declaration(DeclarationPath::of(SymbolPath::forClass('App', $name), $file, DeclarationOrdinal::fromRank($rank)));
        $repository->addSubject($subject, MetricBag::fromArray($values), $file, 1);

        return $subject;
    }
}
