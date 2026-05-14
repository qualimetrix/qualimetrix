<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Architecture\Validation;

use Qualimetrix\Configuration\Exception\ConfigLoadException;
use Qualimetrix\Configuration\Pipeline\DeferredWarning;

/**
 * Parses and validates the {@code architecture.allow} sub-tree.
 *
 * Cross-validates target references against a supplied list of known layer
 * names. Accepts both the short (bare string) and long ({@code {target, types}})
 * forms; the long form's {@code types} key is reserved for a future filter and
 * triggers a deferred deprecation warning.
 *
 * Returns the normalized allowed-targets map (source → list of targets with
 * duplicates and self-references stripped).
 */
final class AllowValidator
{
    private const string CONFIG_PATH = 'architecture';

    /**
     * @param list<string> $layerNames Names from the registry; used for cross-validation.
     * @param list<DeferredWarning> $warnings Accumulator, mutated by reference for warning collection.
     *
     * @return array<string, list<string>> Map source → list of allowed targets, deduplicated and self-references stripped.
     */
    public function validate(mixed $allowRaw, array $layerNames, array &$warnings): array
    {
        if ($allowRaw === [] || $allowRaw === null) {
            return [];
        }

        if (!\is_array($allowRaw) || array_is_list($allowRaw)) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                'architecture.allow: must be a map of layer-name → list of target layer names.',
            );
        }

        $layerSet = array_flip($layerNames);
        $allowed = [];

        foreach ($allowRaw as $source => $targets) {
            if (!\is_string($source)) {
                throw new ConfigLoadException(
                    self::CONFIG_PATH,
                    'architecture.allow: must be a map of layer-name → list of target layer names.',
                );
            }

            if (!isset($layerSet[$source])) {
                throw new ConfigLoadException(
                    self::CONFIG_PATH,
                    \sprintf('architecture.allow.%s: unknown layer.', $source),
                );
            }

            $allowed[$source] = $this->normalizeAllowTargets($source, $targets, $layerSet, $warnings);
        }

        return $allowed;
    }

    /**
     * @param array<string, int> $layerSet
     * @param list<DeferredWarning> $warnings Accumulator, mutated by reference for warning collection.
     *
     * @return list<string>
     */
    private function normalizeAllowTargets(string $source, mixed $targets, array $layerSet, array &$warnings): array
    {
        if ($targets === null) {
            return [];
        }

        if (!\is_array($targets) || !array_is_list($targets)) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf('architecture.allow.%s: must be a list of target layer names.', $source),
            );
        }

        $result = [];
        $seen = [];
        foreach ($targets as $index => $entry) {
            $target = $this->normalizeAllowEntry($source, $index, $entry, $warnings);

            // Self-reference: silently dedup (same-layer is always allowed by LayerPolicy).
            if ($target === $source) {
                continue;
            }

            if (!isset($layerSet[$target])) {
                throw new ConfigLoadException(
                    self::CONFIG_PATH,
                    \sprintf("architecture.allow.%s[%d]: unknown layer '%s'.", $source, $index, $target),
                );
            }

            if (isset($seen[$target])) {
                continue;
            }
            $seen[$target] = true;
            $result[] = $target;
        }

        return $result;
    }

    /**
     * Normalizes a single allow-list entry to a bare target name.
     *
     * Supports two forms:
     * - Short: bare string {@code 'service'}.
     * - Long:  associative array {@code [target: 'service', types: ['method_call']]}.
     *
     * The long form's {@code types} key is accepted for forward compatibility but
     * not yet enforced; if present, append a deferred deprecation-style warning.
     *
     * @param list<DeferredWarning> $warnings Accumulator, mutated by reference for warning collection.
     */
    private function normalizeAllowEntry(string $source, int $index, mixed $entry, array &$warnings): string
    {
        if (\is_string($entry)) {
            if ($entry === '') {
                throw new ConfigLoadException(
                    self::CONFIG_PATH,
                    \sprintf('architecture.allow.%s[%d]: target must be a non-empty string.', $source, $index),
                );
            }

            return $entry;
        }

        if (\is_array($entry) && !array_is_list($entry) && isset($entry['target']) && \is_string($entry['target']) && $entry['target'] !== '') {
            if (\array_key_exists('types', $entry)) {
                $warnings[] = DeferredWarning::warning(\sprintf(
                    "architecture.allow.%s: 'types' filter declared but not yet enforced (Phase 2).",
                    $source,
                ));
            }

            return $entry['target'];
        }

        throw new ConfigLoadException(
            self::CONFIG_PATH,
            \sprintf(
                "architecture.allow.%s[%d]: each target must be a layer name (string) or a map with a non-empty 'target' key.",
                $source,
                $index,
            ),
        );
    }
}
