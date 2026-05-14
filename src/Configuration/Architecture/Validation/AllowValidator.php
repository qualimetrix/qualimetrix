<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Architecture\Validation;

use Qualimetrix\Configuration\Exception\ConfigLoadException;
use Qualimetrix\Configuration\Pipeline\DeferredWarning;
use Qualimetrix\Core\Architecture\Allow\AllowListEntry;
use Qualimetrix\Core\Architecture\Allow\AllowTarget;
use Qualimetrix\Core\Architecture\Allow\InvalidSelectorException;
use Qualimetrix\Core\Architecture\Allow\LayerSelector;
use Qualimetrix\Core\Architecture\Allow\LayerSelectorParser;

/**
 * Parses and validates the {@code architecture.allow} sub-tree.
 *
 * Each key / target string is parsed into a {@see LayerSelector} per the D4
 * grammar (exact / glob / captured); the result is a {@see AllowListEntry}
 * list in user declaration order.
 *
 * Cross-validation against the registry's layer names runs only for
 * {@see LayerSelector}s of kind {@code exact} — glob and captured selectors
 * are intentionally not validated against the current registry because Step D
 * adds template-layer expansion that produces concrete layer names after
 * config load. A glob source that matches no concrete layer today may still be
 * the intent (the user may add layers later, or the template-expansion stage
 * may produce them); the rule executor will simply skip non-matching entries.
 *
 * The long form ({@code [target: 'service', types: ['method_call']]}) is
 * accepted for forward compatibility; the {@code types} key emits a deferred
 * deprecation warning (real wiring lands in Step G).
 */
final class AllowValidator
{
    private const string CONFIG_PATH = 'architecture';

    /**
     * Long-form allow target keys recognised by Step C. Other forward-looking
     * keys reserved by ADR 0007 ({@code relations} for Step G,
     * {@code allow_cross_instance} for Step E) are rejected here as
     * "not yet supported" so a user-side typo cannot silently widen the policy.
     */
    private const array ALLOWED_LONG_FORM_KEYS = ['target', 'types'];

    /**
     * Reserved keys that ADR 0007 promises for future steps. Surfaced with a
     * dedicated "reserved for later step" message so users who try them get a
     * clear signal — not a generic "unknown key" complaint.
     */
    private const array RESERVED_LONG_FORM_KEYS = [
        'relations' => 'Step G',
        'allow_cross_instance' => 'Step E',
    ];

    /**
     * @param list<string> $layerNames Names from the registry; used for cross-validation of exact selectors only.
     * @param list<DeferredWarning> $warnings Accumulator, mutated by reference for warning collection.
     *
     * @return list<AllowListEntry>
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
        $entries = [];

        foreach ($allowRaw as $sourceRaw => $targets) {
            if (!\is_string($sourceRaw)) {
                throw new ConfigLoadException(
                    self::CONFIG_PATH,
                    'architecture.allow: must be a map of layer-name → list of target layer names.',
                );
            }

            $sourceSelector = $this->parseSelector(
                $sourceRaw,
                \sprintf('architecture.allow.%s', $sourceRaw),
            );

            if ($sourceSelector->isExact() && !isset($layerSet[$sourceRaw])) {
                throw new ConfigLoadException(
                    self::CONFIG_PATH,
                    \sprintf('architecture.allow.%s: unknown layer.', $sourceRaw),
                );
            }

            $allowTargets = $this->normalizeAllowTargets($sourceRaw, $targets, $layerSet, $warnings);
            $entries[] = new AllowListEntry($sourceSelector, $allowTargets);
        }

        return $entries;
    }

    /**
     * @param array<string, int> $layerSet
     * @param list<DeferredWarning> $warnings Accumulator, mutated by reference for warning collection.
     *
     * @return list<AllowTarget>
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
        $seenExact = [];
        foreach ($targets as $index => $entry) {
            $targetSelector = $this->normalizeAllowEntry($source, $index, $entry, $warnings);

            if ($this->shouldSkipExactTarget($source, $index, $targetSelector, $layerSet, $seenExact)) {
                continue;
            }

            $result[] = new AllowTarget($targetSelector);
        }

        return $result;
    }

    /**
     * Performs the exact-target side-checks (self-reference dedup, unknown
     * layer rejection, exact-name dedup) and returns true when the caller
     * should skip appending this target to the result list. Glob / captured
     * selectors are deliberately untouched here — only exact-shape targets are
     * cross-validated against the registry's layer names in Step C.
     *
     * @param array<string, int> $layerSet
     * @param array<string, true> $seenExact Map of exact-target names already
     *                                       emitted for this source; mutated
     *                                       by reference for dedup state.
     *
     * @param-out array<string, true> $seenExact
     */
    private function shouldSkipExactTarget(
        string $source,
        int $index,
        LayerSelector $targetSelector,
        array $layerSet,
        array &$seenExact,
    ): bool {
        if (!$targetSelector->isExact()) {
            return false;
        }

        $targetName = $targetSelector->originalString();

        // Self-reference: silently dedup (same-layer is always allowed by LayerPolicy).
        if ($targetName === $source) {
            return true;
        }

        if (!isset($layerSet[$targetName])) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf("architecture.allow.%s[%d]: unknown layer '%s'.", $source, $index, $targetName),
            );
        }

        if (isset($seenExact[$targetName])) {
            return true;
        }

        $seenExact[$targetName] = true;

        return false;
    }

    /**
     * Normalizes a single allow-list entry to a {@see LayerSelector}.
     *
     * Supports two forms:
     * - Short: bare string {@code 'service'} (or {@code 'service-*'} / {@code 'app-{m}'}).
     * - Long:  associative array {@code [target: 'service', types: ['method_call']]}.
     *
     * The long form's {@code types} key is accepted for forward compatibility but
     * not yet enforced; if present, append a deferred deprecation-style warning.
     *
     * @param list<DeferredWarning> $warnings Accumulator, mutated by reference for warning collection.
     */
    private function normalizeAllowEntry(string $source, int $index, mixed $entry, array &$warnings): LayerSelector
    {
        if (\is_string($entry)) {
            if ($entry === '') {
                throw new ConfigLoadException(
                    self::CONFIG_PATH,
                    \sprintf('architecture.allow.%s[%d]: target must be a non-empty string.', $source, $index),
                );
            }

            return $this->parseSelector(
                $entry,
                \sprintf('architecture.allow.%s[%d]', $source, $index),
            );
        }

        if (\is_array($entry) && !array_is_list($entry) && isset($entry['target']) && \is_string($entry['target']) && $entry['target'] !== '') {
            $this->rejectUnsupportedLongFormKeys($source, $index, $entry);

            if (\array_key_exists('types', $entry)) {
                $warnings[] = DeferredWarning::warning(\sprintf(
                    "architecture.allow.%s: 'types' filter declared but not yet enforced (Phase 2).",
                    $source,
                ));
            }

            return $this->parseSelector(
                $entry['target'],
                \sprintf('architecture.allow.%s[%d]', $source, $index),
            );
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

    /**
     * Catches the Core-domain {@see InvalidSelectorException} from
     * {@see LayerSelectorParser::parse()} and rewraps it as a
     * {@see ConfigLoadException} with the user-facing config path prefix.
     */
    private function parseSelector(string $raw, string $context): LayerSelector
    {
        try {
            return LayerSelectorParser::parse($raw);
        } catch (InvalidSelectorException $e) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf('%s: %s', $context, $e->getMessage()),
                $e,
            );
        }
    }

    /**
     * Closes the silent-widening loophole in the long-form allow entry. Any
     * key that is neither in the {@see ALLOWED_LONG_FORM_KEYS} whitelist nor
     * recognised as a {@see RESERVED_LONG_FORM_KEYS} placeholder gets
     * rejected with a user-actionable error: a `relations:` typo (e.g.
     * `relatons:`) would otherwise silently allow every dependency kind
     * instead of the user's intended subset.
     *
     * @param array<string, mixed> $entry
     */
    private function rejectUnsupportedLongFormKeys(string $source, int $index, array $entry): void
    {
        foreach (array_keys($entry) as $key) {
            if (\in_array($key, self::ALLOWED_LONG_FORM_KEYS, true)) {
                continue;
            }

            if (isset(self::RESERVED_LONG_FORM_KEYS[$key])) {
                throw new ConfigLoadException(
                    self::CONFIG_PATH,
                    \sprintf(
                        "architecture.allow.%s[%d]: long-form key '%s' is reserved for %s and not yet supported in this version.",
                        $source,
                        $index,
                        $key,
                        self::RESERVED_LONG_FORM_KEYS[$key],
                    ),
                );
            }

            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf(
                    "architecture.allow.%s[%d]: unknown long-form key '%s'. Allowed keys: %s.",
                    $source,
                    $index,
                    (string) $key,
                    implode(', ', array_map(static fn(string $k): string => "'" . $k . "'", self::ALLOWED_LONG_FORM_KEYS)),
                ),
            );
        }
    }
}
