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
     * Long-form allow target keys. Any other key is rejected here as
     * "unknown long-form key" so a user-side typo cannot silently widen the
     * policy (e.g. {@code relatons:} would otherwise allow every relation kind
     * instead of the user's intended subset).
     */
    private const array ALLOWED_LONG_FORM_KEYS = ['target', 'types', 'allow_cross_instance'];

    /**
     * Reserved keys that ADR 0007 promises for future steps. Surfaced with a
     * dedicated "reserved for later step" message so users who try them get a
     * clear signal — not a generic "unknown key" complaint.
     */
    private const array RESERVED_LONG_FORM_KEYS = [
        'relations' => 'Step G',
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

            $allowTargets = $this->normalizeAllowTargets($sourceRaw, $sourceSelector, $targets, $layerSet, $warnings);
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
    private function normalizeAllowTargets(
        string $source,
        LayerSelector $sourceSelector,
        mixed $targets,
        array $layerSet,
        array &$warnings,
    ): array {
        if ($targets === null) {
            return [];
        }

        if (!\is_array($targets) || !array_is_list($targets)) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf('architecture.allow.%s: must be a list of target layer names.', $source),
            );
        }

        $sourceShapes = $sourceSelector->captureVariableShapes();

        $result = [];
        $seenExact = [];
        foreach ($targets as $index => $entry) {
            [$targetSelector, $allowCrossInstance] = $this->normalizeAllowEntry($source, $index, $entry, $warnings);

            $this->crossValidateCapturedTarget($source, $index, $sourceSelector, $sourceShapes, $targetSelector);

            if ($this->shouldSkipExactTarget($source, $index, $targetSelector, $layerSet, $seenExact)) {
                continue;
            }

            $result[] = new AllowTarget($targetSelector, allowCrossInstance: $allowCrossInstance);
        }

        return $result;
    }

    /**
     * Rejects captured target selectors that the runtime cannot satisfy
     * consistently with what the user declared. Three rejection cases plus
     * one shape-consistency check:
     *
     * - **Non-captured source.** {@code 'shared-*': ['domain-{m}']} or
     *   {@code 'controller': ['domain-{m}']}: target captures cannot bind to
     *   anything → reject with a hint to declare the variable on the source.
     * - **Undeclared variable.** {@code 'app-{x}': ['domain-{y}']}: typo or
     *   design error → reject with a hint to either match the name or fall
     *   back to a glob target.
     * - **Shape mismatch.** {@code 'app-{m}': ['domain-{m:**}']} or
     *   {@code 'app-{m:**}': ['domain-{m}']}: the runtime substitutes the
     *   bound value literally, so the target's multi-segment annotation
     *   would be silently ignored → reject so the user can align the two
     *   shapes explicitly.
     * - **Subset of source variables with matching shapes.** OK.
     *
     * The check runs regardless of {@code allow_cross_instance} — the flag
     * affects the **runtime** binding identity (whether the bound value is
     * substituted), not the grammar of which variables may appear or what
     * shape they have on the target side.
     *
     * @param array<string, bool> $sourceShapes Map of variable name → multi-segment
     *                                          flag, from {@see LayerSelector::captureVariableShapes()}.
     *                                          Empty when source is exact / glob.
     */
    private function crossValidateCapturedTarget(
        string $source,
        int $index,
        LayerSelector $sourceSelector,
        array $sourceShapes,
        LayerSelector $targetSelector,
    ): void {
        if (!$targetSelector->isCaptured()) {
            return;
        }

        $targetShapes = $targetSelector->captureVariableShapes();
        $undeclared = [];
        $shapeMismatches = [];

        foreach ($targetShapes as $variable => $isMultiSegment) {
            if (!\array_key_exists($variable, $sourceShapes)) {
                $undeclared[] = $variable;
                continue;
            }

            if ($sourceShapes[$variable] !== $isMultiSegment) {
                $shapeMismatches[$variable] = [
                    'source' => $sourceShapes[$variable],
                    'target' => $isMultiSegment,
                ];
            }
        }

        if ($undeclared !== []) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                $this->renderUndeclaredCaptureMessage(
                    $source,
                    $index,
                    $sourceSelector,
                    $targetSelector,
                    $undeclared,
                ),
            );
        }

        if ($shapeMismatches !== []) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                $this->renderShapeMismatchMessage(
                    $source,
                    $index,
                    $sourceSelector,
                    $targetSelector,
                    $shapeMismatches,
                ),
            );
        }
    }

    /**
     * Builds the user-facing rejection message for "captured target with
     * undeclared variables". The hint differs between the three flavours of
     * the rejection: non-captured source, undeclared variable on captured
     * source, etc.
     *
     * @param list<string> $undeclared
     */
    private function renderUndeclaredCaptureMessage(
        string $source,
        int $index,
        LayerSelector $sourceSelector,
        LayerSelector $targetSelector,
        array $undeclared,
    ): string {
        $variableList = implode(', ', array_map(static fn(string $v): string => "'{$v}'", $undeclared));

        if (!$sourceSelector->isCaptured()) {
            // Glob / exact source: target captures have no binding to draw from.
            return \sprintf(
                "architecture.allow.%s[%d]: captured target '%s' references variable(s) %s, " .
                "but source '%s' is %s and declares no capture variables. " .
                'Either declare the variable on the source (e.g. `app-{module}` → `domain-{module}`) ' .
                'so the runtime can bind it, or use a glob target (e.g. `domain-*`) so the target ' .
                'matches independently of the binding.',
                $source,
                $index,
                $targetSelector->originalString(),
                $variableList,
                $source,
                $sourceSelector->isGlob() ? 'a glob selector' : 'an exact layer name',
            );
        }

        // Captured source: variable is declared elsewhere but not on this source.
        $sourceVariables = implode(', ', array_map(
            static fn(string $v): string => "'{$v}'",
            array_keys($sourceSelector->captureVariableShapes()),
        ));

        return \sprintf(
            "architecture.allow.%s[%d]: captured target '%s' references variable(s) %s not declared by source '%s' " .
            "(source declares %s). " .
            'Rename the target variable to match the source, or use a glob target (e.g. `domain-*`) ' .
            'when the target should match independently of the binding.',
            $source,
            $index,
            $targetSelector->originalString(),
            $variableList,
            $source,
            $sourceVariables,
        );
    }

    /**
     * Builds the user-facing rejection message for "captured target with
     * shape mismatch". The runtime substitutes the bound value literally
     * so a single-segment / multi-segment annotation conflict between
     * source and target would silently fall through.
     *
     * @param array<string, array{source: bool, target: bool}> $mismatches
     */
    private function renderShapeMismatchMessage(
        string $source,
        int $index,
        LayerSelector $sourceSelector,
        LayerSelector $targetSelector,
        array $mismatches,
    ): string {
        $rendered = implode(', ', array_map(
            static fn(string $variable, array $shapes): string => \sprintf(
                "'%s' (source: %s, target: %s)",
                $variable,
                $shapes['source'] ? '{var:**}' : '{var}',
                $shapes['target'] ? '{var:**}' : '{var}',
            ),
            array_keys($mismatches),
            array_values($mismatches),
        ));

        return \sprintf(
            "architecture.allow.%s[%d]: captured target '%s' declares variable(s) %s with a different segment shape " .
            "than source '%s'. The runtime substitutes the bound value literally, so a mismatched annotation would " .
            'be silently ignored. Align the shapes on both sides (use `{var}` everywhere for single-segment ' .
            'captures or `{var:**}` everywhere for multi-segment).',
            $source,
            $index,
            $targetSelector->originalString(),
            $rendered,
            $sourceSelector->originalString(),
        );
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
     * Normalizes a single allow-list entry to a (selector, allowCrossInstance) tuple.
     *
     * Supports two forms:
     * - Short: bare string {@code 'service'} (or {@code 'service-*'} / {@code 'app-{m}'}).
     *   {@code allowCrossInstance} is always false.
     * - Long:  associative array
     *   {@code [target: 'service', types: ['method_call'], allow_cross_instance: true]}.
     *
     * The long form's {@code types} key is accepted for forward compatibility but
     * not yet enforced; if present, append a deferred deprecation-style warning.
     *
     * @param list<DeferredWarning> $warnings Accumulator, mutated by reference for warning collection.
     *
     * @return array{0: LayerSelector, 1: bool}
     */
    private function normalizeAllowEntry(string $source, int $index, mixed $entry, array &$warnings): array
    {
        if (\is_string($entry)) {
            if ($entry === '') {
                throw new ConfigLoadException(
                    self::CONFIG_PATH,
                    \sprintf('architecture.allow.%s[%d]: target must be a non-empty string.', $source, $index),
                );
            }

            return [
                $this->parseSelector(
                    $entry,
                    \sprintf('architecture.allow.%s[%d]', $source, $index),
                ),
                false,
            ];
        }

        if (\is_array($entry) && !array_is_list($entry) && isset($entry['target']) && \is_string($entry['target']) && $entry['target'] !== '') {
            $this->rejectUnsupportedLongFormKeys($source, $index, $entry);

            if (\array_key_exists('types', $entry)) {
                $warnings[] = DeferredWarning::warning(\sprintf(
                    "architecture.allow.%s: 'types' filter declared but not yet enforced (Phase 2).",
                    $source,
                ));
            }

            $allowCrossInstance = $this->parseAllowCrossInstanceFlag($source, $index, $entry);

            return [
                $this->parseSelector(
                    $entry['target'],
                    \sprintf('architecture.allow.%s[%d]', $source, $index),
                ),
                $allowCrossInstance,
            ];
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
     * Extracts the {@code allow_cross_instance} long-form flag. Absent → false.
     * Non-boolean values are rejected so a user typo (e.g.
     * {@code allow_cross_instance: 'yes'}) cannot silently fall through to the
     * "false" default and surprise the user with mutual-allow warnings they
     * thought they had silenced.
     *
     * @param array<string, mixed> $entry
     */
    private function parseAllowCrossInstanceFlag(string $source, int $index, array $entry): bool
    {
        if (!\array_key_exists('allow_cross_instance', $entry)) {
            return false;
        }

        $value = $entry['allow_cross_instance'];
        if (!\is_bool($value)) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf(
                    "architecture.allow.%s[%d]: 'allow_cross_instance' must be a boolean, got %s.",
                    $source,
                    $index,
                    get_debug_type($value),
                ),
            );
        }

        return $value;
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
