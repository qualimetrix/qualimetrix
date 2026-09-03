<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\NameSelector;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\InlineDirectivePolicyInterface;
use Qualimetrix\Analysis\Policy\Inline\Directive\DirectiveNameHints;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;

/**
 * A "did you mean" answer may not name the one channel no directive may carry.
 *
 * The two halves of the ban used to disagree: the validator refuses a directive
 * addressing `annotation.unused-directive`, while the advice printed for a
 * neighbouring typo offered that very name — the channel is one edit away from
 * its own family, so it reached the list by distance alone. Following the advice
 * produced a directive the next run refuses.
 */
#[CoversClass(DirectiveNameHints::class)]
final class BannedChannelIsNeverSuggestedTest extends TestCase
{
    /** The corpus' own typo: equidistant from two `annotation.*` channels. */
    private const string TYPO = 'annotation.unressed-directive';

    #[Test]
    public function itKeepsTheBannedChannelOutOfAnAnswerItIsCloseEnoughToEnter(): void
    {
        $banned = InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME;

        // The oracle: without the filter the name qualifies, so its absence
        // below is the filter working rather than the radius being too small.
        self::assertLessThanOrEqual(
            DirectiveNameHints::SUGGESTION_DISTANCE,
            levenshtein(self::TYPO, $banned),
            'The banned channel must be within the suggestion radius of this typo, or the guard proves nothing.',
        );

        $selector = NameSelector::tryParse(self::TYPO);
        self::assertNotNull($selector);

        $advice = self::hints()->forChannelSelector($selector);

        self::assertStringNotContainsString($banned, $advice, $advice);
        self::assertStringContainsString(
            'annotation.unresolved-directive',
            $advice,
            'The answer must still name the channels the author could have meant.',
        );
    }

    #[Test]
    public function itStillOffersTheBannedChannelsNeighboursWhenTheTypoIsOnTheRuleName(): void
    {
        $selector = NameSelector::tryParse('annotation.directiv');
        self::assertNotNull($selector);

        $advice = self::hints()->forChannelSelector($selector);

        self::assertStringNotContainsString(
            InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME,
            $advice,
            $advice,
        );
    }

    private static function hints(): DirectiveNameHints
    {
        $universe = (new ContainerFactory())->create()->get(ChannelUniverseInterface::class);
        \assert($universe instanceof ChannelUniverseInterface);

        return new DirectiveNameHints($universe);
    }
}
