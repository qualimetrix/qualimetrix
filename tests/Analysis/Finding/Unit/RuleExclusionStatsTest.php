<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\RuleExecution;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\RuleExclusionStats;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(RuleExclusionStats::class)]
final class RuleExclusionStatsTest extends TestCase
{
    #[Test]
    public function itIsEmptyByDefault(): void
    {
        $stats = new RuleExclusionStats();

        self::assertTrue($stats->isEmpty());
        self::assertSame(0, $stats->totalNamespaceExclusions());
        self::assertSame(0, $stats->totalPathExclusions());
        self::assertSame([], $stats->excludedFindings);
    }

    #[Test]
    public function itSumsNamespaceExclusionsAcrossRules(): void
    {
        $stats = new RuleExclusionStats(namespaceExclusionsByRule: ['rule1' => 2, 'rule2' => 3]);

        self::assertFalse($stats->isEmpty());
        self::assertSame(5, $stats->totalNamespaceExclusions());
        self::assertSame(0, $stats->totalPathExclusions());
    }

    #[Test]
    public function itSumsPathExclusionsAcrossRules(): void
    {
        $stats = new RuleExclusionStats(pathExclusionsByRule: ['rule1' => 4]);

        self::assertFalse($stats->isEmpty());
        self::assertSame(4, $stats->totalPathExclusions());
        self::assertSame(0, $stats->totalNamespaceExclusions());
    }

    #[Test]
    public function itCarriesExcludedFindings(): void
    {
        $finding = new Finding(
            location: new Location(RelativePath::fromString('src/Foo.php'), 10),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('src/Foo.php')),
            subject: MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString('src/Foo.php'))),
            ruleName: 'rule1',
            code: 'rule1',
            message: 'test',
            severity: Severity::Warning,
        );

        $stats = new RuleExclusionStats(excludedFindings: [$finding]);

        self::assertSame([$finding], $stats->excludedFindings);
    }
}
