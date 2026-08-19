<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive;

use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\NameSelector;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;

/**
 * What the author could have meant, when a directive names something the run
 * cannot address.
 *
 * Separate from the rule that reports the mistake because it answers a
 * different question. The rule decides *whether* a directive is wrong — a
 * judgement about this run's universe. This decides what to *say* about it,
 * which is a search over names and nothing else: no directives, no findings,
 * no severity.
 *
 * Every answer is a reverse query against the universe rather than string
 * surgery on what was typed. Stripping `.class` off `coupling.cbo.class`
 * happens to give the right rule; stripping anything off `architecture.coverage`
 * does not, and two rules of the forty-one do not derive their channel codes
 * from their name at all.
 */
final readonly class DirectiveNameHints
{
    private const int SUGGESTION_LIMIT = 5;

    /**
     * How far a name may be from a real one and still be offered as a
     * suggestion. A list is useful only while it is short: past this,
     * "no close match" tells the author more than five unrelated names.
     */
    private const int SUGGESTION_DISTANCE = 5;

    public function __construct(
        private ChannelIdentityInterface $identity,
    ) {}

    /**
     * The advice for a suppression whose selector covers no channel, in the
     * order the answer is worth anything: the channels of the rule they
     * named, the parent they asked for descendants of, then near spellings.
     */
    public function forChannelSelector(NameSelector $selector): string
    {
        $name = $selector->name();

        if ($selector->selectsDescendantsOnly()) {
            return $this->identity->hasChannel($name)
                ? \sprintf('"%s" has no channels below it; write "%s" to address it.', $name, $name)
                : self::listOrNothing($this->nearestChannels($name));
        }

        $ownedByRule = $this->channelsOf($name);
        if ($ownedByRule !== []) {
            return \sprintf(
                '"%s" names a rule, not a channel. Its channels are: %s.',
                $name,
                implode(', ', $ownedByRule),
            );
        }

        return self::listOrNothing($this->nearestChannels($name));
    }

    /**
     * The advice for a threshold naming no rule. A name that turns out to be
     * a *channel* is the common case — the report prints channel names — so
     * it is answered with the producing rule rather than a guess.
     */
    public function forRuleName(string $name): string
    {
        $producer = $this->identity->producerOf($name);
        if ($producer !== null) {
            return \sprintf('"%s" is a channel of rule "%s" — a threshold addresses the rule.', $name, $producer);
        }

        return self::listOrNothing(self::nearest($name, $this->identity->ruleNames()));
    }

    /** @return list<string> */
    private function channelsOf(string $ruleName): array
    {
        $codes = [];

        foreach ($this->identity->channels() as $channel) {
            if ($this->identity->producerOf($channel->violationCode) === $ruleName) {
                $codes[] = $channel->violationCode;
            }
        }

        sort($codes);

        return $codes;
    }

    /**
     * Nearest addressable channels, and — when nothing is near — the channels
     * of the nearest *rule*.
     *
     * The second hop is what makes a one-character typo useful: a
     * multi-channel rule's name is far from every one of its channels (the
     * suffix alone outruns any sane edit distance), so a misspelled rule name
     * would otherwise get "no close match" while its channels sat one letter
     * away from what the author meant.
     *
     * @return list<string>
     */
    private function nearestChannels(string $name): array
    {
        $near = self::nearest($name, array_map(
            static fn(ViolationChannel $channel): string => $channel->violationCode,
            $this->identity->channels(),
        ));

        if ($near !== []) {
            return $near;
        }

        foreach (self::nearest($name, $this->identity->ruleNames()) as $ruleName) {
            $near = [...$near, ...$this->channelsOf($ruleName)];
        }

        return \array_slice($near, 0, self::SUGGESTION_LIMIT);
    }

    /**
     * @param list<string> $candidates
     *
     * @return list<string>
     */
    private static function nearest(string $name, array $candidates): array
    {
        $scored = [];
        foreach (array_unique($candidates) as $candidate) {
            $distance = levenshtein($name, $candidate);
            if ($distance <= self::SUGGESTION_DISTANCE) {
                $scored[$candidate] = $distance;
            }
        }

        asort($scored);

        return \array_slice(array_keys($scored), 0, self::SUGGESTION_LIMIT);
    }

    /** @param list<string> $names */
    private static function listOrNothing(array $names): string
    {
        return $names === []
            ? 'No declared name is close to it.'
            : \sprintf('Addressable names closest to it: %s.', implode(', ', $names));
    }
}
