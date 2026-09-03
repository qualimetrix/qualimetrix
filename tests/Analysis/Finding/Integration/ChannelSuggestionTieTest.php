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
 * It exists because two orders moved in one step: the three type-coverage
 * rules, whose relative order is now a decision in `DesignConfigurator`, and
 * `architecture.unassigned-class`, which moved five places when it became a
 * producer of its own. The first is reachable, the second is not, and this is
 * where both are measured rather than argued.
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
     * guarantee it stands behind is the one the step could not close in the
     * corpus.
     */
    private const int SUGGESTION_DISTANCE = DirectiveNameHints::SUGGESTION_DISTANCE;

    /**
     * The channel that changed position, and the ones it moved past when it
     * stopped being a channel of the layer-violation rule.
     */
    private const string MOVED = 'architecture.unassigned-class';

    private const array MOVED_PAST = [
        'architecture.coverage',
        'architecture.unreachable-layer',
        'architecture.potential-shadow',
        'architecture.empty-template',
        'architecture.pending-layer-matched',
    ];

    #[Test]
    public function noInputCanTieTheChannelThatChangedPositionAgainstAnythingItMovedPast(): void
    {
        $codes = self::channelCodes();

        foreach (self::MOVED_PAST as $other) {
            self::assertContains($other, $codes, $other);

            $distance = levenshtein(self::MOVED, $other);

            self::assertGreaterThan(
                2 * self::SUGGESTION_DISTANCE,
                $distance,
                \sprintf(
                    'A string within %d edits of both "%s" and "%s" would exist (they are %d apart), so the'
                    . ' published order between them would reach a "did you mean" answer, and moving one past the'
                    . ' other would move a finding\'s text.',
                    self::SUGGESTION_DISTANCE,
                    self::MOVED,
                    $other,
                    $distance,
                ),
            );
        }
    }

    /**
     * The counterweight: the guard above is only worth anything if a tie is
     * reachable in general. The three type-coverage channels are six edits
     * apart, so a string three from two of them exists, and
     * `design.type-coverage.propurn` is one.
     *
     * That string reaches a published `message` through the finding-gate
     * corpus, which is what makes the order below observable rather than
     * internal — and it used to be the reason the fixture could not stay there
     * across a rename of these three channels. The two vocabularies are
     * fourteen edits apart, so by the triangle inequality no single string is
     * within five of a channel under both: whichever spelling the corpus
     * holds, one side prints two suggestions and the other prints none, the
     * `message` field moves, and nothing could license that. `message` is a
     * compared field, and the only source of permission `delta-overreach`
     * consulted was a declared split, which produces moves of `channel`,
     * `rule` and `code` and of nothing else. Ш4c added the fixture once the
     * split names were on both sides; Ш5e3 retired it again for exactly that
     * reason.
     *
     * That premise no longer holds, and the fixture is no longer hostage to
     * it. `finding-gate/declared-field-moves.tsv` licenses one exact
     * `(surface, field, from, to)` quadruple at a time, so a step that moves
     * this `message` declares the move instead of removing the fixture that
     * produces it. What it costs was measured on the step that introduced the
     * form: one record's `message`, nine surfaces declared as exact diffs, and
     * a single licence row — `delta-overreach` reads the published
     * `"field": value` syntax, so only the surfaces publishing it need one.
     * The fixture therefore stays, and a renaming step pays a declaration
     * rather than a hole in what the gate observes.
     */
    #[Test]
    public function aTieIsReachableBetweenTheThreeTypeCoverageChannels(): void
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
    public function theTiedTypeCoverageChannelsAreYieldedInTheDeclaredOrder(): void
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
