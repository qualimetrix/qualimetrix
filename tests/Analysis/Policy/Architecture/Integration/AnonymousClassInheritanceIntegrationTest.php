<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfiguration;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\CoverageMode;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitecturePolicyConfiguratorInterface;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ClassContextFactory;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ExcludeSpec;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerRegistry;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MembershipSpec;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Tests\Analysis\Policy\Architecture\Support\AllowListBuilder;

/**
 * Regression coverage for the anonymous-class-inheritance defect:
 * an anonymous class nested inside a named class has no declaration identity
 * of its own, so its `extends`/`implements`/`attributes` header used to be
 * recorded with the ENCLOSING named class as source. Layer membership walked
 * that data directly ({@see \Qualimetrix\Analysis\Policy\Architecture\Layer\ClassContextFactory}),
 * so the enclosing class was silently assigned to layers written for the
 * nested anonymous class instead — including transitively, since
 * {@code extendsMap} is walked as a BFS closure.
 *
 * Runs the live analysis pipeline against {@code tests/Analysis/Policy/Architecture/Fixtures/AnonymousInheritanceSample}.
 * Every `Host\*` fixture class carries a typed dependency on `Sink\Sink`; the
 * `Sink` class always sits in its own self-allow-only layer, so a
 * `architecture.layer-violation` finding whose source is a Host class is the
 * observable evidence that the Host class was classified into the layer
 * under test — its absence is the evidence that it was not.
 *
 * `LegitimateHost` is a REAL named subclass/implementor/attribute-bearer
 * (not an anonymous one) that satisfies every criterion directly. It is the
 * required positive control: without it, a test proving "the anonymous
 * class's declaration is ignored" could not be distinguished from a test
 * proving "the criterion never matches anything".
 */
#[CoversClass(ClassContextFactory::class)]
#[Group('integration')]
final class AnonymousClassInheritanceIntegrationTest extends TestCase
{
    private const string FIXTURE_PATH = __DIR__ . '/../Fixtures/AnonymousInheritanceSample';
    private const string FIXTURE_NAMESPACE = 'Fixtures\\AnonymousInheritanceSample';

    #[Test]
    public function itDoesNotAssignTheEnclosingClassByItsNestedAnonymousClassesDirectExtends(): void
    {
        // extends: [L1] — L1 is the DIRECT parent of the anonymous class
        // nested inside AnonExtendsHost, not of AnonExtendsHost itself.
        $sourceFqns = $this->classifiedSources($this->registryFor(
            new MembershipSpec(extends: [self::FIXTURE_NAMESPACE . '\\Marker\\L1']),
        ));

        self::assertNotContains(
            self::FIXTURE_NAMESPACE . '\\Host\\AnonExtends\\AnonExtendsHost',
            $sourceFqns,
            'AnonExtendsHost declares no parent of its own — the nested anonymous '
            . 'class extending L1 must not lend that ancestry to it.',
        );
        self::assertContains(
            self::FIXTURE_NAMESPACE . '\\Host\\Legit\\LegitimateHost',
            $sourceFqns,
            'LegitimateHost genuinely extends L1 — a real own declaration must still match.',
        );
    }

    #[Test]
    public function itDoesNotAssignTheEnclosingClassByItsNestedAnonymousClassesTransitiveExtends(): void
    {
        // extends: [L0] — the GRANDPARENT, reached only through the BFS
        // closure over extendsMap. Pre-cure, the enclosing class inherited
        // the anonymous class's whole ancestry, not just its direct parent.
        $sourceFqns = $this->classifiedSources($this->registryFor(
            new MembershipSpec(extends: [self::FIXTURE_NAMESPACE . '\\Marker\\L0']),
        ));

        self::assertNotContains(
            self::FIXTURE_NAMESPACE . '\\Host\\AnonExtends\\AnonExtendsHost',
            $sourceFqns,
            'The transitive closure must not resurrect the nested anonymous '
            . "class's grandparent under AnonExtendsHost either.",
        );
        self::assertContains(
            self::FIXTURE_NAMESPACE . '\\Host\\Legit\\LegitimateHost',
            $sourceFqns,
            'LegitimateHost extends L1, which extends L0 — the transitive walk '
            . 'must still work for a real inheritance chain.',
        );
    }

    #[Test]
    public function itDoesNotAssignTheEnclosingClassByItsNestedAnonymousClassesImplements(): void
    {
        $sourceFqns = $this->classifiedSources($this->registryFor(
            new MembershipSpec(implements: [self::FIXTURE_NAMESPACE . '\\Marker\\Iface']),
        ));

        self::assertNotContains(
            self::FIXTURE_NAMESPACE . '\\Host\\AnonImplements\\AnonImplementsHost',
            $sourceFqns,
            'AnonImplementsHost implements nothing itself — the nested anonymous '
            . 'class implementing Iface must not lend that fact to it.',
        );
        self::assertContains(
            self::FIXTURE_NAMESPACE . '\\Host\\Legit\\LegitimateHost',
            $sourceFqns,
            'LegitimateHost genuinely implements Iface — a real own declaration must still match.',
        );
    }

    #[Test]
    public function itDoesNotAssignTheEnclosingClassByItsNestedAnonymousClassesAttribute(): void
    {
        $sourceFqns = $this->classifiedSources($this->registryFor(
            new MembershipSpec(attributes: [self::FIXTURE_NAMESPACE . '\\Marker\\Mark']),
        ));

        self::assertNotContains(
            self::FIXTURE_NAMESPACE . '\\Host\\AnonAttribute\\AnonAttributeHost',
            $sourceFqns,
            'AnonAttributeHost carries no attribute itself — #[Mark] on the nested '
            . 'anonymous class must not lend that fact to it.',
        );
        self::assertContains(
            self::FIXTURE_NAMESPACE . '\\Host\\Legit\\LegitimateHost',
            $sourceFqns,
            'LegitimateHost genuinely carries #[Mark] — a real own declaration must still match.',
        );
    }

    #[Test]
    public function itDoesNotExcludeTheEnclosingClassByItsNestedAnonymousClassesExtends(): void
    {
        // 'all-hosts' picks up every Host\* class by pattern, then an
        // extends:[L1] EXCLUDE clause is supposed to filter out only classes
        // that genuinely extend L1. Both the positive membership map and the
        // exclude map are built by the same ClassContextFactory — this proves
        // the cure applies there too, not only to plain `extends:` layers.
        $registry = new LayerRegistry([
            new LayerDefinition(
                'all-hosts',
                new MembershipSpec(
                    patterns: [self::FIXTURE_NAMESPACE . '\\Host\\**'],
                    exclude: new ExcludeSpec(extends: [self::FIXTURE_NAMESPACE . '\\Marker\\L1']),
                ),
            ),
            new LayerDefinition(
                'sink',
                new MembershipSpec(patterns: [self::FIXTURE_NAMESPACE . '\\Sink\\**']),
            ),
        ]);

        $sourceFqns = $this->classifiedSourcesFromRegistry($registry);

        self::assertContains(
            self::FIXTURE_NAMESPACE . '\\Host\\AnonExtends\\AnonExtendsHost',
            $sourceFqns,
            'AnonExtendsHost does not itself extend L1 — the exclude clause must '
            . 'NOT fire for it, so it stays a member of all-hosts.',
        );
        self::assertNotContains(
            self::FIXTURE_NAMESPACE . '\\Host\\Legit\\LegitimateHost',
            $sourceFqns,
            'LegitimateHost genuinely extends L1 — the exclude clause must fire '
            . 'for it, removing it from all-hosts.',
        );
    }

    // Template layers read the same ClassContext, so they look like they
    // belong in this file. They cannot be exercised meaningfully with the
    // current template-expansion wiring: ArchitecturePolicy::prepare()
    // (src/Analysis/Policy/Architecture/ArchitecturePolicy.php) builds the
    // ClassSet handed to LayerExpansionStage with a brand-new, UNBOUND
    // ClassContextFactory — the dependency graph is only bound to the
    // registry's OWN factory afterwards (registry()->bindGraph($graph), one
    // line later). So every non-pattern criterion (extends/implements/
    // attributes) sees an empty ClassContext during tuple OBSERVATION,
    // regardless of the anonymous-class cure:
    // - match: all makes extends an AND-filter, but the empty context means
    //   it never passes for ANY class (verified: the template expands to
    //   zero concrete layers even for the genuine positive control).
    // - match: any (default) OR's extends with the pattern per
    //   TupleExtractor's own docblock, so a class already bound by the
    //   pattern is observed regardless of what extends says — making the
    //   criterion inert for classification either way.
    // This is a separate, pre-existing defect: it predates the anonymous-class
    // cure and survives it. Fixing it means changing where the expansion stage
    // gets its factory, which is a different subject from this file.

    /**
     * Builds a two-layer registry: the criterion under test (self-allow-only)
     * plus a 'sink' catch-all (self-allow-only) so every Host\* class's
     * typed Sink dependency becomes a violation iff the Host class was
     * classified into the criterion layer.
     */
    private function registryFor(MembershipSpec $criterion): LayerRegistry
    {
        return new LayerRegistry([
            new LayerDefinition('matched', $criterion),
            new LayerDefinition('sink', new MembershipSpec(patterns: [self::FIXTURE_NAMESPACE . '\\Sink\\**'])),
        ]);
    }

    /**
     * @return list<string>
     */
    private function classifiedSources(LayerRegistry $registry): array
    {
        return $this->classifiedSourcesFromRegistry($registry);
    }

    /**
     * @return list<string>
     */
    private function classifiedSourcesFromRegistry(LayerRegistry $registry): array
    {
        $policy = AllowListBuilder::policyFromExactMap(array_fill_keys($registry->layerNames(), []));

        $configuration = new ArchitectureConfiguration($registry, $policy, CoverageMode::Ignore);
        $analysis = $this->runPipelineWithConfiguration($configuration);

        return $this->collectSourceFqns(
            $this->filterByRule($analysis->findings, LayerViolationRule::NAME),
        );
    }

    private function runPipelineWithConfiguration(ArchitectureConfiguration $architecture): AnalysisResult
    {
        $container = (new ContainerFactory())->create();

        $holder = $container->get(ArchitecturePolicyConfiguratorInterface::class);
        self::assertInstanceOf(ArchitecturePolicy::class, $holder);
        $holder->bind($architecture);

        $pipeline = $container->get(AnalysisPipelineInterface::class);
        self::assertInstanceOf(AnalysisPipelineInterface::class, $pipeline);

        $root = AbsolutePath::fromString(self::FIXTURE_PATH);

        return $pipeline->analyze(new RunConfiguration(
            [$root],
            [],
            $root,
            GeneratedFilePolicy::Include,
            coversProjectScope: true,
            authoredPathExcludes: [],
        ));
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<Finding>
     */
    private function filterByRule(array $findings, string $ruleName): array
    {
        return array_values(array_filter(
            $findings,
            static fn(Finding $v): bool => $v->ruleName === $ruleName,
        ));
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<string>
     */
    private function collectSourceFqns(array $findings): array
    {
        $seen = [];
        foreach ($findings as $finding) {
            $seen[$finding->symbolPath->toString()] = true;
        }

        return array_keys($seen);
    }
}
