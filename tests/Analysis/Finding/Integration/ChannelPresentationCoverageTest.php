<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricDefaults;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\ComputedMetricConfiguratorInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelPresentationInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;

/**
 * "Answers for every channel" is only an invariant if the swept set comes
 * from the universe itself rather than a hand-written list — see
 * `docs/internal/plans/sarif-channel-descriptions.md`, package P2.
 */
#[CoversClass(ChannelPresentationInterface::class)]
final class ChannelPresentationCoverageTest extends TestCase
{
    /**
     * Matches {@see ChannelUniverseCoverageTest::DECLARED_CHANNEL_COUNT}: both
     * read the same real container's static declarations, so a divergence
     * between the two counts would itself be a regression.
     */
    private const int DECLARED_CHANNEL_COUNT = 57;

    #[Test]
    public function everyStaticChannelResolvesToARealDescriptionAndAnExistingDocsPage(): void
    {
        $container = (new ContainerFactory())->create();

        $universe = $container->get(ChannelUniverseInterface::class);
        \assert($universe instanceof ChannelUniverseInterface);

        $presentation = $container->get(ChannelPresentationInterface::class);
        \assert($presentation instanceof ChannelPresentationInterface);

        $channelKeys = array_keys($universe->staticDeclarations());
        self::assertCount(self::DECLARED_CHANNEL_COUNT, $channelKeys);

        $missing = [];
        foreach ($channelKeys as $key) {
            $code = new FindingChannel($key)->code;
            $answer = $presentation->presentationFor($code);

            if ($answer === null) {
                $missing[] = $code;

                continue;
            }

            if ($answer->description === '') {
                $missing[] = $code . ' (blank description)';
            }

            if (!is_file(self::docsRoot() . '/' . $answer->docsPage)) {
                $missing[] = $code . ' (docs page does not exist: ' . $answer->docsPage . ')';
            }
        }

        self::assertSame(
            [],
            $missing,
            \sprintf('Channel(s) with no usable presentation: %s.', implode(', ', $missing)),
        );
    }

    /**
     * The static sweep above builds its container without a resolved run
     * configuration, so the computed-metric catalog is empty and every
     * `computed.*` / `health.*` channel is invisible to it — see this class's
     * own `everyStaticChannelResolvesToARealDescriptionAndAnExistingDocsPage()`
     * and {@see \Qualimetrix\Tests\Reporting\Formatter\Sarif\Integration\SarifRuleDescriptorCoverageTest},
     * whose docblocks both say so. That leaves P2's own DoD — "the view
     * answers for all 57 static channels and for a configured `computed.*` /
     * `health.*` definition" — half-checked. This resolves the six built-in
     * health-score definitions the same way a real run would (through
     * {@see ComputedMetricConfiguratorInterface::resolve()} against an empty
     * config document, i.e. defaults only) and sweeps the resulting full
     * channel set.
     */
    #[Test]
    public function everyConfiguredComputedMetricChannelAlsoResolvesToARealDescriptionAndAnExistingDocsPage(): void
    {
        $container = (new ContainerFactory())->create();

        $configurator = $container->get(ComputedMetricConfiguratorInterface::class);
        \assert($configurator instanceof ComputedMetricConfiguratorInterface);

        $document = new ConfigurationDocument([], AbsolutePath::fromString('/'));
        $configurator->replace($configurator->resolve($document));

        $universe = $container->get(ChannelUniverseInterface::class);
        \assert($universe instanceof ChannelUniverseInterface);

        $presentation = $container->get(ChannelPresentationInterface::class);
        \assert($presentation instanceof ChannelPresentationInterface);

        $channels = $universe->channels();
        self::assertCount(
            self::DECLARED_CHANNEL_COUNT + \count(ComputedMetricDefaults::getDefaults()),
            $channels,
            'The universe should now report the 57 static channels plus the 6 built-in computed/health definitions.',
        );

        $missing = [];
        foreach ($channels as $channel) {
            $answer = $presentation->presentationFor($channel->code);

            if ($answer === null) {
                $missing[] = $channel->code;

                continue;
            }

            if ($answer->description === '') {
                $missing[] = $channel->code . ' (blank description)';
            }

            if (!is_file(self::docsRoot() . '/' . $answer->docsPage)) {
                $missing[] = $channel->code . ' (docs page does not exist: ' . $answer->docsPage . ')';
            }
        }

        self::assertSame(
            [],
            $missing,
            \sprintf('Channel(s) with no usable presentation: %s.', implode(', ', $missing)),
        );
    }

    private static function docsRoot(): string
    {
        return \dirname(__DIR__, 4) . '/website/docs';
    }
}
