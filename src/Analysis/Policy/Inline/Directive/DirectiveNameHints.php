<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive;

use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\NameSelector;

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
 * surgery on what was typed. There is no suffix to strip since a channel name
 * carries no level: stripping anything off `architecture.coverage` gives a
 * name that is not a rule, and two rules of the forty-one do not derive their
 * channel codes from their name at all.
 */
final readonly class DirectiveNameHints
{
    private const int SUGGESTION_LIMIT = 5;

    /**
     * How far a name may be from a real one and still be offered as a
     * suggestion. A list is useful only while it is short: past this,
     * "no close match" tells the author more than five unrelated names.
     *
     * Public because it is the radius the published channel order becomes
     * observable within, and the guard that measures that
     * ({@see \Qualimetrix\Tests\Analysis\Finding\Integration\ChannelSuggestionTieTest})
     * has to read it rather than restate it: a raised radius with a stale
     * literal beside it would leave the guard passing on a distance nothing
     * uses any more.
     */
    public const int SUGGESTION_DISTANCE = 5;

    /**
     * The two views this class reads, and not the composite that joins them:
     * an intersection because a single object answers both — the run's channel
     * universe — while naming
     * {@see \Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface}
     * here would additionally hand this class the producer-expansion view it
     * has no business asking.
     */
    public function __construct(
        private ChannelIdentityInterface&ChannelDeclarationRegistryInterface $universe,
    ) {}

    /**
     * The advice for a suppression whose selector covers no channel, in the
     * order the answer is worth anything: the channels of the rule they
     * named, the parent they asked for descendants of, then near spellings.
     *
     * **Every branch here is advice, not an inventory**, and that is what
     * decides how each treats the one channel a directive may not carry. The
     * method is reached only from a directive that already failed to address
     * anything, and every sentence it returns ends up in the author's editor as
     * the name they type next. So a branch that named the banned channel would
     * hand back a directive the next run refuses — which is the same defect on
     * the descendants branch ("write X to address it") and on the rule branch
     * ("its channels are ...") as it was on the near-spelling one, however
     * differently each phrases it.
     *
     * The rule branch therefore says *addressable* channels and means it: a
     * reader who wants the full channel list of a rule has `qmx rules`, which
     * answers "what does this rule produce" without pretending to answer "what
     * may I write here".
     */
    public function forChannelSelector(NameSelector $selector): string
    {
        $name = $selector->name();

        if ($selector->selectsDescendantsOnly()) {
            if (!$this->universe->hasChannel($name)) {
                return self::listOrNothing($this->nearestChannels($name));
            }

            return DirectiveChannelBan::covers($name)
                ? \sprintf('"%s" has no channels below it, and no directive may address it either.', $name)
                : \sprintf('"%s" has no channels below it; write "%s" to address it.', $name, $name);
        }

        $ownedByRule = self::addressable($this->channelsOf($name));
        if ($ownedByRule !== []) {
            return \sprintf(
                '"%s" names a rule, not a channel. Its addressable channels are: %s.',
                $name,
                implode(', ', $ownedByRule),
            );
        }

        return self::listOrNothing($this->nearestChannels($name));
    }

    /**
     * The advice for a threshold naming no rule, in the order the answer is
     * worth anything: the name is a *channel* (the common case — the report
     * prints channel names), the name is a *metric* some channel judges, then
     * near spellings.
     *
     * The metric branch sits ahead of the near-spelling search because that
     * search cannot reach it: `complexity.ccn` is eight edits from
     * `complexity.cyclomatic`, so an author who typed the metric key they read
     * in a report used to be told that no declared name was close to it.
     */
    public function forRuleName(string $name): string
    {
        $producer = $this->universe->producerOf($name);
        if ($producer !== null) {
            return \sprintf('"%s" is a channel of rule "%s" — a threshold addresses the rule.', $name, $producer);
        }

        $judging = $this->channelsJudging($name);
        if ($judging !== []) {
            return \sprintf(
                '"%s" is a metric, not a rule. It is judged by %s — a threshold addresses the rule.',
                $name,
                implode(', ', $judging),
            );
        }

        return self::listOrNothing(self::nearest($name, $this->universe->ruleNames()));
    }

    /**
     * The declared "this channel judges that metric" pairs whose metric is the
     * name that was typed, each as `channel "X" of rule "Y"`.
     *
     * Both halves are printed even where they spell the same, which is every
     * judging channel today: the sentence has to name the rule a threshold may
     * address, and a reader given only one name cannot tell which of the two
     * questions it answers.
     *
     * Answered from the declarations rather than from the spelling of the
     * name, which is the whole point: `complexity.ccn` and
     * `complexity.cyclomatic` are one letter short of unrelated as strings,
     * and a channel whose code happens to equal a metric key says nothing
     * about whether it reads that metric. A metric two channels judge yields
     * two pairs, because both are things the author could have meant.
     *
     * The run-time computed-metric family is out of scope by construction: a
     * `computed.*` channel's number is its own formula's result, never a
     * catalog metric, so no declaration there carries judged metrics to match.
     *
     * @return list<string>
     */
    private function channelsJudging(string $metricKey): array
    {
        $pairs = [];

        foreach ($this->universe->staticDeclarations() as $code => $declaration) {
            if ($declaration->judges === null || !\in_array($metricKey, $declaration->judges->keys, true)) {
                continue;
            }

            $producer = $this->universe->producerOf($code);

            // A declared channel with no producer cannot be advised about: the
            // sentence exists to name the rule a threshold should address.
            if ($producer !== null) {
                $pairs[] = \sprintf('channel "%s" of rule "%s"', $code, $producer);
            }
        }

        sort($pairs);

        return $pairs;
    }

    /** @return list<string> */
    private function channelsOf(string $ruleName): array
    {
        $codes = [];

        foreach ($this->universe->channels() as $channel) {
            if ($this->universe->producerOf($channel->code) === $ruleName) {
                $codes[] = $channel->code;
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
     * Both hops offer addressable names only, which is why the banned channel
     * is filtered out of the candidates rather than out of the answer: it is
     * near-spelled by everything in its own family, so an author who mistyped a
     * neighbouring `annotation.*` name would be handed the one name a directive
     * may not carry, and the directive written from that advice is refused.
     *
     * @return list<string>
     */
    private function nearestChannels(string $name): array
    {
        $near = self::nearest($name, self::addressable(array_map(
            static fn(FindingChannel $channel): string => $channel->code,
            $this->universe->channels(),
        )));

        if ($near !== []) {
            return $near;
        }

        foreach (self::nearest($name, $this->universe->ruleNames()) as $ruleName) {
            $near = [...$near, ...self::addressable($this->channelsOf($ruleName))];
        }

        return \array_slice($near, 0, self::SUGGESTION_LIMIT);
    }

    /**
     * The candidates a directive could actually carry.
     *
     * Applied to every list this class offers, so a rule whose channels are all
     * banned yields no list at all and falls through to the near-spelling
     * search — an empty "its channels are" is a worse answer than a guess.
     *
     * @param list<string> $codes
     *
     * @return list<string>
     */
    private static function addressable(array $codes): array
    {
        return array_values(array_filter(
            $codes,
            static fn(string $code): bool => !DirectiveChannelBan::covers($code),
        ));
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
