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

        if (!\is_array($value) || \count($value) !== 1) {
            throw ConfigurationRefusal::at(
                $origin,
                $entryPosition,
                'Selector entries must be one-entry mappings: {exact: value}, {subtree: value}, or {regex: value}. Bare strings are not supported.',
            );
        }

        $kind = array_key_first($value);
        if (!\is_string($kind)) {
            throw ConfigurationRefusal::at(
                $origin,
                $entryPosition,
                'Selector mapping keys must be one of exact, subtree, or regex.',
            );
        }

        $kindPosition = self::position([...$position, $kind]);
        if (!\in_array($kind, self::KINDS, true)) {
            throw ConfigurationRefusal::at(
                $origin,
                RefusedPosition::closed([...$position, $kind], $kind, self::KINDS),
                \sprintf('Unknown selector kind "%s"; expected exact, subtree, or regex.', $kind),
            );
        }

        $pattern = $value[$kind];
        if (!\is_string($pattern) || $pattern === '') {
            throw ConfigurationRefusal::at(
                $origin,
                $kindPosition,
                \sprintf('Selector "%s" must have a non-empty string value.', $kind),
            );
        }

        try {
            return [SelectorDefinition::fromKindAndValue($kind, $pattern), $kindPosition];
        } catch (InvalidArgumentException $e) {
            throw ConfigurationRefusal::at($origin, $kindPosition, $e->getMessage(), $e);
        }
    }

    /** @param list<string> $segments */
    private static function position(array $segments): RefusedPosition
    {
        $written = $segments[array_key_last($segments)] ?? 'selector';

        return RefusedPosition::open($segments, $written);
    }
}
