<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Exclusion;

use InvalidArgumentException;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelLevelSelector;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Util\NamespaceMatcher;

/**
 * Stores per-rule namespace exclusions and provides namespace matching.
 *
 * Extracted from config during RuleOptionsFactory::create() and consumed
 * by RuleExecution to filter findings at framework level.
 */
final class RuleNamespaceExclusionProvider
{
    /** @var array<string, NamespaceMatcher> */
    private array $matchers = [];

    /** @var array<string, list<string>> raw patterns for getExclusions() */
    private array $exclusions = [];

    /** @var array<string, array<string, NamespaceMatcher>> rule name => channel selector => namespace-aggregate matcher */
    private array $channelMatchers = [];

    /** @var array<string, array<string, list<string>>> raw channel patterns for getChannelExclusions() */
    private array $channelExclusions = [];

    /**
     * @param list<string> $patterns Namespace patterns (prefixes or globs)
     */
    public function setExclusions(string $ruleName, array $patterns): void
    {
        if ($patterns === []) {
            return;
        }

        $this->exclusions[$ruleName] = $patterns;
        $this->matchers[$ruleName] = new NamespaceMatcher($patterns);
    }

    public function configureExclusions(string $ruleName, mixed $patterns): void
    {
        if ($patterns === null || $patterns === []) {
            return;
        }

        $patterns = \is_string($patterns) ? [$patterns] : $patterns;
        if (!\is_array($patterns) || !array_is_list($patterns)) {
            throw new InvalidArgumentException(\sprintf(
                'Option "exclude_namespaces" for rule "%s" must be a string or a list of strings; use "exclude_namespace_channels" for namespace-aggregate channel exclusions',
                $ruleName,
            ));
        }

        $this->setExclusions($ruleName, $this->validatePatterns($ruleName, 'exclude_namespaces', $patterns));
    }

    /**
     * Stores namespace-aggregate exclusions scoped to one channel selector.
     *
     * The selector is a {@see ChannelLevelSelector}: an exact channel name, or
     * `X.*` for its strict descendants, either optionally narrowed to one
     * level with `:level`. A bare prefix such as `health` no longer stands for
     * a group — write `health.*`.
     *
     * @param list<string> $patterns Namespace patterns (prefixes or globs)
     */
    public function setChannelExclusions(string $ruleName, string $channelSelector, array $patterns): void
    {
        if ($patterns === []) {
            return;
        }

        $this->channelExclusions[$ruleName][$channelSelector] = $patterns;
        $this->channelMatchers[$ruleName][$channelSelector] = new NamespaceMatcher($patterns);
    }

    /**
     * Validates and stores the `exclude_namespace_channels` configuration map.
     *
     * @param array<mixed> $channelPatterns
     */
    public function configureChannelExclusions(string $ruleName, mixed $channelPatterns): void
    {
        if ($channelPatterns === null) {
            return;
        }

        if (!\is_array($channelPatterns) || $channelPatterns === [] || array_is_list($channelPatterns)) {
            throw new InvalidArgumentException(\sprintf(
                'Option "exclude_namespace_channels" for rule "%s" must be a non-empty channel map',
                $ruleName,
            ));
        }

        foreach ($channelPatterns as $selector => $patterns) {
            $validatedSelector = $this->validateSelector($ruleName, $selector);
            $validatedPatterns = $this->validateChannelPatterns($ruleName, $validatedSelector, $patterns);
            $this->setChannelExclusions($ruleName, $validatedSelector, $validatedPatterns);
        }
    }

    private function validateSelector(string $ruleName, mixed $selector): string
    {
        if (!\is_string($selector) || trim($selector) === '') {
            throw new InvalidArgumentException(\sprintf(
                'Option "exclude_namespace_channels" for rule "%s" contains an empty or non-string channel selector',
                $ruleName,
            ));
        }

        return $selector;
    }

    /**
     * @return list<string>
     */
    private function validateChannelPatterns(string $ruleName, string $selector, mixed $patterns): array
    {
        if (!\is_array($patterns) || !array_is_list($patterns) || $patterns === []) {
            throw new InvalidArgumentException(\sprintf(
                'Option "exclude_namespace_channels.%s" for rule "%s" must be a non-empty list of strings',
                $selector,
                $ruleName,
            ));
        }

        return $this->validatePatterns(
            $ruleName,
            'exclude_namespace_channels.' . $selector,
            $patterns,
        );
    }

    /**
     * @param list<mixed> $patterns
     *
     * @return list<string>
     */
    private function validatePatterns(string $ruleName, string $option, array $patterns): array
    {
        foreach ($patterns as $pattern) {
            if (!\is_string($pattern) || trim($pattern) === '') {
                throw new InvalidArgumentException(\sprintf(
                    'Option "%s" for rule "%s" must contain only non-empty strings',
                    $option,
                    $ruleName,
                ));
            }
        }

        /** @var list<string> $patterns */
        return $patterns;
    }

    /**
     * Returns the exclusion patterns for a given rule.
     *
     * @return list<string>
     */
    public function getExclusions(string $ruleName): array
    {
        return $this->exclusions[$ruleName] ?? [];
    }

    /**
     * Returns channel-scoped namespace-aggregate patterns for a rule.
     *
     * @return array<string, list<string>> channel selector => namespace patterns
     */
    public function getChannelExclusions(string $ruleName): array
    {
        return $this->channelExclusions[$ruleName] ?? [];
    }

    public function isExcluded(string $ruleName, string $namespace): bool
    {
        return isset($this->matchers[$ruleName]) && $this->matchers[$ruleName]->matches($namespace);
    }

    /**
     * The channel is taken whole rather than as a bare string so that the
     * caller cannot pass the *producer's* name by accident: `$ruleName` is the
     * rule the option is configured under, and the layer policy emits four
     * channels whose names no class declares as its own.
     *
     * The level a key is matched at is `namespace` and is not read from the
     * key: this option is offered namespace aggregates and nothing else, so a
     * key narrowed to any other level would describe a filter that can never
     * fire. Such a key is refused by name — before the run starts, by
     * {@see \Qualimetrix\Infrastructure\Console\ChannelExclusionKeyValidator} —
     * rather than judged a second time here, so one mistake keeps one answer.
     */
    public function isChannelExcluded(string $ruleName, FindingChannel $channel, string $namespace): bool
    {
        foreach ($this->channelMatchers[$ruleName] ?? [] as $selector => $matcher) {
            if (
                ChannelLevelSelector::tryParse($selector)?->matches($channel->code, SymbolLevel::Namespace_) === true
                && $matcher->matches($namespace)
            ) {
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
