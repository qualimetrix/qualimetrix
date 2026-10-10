<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Health\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\DrillDown\WorstClassDrillDown;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\OffenderNamespaceSelection;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\WorstOffender;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender\WorstOffenderEvidence;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Core\Unit\Pattern\NamespacePatternStub;

#[CoversClass(WorstClassDrillDown::class)]
final class WorstClassDrillDownTest extends TestCase
{
    #[Test]
    public function itSelectsTheUnionWithoutRebuildingOrReorderingRecords(): void
    {
        $first = $this->offender('App\Service', 'Worker', 80, 0);
        $duplicate = $this->offender('App\Service', 'Worker', 20, 1);
        $wholeName = $this->offender('App\Other', 'Widget', 30, 0);
        $outside = $this->offender('App\ServiceBus', 'Bus', 10, 0);
        $source = [$first, $duplicate, $outside, $wholeName];
        $selection = new OffenderNamespaceSelection([
            NamespacePatternStub::exact('App\Service'),
            NamespacePatternStub::regex('App\\\\Other\\\\Widget'),
        ]);
        $result = (new WorstClassDrillDown())->buildWorstClasses($source, $selection);
        self::assertSame([$first, $duplicate, $wholeName], $result);
        self::assertSame([80.0, 20.0, 30.0], array_map(static fn($item) => $item->healthOverall, $result));
        self::assertSame([90.0, 85.0], $result[0]->overallThresholds);
        self::assertSame('reason captured with report', $result[0]->reason);
        self::assertSame($source, [$first, $duplicate, $outside, $wholeName]);
    }

    #[Test]
    public function itSelectsNothingWhenNoNamespaceOrWholeNameMatches(): void
    {
        self::assertSame([], (new WorstClassDrillDown())->buildWorstClasses(
            [$this->offender('App', 'Worker', 20, 0)],
            new OffenderNamespaceSelection([NamespacePatternStub::subtree('Other')]),
        ));
    }

    #[Test]
    public function itDoesNotTreatTheGlobalNamespaceDisplayLabelAsAnAnalyzedName(): void
    {
        $selection = new OffenderNamespaceSelection([NamespacePatternStub::regex('\\(global\\)')]);
        self::assertFalse($selection->matches(SymbolPath::forNamespace('')));
    }

    private function offender(string $namespace, string $name, float $score, int $ordinal): WorstOffender
    {
        $symbol = SymbolPath::forClass($namespace, $name);
        $file = RelativePath::fromString('src/Types.php');
        $subject = MetricSubject::declaration(DeclarationPath::of($symbol, $file, DeclarationOrdinal::fromRank($ordinal)));

        return new WorstOffender($subject, $score, 'captured label', 'reason captured with report', new WorstOffenderEvidence(2, 0, ['coupling.cbo' => 0], ['complexity' => 0.0]), [90.0, 85.0]);
    }
}
