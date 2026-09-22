<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Exclusion;

use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelLevelSelector;
use Qualimetrix\Core\Pattern\NamespaceMatcher;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Symbol\SymbolLevel;

/** Stores per-rule namespace suppressions as already-bound namespace selectors. */
final class RuleNamespaceExclusionProvider
{
    /** @var array<string, NamespaceMatcher> */
    private array $matchers = [];

    /** @var array<string, list<NamespacePattern>> */
    private array $exclusions = [];

    /** @var array<string, array<string, NamespaceMatcher>> */
    private array $channelMatchers = [];

    /** @var array<string, array<string, list<NamespacePattern>>> */
    private array $channelExclusions = [];

    /** @param list<NamespacePattern> $patterns */
    public function setExclusions(string $ruleName, array $patterns): void
    {
        if ($patterns === []) {
            return;
        }

        $this->exclusions[$ruleName] = $patterns;
        $this->matchers[$ruleName] = new NamespaceMatcher($patterns);
    }

    /** @param list<NamespacePattern> $patterns */
    public function setChannelExclusions(string $ruleName, string $channelSelector, array $patterns): void
    {
        if ($patterns === []) {
            return;
        }

        $this->channelExclusions[$ruleName][$channelSelector] = $patterns;
        $this->channelMatchers[$ruleName][$channelSelector] = new NamespaceMatcher($patterns);
    }

    /** @return list<NamespacePattern> */
    public function getExclusions(string $ruleName): array
    {
        return $this->exclusions[$ruleName] ?? [];
    }

    /** @return array<string, list<NamespacePattern>> */
    public function getChannelExclusions(string $ruleName): array
    {
        return $this->channelExclusions[$ruleName] ?? [];
    }

    public function isExcluded(string $ruleName, string $namespace): bool
    {
        return isset($this->matchers[$ruleName]) && $this->matchers[$ruleName]->matches($namespace) !== null;
    }

    public function isChannelExcluded(string $ruleName, FindingChannel $channel, string $namespace): bool
    {
        foreach ($this->channelMatchers[$ruleName] ?? [] as $selector => $matcher) {
            if (ChannelLevelSelector::tryParse($selector)?->matches($channel->code, SymbolLevel::Namespace_) === true
                && $matcher->matches($namespace) !== null) {
                return true;
            }
        }

        return false;
    }

    public function reset(): void
    {
        $this->matchers = [];
        $this->exclusions = [];
        $this->channelMatchers = [];
        $this->channelExclusions = [];
    }

}
