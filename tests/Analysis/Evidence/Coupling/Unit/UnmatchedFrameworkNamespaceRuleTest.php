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
     * A dependency source is classified by the collector too, so a prefix that
     * only matches analysed code has bound — and reporting it would send an
     * author looking for a mistake that is not there.
     */
    #[Test]
    public function itTreatsADependencySourceAsABinding(): void
    {
        self::assertSame(
            [],
            $this->rule(['Sample'])->analyze($this->context($this->graph())),
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
            [['source' => 'test', 'values' => ['coupling' => ['frameworkNamespaces' => $prefixes]]]],
            AbsolutePath::fromString('/project'),
        )));

        return new UnmatchedFrameworkNamespaceRule(
            $options ?? new UnmatchedFrameworkNamespaceOptions(),
            $coupling,
        );
    }

    private function context(?DependencyGraphInterface $graph, bool $coversProjectScope = true): AnalysisContext
    {
        return new AnalysisContext(
            self::createStub(MetricRepositoryInterface::class),
            $graph,
            coversProjectScope: $coversProjectScope,
        );
    }

    /** One edge: `Sample\Service` depends on `Symfony\Component\Console\Command\Command`. */
    private function graph(): DependencyGraphInterface
    {
        $graph = self::createStub(DependencyGraphInterface::class);
        $graph->method('getAllDependencies')->willReturn([
            new Dependency(
                DeclarationPath::of(
                    SymbolPath::forClass('Sample', 'Service'),
                    RelativePath::fromString('src/Service.php'),
                    DeclarationOrdinal::fromRank(0),
                ),
                new LogicalClassPath(SymbolPath::forClass('Symfony\\Component\\Console\\Command', 'Command')),
                DependencyType::TypeHint,
                new Location(RelativePath::fromString('src/Service.php'), 10),
            ),
        ]);

        return $graph;
    }
}
