<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Policy\Inline\Directive\DirectiveNameHints;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;

/**
 * Which channel pairs can appear in a "did you mean" answer at the same
 * distance — the only way the published channel order reaches a user.
 *
 * `DirectiveNameHints` scores candidates within five edits and sorts them with
 * a stable `asort`, so two names equally far from what was typed are printed in
 * universe order. That makes the order of the universe part of a published
 * finding's text, but only for names close enough to tie. This test measures
 * which pairs those are, instead of assuming.
 *
 * The fixture verifies which nearby names can tie and affect published
 * suggestion text; it also confirms the type-coverage names can tie while the
 * architecture names tested below cannot.
 */
#[CoversClass(ChannelUniverseInterface::class)]
final class ChannelSuggestionTieTest extends TestCase
{
    /**
     * The radius the product allows, read from the product. Two names further
     * apart than twice it cannot both be within it of one string — the triangle
     * inequality, which is what makes this measurement exact rather than a
     * sample of typos somebody thought of.
     *
     * Read, not restated: a copy of the number here would keep this test
     * passing on a radius nothing uses after the product raised it, and the
     * guarantee it stands behind is that no tied pair can alter a user's
     * suggestion text without being covered here.
     */
    private const int SUGGESTION_DISTANCE = DirectiveNameHints::SUGGESTION_DISTANCE;

    /**
     * The architecture channel compared with the other architecture channels.
     */
    private const string TARGET_CHANNEL = 'architecture.unassigned-class';

    private const array COMPARED_CHANNELS = [
        'architecture.coverage-gap',
        'architecture.unreachable-layer',
        'architecture.potential-shadow',
        'architecture.empty-template',
        'architecture.pending-layer-matched',
    ];

    #[Test]
    public function itFindsNoInputThatTiesTheSelectedChannelAgainstOtherArchitectureChannels(): void
    {
        $codes = self::channelCodes();

        foreach (self::COMPARED_CHANNELS as $other) {
            self::assertContains($other, $codes, $other);

            $distance = levenshtein(self::TARGET_CHANNEL, $other);

            self::assertGreaterThan(
                2 * self::SUGGESTION_DISTANCE,
                $distance,
                \sprintf(
                    'A string within %d edits of both "%s" and "%s" would exist (they are %d apart), so their'
                    . ' relative order could change the published "did you mean" answer.',
                    self::SUGGESTION_DISTANCE,
                    self::TARGET_CHANNEL,
                    $other,
                    $distance,
                ),
            );
        }
    }

    /**
     * The guard above is only useful if a tie is reachable in general. The
     * three type-coverage channels are close enough for one misspelling to
     * tie two suggestions, which makes their published order observable.
     */
    #[Test]
    public function itConfirmsATieIsReachableBetweenTheThreeTypeCoverageChannels(): void
    {
        $codes = self::channelCodes();
        $typeCoverage = [
            'design.type-coverage.param',
            'design.type-coverage.return',
            'design.type-coverage.property',
        ];

        foreach ($typeCoverage as $code) {
            self::assertContains($code, $codes, $code);
        }

        foreach ([[0, 1], [0, 2], [1, 2]] as [$left, $right]) {
            self::assertLessThanOrEqual(
                2 * self::SUGGESTION_DISTANCE,
                levenshtein($typeCoverage[$left], $typeCoverage[$right]),
                $typeCoverage[$left] . ' / ' . $typeCoverage[$right],
            );
        }

        self::assertSame(
            3,
            levenshtein('design.type-coverage.propurn', 'design.type-coverage.return'),
            'the demonstrating string must stay equidistant from the two names it ties',
        );
        self::assertSame(
            3,
            levenshtein('design.type-coverage.propurn', 'design.type-coverage.property'),
            'the demonstrating string must stay equidistant from the two names it ties',
        );
    }

    /**
     * And the order those two are printed in is the order the universe yields
     * them, which is the order `DesignConfigurator` names their rules in.
     */
    #[Test]
    public function itYieldsTheTiedTypeCoverageChannelsInTheDeclaredOrder(): void
    {
        $positions = array_flip(self::channelCodes());

        self::assertLessThan(
            $positions['design.type-coverage.return'],
            $positions['design.type-coverage.param'],
        );
        self::assertLessThan(
            $positions['design.type-coverage.property'],
            $positions['design.type-coverage.return'],
        );
    }

    /**
     * @return list<string>
     */
    private static function channelCodes(): array
    {
        $universe = (new ContainerFactory())->create()->get(ChannelUniverseInterface::class);
        \assert($universe instanceof ChannelUniverseInterface);

        return array_map(
            static fn(FindingChannel $channel): string => $channel->code,
            $universe->channels(),
        );
    }
}
