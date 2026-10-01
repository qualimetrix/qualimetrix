<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Selection;

use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\RefusedName;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelLevelAddressing;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelLevelSelector;
use Qualimetrix\Analysis\Finding\Selection\RetiredRuleNames;
use Qualimetrix\Core\Symbol\SymbolLevel;

/** Authored producer owners and selectors judged against their declared universe. */
final readonly class RuleNameJudge
{
    /** @param list<string> $producers */
    public function __construct(private array $producers) {}

    public function judge(string $written): ?RefusedName
    {
        if (\in_array($written, $this->producers, true)) {
            return null;
        }
        $summary = \sprintf('Rule option owner "%s" does not match any registered producer rule.', $written);
        $replacement = RetiredRuleNames::replacementFor($written) ?? $this->closestTo($written);
        if ($replacement !== null) {
            $summary .= \sprintf(' Did you mean "%s"?', $replacement);
        }
        return RefusedName::among($summary, $this->producers);
    }

    public function selector(string $written, ChannelUniverseInterface $channels): ?RefusedName
    {
        if (FindingChannel::isRetiredPairSpelling($written)) {
            return RefusedName::open(\sprintf('Rule selector "%s" is written in the retired channel-pair form. %s', $written, FindingChannel::retiredPairAdvice($written)));
        }
        $problem = (new ChannelLevelAddressing($channels))->problemWith($written, \sprintf('Rule selector "%s"', $written));
        if ($problem !== null) {
            return RefusedName::open($problem);
        }
        $parsed = ChannelLevelSelector::tryParse($written);
        if ($parsed !== null && ($channels->expand($parsed->channel()) !== []
            || ($parsed->level() === null && array_any($channels->ruleNames(), $parsed->channel()->matches(...))))) {
            return null;
        }
        return RefusedName::open(
            \sprintf('Rule selector "%s" does not match any registered producer or channel.', $written)
            . $this->selectorAdvice($written, $channels),
        );
    }

    private function selectorAdvice(string $written, ChannelUniverseInterface $channels): string
    {
        $replacement = RetiredRuleNames::replacementFor($written);
        if ($replacement !== null) {
            return \sprintf(' Write "%s" instead.', $replacement);
        }
        if ($written === 'computed.*' && \in_array('computed', $channels->ruleNames(), true)) {
            return ' Write "computed" to select the producer; "computed.*" selects only declared descendant channels.';
        }
        if (array_any($channels->ruleNames(), static fn(string $producer): bool => str_starts_with($producer, $written . '.'))) {
            return \sprintf(' Write "%s.*" to select its descendants.', $written);
        }
        $closest = $this->closestTo($written);
        return $closest === null ? '' : \sprintf(' Did you mean "%s"?', $closest);
    }

    public function namespaceChannel(string $producer, string $written, ChannelUniverseInterface $channels): ?RefusedName
    {
        $subject = \sprintf('Option "suppress_namespace_channels" for rule "%s", keyed by "%s",', $producer, $written);
        if (FindingChannel::isRetiredPairSpelling($written)) {
            return RefusedName::open($subject . ' is not a channel selector. ' . FindingChannel::retiredPairAdvice($written));
        }
        $problem = (new ChannelLevelAddressing($channels))->problemWithAtLevelAmong(
            $written,
            SymbolLevel::Namespace_,
            $channels->channelsProducedBy($producer),
            \sprintf('the channels of "%s"', $producer),
            $subject,
        );
        return $problem === null ? null : RefusedName::open($problem);
    }

    private function closestTo(string $written): ?string
    {
        $dot = strrpos($written, '.');
        $sameLeaf = $dot === false ? [] : array_values(array_filter($this->producers, static fn(string $name): bool => str_ends_with($name, substr($written, $dot))));
        if (\count($sameLeaf) === 1) {
            return $sameLeaf[0];
        }
        $best = null;
        $distance = 4;
        foreach ($sameLeaf === [] ? $this->producers : $sameLeaf as $name) {
            $next = levenshtein($written, $name);
            if ($next < $distance) {
                $best = $name;
                $distance = $next;
            }
        }
        return $best;
    }
}
