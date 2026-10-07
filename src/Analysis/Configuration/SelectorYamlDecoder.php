<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;

/** Decodes one explicit selector mapping from a YAML configuration document. */
final class SelectorYamlDecoder
{
    /** @var list<string> */
    private const array KINDS = ['exact', 'subtree', 'regex'];

    /**
     * @param list<string> $position Path to the selector list entry in the authored document
     */
    public function decodePath(mixed $value, ConfigurationOrigin $origin, array $position): PathPattern
    {
        [$definition, $definitionPosition] = $this->definition($value, $origin, $position);

        try {
            return new PathPattern($definition);
        } catch (InvalidArgumentException $e) {
            throw ConfigurationRefusal::at($origin, $definitionPosition, $e->getMessage(), $e);
        }
    }

    /**
     * @param list<string> $position Path to the selector list entry in the authored document
     */
    public function decodeNamespace(mixed $value, ConfigurationOrigin $origin, array $position): NamespacePattern
    {
        [$definition, $definitionPosition] = $this->definition($value, $origin, $position);

        try {
            return new NamespacePattern($definition);
        } catch (InvalidArgumentException $e) {
            throw ConfigurationRefusal::at($origin, $definitionPosition, $e->getMessage(), $e);
        }
    }

    /**
     * @param list<string> $position
     *
     * @return array{SelectorDefinition, RefusedPosition}
     */
    private function definition(mixed $value, ConfigurationOrigin $origin, array $position): array
    {
        $entryPosition = self::position($position);
        self::refuseArchitecturePattern($value, $origin, $entryPosition);
        $mapping = self::oneEntryMapping($value, $origin, $entryPosition);
        $kind = self::selectorKind($mapping, $origin, $position, $entryPosition);
        $kindPosition = self::position([...$position, $kind]);
        $pattern = self::pattern($mapping, $kind, $origin, $kindPosition);

        try {
            return [SelectorDefinition::fromKindAndValue($kind, $pattern), $kindPosition];
        } catch (InvalidArgumentException $e) {
            throw ConfigurationRefusal::at($origin, $kindPosition, $e->getMessage(), $e);
        }
    }

    private static function refuseArchitecturePattern(
        mixed $value,
        ConfigurationOrigin $origin,
        RefusedPosition $position,
    ): void {
        if (\is_string($value) && str_ends_with($value, '\\**')) {
            $subtree = substr($value, 0, -3);
            if ($subtree !== '') {
                throw ConfigurationRefusal::at(
                    $origin,
                    $position,
                    \sprintf(
                        'Selector entries use explicit selector mappings; "%s" is an Architecture pattern; write {subtree: %s}.',
                        $value,
                        rtrim($subtree, '\\'),
                    ),
                );
            }
        }
    }

    /** @return array<mixed, mixed> */
    private static function oneEntryMapping(
        mixed $value,
        ConfigurationOrigin $origin,
        RefusedPosition $position,
    ): array {
        if (!\is_array($value) || \count($value) !== 1) {
            throw ConfigurationRefusal::at(
                $origin,
                $position,
                'Selector entries must be one-entry mappings: {exact: value}, {subtree: value}, or {regex: value}. Bare strings are not supported.',
            );
        }

        return $value;
    }

    /**
     * @param array<mixed, mixed> $mapping
     * @param list<string> $position
     */
    private static function selectorKind(
        array $mapping,
        ConfigurationOrigin $origin,
        array $position,
        RefusedPosition $entryPosition,
    ): string {
        $kind = array_key_first($mapping);
        if (!\is_string($kind)) {
            throw ConfigurationRefusal::at(
                $origin,
                $entryPosition,
                'Selector mapping keys must be one of exact, subtree, or regex.',
            );
        }

        if (!\in_array($kind, self::KINDS, true)) {
            throw ConfigurationRefusal::at(
                $origin,
                RefusedPosition::closed([...$position, $kind], $kind, self::KINDS),
                \sprintf('Unknown selector kind "%s"; expected exact, subtree, or regex.', $kind),
            );
        }

        return $kind;
    }

    /** @param array<mixed, mixed> $mapping */
    private static function pattern(
        array $mapping,
        string $kind,
        ConfigurationOrigin $origin,
        RefusedPosition $position,
    ): string {
        $pattern = $mapping[$kind];
        if (!\is_string($pattern) || $pattern === '') {
            throw ConfigurationRefusal::at(
                $origin,
                $position,
                \sprintf('Selector "%s" must have a non-empty string value.', $kind),
            );
        }

        return $pattern;
    }

    /** @param list<string> $segments */
    private static function position(array $segments): RefusedPosition
    {
        $written = $segments[array_key_last($segments)] ?? 'selector';

        return RefusedPosition::open($segments, $written);
    }
}
