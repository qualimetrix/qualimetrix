<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit\Processing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ExcludeSpec;
use Qualimetrix\Analysis\Policy\Architecture\Layer\Expansion\LayerInstantiator;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MembershipSpec;
use Qualimetrix\Analysis\Policy\Architecture\Layer\TemplateLayerDefinition;

/**
 * Pins the behavior of {@see LayerInstantiator} extracted from
 * {@see \Qualimetrix\Analysis\Policy\Architecture\Layer\Expansion\LayerExpansionStage} during
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
    public function itSubstitutesNameAndPatternForASingleVariable(): void
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
    public function itSubstitutesTheVariableInExcludePatterns(): void
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
    public function itPreservesNonPatternCriteriaVerbatim(): void
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
    public function itThrowsAnActionableErrorForAnIncompleteBindingTuple(): void
    {
        $template = new TemplateLayerDefinition(
            'cluster-{tenant}-{module}',
            new MembershipSpec(patterns: ['App\\{tenant}\\Module\\{module}\\Domain\\**']),
        );

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessageMatches('/incomplete binding tuple .* "module"/');

        $this->instantiator->instantiate($template, ['tenant' => 'AcmeCorp']);
    }

    #[Test]
    public function itThrowsAnActionableErrorForAnInvalidNameAfterSubstitution(): void
    {
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(patterns: ['App\\Module\\{module}\\**']),
        );

        // A binding value containing a backslash is rejected by the relaxed
        // expansion-mode regex.
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessageMatches('/invalid concrete layer name/');

        $this->instantiator->instantiate($template, ['module' => 'Order\\Foo']);
    }
}
