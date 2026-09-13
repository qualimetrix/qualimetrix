<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\NameSelector;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\InlineDirectivePolicyInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;

/**
 * The channels an inline directive may neither address nor silence, one entry
 * each because each is banned for a different reason.
 *
 * `annotation.unused-directive` reports the directives that did nothing this
 * run. A directive silencing it hides the answer to the question the channel
 * exists to ask — about itself as readily as about its neighbours — so the
 * arrangement is not debt an author may accept in place: the directive is
 * refused where it is written.
 *
 * `duplication.clone` reports one finding per duplicate block,
 * aggregated at project level ({@see \Qualimetrix\Analysis\Evidence\Duplication\CodeDuplicationRule::channelDeclarations()}
 * declares {@see \Qualimetrix\Core\Symbol\SymbolLevel::Project} and nothing
 * else). No directive form binds to that aggregate in a way an author
 * controls: a symbol directive binds to the declaration it is written on, and
 * the project is never that declaration; a file or next-line directive is
 * matched against the finding's `Location`, which the rule sets to the
 * block's first occurrence — an implementation detail of which copy the
 * duplicate scan visits first, not a stable target. On a two-file fixture:
 * a symbol directive on either copy's declaration never suppresses the
 * finding; a file or next-line directive suppresses it only when placed in
 * whichever file happens to hold the first occurrence, and does nothing —
 * silently, becoming `annotation.unused-directive` — in the other. Refusing
 * every form here closes that accident rather than leaving it to chance.
 *
 * **Two questions, one list, and that is why they live together.** Can this
 * target be addressed at all ({@see problemWith()}, read by the two halves that
 * judge an authored directive), and could a directive have silenced this
 * finding ({@see covers()}, read by the publication filter). The second exists
 * because the first cannot reach the form without a rule filter: it names no
 * channel, so there is nothing to refuse, and it silenced the channel today by
 * covering everything. Two spellings of a channel's identity would be two
 * chances to ban one of them and not the other.
 *
 * What the ban is **not**: an exemption from the report. A finding on either
 * channel is ordinary debt — ratchetable, dropped by the top-level
 * `suppress_paths`, inside a git scope like any other — and passes every stage
 * after suppression. Only the configuration errors are lifted out of the
 * pipeline, and neither channel is one. Two exclusions never reached
 * `annotation.unused-directive` and still do not: `suppress_namespaces`
 * matches a namespace, and this finding's subject is the file; the producer's
 * own `exclude_*` keys run inside rule execution, and the channel is
 * assembled after it. The working path for `duplication.clone` is
 * channel-level: `disabled_rules: [duplication.clone]` /
 * `--disable-rule=duplication.clone`, or accepting individual
 * occurrences in the baseline.
 */
final readonly class DirectiveChannelBan
{
    /**
     * Raw string rather than an imported `CodeDuplicationRule::NAME`: Inline
     * depends on Finding's contracts, never on another evidence capability's
     * rule class. The name is pinned by
     * {@see \Qualimetrix\Tests\Analysis\Finding\Integration\RuleIdentifierLiteralGuardTest::itRequiresEveryNamedFileToStillEarnItsEntry()},
     * whose staleness check resolves this literal to its live owning
     * capability through the container and fails if a rename on the rule's
     * side leaves this file's copy unowned.
     */
    private const string PROJECT_ONLY_DUPLICATION_NAME = 'duplication.clone';

    public function __construct(
        private ChannelIdentityInterface $identity,
    ) {}

    /** Whether a finding is on a banned channel. */
    public static function covers(string $code): bool
    {
        return $code === InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME
            || $code === self::PROJECT_ONLY_DUPLICATION_NAME;
    }

    /**
     * The refusal for a target that reaches a banned channel, or null.
     *
     * Answered against the expansion rather than against the authored text, so
     * the group form is refused for the same reason the exact name is: the
     * question is which channels a directive would silence, and only the
     * expansion knows. It reads {@see ChannelIdentityInterface::expand()} and
     * does not narrow it — the configuration family asks the same object the
     * same question, and a narrowed expansion would silently shrink the
     * `annotation.*` spelling of an `exclude_*_channels` key.
     */
    public function problemWith(string $raw, NameSelector $selector): ?string
    {
        foreach ($this->identity->expand($selector) as $channel) {
            if (!self::covers($channel->code)) {
                continue;
            }

            return self::message($raw, $channel->code);
        }

        return null;
    }

    /**
     * The wording for one banned channel, kept out of {@see problemWith()} so
     * that method reads {@see covers()} — the same predicate the publication
     * filter reads — rather than repeating the two names beside it. Only
     * ever called with a code {@see covers()} already accepted; the `default`
     * arm is unreachable by that contract and exists so a third banned name
     * added to `covers()` without a wording here fails loudly at runtime
     * instead of falling through to one of the two existing messages.
     */
    private static function message(string $raw, string $code): string
    {
        return match ($code) {
            InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME => \sprintf(
                'Suppression "%s" addresses "%s", which no directive may silence: that channel reports the'
                . ' directives that did nothing, so silencing it would hide the answer. Delete the directive'
                . ' it complains about, or accept the finding in the baseline. A reason goes after "%s".',
                $raw,
                InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME,
                Suppression::REASON_SEPARATOR,
            ),
            self::PROJECT_ONLY_DUPLICATION_NAME => \sprintf(
                'Suppression "%s" addresses "%s", which reports one project-wide finding per duplicate'
                . ' block: no declaration a symbol directive binds to is the project, and the file a file or'
                . ' next-line directive names is only the block\'s first occurrence, which the scan chooses'
                . ' and the author does not control. Disable the rule instead:'
                . ' "disabled_rules: [%s]" in the configuration, or "--disable-rule=%s", or accept the'
                . ' occurrence in the baseline. A reason goes after "%s".',
                $raw,
                self::PROJECT_ONLY_DUPLICATION_NAME,
                self::PROJECT_ONLY_DUPLICATION_NAME,
                self::PROJECT_ONLY_DUPLICATION_NAME,
                Suppression::REASON_SEPARATOR,
            ),
            default => throw new LogicException(\sprintf(
                'DirectiveChannelBan::covers() accepted "%s" but message() has no wording for it.',
                $code,
            )),
        };
    }
}
