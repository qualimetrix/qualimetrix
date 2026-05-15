<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Architecture\Unit\Processing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Architecture\Domain\Layer\ExcludeSpec;
use Qualimetrix\Architecture\Domain\Layer\MembershipSpec;
use Qualimetrix\Architecture\Domain\Layer\TemplateLayerDefinition;
use Qualimetrix\Architecture\Processing\LayerExpansionException;
use Qualimetrix\Architecture\Processing\LayerInstantiator;

/**
 * Pins the behavior of {@see LayerInstantiator} extracted from
 * {@see \Qualimetrix\Architecture\Processing\LayerExpansionStage} during
 * Phase 4.1 of the remediation (ADR 0008). The end-to-end stage test still
 * covers orchestration; this test focuses on the instantiation helper in
 * isolation, including its actionable error messages.
 */
#[CoversClass(LayerInstantiator::class)]
final class LayerInstantiatorTest extends TestCase
{
    private LayerInstantiator $instantiator;

    protected function setUp(): void
    {
        $this->instantiator = new LayerInstantiator();
    }

    #[Test]
    public function instantiate_singleVariable_substitutesNameAndPattern(): void
    {
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(patterns: ['App\\Module\\{module}\\Domain\\**']),
        );

        $layer = $this->instantiator->instantiate($template, ['module' => 'Order']);

        self::assertSame('domain-Order', $layer->name());
        self::assertSame(['App\\Module\\Order\\Domain\\**'], $layer->membership()->patterns);
    }

    #[Test]
    public function instantiate_substitutesInExcludePatterns(): void
    {
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(
                patterns: ['App\\Module\\{module}\\Domain\\**'],
                exclude: new ExcludeSpec(
                    patterns: ['App\\Module\\{module}\\Domain\\Legacy\\**'],
                ),
            ),
        );

        $layer = $this->instantiator->instantiate($template, ['module' => 'Order']);

        self::assertNotNull($layer->membership()->exclude);
        self::assertSame(
            ['App\\Module\\Order\\Domain\\Legacy\\**'],
            $layer->membership()->exclude->patterns,
        );
    }

    #[Test]
    public function instantiate_preservesNonPatternCriteriaVerbatim(): void
    {
        // Suffix / implements / extends do not currently support captures —
        // they must pass through unchanged.
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(
                patterns: ['App\\Module\\{module}\\Domain\\**'],
                suffix: ['Service'],
                attributes: ['App\\Marker'],
                implements: ['App\\Contract\\Iface'],
                extends: ['App\\Base'],
            ),
        );

        $layer = $this->instantiator->instantiate($template, ['module' => 'Order']);

        self::assertSame(['Service'], $layer->membership()->suffix);
        self::assertSame(['App\\Marker'], $layer->membership()->attributes);
        self::assertSame(['App\\Contract\\Iface'], $layer->membership()->implements);
        self::assertSame(['App\\Base'], $layer->membership()->extends);
    }

    #[Test]
    public function instantiate_incompleteBindingTuple_throwsActionableError(): void
    {
        $template = new TemplateLayerDefinition(
            'cluster-{tenant}-{module}',
            new MembershipSpec(patterns: ['App\\{tenant}\\Module\\{module}\\Domain\\**']),
        );

        $this->expectException(LayerExpansionException::class);
        $this->expectExceptionMessageMatches('/incomplete binding tuple .* "module"/');

        $this->instantiator->instantiate($template, ['tenant' => 'AcmeCorp']);
    }

    #[Test]
    public function instantiate_invalidNameAfterSubstitution_throwsActionableError(): void
    {
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(patterns: ['App\\Module\\{module}\\**']),
        );

        // A binding value containing a backslash is rejected by the relaxed
        // expansion-mode regex.
        $this->expectException(LayerExpansionException::class);
        $this->expectExceptionMessageMatches('/invalid concrete layer name/');

        $this->instantiator->instantiate($template, ['module' => 'Order\\Foo']);
    }
}
