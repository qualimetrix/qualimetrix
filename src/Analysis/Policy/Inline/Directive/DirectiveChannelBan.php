<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive;

use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\NameSelector;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\InlineDirectivePolicyInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;

/**
 * The one channel an inline directive may neither address nor silence.
 *
 * `annotation.unused-directive` reports the directives that did nothing this
 * run. A directive silencing it hides the answer to the question the channel
 * exists to ask — about itself as readily as about its neighbours — so the
 * arrangement is not debt an author may accept in place: the directive is
 * refused where it is written.
 *
 * **Two questions, one channel, and that is why they live together.** Can this
 * target be addressed at all ({@see problemWith()}, read by the two halves that
 * judge an authored directive), and could a directive have silenced this
 * finding ({@see covers()}, read by the publication filter). The second exists
 * because the first cannot reach the form without a rule filter: it names no
 * channel, so there is nothing to refuse, and it silenced the channel today by
 * covering everything. Two spellings of the channel's identity would be two
 * chances to ban one of them and not the other.
 *
 * What the ban is **not**: an exemption from the report. A finding on this
 * channel is ordinary debt — ratchetable, excludable, inside a git scope like
 * any other — and passes every stage after suppression. Only the configuration
 * errors are lifted out of the pipeline, and this channel is not one.
 */
final readonly class DirectiveChannelBan
{
    public function __construct(
        private ChannelIdentityInterface $identity,
    ) {}

    /** Whether a finding is on the banned channel. */
    public static function covers(string $code): bool
    {
        return $code === InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME;
    }

    /**
     * The refusal for a target that reaches the banned channel, or null.
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

            return \sprintf(
                'Suppression "%s" addresses "%s", which no directive may silence: that channel reports the'
                . ' directives that did nothing, so silencing it would hide the answer. Delete the directive'
                . ' it complains about, or accept the finding in the baseline. A reason goes after "%s".',
                $raw,
                InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME,
                Suppression::REASON_SEPARATOR,
            );
        }

        return null;
    }
}
