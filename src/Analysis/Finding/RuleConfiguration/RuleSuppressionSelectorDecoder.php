<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Throwable;

/** Decodes framework-owned suppression selectors from one rule configuration. */
final class RuleSuppressionSelectorDecoder
{
    /** @return list<PathPattern> */
    public function optionalPaths(string $ruleName, string $option, mixed $raw): array
    {
        return array_map(
            static fn(SelectorDefinition $definition): PathPattern => new PathPattern($definition),
            $this->optionalDefinitions($ruleName, $option, $raw),
        );
    }

    /** @return list<NamespacePattern> */
    public function optionalNamespaces(string $ruleName, string $option, mixed $raw): array
    {
        return array_map(
            static fn(SelectorDefinition $definition): NamespacePattern => new NamespacePattern($definition),
            $this->optionalDefinitions($ruleName, $option, $raw),
        );
    }

    /** @return array<string, list<NamespacePattern>> */
    public function channels(string $ruleName, mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (!\is_array($raw) || $raw === [] || array_is_list($raw)) {
            throw $this->refusal($ruleName, 'suppress_namespace_channels', 'must be a non-empty channel map');
        }

        $result = [];
        foreach ($raw as $selector => $patterns) {
            if (!\is_string($selector) || trim($selector) === '') {
                throw $this->refusal($ruleName, 'suppress_namespace_channels', 'contains an empty or non-string channel selector');
            }

            $option = 'suppress_namespace_channels.' . $selector;
            $result[$selector] = array_map(
                static fn(SelectorDefinition $definition): NamespacePattern => new NamespacePattern($definition),
                $this->requiredDefinitions($ruleName, $option, $patterns),
            );
        }

        return $result;
    }

    /** @return list<SelectorDefinition> */
    private function optionalDefinitions(string $ruleName, string $option, mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (!\is_array($raw) || !array_is_list($raw)) {
            throw $this->refusal($ruleName, $option, 'must be a list of explicit selector mappings');
        }

        return $this->definitions($ruleName, $option, $raw);
    }

    /** @return list<SelectorDefinition> */
    private function requiredDefinitions(string $ruleName, string $option, mixed $raw): array
    {
        if (!\is_array($raw) || !array_is_list($raw) || $raw === []) {
            throw $this->refusal($ruleName, $option, 'must be a non-empty list of explicit selector mappings');
        }

        return $this->definitions($ruleName, $option, $raw);
    }

    /**
     * @param list<mixed> $entries
     *
     * @return list<SelectorDefinition>
     */
    private function definitions(string $ruleName, string $option, array $entries): array
    {
        return array_map(
            fn(mixed $entry, int $index): SelectorDefinition => $this->definition($ruleName, $option, $entry, $index),
            $entries,
            array_keys($entries),
        );
    }

    private function definition(string $ruleName, string $option, mixed $entry, int $index): SelectorDefinition
    {
        if (!\is_array($entry) || \count($entry) !== 1) {
            throw $this->refusal($ruleName, $option . '.' . $index, 'entries must be one-entry mappings: {exact: value}, {subtree: value}, or {regex: value}; bare strings are not supported');
        }

        $kind = array_key_first($entry);
        $value = \is_string($kind) ? $entry[$kind] : null;
        if (!\is_string($kind) || !\is_string($value) || $value === '') {
            throw $this->refusal($ruleName, $option . '.' . $index, 'entries must name exact, subtree, or regex with a non-empty string value');
        }

        try {
            return SelectorDefinition::fromKindAndValue($kind, $value);
        } catch (InvalidArgumentException $e) {
            throw $this->refusal($ruleName, $option . '.' . $index, $e->getMessage(), $e);
        }
    }

    private function refusal(string $ruleName, string $option, string $summary, ?Throwable $previous = null): ConfigurationRefusal
    {
        return ConfigurationRefusal::atResolvedKey(
            RefusedPosition::open([$ruleName, ...explode('.', $option)], $option),
            \sprintf('Option "%s" for rule "%s" %s.', $option, $ruleName, $summary),
            $option,
            $previous,
        );
    }
}
