<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\ChannelPresentationView;
use Qualimetrix\Analysis\Finding\Contract\ChannelPresentationInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;

/**
 * Every channel of the product universe is presented with a description of
 * that channel: the one named after its producer with the producer's own, and
 * every other with a text of its own. Before channels declared descriptions,
 * all nine `architecture.*` channels were published in SARIF as "Detects
 * dependencies between layers…", whatever they reported.
 *
 * The channels come from the universe itself, never from a list here.
 */
#[CoversClass(ChannelPresentationView::class)]
final class ChannelDescriptionTest extends TestCase
{
    #[Test]
    public function itPresentsEveryChannelNotNamedAfterItsProducerWithATextOfItsOwn(): void
    {
        $container = (new ContainerFactory())->create();

        $universe = $container->get(ChannelUniverseInterface::class);
        \assert($universe instanceof ChannelUniverseInterface);

        $presentation = $container->get(ChannelPresentationInterface::class);
        \assert($presentation instanceof ChannelPresentationInterface);

        $execution = $container->get(RuleExecutionInterface::class);
        \assert($execution instanceof RuleExecutionInterface);

        $producerDescriptions = [];
        foreach ($execution->allRules() as $rule) {
            $producerDescriptions[$rule->name] = $rule->description;
        }

        $failures = [];
        $secondary = 0;
        /** @var array<string, string> $channelByDescription */
        $channelByDescription = [];

        foreach ($universe->channels() as $channel) {
            $code = $channel->code;
            $producer = $universe->producerOf($code);
            $text = $presentation->presentationFor($code)?->description;

            if ($producer === null || $text === null) {
                $failures[] = \sprintf('%s: no producer or no presentation.', $code);

                continue;
            }

            if ($code === $producer) {
                if ($text !== ($producerDescriptions[$producer] ?? null)) {
                    $failures[] = \sprintf('%s: named after its producer but not presented with its text.', $code);
                }
            } else {
                ++$secondary;

                if ($text === ($producerDescriptions[$producer] ?? null)) {
                    $failures[] = \sprintf('%s: presented with its producer\'s ("%s") description.', $code, $producer);
                }
            }

            if (isset($channelByDescription[$text])) {
                $failures[] = \sprintf('%s: shares its description with %s.', $code, $channelByDescription[$text]);
            }

            $channelByDescription[$text] = $code;
        }

        self::assertGreaterThan(0, $secondary, 'No channel is named otherwise than its producer, so nothing was checked.');
        self::assertSame([], $failures, implode("\n", $failures));
    }
}
