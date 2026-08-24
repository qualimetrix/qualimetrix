<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use RuntimeException;

/**
 * Drift guard on the ORDER of the channel universe, against the tracked
 * fixture at `tests/Analysis/Finding/Fixtures/Channels/order.txt`.
 *
 * The order is observable. `DirectiveNameHints` scores candidates with a
 * stable `asort`, so two names at equal Levenshtein distance from a
 * misspelled directive are suggested in universe order, and that suggestion
 * is embedded in the message of a published `annotation.unresolved-directive`
 * finding. Splitting a rule's channels between the rule and a configuration
 * validator moved exactly one channel this way and nothing noticed: the
 * sibling declaration fixture is sorted alphabetically, so it cannot see
 * order at all.
 *
 * Scoped to the static half of the universe for the same reason
 * {@see ChannelDeclarationFixtureDriftTest} is: the run-time
 * `computed.*`/`health.*` family depends on configuration a fixed line list
 * could never enumerate. A bare container declares none of it, so
 * {@see ChannelUniverseInterface::channels()} here yields the static half
 * alone.
 */
#[CoversClass(ChannelUniverseInterface::class)]
final class ChannelOrderFixtureDriftTest extends TestCase
{
    private const string FIXTURE = '/Fixtures/Channels/order.txt';

    #[Test]
    public function theContainerYieldsChannelsInTheOrderTheFixtureRecords(): void
    {
        $actual = self::realChannelOrder();
        $expected = self::readFixture();

        self::assertSame(
            $expected,
            $actual,
            'The order of the channel universe no longer matches'
            . ' tests/Analysis/Finding/Fixtures/Channels/order.txt. That order is published: it breaks ties'
            . " between equidistant names in a \"did you mean\" suggestion, so a finding's text moved with it."
            . ' The usual cause is a producer registered in a new place in a configurator, or a producer kind'
            . ' collected as a block instead of in registration order. Update the fixture only after deciding'
            . ' the new published order is the intended one.',
        );
    }

    /**
     * @return list<string>
     */
    private static function realChannelOrder(): array
    {
        $universe = (new ContainerFactory())->create()->get(ChannelUniverseInterface::class);
        \assert($universe instanceof ChannelUniverseInterface);

        $codes = [];

        foreach ($universe->channels() as $channel) {
            $codes[] = $channel->code;
        }

        return $codes;
    }

    /**
     * @return list<string>
     */
    private static function readFixture(): array
    {
        $path = \dirname(__DIR__) . self::FIXTURE;
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(\sprintf('Could not read fixture file %s.', $path));
        }

        $codes = [];

        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $codes[] = $line;
        }

        return $codes;
    }
}
