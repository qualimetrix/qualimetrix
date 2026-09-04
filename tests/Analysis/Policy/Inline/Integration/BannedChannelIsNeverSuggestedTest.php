<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\NameSelector;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\InlineDirectivePolicyInterface;
use Qualimetrix\Analysis\Policy\Inline\Directive\DirectiveNameHints;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;

/**
 * No answer of {@see DirectiveNameHints::forChannelSelector()} may name the one
 * channel no directive may carry.
 *
 * The two halves of the ban used to disagree: the validator refuses a directive
 * addressing `annotation.unused-directive`, while the advice printed for a
 * neighbouring typo offered that very name — the channel is one edit away from
 * its own family, so it reached the list by distance alone. Following the advice
 * produced a directive the next run refuses.
 *
 * The first repair filtered the near-spelling search and named the invariant
 * after it, which is a claim about every branch proved on one. Two others still
 * printed the name: the rule branch enumerated the rule's channels, and the
 * descendants branch told the author to write the parent. So the guard here is a
 * **sweep over the whole declared universe** in both selector forms rather than
 * a list of the spellings someone thought of — the enumeration is what the
 * previous spelling of this test was missing, not the assertion.
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

        $advice = self::adviceFor(self::TYPO);

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
        $advice = self::adviceFor('annotation.directiv');

        self::assertStringNotContainsString(
            InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME,
            $advice,
            $advice,
        );
    }

    /**
     * The rule branch: an exact rule name is answered with its channels, and the
     * banned one is not among them even though the rule produces it.
     */
    #[Test]
    public function itLeavesTheBannedChannelOutOfTheChannelListOfItsOwnRule(): void
    {
        $banned = InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME;
        $producer = InlineDirectivePolicyInterface::PRODUCER_RULE_NAME;

        // The oracle: the rule really does produce the banned channel, so its
        // absence below is the filter and not a universe that never had it.
        self::assertSame($producer, self::universe()->producerOf($banned));

        $advice = self::adviceFor($producer);

        self::assertStringContainsString('names a rule', $advice, $advice);
        self::assertStringNotContainsString($banned, $advice, $advice);
        self::assertStringContainsString('annotation.unresolved-directive', $advice, $advice);
    }

    /**
     * The descendants branch: `X.*` over a leaf channel used to answer "write X
     * to address it", which for the banned channel is advice to write the one
     * directive the next run refuses.
     */
    #[Test]
    public function itDoesNotTellTheAuthorToWriteTheBannedChannelWhenTheyAskedForItsDescendants(): void
    {
        $banned = InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME;

        // The oracle: the branch under test is the "no channels below it" one,
        // which needs the name to be a real, childless channel.
        self::assertTrue(self::universe()->hasChannel($banned));

        $advice = self::adviceFor($banned . '.*');

        self::assertStringContainsString('no channels below it', $advice, $advice);
        self::assertStringNotContainsString('write "' . $banned . '"', $advice, $advice);
    }

    /**
     * Every declared name, in both selector forms, and the banned channel in
     * none of the answers.
     *
     * The point is the enumeration: a branch nobody listed is still swept, and a
     * branch added later is swept the day it is added. Rule names are included
     * because the rule branch is reached by them and by nothing else.
     */
    #[Test]
    public function itNamesTheBannedChannelInNoAnswerTheWholeUniverseCanProduce(): void
    {
        $universe = self::universe();
        $banned = InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME;

        $names = [
            ...array_map(static fn(FindingChannel $channel): string => $channel->code, $universe->channels()),
            ...$universe->ruleNames(),
        ];

        $answers = 0;

        foreach ($names as $name) {
            foreach ([$name, $name . '.*'] as $raw) {
                $selector = NameSelector::tryParse($raw);

                if ($selector === null || $universe->expand($selector) !== []) {
                    // Only a selector addressing nothing is answered with advice.
                    continue;
                }

                ++$answers;
                $advice = self::hints()->forChannelSelector($selector);

                // An answer to a selector that *is* the banned name has to
                // repeat it — that is what the author asked about. What it may
                // not do is tell them to write it. Every other answer may not
                // name it at all.
                if ($selector->name() === $banned) {
                    self::assertStringNotContainsString('write "' . $banned . '"', $advice, $raw . ': ' . $advice);

                    continue;
                }

                self::assertStringNotContainsString(
                    $banned,
                    $advice,
                    $raw . ' is answered with the one channel no directive may carry.',
                );
            }
        }

        // A sweep that swept nothing is not a sweep. The floor is deliberately
        // low: what it rules out is an empty loop, not a shrunken universe.
        self::assertGreaterThan(20, $answers, 'The sweep produced almost no advice, so it proves almost nothing.');
    }

    private static function adviceFor(string $raw): string
    {
        $selector = NameSelector::tryParse($raw);
        self::assertNotNull($selector);

        return self::hints()->forChannelSelector($selector);
    }

    private static ?ChannelUniverseInterface $universe = null;

    private static function universe(): ChannelUniverseInterface
    {
        if (self::$universe === null) {
            $universe = (new ContainerFactory())->create()->get(ChannelUniverseInterface::class);
            \assert($universe instanceof ChannelUniverseInterface);
            self::$universe = $universe;
        }

        return self::$universe;
    }

    private static function hints(): DirectiveNameHints
    {
        return new DirectiveNameHints(self::universe());
    }
}
