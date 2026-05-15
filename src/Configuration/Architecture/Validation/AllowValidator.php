<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Architecture\Validation;

use Qualimetrix\Architecture\Domain\Allow\AllowListEntry;
use Qualimetrix\Architecture\Domain\Allow\AllowTarget;
use Qualimetrix\Architecture\Domain\Allow\InvalidSelectorException;
use Qualimetrix\Architecture\Domain\Allow\LayerSelector;
use Qualimetrix\Architecture\Domain\Allow\LayerSelectorParser;
use Qualimetrix\Configuration\Exception\ConfigLoadException;
use Qualimetrix\Configuration\Pipeline\DeferredWarning;
use Qualimetrix\Core\Dependency\DependencyType;

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
 * The long form ({@code [target: 'service', relations: ['static_call']]}) is
 * fully wired in Step G: {@code relations:} expands through
 * {@see AllowAliasExpander} into a {@see DependencyType} list that
 * {@see \Qualimetrix\Architecture\Domain\Layer\LayerPolicy::isAllowed()} checks
 * the actual dependency edge against. Bare-string targets keep the legacy
 * "all relations allowed" semantics.
 */
final class AllowValidator
{
    private const string CONFIG_PATH = 'architecture';

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

            $allowTargets = $this->normalizeAllowTargets($sourceRaw, $sourceSelector, $targets, $layerSet);
            $entries[] = new AllowListEntry($sourceSelector, $allowTargets);
        }

        return $entries;
    }

    /**
     * @param array<string, int> $layerSet
     *
     * @return list<AllowTarget>
     */
    private function normalizeAllowTargets(
        string $source,
        LayerSelector $sourceSelector,
        mixed $targets,
        array $layerSet,
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
        $seenBareExact = [];
        foreach ($targets as $index => $entry) {
            [$targetSelector, $allowCrossInstance, $relations] = $this->normalizeAllowEntry($source, $index, $entry);

            $this->crossValidateCapturedTarget($source, $index, $sourceSelector, $sourceShapes, $targetSelector);

            $isBare = $relations === null && !$allowCrossInstance;
            if ($this->shouldSkipExactTarget($source, $index, $targetSelector, $layerSet, $isBare, $seenBareExact)) {
                continue;
            }

            $result[] = new AllowTarget($targetSelector, relations: $relations, allowCrossInstance: $allowCrossInstance);
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
     * **Dedup scope.** Only bare-equivalent targets (no {@code relations}, no
     * {@code allow_cross_instance}) are dedupped. A bare 'vendor' + long-form
     * 'vendor' with {@code relations: [extends]} carry different semantics
     * (the bare one rescues the relation gate via UNION) and MUST both reach
     * {@see \Qualimetrix\Architecture\Domain\Layer\LayerPolicy}; dedupping them
     * would silently drop the broader sibling's effect.
     *
     * @param array<string, int> $layerSet
     * @param bool $isBare True when the current target carries no Step E/G
     *                     long-form fields (i.e. effectively equivalent to a
     *                     short-form bare string entry).
     * @param array<string, true> $seenBareExact Map of exact-target names
     *                                           already emitted as bare-form
     *                                           for this source; mutated by
     *                                           reference for dedup state.
     *
     * @param-out array<string, true> $seenBareExact
     */
    private function shouldSkipExactTarget(
        string $source,
        int $index,
        LayerSelector $targetSelector,
        array $layerSet,
        bool $isBare,
        array &$seenBareExact,
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

        if (!$isBare) {
            return false;
        }

        if (isset($seenBareExact[$targetName])) {
            return true;
        }

        $seenBareExact[$targetName] = true;

        return false;
    }

    /**
     * Discriminates the short- and long-form shapes and returns the
     * (selector, allowCrossInstance, relations) triple consumed by
     * {@see self::normalizeAllowTargets()}.
     *
     * Short form: bare string {@code 'service'} (or {@code 'service-*'} /
     * {@code 'app-{m}'}). {@code allowCrossInstance} is always false;
     * {@code relations} is null (any relation allowed).
     *
     * Long form: associative array
     * {@code [target: 'service', relations: ['static_call', 'inheritance'],
     * allow_cross_instance: true]}. The long-form vocabulary
     * (allowed keys, per-key shape rules) lives in
     * {@see LongFormAllowEntryNormalizer}.
     *
     * @return array{0: LayerSelector, 1: bool, 2: list<DependencyType>|null}
     */
    private function normalizeAllowEntry(string $source, int $index, mixed $entry): array
    {
        $context = \sprintf('architecture.allow.%s[%d]', $source, $index);

        if (\is_string($entry)) {
            if ($entry === '') {
                throw new ConfigLoadException(
                    self::CONFIG_PATH,
                    \sprintf('%s: target must be a non-empty string.', $context),
                );
            }

            return [$this->parseSelector($entry, $context), false, null];
        }

        if (\is_array($entry) && !array_is_list($entry)) {
            [$targetRaw, $allowCrossInstance, $relations] = LongFormAllowEntryNormalizer::normalize($source, $index, $entry);

            return [$this->parseSelector($targetRaw, $context), $allowCrossInstance, $relations];
        }

        throw new ConfigLoadException(
            self::CONFIG_PATH,
            \sprintf(
                "%s: each target must be a layer name (string) or a map with a non-empty 'target' key.",
                $context,
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
}
