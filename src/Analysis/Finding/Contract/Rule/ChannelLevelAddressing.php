<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;

/**
 * The one place an authored `channel:level` pair is judged; the words of that
 * judgement are written in {@see ChannelLevelRefusalWording}, which is the
 * other half of this same seam and has no other caller.
 *
 * Ш5c widened the addressing vocabulary from three values to five, and a
 * level a channel does not report at is now a thing a user can write. There
 * was no seam that could refuse it: the configuration one
 * ({@see \Qualimetrix\Infrastructure\Console\ChannelExclusionKeyValidator})
 * throws, but only on the option whose key is a channel, and the inline one
 * ({@see \Qualimetrix\Analysis\Policy\Inline\Directive\DirectiveAddressability})
 * reports, but only on a target that already parsed. Two seams deciding this
 * separately would give the two families of directive different answers to
 * the same mistake, so both ask here and neither decides.
 *
 * What each seam keeps is how it is *loud*: configuration and CLI fail the run
 * before analysis starts, an inline directive is reported on
 * `annotation.unresolved-directive`, which is a configuration error and ends
 * the run too. What neither may do is swallow the pair — the outcome
 * `baseline:explain` used to give, where a level nobody reports at looked like
 * a selector that simply matched nothing.
 *
 * Round 11 found what a single *existence* question is not enough for, so the
 * seam answers three questions rather than one:
 *
 * - {@see problemWith()} — can the pair exist **anywhere**? For seams with no
 *   candidate set of their own: CLI selection selectors, inline directives.
 * - {@see problemWithAmong()} — can it exist **inside this set of channels**?
 *   The witness must be one channel: covered by the selector, a member of the
 *   set, and reporting at the level. A seam that asked the global question and
 *   then checked membership separately accepted `coupling.*:namespace` under
 *   `coupling.class-rank`, because the level witness was `coupling.cbo` and
 *   the membership witness was `coupling.class-rank`.
 * - {@see selectorsCoverEveryDeclaredLevelOf()} — do these selectors, taken
 *   **together**, silence every declared level of every one of these channels?
 *   The stop question of a producer, which is quantified over the producer and
 *   not over one channel: `X:callable` and `X:class` together cover what `X`
 *   covers.
 *
 * Text carrying no separator is not the pair questions' business and is
 * answered `null`: whether such text addresses anything is the question each
 * seam already answered before this one existed. The one exception is
 * {@see problemWithAmong()}, whose whole subject is the candidate set, so it
 * answers membership for level-free text too.
 *
 * Three refusals for a pair, and they are deliberately distinguishable because
 * the correction differs:
 *
 * - the half after the separator is not a level at all;
 * - the channel half addresses nothing, so no level of it can exist;
 * - the channel is real and does not report at that level.
 *
 * Every method takes the caller's own name for the text it read — its
 * `$subject` — and returns a whole sentence about it. Refusals used to be
 * assembled by two authors, one supplying `Suppression %s` and this one
 * supplying `Channel selector "…" addresses …`, which read as two subjects in
 * one sentence. Passing the subject in keeps the wording, the order of the
 * checks and the corrective advice inside the seam, where they can be
 * compared, and leaves the caller only the part it alone knows: what to call
 * the thing the user wrote.
 */
final readonly class ChannelLevelAddressing
{
    public function __construct(
        private ChannelIdentityInterface $identity,
    ) {}

    /**
     * What is wrong with an authored pair, or `null` when the text addresses
     * at least one channel that reports at the level it names.
     *
     * @param ?string $subject how the caller names the text it read; `null`
     *                         keeps the wording of the seams that have not
     *                         been handed their own subject yet
     */
    public function problemWith(string $raw, ?string $subject = null): ?string
    {
        if (!ChannelLevelSelector::carriesLevelSeparator($raw)) {
            return null;
        }

        return $this->refusePair($raw, null, null, $subject);
    }

    /**
     * The same question narrowed to a set of channels the caller already has —
     * the channels a rule produces, the channels a run enabled — answered by
     * **one** witness channel that satisfies every condition at once.
     *
     * Level-free text is answered too, because membership is the whole point
     * of the question. Two cases are still handed back: text that is not a
     * selector at all, and a selector that addresses nothing anywhere. Both
     * are questions the caller answered before this seam existed, and it has
     * the better words for them — a did-you-mean hint this seam cannot build.
     *
     * @param list<FindingChannel> $candidates
     * @param string $candidatesAre a noun phrase naming the set,
     *                              read as "addresses none of …"
     */
    public function problemWithAmong(
        string $raw,
        array $candidates,
        string $candidatesAre,
        ?string $subject = null,
    ): ?string {
        if (ChannelLevelSelector::carriesLevelSeparator($raw)) {
            return $this->refusePair($raw, $candidates, $candidatesAre, $subject);
        }

        $selector = NameSelector::tryParse($raw);

        if ($selector === null || $this->identity->expand($selector) === []) {
            return null;
        }

        if ($this->within($selector, $candidates) !== []) {
            return null;
        }

        return ChannelLevelRefusalWording::addressesNoneOf($subject, $raw, $candidatesAre);
    }

    /**
     * Whether these selectors, taken together, address every declared level of
     * every one of these channels — the condition under which narrowing by
     * level is the same thing as not narrowing at all, and a producer can
     * therefore be stopped rather than run and filtered.
     *
     * Two answers are `false` on purpose and are not edge cases:
     *
     * - a channel declaring **no** level is not covered. An empty set of
     *   levels makes "every level is addressed" trivially true, which would
     *   stop a producer whose levels come from configuration rather than from
     *   its declaration — the `computed.*` / `health.*` case;
     * - an empty set of channels is not covered either, for the same reason
     *   from the other side.
     *
     * @param list<string> $selectors authored selector text, parsed here so
     *                                the caller cannot narrow by a spelling
     *                                this grammar rejects
     * @param list<string> $channelCodes every channel of the producer in
     *                                   question
     */
    public function selectorsCoverEveryDeclaredLevelOf(array $selectors, array $channelCodes): bool
    {
        if ($channelCodes === []) {
            return false;
        }

        $parsed = [];

        foreach ($selectors as $raw) {
            $selector = ChannelLevelSelector::tryParse($raw);

            if ($selector !== null) {
                $parsed[] = $selector;
            }
        }

        foreach ($channelCodes as $code) {
            $levels = $this->identity->levelsOf($code);

            if ($levels === []) {
                return false;
            }

            foreach ($levels as $level) {
                if (!self::anyMatches($parsed, $code, $level)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * What is wrong with text that addresses a **rule** and was written as a
     * pair — the `@qmx-threshold` mistake.
     *
     * A threshold does not distinguish levels (ADR 0024), so the pair is
     * always refused; the order the four refusals are tried in is the point.
     * Answering "addresses a channel at a level" first told an author who
     * mistyped the level, or the rule, to go and write a `--rule-opt` key
     * built out of that same mistyped half — a command the CLI accepts and
     * silently ignores. So the level half is checked first, the rule half
     * second, and the advice appears only once both have passed and the
     * spelling it recommends is one that works.
     *
     * Returns `null` when the text carries no separator: a plain rule name is
     * the caller's own question.
     */
    public function problemWithRulePair(string $raw, string $subject): ?string
    {
        if (!ChannelLevelSelector::carriesLevelSeparator($raw)) {
            return null;
        }

        $level = ChannelLevelSelector::levelHalf($raw);
        $ruleHalf = ChannelLevelSelector::channelHalf($raw);

        if ($level === null) {
            return ChannelLevelRefusalWording::ruleNamesNoLevel($subject, $raw, self::levelWords());
        }

        if (!$this->identity->hasRule($ruleHalf)) {
            return ChannelLevelRefusalWording::namesNoRule($subject, $ruleHalf);
        }

        // Fourth refusal, and it exists for the same reason as the ordering
        // above: the advice must recommend a spelling that works. A rule that
        // declares no threshold support has no threshold to set at a level, so
        // both clauses of the level-blind wording — retune the whole rule, or
        // set the level with `--rule-opt` — would name commands that do
        // nothing. This became reachable when the computed-metric family
        // stopped being one producer: its seven names are addressable rules
        // that can never be retuned.
        if (!$this->identity->supportsThresholdOverride($ruleHalf)) {
            return ChannelLevelRefusalWording::thresholdCannotBeRetunedAtAnyLevel($subject, $ruleHalf, $level->value);
        }

        return ChannelLevelRefusalWording::thresholdIgnoresLevels($subject, $ruleHalf, $level->value);
    }

    /**
     * @param ?list<FindingChannel> $candidates the set the witness must belong
     *                                          to, or `null` for the global
     *                                          question
     */
    private function refusePair(
        string $raw,
        ?array $candidates,
        ?string $candidatesAre,
        ?string $subject,
    ): ?string {
        $channelHalf = ChannelLevelSelector::channelHalf($raw);
        $level = ChannelLevelSelector::levelHalf($raw);

        if ($level === null) {
            return ChannelLevelRefusalWording::noLevelAfterSeparator($subject, $raw, self::levelWords());
        }

        $channel = NameSelector::tryParse($channelHalf);

        if ($channel === null) {
            return ChannelLevelRefusalWording::channelHalfIsNotASelector($subject, $raw, $channelHalf, $level->value);
        }

        $addressed = $this->identity->expand($channel);

        if ($addressed === []) {
            return ChannelLevelRefusalWording::addressesNoChannel($subject, $raw, $level->value);
        }

        if ($candidates !== null) {
            $addressed = $this->within($channel, $candidates);

            if ($addressed === []) {
                return ChannelLevelRefusalWording::addressesNoneOfSoNoLevelEither($subject, $raw, $candidatesAre);
            }
        }

        return $this->refuseLevel($addressed, $level, $subject, $channelHalf);
    }

    /**
     * The last condition of the pair, and the only one whose refusal needs the
     * levels every addressed channel declares.
     *
     * @param list<FindingChannel> $addressed the channels the pair does address
     */
    private function refuseLevel(
        array $addressed,
        SymbolLevel $level,
        ?string $subject,
        string $channelHalf,
    ): ?string {
        $reported = [];

        foreach ($addressed as $candidate) {
            $levels = $this->identity->levelsOf($candidate->code);

            if (\in_array($level, $levels, true)) {
                return null;
            }

            foreach ($levels as $declared) {
                $reported[$declared->value] = true;
            }
        }

        return ChannelLevelRefusalWording::noneReportsAtLevel(
            $subject,
            $channelHalf,
            array_map(static fn(FindingChannel $channel): string => $channel->code, $addressed),
            $level->value,
            array_map(strval(...), array_keys($reported)),
        );
    }

    /**
     * The channels the selector covers **and** the caller already has, in the
     * caller's order.
     *
     * @param list<FindingChannel> $candidates
     *
     * @return list<FindingChannel>
     */
    private function within(NameSelector $selector, array $candidates): array
    {
        return array_values(array_filter(
            $candidates,
            static fn(FindingChannel $candidate): bool => $selector->matches($candidate->code),
        ));
    }

    /**
     * The level words a refusal offers the author, which is every case of the
     * vocabulary and not a subset this seam chose.
     *
     * @return list<string>
     */
    private static function levelWords(): array
    {
        return array_map(static fn(SymbolLevel $level): string => $level->value, SymbolLevel::cases());
    }

    /** @param list<ChannelLevelSelector> $selectors */
    private static function anyMatches(array $selectors, string $code, SymbolLevel $level): bool
    {
        foreach ($selectors as $selector) {
            if ($selector->matches($code, $level)) {
                return true;
            }
        }

        return false;
    }
}
