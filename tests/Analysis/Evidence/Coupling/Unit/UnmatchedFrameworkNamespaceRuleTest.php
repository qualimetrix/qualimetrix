<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Evidence\Coupling\CouplingAnalysis;
use Qualimetrix\Analysis\Evidence\Coupling\UnmatchedFrameworkNamespaceOptions;
use Qualimetrix\Analysis\Evidence\Coupling\UnmatchedFrameworkNamespaceRule;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * The branches where the rule must say nothing.
 *
 * What it says when it does speak is proved by
 * {@see \Qualimetrix\Tests\Analysis\Evidence\Coupling\Integration\UnmatchedFrameworkNamespaceIntegrationTest},
 * through the real command — a channel that never reached a report would pass
 * any assertion made here.
 */
#[CoversClass(UnmatchedFrameworkNamespaceRule::class)]
final class UnmatchedFrameworkNamespaceRuleTest extends TestCase
{
    #[Test]
    public function itNamesItselfAfterItsChannel(): void
    {
        self::assertSame('coupling.unmatched-framework-namespace', $this->rule(['Nope'])->getName());
        self::assertArrayHasKey(
            UnmatchedFrameworkNamespaceRule::NAME,
            UnmatchedFrameworkNamespaceRule::channelDeclarations(),
        );
    }

    /**
     * Without a graph there is no universe of names to ask about, so no prefix
     * can be called unbound. Reporting every prefix here would turn a run that
     * merely could not look — a graph-less pipeline — into an accusation about
     * the configuration.
     */
    #[Test]
    public function itSaysNothingWithoutADependencyGraph(): void
    {
        self::assertSame([], $this->rule(['Nope\\Missing'])->analyze($this->context(null)));
    }

    /** Nothing declared is not a prefix that failed. */
    #[Test]
    public function itSaysNothingWhenNoPrefixesAreDeclared(): void
    {
        self::assertSame([], $this->rule([])->analyze($this->context($this->graph())));
    }

    #[Test]
    public function itSaysNothingWhenTheRuleIsDisabled(): void
    {
        self::assertSame(
            [],
            $this->rule(['Nope\\Missing'], new UnmatchedFrameworkNamespaceOptions(enabled: false))
                ->analyze($this->context($this->graph())),
        );
    }

    /**
     * A run whose graph carries no edge gave the collector nothing to
     * classify, so no prefix is inert because of its spelling. Reporting there
     * would fire on a correct project-wide `qmx.yaml` every time someone
     * checked one self-contained file.
     */
    #[Test]
    public function itSaysNothingWhenTheGraphCarriesNoDependency(): void
    {
        $empty = self::createStub(DependencyGraphInterface::class);
        $empty->method('getAllDependencies')->willReturn([]);

        self::assertSame([], $this->rule(['Nope\\Missing'])->analyze($this->context($empty)));
    }

    /**
     * Measured on this tree before the precondition existed: `qmx check
     * src/Analysis/Evidence/Cohesion/` under the project's own `qmx.yaml`
     * reported three of its four prefixes as unmatched, and every one of them
     * binds on `qmx check src/`. "Bound nothing" is a fact about the pair
     * (configuration, run scope); a slice cannot carry the configuration's
     * denominator, so the channel says nothing rather than blaming the author
     * for the caller's choice of path.
     */
    #[Test]
    public function itSaysNothingWhenTheRunIsNarrowerThanTheProject(): void
    {
        self::assertSame(
            [],
            $this->rule(['Nope\\Missing'])->analyze($this->context($this->graph(), coversProjectScope: false)),
        );
    }

    /**
     * The universe is the collector's classification sites, not both ends of
     * every edge.
     *
     * `Sample\Service` is measured and its only edge points at code this run
     * did not measure, so the collector asks its predicate about the target and
     * never about `Sample\Service` itself: a prefix over the source moves
     * neither `coupling.cbo-app` nor `coupling.ce-framework`. Counting both
     * ends called that prefix bound while it classified nothing.
     */
    #[Test]
    public function itDoesNotCountASourceTheCollectorNeverClassifies(): void
    {
        $findings = $this->rule(['Sample'])->analyze($this->context($this->graph()));

        self::assertCount(1, $findings);
        self::assertStringContainsString('Sample', $findings[0]->message);
    }

    /** The same prefix on a run that did measure the edge's target: now classified, so bound. */
    #[Test]
    public function itCountsASourceOnceTheTargetIsMeasuredToo(): void
    {
        $context = $this->context($this->graph(), measured: [
            SymbolPath::forClass('Sample', 'Service'),
            SymbolPath::forClass('Symfony\\Component\\Console\\Command', 'Command'),
        ]);

        self::assertSame([], $this->rule(['Sample'])->analyze($context));
    }

    #[Test]
    public function itReportsOneFindingPerUnboundPrefix(): void
    {
        $findings = $this->rule(['Nope\\Missing', 'Symfony'])->analyze($this->context($this->graph()));

        self::assertCount(1, $findings);
        self::assertStringContainsString('Nope\\Missing', $findings[0]->message);
        self::assertSame(SymbolPath::forProject()->toCanonical(), $findings[0]->symbolPath->toCanonical());
    }

    /** @param list<string> $prefixes */
    private function rule(array $prefixes, ?UnmatchedFrameworkNamespaceOptions $options = null): UnmatchedFrameworkNamespaceRule
    {
        $coupling = new CouplingAnalysis();
        $coupling->replace($coupling->resolve(new ConfigurationDocument(
            [[
                'source' => 'test',
                'values' => ['coupling' => ['frameworkNamespaces' => array_map(static fn(string $prefix): array => ['subtree' => $prefix], $prefixes)]],
            ]],
            AbsolutePath::fromString('/project'),
        )));

        return new UnmatchedFrameworkNamespaceRule(
            $options ?? new UnmatchedFrameworkNamespaceOptions(),
            $coupling,
        );
    }

    /**
     * @param list<SymbolPath> $measured the classes the repository holds; the
     *                                   collector classifies nothing about a
     *                                   class this run did not measure
     */
    private function context(
        ?DependencyGraphInterface $graph,
        bool $coversProjectScope = true,
        ?array $measured = null,
    ): AnalysisContext {
        $measured ??= [SymbolPath::forClass('Sample', 'Service')];
        $canonical = array_map(static fn(SymbolPath $p): string => $p->toCanonical(), $measured);

        $metrics = self::createStub(MetricRepositoryInterface::class);
        $metrics->method('has')->willReturnCallback(
            static fn(SymbolPath $path): bool => \in_array($path->toCanonical(), $canonical, true),
        );

        return new AnalysisContext($metrics, $graph, coversProjectScope: $coversProjectScope);
    }

    /** One edge: `Sample\Service` depends on `Symfony\Component\Console\Command\Command`. */
    private function graph(): DependencyGraphInterface
    {
        return $this->graphOf($this->edge(
            SymbolPath::forClass('Sample', 'Service'),
            SymbolPath::forClass('Symfony\\Component\\Console\\Command', 'Command'),
        ));
    }

    /**
     * A graph that answers the three questions the coupling walk asks: which
     * classes it holds, and each class's outgoing and incoming edges.
     */
    private function graphOf(Dependency ...$edges): DependencyGraphInterface
    {
        $classes = [];
        $bySource = [];
        $byTarget = [];

        foreach ($edges as $edge) {
            foreach ([$edge->sourceLogical(), $edge->targetLogical()] as $end) {
                $classes[$end->toCanonical()] = $end;
            }

            $bySource[$edge->sourceLogical()->toCanonical()][] = $edge;
            $byTarget[$edge->targetLogical()->toCanonical()][] = $edge;
        }

        $graph = self::createStub(DependencyGraphInterface::class);
        $graph->method('getAllDependencies')->willReturn(array_values($edges));
        $graph->method('getAllClasses')->willReturn(array_values($classes));
        $graph->method('getClassDependencies')->willReturnCallback(
            static fn(SymbolPath $class): array => $bySource[$class->toCanonical()] ?? [],
        );
        $graph->method('getClassDependents')->willReturnCallback(
            static fn(SymbolPath $class): array => $byTarget[$class->toCanonical()] ?? [],
        );

        return $graph;
    }

    private function edge(SymbolPath $source, SymbolPath $target): Dependency
    {
        return new Dependency(
            DeclarationPath::of($source, RelativePath::fromString('src/Service.php'), DeclarationOrdinal::fromRank(0)),
            new LogicalClassPath($target),
            DependencyType::TypeHint,
            new Location(RelativePath::fromString('src/Service.php'), 10),
        );
    }
}
