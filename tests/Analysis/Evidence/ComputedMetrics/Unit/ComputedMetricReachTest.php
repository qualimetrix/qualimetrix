<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricReach;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricExpression;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricReach;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricReachCatalogInterface;
use Qualimetrix\Core\Symbol\SymbolLevel;

#[CoversClass(ComputedMetricReach::class)]
final class ComputedMetricReachTest extends TestCase
{
    #[Test]
    public function itResolvesNestedInputsAtTheRequestedLevelWithProjectFormulaFallback(): void
    {
        $definitions = new ResolvedComputedMetricDefinitions([
            $this->definition('computed.outer', ['class' => 'm["computed.inner"]', 'namespace' => 'm["computed.inner"]']),
            $this->definition('computed.inner', ['class' => 'm["complexity.ccn.avg"]', 'namespace' => 'm["coupling.instability"]']),
        ]);
        $reach = $this->reach();

        self::assertSame(MetricReach::Members, $reach->reachAt('computed.outer', SymbolLevel::Class_, $definitions));
        self::assertSame(MetricReach::Run, $reach->reachAt('computed.outer', SymbolLevel::Namespace_, $definitions));
        self::assertSame(MetricReach::Run, $reach->reachAt('computed.outer', SymbolLevel::Project, $definitions));
    }

    #[Test]
    public function itReadsEveryAstBranchAndIgnoresMetricLikeTextInStringValues(): void
    {
        $definitions = new ResolvedComputedMetricDefinitions([
            $this->definition('computed.branch', ['class' => 'false ? m ["coupling.instability"] : m["complexity.ccn"]']),
            $this->definition('computed.literal', ['class' => "'coupling.instability' == '' ? 0 : m [\"complexity.ccn\"]"]),
        ]);

        self::assertSame(MetricReach::Run, $this->reach()->reachAt('computed.branch', SymbolLevel::Class_, $definitions));
        self::assertSame(MetricReach::Members, $this->reach()->reachAt('computed.literal', SymbolLevel::Class_, $definitions));
    }

    #[Test]
    public function itUsesOnlyTheDefinitionsPassedToEachQuery(): void
    {
        $reach = $this->reach();
        $member = new ResolvedComputedMetricDefinitions([$this->definition('computed.same', ['class' => 'm["complexity.ccn"]'])]);
        $run = new ResolvedComputedMetricDefinitions([$this->definition('computed.same', ['class' => 'm["coupling.instability"]'])]);

        self::assertSame(MetricReach::Members, $reach->reachAt('computed.same', SymbolLevel::Class_, $member));
        self::assertSame(MetricReach::Run, $reach->reachAt('computed.same', SymbolLevel::Class_, $run));
        self::assertSame(MetricReach::Members, $reach->reachAt('computed.same', SymbolLevel::Class_, $member));
    }

    #[Test]
    public function itTreatsKnownInputsUnpublishedAtTheLevelAsAlwaysAbsent(): void
    {
        $definitions = new ResolvedComputedMetricDefinitions([
            $this->definition('computed.class-only', ['class' => 'm["computed.namespace-only"] ?? m["complexity.ccn"]'], [SymbolLevel::Class_]),
            $this->definition('computed.namespace-only', ['namespace' => 'm["coupling.instability"]'], [SymbolLevel::Namespace_]),
        ]);

        self::assertSame(MetricReach::Members, $this->reach()->reachAt('computed.class-only', SymbolLevel::Class_, $definitions));
    }

    #[Test]
    public function itRefusesCircularComputedInputs(): void
    {
        $definitions = new ResolvedComputedMetricDefinitions([
            $this->definition('computed.first', ['class' => 'm["computed.second"]']),
            $this->definition('computed.second', ['class' => 'm["computed.first"]']),
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Circular computed metric reach');
        $this->reach()->reachAt('computed.first', SymbolLevel::Class_, $definitions);
    }

    #[Test]
    public function itRefusesUnknownInputsEvenAfterAnInputAlreadyEstablishedRunReach(): void
    {
        $definitions = new ResolvedComputedMetricDefinitions([
            $this->definition('computed.bad', ['class' => 'm["coupling.instability"] + m["computed.unknown"]']),
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unknown computed metric "computed.unknown"');
        $this->reach()->reachAt('computed.bad', SymbolLevel::Class_, $definitions);
    }

    #[Test]
    public function itRefusesAReportedLevelWithoutAFormula(): void
    {
        $definitions = new ResolvedComputedMetricDefinitions([$this->definition('computed.bad', [])]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('has no formula at level "class"');
        $this->reach()->reachAt('computed.bad', SymbolLevel::Class_, $definitions);
    }

    #[Test]
    public function itRefusesANonLiteralMetricAccessInsteadOfInventingMemberReach(): void
    {
        $definitions = new ResolvedComputedMetricDefinitions([$this->definition('computed.bad', ['class' => 'm.offsetGet("coupling.instability")'])]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('non-literal metric access');
        $this->reach()->reachAt('computed.bad', SymbolLevel::Class_, $definitions);
    }

    private function reach(): ComputedMetricReach
    {
        $catalog = self::createStub(MetricReachCatalogInterface::class);
        $catalog->method('metricReach')->willReturnCallback(static fn(string $key): MetricReach => match (MetricName::base($key)) {
            MetricName::COMPLEXITY_CCN => MetricReach::Members,
            MetricName::COUPLING_INSTABILITY => MetricReach::Run,
            default => throw new LogicException('Unknown measured fixture metric: ' . $key),
        });

        return new ComputedMetricReach($catalog, new ComputedMetricExpression());
    }

    /**
     * @param array<string, string> $formulas
     * @param list<SymbolLevel> $levels
     */
    private function definition(
        string $name,
        array $formulas,
        array $levels = [SymbolLevel::Class_, SymbolLevel::Namespace_, SymbolLevel::Project],
    ): ComputedMetricDefinition {
        return new ComputedMetricDefinition($name, $formulas, 'Fixture definition.', $levels);
    }
}
