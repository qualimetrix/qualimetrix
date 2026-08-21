<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ChannelPresentationInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
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
            $violationCode = ViolationChannel::fromKey($key)->violationCode;
            $answer = $presentation->presentationFor($violationCode);

            if ($answer === null) {
                $missing[] = $violationCode;

                continue;
            }

            if ($answer->description === '') {
                $missing[] = $violationCode . ' (blank description)';
            }

            if (!is_file(self::docsRoot() . '/' . $answer->docsPage)) {
                $missing[] = $violationCode . ' (docs page does not exist: ' . $answer->docsPage . ')';
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
