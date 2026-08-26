<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use InvalidArgumentException;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelLevelAddressing;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelLevelSelector;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * Whether one `exclude_namespace_channels` key addresses a channel the rule it
 * is written under produces, at a level this option can ever ask about.
 *
 * The key reads the one selector grammar there is: an exact channel name, or
 * `X.*` for the channels below it, either optionally narrowed to one level of
 * the aggregation tree with `:level`. Three spellings are refused by name
 * rather than left to fall through as unknown names — the retired
 * `ruleName#violationCode` pair, a `channel:level` pair the rule's own channels
 * cannot produce, and a level other than `namespace`. This option is the one
 * whose *key is a channel*, so it is where a stale spelling is most likely to
 * have been written down.
 *
 * The impossible pair is not decided here: it is decided by
 * {@see ChannelLevelAddressing}, the same object the inline directives ask, so
 * the configuration and the annotation families cannot answer one mistake two
 * ways. What is decided here is that the answer ends the run.
 *
 * **One witness, not two.** A key carrying a level asks the seam its *narrowed*
 * question, {@see ChannelLevelAddressing::problemWithAmong()}, whose witness
 * must be a single channel: covered by the key, produced by this rule, and
 * reporting at the level. Asking the global question and then checking
 * production separately accepted `coupling.*:namespace` under
 * `coupling.class-rank` — the level was witnessed by `coupling.cbo` and the
 * production by `coupling.class-rank`, and neither witness satisfied the
 * other's condition. A level-free key has no level witness to disagree with,
 * so it keeps the production check that can also name the rule's channels back
 * to the author.
 *
 * **The level is `namespace` or nothing.** `RuleExecution` offers this option
 * only findings whose subject is a namespace, and
 * {@see \Qualimetrix\Analysis\Finding\Exclusion\RuleNamespaceExclusionProvider}
 * matches every key against `namespace` and no other level. A key naming any
 * other level therefore describes a filter that can never fire, however real
 * the channel and however truthfully it reports at that level:
 * `coupling.cbo:class` used to be accepted and excluded nothing. The level is
 * kept accepted rather than forbidden outright because writing it down is the
 * one way for a key to say "the namespace aggregate, not the class findings",
 * and this project's own configuration says it twice.
 *
 * **Production, not applicability.** A level-free key naming an occurrence or
 * class-only channel of the right rule passes here and still excludes nothing.
 * Refusing those would need a declared "can appear as a namespace aggregate"
 * property that `ChannelDeclaration` does not carry — see ADR 0025, which
 * records why a half-built version of that check was not worth having.
 */
final readonly class ChannelExclusionKeyValidator
{
    private const string OPTION = 'exclude_namespace_channels';

    /**
     * The level every key is matched at, whether or not it names one.
     *
     * Spelled here as well as in the provider that applies it, because a
     * refusal that recommends a spelling has to recommend one that works.
     */
    private const SymbolLevel APPLIED_LEVEL = SymbolLevel::Namespace_;

    public function __construct(
        private ChannelUniverseInterface $channels,
    ) {}

    /** @throws InvalidArgumentException when the key can never exclude anything */
    public function assertAddressesAProducedChannel(string $ruleName, string $key): void
    {
        // The retired pair goes first, here and in every other seam that reads
        // this grammar: its `#` half is not a name, so every later question
        // would call that half unparseable instead of naming the spelling that
        // was retired and the one that replaced it.
        if (FindingChannel::isRetiredPairSpelling($key)) {
            throw new InvalidArgumentException(ChannelExclusionKeyHints::notASelector($ruleName, $key));
        }

        $produced = $this->channels->channelsProducedBy($ruleName);

        if (ChannelLevelSelector::carriesLevelSeparator($key)) {
            $this->assertPairAddressesAProducedChannel($ruleName, $key, $produced);

            return;
        }

        $parsed = ChannelLevelSelector::tryParse($key)
            ?? throw new InvalidArgumentException(ChannelExclusionKeyHints::notASelector($ruleName, $key));

        $addressed = $this->channels->expand($parsed->channel());

        foreach ($addressed as $channel) {
            foreach ($produced as $candidate) {
                if ($candidate->equals($channel)) {
                    return;
                }
            }
        }

        throw new InvalidArgumentException(
            ChannelExclusionKeyHints::refusal($ruleName, $parsed->channel(), $addressed, $produced),
        );
    }

    /**
     * @param list<FindingChannel> $produced
     *
     * @throws InvalidArgumentException
     */
    private function assertPairAddressesAProducedChannel(string $ruleName, string $key, array $produced): void
    {
        $problem = (new ChannelLevelAddressing($this->channels))->problemWithAmong(
            $key,
            $produced,
            \sprintf('the channels of "%s"', $ruleName),
            self::subject($ruleName, $key),
        );

        if ($problem !== null) {
            throw new InvalidArgumentException($problem);
        }

        $parsed = ChannelLevelSelector::tryParse($key)
            ?? throw new InvalidArgumentException(ChannelExclusionKeyHints::notASelector($ruleName, $key));

        $level = $parsed->level();

        if ($level === null || $level === self::APPLIED_LEVEL) {
            return;
        }

        throw new InvalidArgumentException(\sprintf(
            '%s names level "%s", and this option removes namespace aggregates only: the one level it can name'
            . ' is "%s". Drop the level, or write "%s%s%s".',
            self::subject($ruleName, $key),
            $level->value,
            self::APPLIED_LEVEL->value,
            $parsed->channel(),
            ChannelLevelSelector::LEVEL_SEPARATOR,
            self::APPLIED_LEVEL->value,
        ));
    }

    /**
     * How this option names the text it read, so the seam's refusal is one
     * sentence about one subject rather than a wrapper's subject and its own.
     */
    private static function subject(string $ruleName, string $key): string
    {
        return \sprintf('Option "%s" for rule "%s", keyed by "%s",', self::OPTION, $ruleName, $key);
    }
}
