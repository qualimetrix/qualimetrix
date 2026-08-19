<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Finding\Contract\ChannelAcceptability;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfiguration;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\CoverageMode;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerLifecycle;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerRegistry;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MembershipSpec;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\DeclaredLayerReachability;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationOptions;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Policy\Architecture\Support\AllowListBuilder;
use Qualimetrix\Tests\Analysis\Policy\Architecture\Support\ProcessorBuilder;

/**
 * `pending: true` — the author's declaration that a layer describes code not
 * written yet — and `architecture.pending-layer-matched`, the diagnostic that
 * keeps the declaration from outliving its own truth.
 */
#[CoversClass(DeclaredLayerReachability::class)]
#[CoversClass(LayerViolationRule::class)]
final class PendingLayerDiagnosticsTest extends TestCase
{
    private ArchitecturePolicy $processor;

    protected function setUp(): void
    {
        $this->processor = new ArchitecturePolicy();
    }

    #[Test]
    public function itDoesNotReportAPendingLayerAsUnreachable(): void
    {
        $violations = $this->analyze(
            [new LayerDefinition('reporting', new MembershipSpec(['App\\Reporting\\**']), lifecycle: LayerLifecycle::Pending)],
            ['App\\Domain\\Order'],
        );

        self::assertSame([], $this->diagnostics($violations, LayerViolationRule::UNREACHABLE_LAYER_DIAGNOSTIC_NAME));
        self::assertSame([], $this->diagnostics($violations, LayerViolationRule::PENDING_LAYER_MATCHED_DIAGNOSTIC_NAME));
    }

    #[Test]
    public function itStillReportsTheSameEmptyLayerAsUnreachableWithoutTheFlag(): void
    {
        $violations = $this->analyze(
            [new LayerDefinition('reporting', new MembershipSpec(['App\\Reporting\\**']))],
            ['App\\Domain\\Order'],
        );

        $unreachable = $this->diagnostics($violations, LayerViolationRule::UNREACHABLE_LAYER_DIAGNOSTIC_NAME);
        self::assertCount(1, $unreachable);
        self::assertStringContainsString('Layer "reporting" was never matched', $unreachable[0]->message);
    }

    #[Test]
    public function itReportsAPendingLayerThatMatchedAClass(): void
    {
        $violations = $this->analyze(
            [new LayerDefinition('reporting', new MembershipSpec(['App\\Reporting\\**']), lifecycle: LayerLifecycle::Pending)],
            ['App\\Reporting\\MonthlyReport'],
        );

        $matched = $this->diagnostics($violations, LayerViolationRule::PENDING_LAYER_MATCHED_DIAGNOSTIC_NAME);
        self::assertCount(1, $matched);
        self::assertSame(Severity::Error, $matched[0]->severity);
        self::assertStringContainsString('Layer "reporting" is declared "pending: true"', $matched[0]->message);
        self::assertStringContainsString('matched 1 distinct symbol(s)', $matched[0]->message);
        self::assertStringContainsString('Remove "pending: true"', (string) $matched[0]->recommendation);
    }

    /**
     * The case the whole channel exists for: the pending layer's criteria
     * match, but a broader layer declared earlier wins every one of those
     * matches, so its ASSIGNED count stays zero. Counting assignments would
     * leave the flag silently switched on forever over code that already
     * exists — with `architecture.unreachable-layer`, the diagnostic that
     * would otherwise have said so, suppressed by that very flag.
     */
    #[Test]
    public function itReportsAPendingLayerWhoseEveryMatchWasStolenByABroaderLayer(): void
    {
        $violations = $this->analyze(
            [
                new LayerDefinition('legacy', new MembershipSpec(['App\\**'])),
                new LayerDefinition('reporting', new MembershipSpec(['App\\Reporting\\**']), lifecycle: LayerLifecycle::Pending),
            ],
            ['App\\Reporting\\MonthlyReport'],
        );

        $matched = $this->diagnostics($violations, LayerViolationRule::PENDING_LAYER_MATCHED_DIAGNOSTIC_NAME);
        self::assertCount(1, $matched);
        self::assertStringContainsString('Layer "reporting"', $matched[0]->message);

        self::assertSame(
            [],
            $this->diagnostics($violations, LayerViolationRule::UNREACHABLE_LAYER_DIAGNOSTIC_NAME),
            'The pending flag still suppresses unreachable-layer — pending-layer-matched is what must speak instead.',
        );
    }

    #[Test]
    public function itReportsAPendingLayerMatchedOnlyAsADependencyEdgeEnd(): void
    {
        $violations = $this->analyze(
            [
                new LayerDefinition('domain', new MembershipSpec(['App\\Domain\\**'])),
                new LayerDefinition('reporting', new MembershipSpec(['App\\Reporting\\**']), lifecycle: LayerLifecycle::Pending),
            ],
            ['App\\Domain\\Order'],
            [$this->buildDependency('App\\Domain', 'Order', 'App\\Reporting', 'MonthlyReport')],
        );

        $matched = $this->diagnostics($violations, LayerViolationRule::PENDING_LAYER_MATCHED_DIAGNOSTIC_NAME);
        self::assertCount(1, $matched);
        self::assertStringContainsString('Layer "reporting"', $matched[0]->message);
    }

    /**
     * The edge-side twin of the stolen-match case: the pending layer's only
     * evidence is one END of a dependency edge, and a broader layer declared
     * earlier takes that end too. Nothing about the layer is visible in the
     * analysed declaration set, so the edge walk is the only place the match
     * can be observed at all.
     */
    #[Test]
    public function itReportsAPendingLayerWhoseEdgeEndMatchWasStolenByABroaderLayer(): void
    {
        $violations = $this->analyze(
            [
                new LayerDefinition('legacy', new MembershipSpec(['App\\**'])),
                new LayerDefinition('reporting', new MembershipSpec(['App\\Reporting\\**']), lifecycle: LayerLifecycle::Pending),
            ],
            ['App\\Domain\\Order'],
            [$this->buildDependency('App\\Domain', 'Order', 'App\\Reporting', 'MonthlyReport')],
        );

        $matched = $this->diagnostics($violations, LayerViolationRule::PENDING_LAYER_MATCHED_DIAGNOSTIC_NAME);
        self::assertCount(1, $matched);
        self::assertStringContainsString('Layer "reporting"', $matched[0]->message);
        self::assertStringContainsString('matched 1 distinct symbol(s)', $matched[0]->message);

        self::assertSame(
            [],
            $this->diagnostics($violations, LayerViolationRule::UNREACHABLE_LAYER_DIAGNOSTIC_NAME),
            'Both layers were reached; only the pending declaration is the mistake.',
        );
    }

    /**
     * What the reported number counts: symbols, not match events. The two
     * classes below are joined by four edges and are also walked as analysed
     * declarations, so a per-match tally reports ten.
     */
    #[Test]
    public function itCountsEachMatchedSymbolOnceAcrossEveryEdgeItTouches(): void
    {
        $violations = $this->analyze(
            [new LayerDefinition('reporting', new MembershipSpec(['App\\Reporting\\**']), lifecycle: LayerLifecycle::Pending)],
            ['App\\Reporting\\Alpha', 'App\\Reporting\\Beta'],
            [
                $this->buildDependency('App\\Reporting', 'Alpha', 'App\\Reporting', 'Beta'),
                $this->buildDependency('App\\Reporting', 'Beta', 'App\\Reporting', 'Alpha'),
                $this->buildDependency('App\\Reporting', 'Alpha', 'App\\Reporting', 'Beta'),
                $this->buildDependency('App\\Reporting', 'Beta', 'App\\Reporting', 'Alpha'),
            ],
        );

        $matched = $this->diagnostics($violations, LayerViolationRule::PENDING_LAYER_MATCHED_DIAGNOSTIC_NAME);
        self::assertCount(1, $matched);
        self::assertStringContainsString('matched 2 distinct symbol(s)', $matched[0]->message);
    }

    /**
     * A template expands only from tuples observed in the analysed code, so
     * `pending` has nothing to say about it. The channel that reports a
     * template which produced nothing is a different one, and a pending
     * sibling layer must not silence it.
     */
    #[Test]
    public function itLeavesTheEmptyTemplateDiagnosticAlone(): void
    {
        $violations = $this->analyze(
            [new LayerDefinition('reporting', new MembershipSpec(['App\\Reporting\\**']), lifecycle: LayerLifecycle::Pending)],
            ['App\\Domain\\Order'],
            [],
            ['module-{mod}'],
        );

        $emptyTemplates = $this->diagnostics($violations, LayerViolationRule::EMPTY_TEMPLATE_DIAGNOSTIC_NAME);
        self::assertCount(1, $emptyTemplates);
        self::assertStringContainsString('Template layer "module-{mod}"', $emptyTemplates[0]->message);
    }

    /**
     * The diagnostic says the declaration lies, which is a mistake in the
     * configuration rather than debt in the code — so no ratchet may accept
     * it and no `@qmx-ignore` may silence it.
     */
    #[Test]
    public function itDeclaresTheChannelAsAConfigurationError(): void
    {
        $declaration = LayerViolationRule::channelDeclarations()['architecture.pending-layer-matched#architecture.pending-layer-matched']
            ?? null;

        self::assertNotNull($declaration);
        self::assertSame(ChannelShape::Occurrence, $declaration->shape);
        self::assertSame(ChannelAcceptability::ConfigurationError, $declaration->acceptability);
    }

    /**
     * @param list<LayerDefinition> $layers
     * @param list<string> $logicalClasses
     * @param list<Dependency> $dependencies
     * @param list<string> $emptyTemplateNames
     *
     * @return list<Violation>
     */
    private function analyze(array $layers, array $logicalClasses, array $dependencies = [], array $emptyTemplateNames = []): array
    {
        $architecture = new ArchitectureConfiguration(
            new LayerRegistry($layers),
            AllowListBuilder::policyFromExactMap(
                array_fill_keys(array_map(static fn(LayerDefinition $l): string => $l->name(), $layers), []),
            ),
            CoverageMode::Ignore,
            emptyTemplateNames: $emptyTemplateNames,
        );

        $graph = self::createStub(DependencyGraphInterface::class);
        $graph->method('getAllDependencies')->willReturn($dependencies);

        $repository = new InMemoryMetricRepository();
        foreach ($logicalClasses as $logicalClass) {
            $repository->add(
                SymbolPath::fromClassFqn($logicalClass),
                new MetricBag(),
                RelativePath::fromString('src/dummy.php'),
                1,
            );
        }

        ProcessorBuilder::prepared($architecture, $graph, $repository, $this->processor);

        return (new LayerViolationRule(new LayerViolationOptions(), $this->processor))->analyze(
            new AnalysisContext(metrics: $repository, dependencyGraph: $graph),
        );
    }

    private function buildDependency(
        string $sourceNamespace,
        string $sourceClass,
        string $targetNamespace,
        string $targetClass,
    ): Dependency {
        return new Dependency(
            source: new DeclarationPath(
                SymbolPath::forClass($sourceNamespace, $sourceClass),
                RelativePath::fromString('src/dummy.php'),
                0,
            ),
            target: new LogicalClassPath(SymbolPath::forClass($targetNamespace, $targetClass)),
            type: DependencyType::New_,
            location: new Location(RelativePath::fromString('src/dummy.php'), 1),
        );
    }

    /**
     * @param list<Violation> $violations
     *
     * @return list<Violation>
     */
    private function diagnostics(array $violations, string $ruleName): array
    {
        return array_values(array_filter(
            $violations,
            static fn(Violation $v): bool => $v->ruleName === $ruleName,
        ));
    }
}
