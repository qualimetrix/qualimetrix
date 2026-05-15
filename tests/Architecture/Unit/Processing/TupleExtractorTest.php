<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Architecture\Unit\Processing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Architecture\Domain\Layer\ClassContextFactory;
use Qualimetrix\Architecture\Domain\Layer\ClassSet;
use Qualimetrix\Architecture\Domain\Layer\MatchMode;
use Qualimetrix\Architecture\Domain\Layer\MembershipSpec;
use Qualimetrix\Architecture\Domain\Layer\TemplateLayerDefinition;
use Qualimetrix\Architecture\Processing\TupleExtractor;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Pins the behavior of {@see TupleExtractor} extracted from
 * {@see \Qualimetrix\Architecture\Processing\LayerExpansionStage} during
 * Phase 4.1 of the remediation (ADR 0008). The {@code LayerExpansionStageTest}
 * still covers the end-to-end orchestration; this test focuses on the helper
 * surface in isolation.
 */
#[CoversClass(TupleExtractor::class)]
final class TupleExtractorTest extends TestCase
{
    private TupleExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new TupleExtractor();
    }

    #[Test]
    public function collect_singleVariableTemplate_dedupesAndLexSorts(): void
    {
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(patterns: ['App\\Module\\{module}\\Domain\\**']),
        );

        $classes = self::classSet([
            'App\\Module\\Order\\Domain\\A',
            'App\\Module\\Audit\\Domain\\B',
            'App\\Module\\Order\\Domain\\C',
        ]);

        $tuples = $this->extractor->collect($template, $classes);

        self::assertSame(
            [
                ['module' => 'Audit'],
                ['module' => 'Order'],
            ],
            $tuples,
        );
    }

    #[Test]
    public function collect_multiVariable_observedTuplesNotCartesian(): void
    {
        $template = new TemplateLayerDefinition(
            'cluster-{tenant}-{module}',
            new MembershipSpec(patterns: ['App\\{tenant}\\Module\\{module}\\Domain\\**']),
        );

        $classes = self::classSet([
            'App\\AcmeCorp\\Module\\Order\\Domain\\A',
            'App\\AcmeCorp\\Module\\Audit\\Domain\\B',
            'App\\WidgetsLtd\\Module\\Reports\\Domain\\C',
        ]);

        $tuples = $this->extractor->collect($template, $classes);

        self::assertCount(3, $tuples);
        // Sort order is by (module, tenant) per variable order (sorted alphabetically),
        // even though the array's own key order reflects pattern-capture insertion.
        self::assertSame('AcmeCorp', $tuples[0]['tenant']);
        self::assertSame('Audit', $tuples[0]['module']);
        self::assertSame('AcmeCorp', $tuples[1]['tenant']);
        self::assertSame('Order', $tuples[1]['module']);
        self::assertSame('WidgetsLtd', $tuples[2]['tenant']);
        self::assertSame('Reports', $tuples[2]['module']);
    }

    #[Test]
    public function collect_noClassMatches_returnsEmptyList(): void
    {
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(patterns: ['App\\Mdule\\{module}\\Domain\\**']),
        );

        $classes = self::classSet(['App\\Module\\Order\\Domain\\A']);

        self::assertSame([], $this->extractor->collect($template, $classes));
    }

    #[Test]
    public function collect_emptyClassSet_returnsEmptyList(): void
    {
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(patterns: ['App\\Module\\{module}\\Domain\\**']),
        );

        $tuples = $this->extractor->collect($template, self::classSet([]));

        self::assertSame([], $tuples);
    }

    #[Test]
    public function collect_appliesNonCapturePatternsAsAndFilter(): void
    {
        // Combine a capture-producing pattern with a non-capture pattern.
        // The non-capture pattern restricts the set; the capture pattern
        // extracts the binding.
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(patterns: [
                'App\\Module\\{module}\\Domain\\**',
                'App\\Module',  // non-capture filter — prefix on App\Module
            ]),
        );

        $classes = self::classSet([
            'App\\Module\\Order\\Domain\\A',     // matches both — kept
            'App\\OtherRoot\\Order\\Domain\\B',  // capture pattern would not match anyway
        ]);

        $tuples = $this->extractor->collect($template, $classes);

        self::assertSame([['module' => 'Order']], $tuples);
    }

    #[Test]
    public function collect_appliesSuffixCriterionAsAndFilter(): void
    {
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(
                patterns: ['App\\Module\\{module}\\**'],
                suffix: ['Service'],
            ),
        );

        $classes = self::classSet([
            'App\\Module\\Order\\Domain\\OrderService',
            'App\\Module\\Order\\Domain\\OrderRepository',
        ]);

        $tuples = $this->extractor->collect($template, $classes);

        self::assertSame([['module' => 'Order']], $tuples);
    }

    #[Test]
    public function collect_matchAll_unionsBindingsAcrossPatterns(): void
    {
        // Both capture-producing patterns must match the same FQN; bindings
        // union, conflicting bindings would reject the tuple.
        $template = new TemplateLayerDefinition(
            'cluster-{tenant}-{module}',
            new MembershipSpec(
                patterns: [
                    'App\\{tenant}\\Module\\**',
                    'App\\**\\Module\\{module}\\Domain\\**',
                ],
                mode: MatchMode::All,
            ),
        );

        $classes = self::classSet([
            'App\\AcmeCorp\\Module\\Order\\Domain\\A',
        ]);

        $tuples = $this->extractor->collect($template, $classes);

        self::assertCount(1, $tuples);
        self::assertSame('AcmeCorp', $tuples[0]['tenant']);
        self::assertSame('Order', $tuples[0]['module']);
    }

    #[Test]
    public function collect_matchAll_conflictingBindings_skipsClass(): void
    {
        $template = new TemplateLayerDefinition(
            'tag-{name}',
            new MembershipSpec(
                patterns: [
                    'App\\{name}\\Module\\Order\\**',
                    'App\\AcmeCorp\\Module\\Order\\{name}\\**',
                ],
                mode: MatchMode::All,
            ),
        );

        // The same variable {name} gets two different values; conflicting bindings.
        $classes = self::classSet([
            'App\\AcmeCorp\\Module\\Order\\InfraThing\\A',
        ]);

        $tuples = $this->extractor->collect($template, $classes);

        self::assertSame([], $tuples);
    }

    /**
     * @param list<string> $fqns
     */
    private static function classSet(array $fqns): ClassSet
    {
        $classes = [];
        foreach ($fqns as $fqn) {
            $position = strrpos($fqn, '\\');
            if ($position === false) {
                $classes[] = SymbolPath::forClass('', $fqn);

                continue;
            }
            $namespace = substr($fqn, 0, $position);
            $shortName = substr($fqn, $position + 1);
            $classes[] = SymbolPath::forClass($namespace, $shortName);
        }

        return new ClassSet($classes, new ClassContextFactory());
    }
}
