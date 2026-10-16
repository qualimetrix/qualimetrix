<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling\Configuration;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedListInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Core\Pattern\NamespaceMatcher;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;

final class FrameworkNamespaceSelectorParser
{
    /** @return list<NamespacePattern> */
    public static function parseList(ResolvedValueInterface $selectors): array
    {
        if (!$selectors instanceof ResolvedListInterface) {
            $selectors->refuse('Framework namespace selectors must be a list.');
        }

        $patterns = [];
        foreach ($selectors->items() as $selector) {
            $patterns[] = self::parse($selector);
        }
        try {
            new NamespaceMatcher($patterns);
        } catch (InvalidArgumentException $e) {
            throw self::refusalFrom($selectors, $e);
        }

        return $patterns;
    }

    public static function parse(ResolvedValueInterface $selector): NamespacePattern
    {
        $value = $selector->plain();

        if (!\is_array($value) || \count($value) !== 1) {
            $selector->refuse(
                'Framework namespace selectors must be one-entry mappings: {exact: value}, {subtree: value}, or {regex: value}; bare strings are not supported.',
            );
        }

        $kind = array_key_first($value);
        $pattern = \is_string($kind) ? $value[$kind] : null;
        if (!\is_string($kind) || !\is_string($pattern) || $pattern === '') {
            $selector->refuse(
                'Framework namespace selectors must name exact, subtree, or regex with a non-empty string value.',
            );
        }

        try {
            return new NamespacePattern(SelectorDefinition::fromKindAndValue($kind, $pattern));
        } catch (InvalidArgumentException $e) {
            throw self::refusalFrom($selector, $e);
        }
    }

    public static function refusalFrom(ResolvedValueInterface $value, InvalidArgumentException $cause): ConfigurationRefusal
    {
        $writers = $value->contributors();

        return ConfigurationRefusal::acrossLayers(
            array_map(static fn(Provenance $writer): ConfigurationOrigin => $writer->origin, $writers),
            $writers[\count($writers) - 1]->position(),
            $cause->getMessage(),
            $cause,
        );
    }
}
