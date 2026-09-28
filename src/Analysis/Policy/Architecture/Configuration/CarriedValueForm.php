<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;

/**
 * The form of the two values {@see ArchitectureSection} carries unread — a
 * layer's criterion and an allow target — as a single configuration layer
 * wrote them. The section declares {@see ofLayerEntry()} and
 * {@see ofAllowTarget()} as judgements the engine passes on every layer
 * before the layers merge, so a malformed value a higher layer replaces is
 * refused in the layer that wrote it all the same.
 *
 * Only the form is judged here: what a value means — a pattern's syntax, a
 * selector, a relation kind, the layer a target names — is judged on the
 * merged value by the validators, which read a layer's name, a criterion and
 * a short-form target through the judgements here.
 */
final class CarriedValueForm
{
    /** The criterion keys of a layer entry and of its `exclude` block. */
    private const array CRITERIA = ['patterns', 'suffix', 'attributes', 'implements', 'extends'];

    /**
     * @param list<string> $path canonical path of the entry, its index last
     *
     * @throws ConfigurationRefusal naming the layer that wrote the entry
     */
    public static function ofLayerEntry(ResolvedValueInterface $entry, array $path): void
    {
        $index = (int) $path[\count($path) - 1];
        $spot = SectionSpot::node($path, $entry);
        $name = self::layerName($index, $spot);

        foreach (self::CRITERIA as $kind) {
            self::criterionEntries($index, $name, $kind, $spot->child($kind));
        }

        $exclude = $spot->child('exclude');
        if ($exclude->isWritten()) {
            foreach (self::CRITERIA as $kind) {
                self::criterionEntries($index, $name . '.exclude', $kind, $exclude->child($kind));
            }
        }
    }

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
            LongFormAllowEntryNormalizer::judgeForm($source, $index, $entry);

            return;
        }

        self::selector($source, $index, $entry);
    }

    /** The entry's name, refused when it is missing or empty. */
    public static function layerName(int $index, SectionSpot $entry): string
    {
        $name = $entry->child('name');
        $value = $name->value();
        if (!\is_string($value) || $value === '') {
            throw $name->refusal(\sprintf('architecture.layers[%d]: missing or empty "name" (must be a non-empty string).', $index));
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
