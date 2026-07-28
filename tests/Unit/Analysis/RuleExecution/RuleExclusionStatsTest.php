<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\RuleExecution;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\RuleExecution\RuleExclusionStats;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;

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
        self::assertSame([], $stats->excludedViolations);
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
    public function itCarriesExcludedViolations(): void
    {
        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/Foo.php'), 10),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('src/Foo.php')),
            ruleName: 'rule1',
            violationCode: 'rule1',
            message: 'test',
            severity: Severity::Warning,
        );

        $stats = new RuleExclusionStats(excludedViolations: [$violation]);

        self::assertSame([$violation], $stats->excludedViolations);
    }
}
