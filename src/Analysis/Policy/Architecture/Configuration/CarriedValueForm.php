<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Configuration;

use InvalidArgumentException;
use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\InvalidSelectorException;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\LayerSelector;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\LayerSelectorParser;
use Qualimetrix\Analysis\Policy\Architecture\Layer\CapturePattern;
use Qualimetrix\Analysis\Policy\Architecture\Layer\InvalidLayerDefinitionException;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition;
use Qualimetrix\Analysis\Policy\Architecture\Layer\TemplateLayerDefinition;

/**
 * Context-free value forms for {@see ArchitectureSection}, judged in each
 * layer that writes them before merging. Resolved validators use the same
 * parsers; references to declared layers and cross-field constraints are
 * judged only after merging.
 */
final class CarriedValueForm
{
    /**
     * @param list<string> $path canonical path of the target: architecture, allow, source, index
     *
     * @throws ConfigurationRefusal naming the layer that wrote the target
     */
    public static function ofAllowTarget(ResolvedValueInterface $target, array $path): void
    {
        $count = \count($path);
        $source = $path[$count - 2];
        $index = (int) $path[$count - 1];
        $entry = SectionSpot::node($path, $target);
        $value = $entry->value();

        if (\is_array($value) && !array_is_list($value)) {
            [$selector] = LongFormAllowEntryNormalizer::normalize($source, $index, $entry);
            self::parseSelector($selector, $entry->display(), $entry->child('target'));

            return;
        }

        self::parseSelector(self::selector($source, $index, $entry), $entry->display(), $entry);
    }

    /** @param list<string> $path */
    public static function ofCoverageMode(ResolvedValueInterface $value, array $path): void
    {
        (new CoverageValidator())->validate(SectionSpot::node($path, $value));
    }

    /** @param list<string> $path */
    public static function ofExpansionCeiling(ResolvedValueInterface $value, array $path): void
    {
        self::maxExpandedLayers(SectionSpot::node($path, $value));
    }

    /** @param list<string> $path */
    public static function ofAllowMap(ResolvedValueInterface $value, array $path): void
    {
        $allow = SectionSpot::node($path, $value);
        foreach ($allow->keys() as $source) {
            self::parseSelector($source, $allow->child($source)->display(), $allow->child($source));
        }
    }

    public static function parseSelector(string $raw, string $context, SectionSpot $spot): LayerSelector
    {
        try {
            return LayerSelectorParser::parse($raw);
        } catch (InvalidSelectorException $e) {
            throw $spot->refusal(\sprintf('%s: %s', $context, $e->getMessage()), written: $raw);
        }
    }

    /**
     * Validates the {@code max_expanded_layers} value: the engine has already
     * refused anything but an integer, so this refuses one below 1, showing
     * the default ceiling so the user knows what to put back.
     */
    public static function maxExpandedLayers(SectionSpot $spot): int
    {
        $value = $spot->value() ?? ArchitectureConfiguration::DEFAULT_MAX_EXPANDED_LAYERS;
        if (!\is_int($value)) {
            throw new LogicException('The configuration engine admits only an integer as architecture.max_expanded_layers.');
        }

        if ($value < 1) {
            throw $spot->refusal(\sprintf(
                'architecture.max_expanded_layers: must be a positive integer (>= 1) — the cumulative ceiling on template-layer expansions. Got %d. Omit the key to use the default of %d, or set a higher integer if your config legitimately produces more layers.',
                $value,
                ArchitectureConfiguration::DEFAULT_MAX_EXPANDED_LAYERS,
            ));
        }

        return $value;
    }

    /** The entry's name, refused when it is missing or empty. */
    public static function layerName(int $index, SectionSpot $entry): string
    {
        $name = $entry->child('name');
        $value = $name->value();
        if (!\is_string($value) || $value === '') {
            throw $name->refusal(\sprintf('architecture.layers[%d]: missing or empty "name" (must be a non-empty string).', $index));
        }

        if (TemplateLayerDefinition::containsCaptureVariable($value)) {
            try {
                CapturePattern::extractVariables($value);
            } catch (InvalidArgumentException $error) {
                throw $name->refusal(\sprintf(
                    'architecture.layers[%d] ("%s"): TemplateLayerDefinition: name template "%s" has invalid capture grammar — %s',
                    $index,
                    $value,
                    $value,
                    $error->getMessage(),
                ));
            }

            return $value;
        }

        try {
            LayerDefinition::assertValidDeclaredName($value);
        } catch (InvalidLayerDefinitionException $error) {
            throw $name->refusal(\sprintf('architecture.layers[%d] ("%s"): %s', $index, $value, $error->getMessage()));
        }

        return $value;
    }

    /** A short-form target: the selector string itself, refused when it is not one. */
    public static function selector(string $source, int $index, SectionSpot $entry): string
    {
        $context = \sprintf('architecture.allow.%s[%d]', $source, $index);
        $value = $entry->value();

        if (\is_string($value)) {
            if ($value === '') {
                throw $entry->refusal(\sprintf('%s: target must be a non-empty string.', $context));
            }

            return $value;
        }

        throw $entry->refusal(
            \sprintf(
                "%s: each target must be a layer name (string) or a map with a non-empty 'target' key.",
                $context,
            ),
        );
    }

    /**
     * A written criterion as its entries, each with the spot it was written
     * at: one string, or a non-empty list of non-empty strings. Not written,
     * it has none.
     *
     * @throws ConfigurationRefusal naming the layer that wrote the criterion
     *
     * @return array<int, array{string, SectionSpot}>
     */
    public static function criterionEntries(int $index, string $layerName, string $kind, SectionSpot $value): array
    {
        if (!$value->isWritten()) {
            return [];
        }

        $entries = [];
        foreach (self::coerceToStringList($index, $layerName, $kind, $value) as $entryIndex => $entry) {
            $spot = \is_array($value->value()) ? $value->child($entryIndex) : $value;

            if (!\is_string($entry) || $entry === '') {
                throw $spot->refusal(
                    \sprintf(
                        'architecture.layers[%d] ("%s"): "%s" entry at index %d must be a non-empty string (got %s).',
                        $index,
                        $layerName,
                        $kind,
                        $entryIndex,
                        \is_string($entry) ? "''" : get_debug_type($entry),
                    ),
                    written: \is_string($entry) ? $entry : null,
                );
            }

            $entries[$entryIndex] = [$entry, $spot];
        }

        return $entries;
    }

    /**
     * @return list<mixed>
     */
    private static function coerceToStringList(int $index, string $layerName, string $kind, SectionSpot $spot): array
    {
        $value = $spot->value();
        $entries = \is_string($value) ? [$value] : $value;

        if (!\is_array($entries)) {
            throw $spot->refusal(
                \sprintf(
                    'architecture.layers[%d] ("%s"): "%s" must be a string or a non-empty list of strings, got %s.',
                    $index,
                    $layerName,
                    $kind,
                    get_debug_type($value),
                ),
            );
        }

        if (!array_is_list($entries)) {
            // Associative map where an ordered list is required — the
            // typical mistake is using YAML mapping syntax ({@code key: val})
            // for what should be a sequence ({@code - val}).
            throw $spot->refusal(
                \sprintf(
                    'architecture.layers[%d] ("%s"): "%s" must be a string or a non-empty list of strings, got an associative map (keys: %s). Use sequence syntax (a "-" prefix per entry) or omit the key to leave the criterion undeclared.',
                    $index,
                    $layerName,
                    $kind,
                    self::renderMapKeysForError($entries),
                ),
            );
        }

        if ($entries === []) {
            throw $spot->refusal(
                \sprintf(
                    'architecture.layers[%d] ("%s"): "%s" must contain at least one entry; omit the key to leave the criterion undeclared.',
                    $index,
                    $layerName,
                    $kind,
                ),
            );
        }

        return $entries;
    }

    /**
     * Renders the keys of an associative map in a stable, bounded form for
     * inclusion in an error message. Caps the list at four keys to keep the
     * error one-line readable when the user paste a large map by accident.
     *
     * @param array<array-key, mixed> $map
     */
    private static function renderMapKeysForError(array $map): string
    {
        $keys = array_keys($map);
        $shown = \array_slice($keys, 0, 4);
        $quoted = array_map(static fn(int|string $k): string => '"' . (string) $k . '"', $shown);
        $tail = \count($keys) > 4 ? ', …' : '';

        return implode(', ', $quoted) . $tail;
    }
}
