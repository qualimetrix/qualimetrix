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
use ReflectionClass;

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

    /**
     * The per-rule exclusion ledger has exactly two halves —
     * `suppress_namespaces`/`suppress_namespace_channels` and `suppress_paths`.
     * `Reporting\FindingProjection\SuppressionMechanism::ledgerHalves()` is
     * beholden to that count (its own test,
     * `Tests\Reporting\FindingProjection\Unit\SuppressionMechanismTest`,
     * checks the enum side); this test reads the count structurally, off the
     * `*ByRule` constructor parameters, so a third half added to this VO
     * fails here rather than silently under-reporting the `suppressed`
     * format's composition. Kept independent of the Reporting-owned enum
     * deliberately — this is an Analysis.Finding test and has no reason to
     * import a Reporting type.
     */
    #[Test]
    public function itHasExactlyTwoPerRuleExclusionHalves(): void
    {
        $parameters = (new ReflectionClass(RuleExclusionStats::class))->getConstructor()?->getParameters() ?? [];

        $ledgerParameterNames = array_values(array_filter(
            array_map(static fn($parameter): string => $parameter->getName(), $parameters),
            static fn(string $name): bool => str_ends_with($name, 'ByRule'),
        ));

        self::assertCount(
            2,
            $ledgerParameterNames,
            'A third *ByRule ledger half appeared without a matching SuppressionMechanism case.',
        );
    }
}
