<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Rule\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelPresentation;
use Qualimetrix\Analysis\Finding\Contract\ChannelPresentationInterface;
use Qualimetrix\Infrastructure\Rule\ComputedMetricChannelPresentation;

/**
 * The `computed.*` / `health.*` description preference this decorator layers
 * onto {@see \Qualimetrix\Analysis\Finding\ChannelPresentationView} — see
 * `docs/internal/plans/sarif-channel-descriptions.md`, package P2, and that
 * view's own docblock for why the preference cannot live in `Analysis\Finding`
 * itself (it would close a dependency cycle onto
 * `Analysis\Evidence\ComputedMetrics`).
 */
#[CoversClass(ComputedMetricChannelPresentation::class)]
final class ComputedMetricChannelPresentationTest extends TestCase
{
    #[Test]
    public function itPassesThroughAStaticChannelUnchanged(): void
    {
        $decorator = $this->decorator(
            innerAnswer: new ChannelPresentation('Flags overly complex callables.', 'rules/complexity.md'),
            definition: null,
        );

        $presentation = $decorator->presentationFor('complexity.cyclomatic.function');

        self::assertNotNull($presentation);
        self::assertSame('Flags overly complex callables.', $presentation->description);
        self::assertSame('rules/complexity.md', $presentation->docsPage);
    }

    #[Test]
    public function itPrefersTheConfiguredDefinitionsOwnDescriptionOverTheProducersGenericOne(): void
    {
        $decorator = $this->decorator(
            innerAnswer: new ChannelPresentation('Checks computed health metrics against thresholds', 'reference/health-scores.md'),
            definition: new ComputedMetricDefinition(
                name: 'health.cohesion',
                formulas: ['class' => '1'],
                description: 'Overall cohesion health score.',
                levels: [],
            ),
        );

        $presentation = $decorator->presentationFor('health.cohesion');

        self::assertNotNull($presentation);
        self::assertSame('Overall cohesion health score.', $presentation->description);
        // The docs page still comes from the producing rule, not the definition.
        self::assertSame('reference/health-scores.md', $presentation->docsPage);
    }

    /**
     * Sabotaging the join: a definition with a blank description must not
     * silently keep the producer's generic text, and must not surface an
     * empty string either — see the package report for the before/after
     * failure output this specific case was used to demonstrate.
     */
    #[Test]
    public function itReturnsNullWhenTheConfiguredDefinitionsDescriptionIsEmpty(): void
    {
        $decorator = $this->decorator(
            innerAnswer: new ChannelPresentation('Checks computed health metrics against thresholds', 'reference/health-scores.md'),
            definition: new ComputedMetricDefinition(
                name: 'health.cohesion',
                formulas: ['class' => '1'],
                description: '',
                levels: [],
            ),
        );

        self::assertNull($decorator->presentationFor('health.cohesion'));
    }

    #[Test]
    public function itReturnsNullWhenTheInnerViewAlreadyDoes(): void
    {
        $decorator = $this->decorator(innerAnswer: null, definition: null);

        self::assertNull($decorator->presentationFor('no.such.channel'));
    }

    private function decorator(?ChannelPresentation $innerAnswer, ?ComputedMetricDefinition $definition): ComputedMetricChannelPresentation
    {
        $inner = self::createStub(ChannelPresentationInterface::class);
        $inner->method('presentationFor')->willReturn($innerAnswer);

        $catalog = self::createStub(ComputedMetricDefinitionCatalogInterface::class);
        $catalog->method('find')->willReturn($definition);

        return new ComputedMetricChannelPresentation($inner, $catalog);
    }
}
