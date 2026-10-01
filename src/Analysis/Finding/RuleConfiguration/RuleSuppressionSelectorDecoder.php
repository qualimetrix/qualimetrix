<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration;

use InvalidArgumentException;
use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedListInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedMapInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionRefusal;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Throwable;

/** Decodes framework-owned suppression selectors from one rule configuration. */
final class RuleSuppressionSelectorDecoder
{
    /** @param list<string> $path */
    public function judgeSelector(ResolvedValueInterface $value, array $path, bool $namespace): void
    {
        if (!$value instanceof ResolvedMapInterface || \count($path) < 4) {
            throw new LogicException('A selector judgement requires its declared mapping and exact document path.');
        }
        $ruleName = $path[1];
        $option = \array_slice($path, 2);
        $index = array_pop($option);
        if ($index === null || $option === []) {
            throw new LogicException('A selector judgement requires an element position.');
        }
        try {
            $definition = $this->definition($ruleName, $option, $value->plain(), $index);
            if ($namespace) {
                new NamespacePattern($definition);
            } else {
                new PathPattern($definition);
            }
        } catch (RuleOptionRefusal $error) {
            $value->refuse($error->getMessage());
        } catch (InvalidArgumentException $error) {
            $value->refuse(\sprintf('Option "%s" for rule "%s" %s.', implode('.', [...$option, $index]), $ruleName, $error->getMessage()));
        }
    }

    /** @param list<string> $path */
    public function judgeChannels(ResolvedValueInterface $value, array $path): void
    {
        if (!$value instanceof ResolvedMapInterface || \count($path) !== 3) {
            throw new LogicException('A channel selector judgement requires its declared mapping and exact producer path.');
        }
        try {
            $this->channels($path[1], $value);
        } catch (RuleOptionRefusal $error) {
            $value->refuse($error->getMessage());
        }
    }

    /** @return list<PathPattern> */
    public function optionalPaths(string $ruleName, string $option, ?ResolvedListInterface $value): array
    {
        return array_map(
            static fn(SelectorDefinition $definition): PathPattern => new PathPattern($definition),
            $this->optionalDefinitions($ruleName, [str_replace('_', '-', $option)], $value?->plain()),
        );
    }

    /** @return list<NamespacePattern> */
    public function optionalNamespaces(string $ruleName, string $option, ?ResolvedListInterface $value): array
    {
        return array_map(
            static fn(SelectorDefinition $definition): NamespacePattern => new NamespacePattern($definition),
            $this->optionalDefinitions($ruleName, [str_replace('_', '-', $option)], $value?->plain()),
        );
    }

    /** @return array<string, list<NamespacePattern>> */
    public function channels(string $ruleName, ?ResolvedMapInterface $value): array
    {
        $raw = $value?->plain();
        if ($raw === null) {
            return [];
        }

        if ($raw === [] || array_is_list($raw)) {
            throw $this->refusal($ruleName, ['suppress-namespace-channels'], 'must be a non-empty channel map');
        }

        $result = [];
        foreach ($raw as $selector => $patterns) {
            if (!\is_string($selector) || trim($selector) === '') {
                throw $this->refusal($ruleName, ['suppress-namespace-channels'], 'contains an empty or non-string channel selector');
            }

            $option = ['suppress-namespace-channels', $selector];
            $result[$selector] = array_map(
                static fn(SelectorDefinition $definition): NamespacePattern => new NamespacePattern($definition),
                $this->requiredDefinitions($ruleName, $option, $patterns),
            );
        }

        return $result;
    }

    /** @param non-empty-list<string> $option
     * @return list<SelectorDefinition> */
    private function optionalDefinitions(string $ruleName, array $option, mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (!\is_array($raw) || !array_is_list($raw)) {
            throw $this->refusal($ruleName, $option, 'must be a list of explicit selector mappings');
        }

        return $this->definitions($ruleName, $option, $raw);
    }

    /** @param non-empty-list<string> $option
     * @return list<SelectorDefinition> */
    private function requiredDefinitions(string $ruleName, array $option, mixed $raw): array
    {
        if (!\is_array($raw) || !array_is_list($raw) || $raw === []) {
            throw $this->refusal($ruleName, $option, 'must be a non-empty list of explicit selector mappings');
        }

        return $this->definitions($ruleName, $option, $raw);
    }

    /**
     * @param non-empty-list<string> $option
     * @param list<mixed> $entries
     *
     * @return list<SelectorDefinition>
     */
    private function definitions(string $ruleName, array $option, array $entries): array
    {
        return array_map(
            fn(mixed $entry, int|string $index): SelectorDefinition => $this->definition($ruleName, $option, $entry, $index),
            $entries,
            array_keys($entries),
        );
    }

    /** @param non-empty-list<string> $option */
    private function definition(string $ruleName, array $option, mixed $entry, int|string $index): SelectorDefinition
    {
        if (!\is_array($entry) || \count($entry) !== 1) {
            throw $this->refusal($ruleName, [...$option, (string) $index], 'entries must be one-entry mappings: {exact: value}, {subtree: value}, or {regex: value}; bare strings are not supported');
        }

        $kind = array_key_first($entry);
        $value = \is_string($kind) ? $entry[$kind] : null;
        if (!\is_string($kind) || !\is_string($value) || $value === '') {
            throw $this->refusal($ruleName, [...$option, (string) $index], 'entries must name exact, subtree, or regex with a non-empty string value');
        }

        try {
            return SelectorDefinition::fromKindAndValue($kind, $value);
        } catch (InvalidArgumentException $e) {
            throw $this->refusal($ruleName, [...$option, (string) $index], $e->getMessage(), $e);
        }
    }

    /** @param non-empty-list<string> $option */
    private function refusal(string $ruleName, array $option, string $summary, ?Throwable $previous = null): RuleOptionRefusal
    {
        $written = [str_replace('-', '_', $option[0]), ...\array_slice($option, 1)];
        return new RuleOptionRefusal($option, \sprintf('Option "%s" for rule "%s" %s.', implode('.', $written), $ruleName, $summary));
    }
}
