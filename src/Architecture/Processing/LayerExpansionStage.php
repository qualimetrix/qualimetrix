<?php

declare(strict_types=1);

namespace Qualimetrix\Architecture\Processing;

use Qualimetrix\Architecture\Domain\Layer\CapturePattern;
use Qualimetrix\Architecture\Domain\Layer\ClassContext;
use Qualimetrix\Architecture\Domain\Layer\ClassSet;
use Qualimetrix\Architecture\Domain\Layer\ExcludeSpec;
use Qualimetrix\Architecture\Domain\Layer\InvalidLayerDefinitionException;
use Qualimetrix\Architecture\Domain\Layer\LayerDefinition;
use Qualimetrix\Architecture\Domain\Layer\MatchMode;
use Qualimetrix\Architecture\Domain\Layer\MembershipSpec;
use Qualimetrix\Architecture\Domain\Layer\TemplateLayerDefinition;
use Qualimetrix\Core\Util\NamespaceMatcher;

/**
 * Expands a mixed list of {@see LayerDefinition} and
 * {@see TemplateLayerDefinition} entries into the concrete declaration-order
 * layer list consumed by {@see \Qualimetrix\Architecture\Domain\Layer\LayerRegistry}.
 *
 * Plugs into {@see \Qualimetrix\Analysis\Pipeline\AnalysisPipeline} between
 * the collection and rule-execution phases (after {@code CollectionOrchestrator}
 * has produced the class set and the dependency graph, but before
 * {@code MetricEnricher} runs). Result is written into the existing
 * {@see \Qualimetrix\Architecture\Domain\ArchitectureConfigurationHolder} so
 * the rule layer reads it without any change to the {@code AnalysisContext}
 * surface.
 *
 * **Observed-tuple expansion (NOT cartesian).** For each template, the stage
 * walks the project's class set, applies all of the template's criteria
 * (capture-producing AND non-capturing per D7), and collects the distinct
 * observed binding tuples. One concrete {@see LayerDefinition} is produced
 * per tuple, named by substituting the binding values into
 * {@see TemplateLayerDefinition::$nameTemplate}. A two-variable template
 * over a project where {@code tenant=AcmeCorp} and {@code module=Order} but
 * never together produces zero layers, not the cartesian {@code AcmeCorp×Order}
 * combinations.
 *
 * **Capture-producing vs non-capturing criteria (D7 carve-out).**
 * Within {@see MembershipSpec::$patterns}, individual patterns are classified:
 * a pattern containing at least one {@code &#123;var&#125;} placeholder is
 * capture-producing; a plain glob is non-capturing. {@see MembershipSpec::$mode}
 * ({@code match: any|all}) governs only the combination of capture-producing
 * patterns. Non-capturing patterns plus the {@code suffix}, {@code attributes},
 * {@code implements}, and {@code extends} criteria ALWAYS act as an AND-filter.
 *
 * **Determinism.** Observed tuples are sorted lexicographically by the
 * template's {@see TemplateLayerDefinition::$variables} order (which is
 * already alphabetically sorted at construction). The resulting concrete
 * layers therefore land in a stable order across runs even though
 * {@code metrics->all()} iteration is parallel-collection-sensitive.
 *
 * **Failure modes** (all surface as {@see LayerExpansionException}):
 * - Cumulative expansion exceeds {@code architecture.max_expanded_layers}.
 * - A concrete name produced by substitution collides with a static layer
 *   name (or another template-expanded name).
 * - Substitution produces an invalid name (binding contains a character
 *   the relaxed expansion-mode regex does not accept).
 *
 * **Empty-template signal.** Templates that observe zero tuples are
 * collected into {@see LayerExpansionResult::$emptyTemplateNames}; the
 * {@see \Qualimetrix\Rules\Architecture\LayerViolationRule} drains the list
 * into one {@code architecture.empty-template} warning per name at the end
 * of the run.
 */
final class LayerExpansionStage
{
    /**
     * @param list<LayerDefinition|TemplateLayerDefinition> $entries Mixed
     *                                                               layer-and-template
     *                                                               entries
     *                                                               in
     *                                                               declaration
     *                                                               order.
     * @param ClassSet $classes Project class set + context resolver.
     * @param int $maxExpansion Hard ceiling on cumulative template-produced
     *                          layers ({@code architecture.max_expanded_layers}).
     *                          Must be positive.
     *
     * @throws LayerExpansionException
     */
    public function expand(array $entries, ClassSet $classes, int $maxExpansion): LayerExpansionResult
    {
        if ($maxExpansion < 1) {
            throw new LayerExpansionException(\sprintf(
                'LayerExpansionStage: max-expansion ceiling must be >= 1, got %d.',
                $maxExpansion,
            ));
        }

        $expandedLayers = [];
        $emptyTemplates = [];
        /** @var array<string, array{source: string, origin: string}> */
        $seenNames = [];
        $totalTemplateExpansions = 0;

        foreach ($entries as $entry) {
            if ($entry instanceof LayerDefinition) {
                self::recordName($seenNames, $entry->name(), 'static layer', $entry->name());
                $expandedLayers[] = $entry;

                continue;
            }

            $tuples = self::collectObservedTuples($entry, $classes);

            if ($tuples === []) {
                $emptyTemplates[] = $entry->nameTemplate();

                continue;
            }

            $tuples = self::sortTuplesLexicographically($tuples, $entry->variables());

            $thisTemplateCount = \count($tuples);
            $totalTemplateExpansions += $thisTemplateCount;
            if ($totalTemplateExpansions > $maxExpansion) {
                throw new LayerExpansionException(\sprintf(
                    'LayerExpansionStage: template "%s" added %d layers (cumulative %d across all templates), '
                    . 'exceeding the architecture.max_expanded_layers ceiling of %d. '
                    . 'Raise the ceiling via architecture.max_expanded_layers in your config, '
                    . 'or tighten the template patterns to reduce the observed binding set.',
                    $entry->nameTemplate(),
                    $thisTemplateCount,
                    $totalTemplateExpansions,
                    $maxExpansion,
                ));
            }

            foreach ($tuples as $tuple) {
                $concreteLayer = self::instantiateLayer($entry, $tuple);
                self::recordName(
                    $seenNames,
                    $concreteLayer->name(),
                    'template "' . $entry->nameTemplate() . '"',
                    $entry->nameTemplate(),
                );
                $expandedLayers[] = $concreteLayer;
            }
        }

        return new LayerExpansionResult($expandedLayers, $emptyTemplates);
    }

    /**
     * @return list<array<string, string>>
     */
    private static function collectObservedTuples(TemplateLayerDefinition $template, ClassSet $classes): array
    {
        $membership = $template->membership();

        [$captureProducing, $nonCapturePatterns] = self::splitPatterns($membership->patterns);

        if ($captureProducing === []) {
            // TemplateLayerDefinition's invariant guarantees at least one
            // capture-producing pattern, but defend against future contract
            // drift.
            return [];
        }

        /** @var array<string, array<string, string>> */
        $observed = [];

        foreach ($classes->classes() as $classPath) {
            $context = $classes->contextFor($classPath);
            if ($context->fqn === '') {
                continue;
            }

            if (!self::passesNonCapturePatterns($nonCapturePatterns, $context)) {
                continue;
            }

            if (!self::passesNonPatternCriteria($membership, $context)) {
                continue;
            }

            $tuple = self::extractTuple($captureProducing, $context->fqn, $membership->mode);
            if ($tuple === null) {
                continue;
            }

            $key = self::tupleKey($tuple);
            $observed[$key] ??= $tuple;
        }

        return array_values($observed);
    }

    /**
     * Splits patterns into capture-producing (compiled to {@see CapturePattern})
     * and non-capture (raw strings routed through {@see NamespaceMatcher} so
     * non-glob filters keep Phase-1 prefix semantics).
     *
     * **Why two engines?** {@see CapturePattern}'s regex anchors with `^...$`
     * and uses exact-character matching for any non-glob, non-capture residue
     * — perfect for substituted concrete patterns, wrong for filter patterns
     * like {@code App\Domain} which a Phase-1 user reasonably expects to match
     * {@code App\Domain\Foo} too. {@see NamespaceMatcher::matchesSingle()}
     * implements the documented Phase-1 prefix semantics. Routing non-capture
     * filters through it preserves the D7 carve-out's "filter behaves like a
     * Phase-1 pattern" intuition.
     *
     * @param list<string> $patterns
     *
     * @return array{0: list<CapturePattern>, 1: list<string>}
     */
    private static function splitPatterns(array $patterns): array
    {
        $capture = [];
        $nonCapture = [];
        foreach ($patterns as $pattern) {
            if (CapturePattern::isCaptureProducing($pattern)) {
                $capture[] = CapturePattern::compile($pattern);
            } else {
                $nonCapture[] = $pattern;
            }
        }

        return [$capture, $nonCapture];
    }

    /**
     * Returns true if the class FQN matches every non-capture pattern
     * (D7 AND-filter). Empty non-capture pattern list trivially passes.
     *
     * Delegates to {@see NamespaceMatcher::matchesSingle()} so non-glob filter
     * patterns ({@code App\Domain}) keep Phase-1 prefix semantics — they
     * match the namespace itself AND any class beneath it.
     *
     * @param list<string> $patterns
     */
    private static function passesNonCapturePatterns(array $patterns, ClassContext $context): bool
    {
        foreach ($patterns as $pattern) {
            if (!NamespaceMatcher::matchesSingle(rtrim($pattern, '\\'), $context->fqn)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns true if the class context satisfies every declared non-pattern
     * criterion (suffix / attributes / implements / extends). Empty criteria
     * trivially pass — D7 always AND.
     */
    private static function passesNonPatternCriteria(MembershipSpec $membership, ClassContext $context): bool
    {
        if ($membership->suffix !== [] && !self::matchesAnySuffix($membership->suffix, $context->shortName)) {
            return false;
        }

        if ($membership->attributes !== [] && !self::haystackContainsAny($membership->attributes, $context->attributeFqns)) {
            return false;
        }

        if ($membership->implements !== [] && !self::haystackContainsAny($membership->implements, $context->interfaces)) {
            return false;
        }

        if ($membership->extends !== [] && !self::haystackContainsAny($membership->extends, $context->parentClasses)) {
            return false;
        }

        return true;
    }

    /**
     * @param list<string> $suffixes
     */
    private static function matchesAnySuffix(array $suffixes, string $shortName): bool
    {
        if ($shortName === '') {
            return false;
        }

        foreach ($suffixes as $suffix) {
            if (str_ends_with($shortName, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $needles
     * @param list<string> $haystack
     */
    private static function haystackContainsAny(array $needles, array $haystack): bool
    {
        if ($haystack === []) {
            return false;
        }

        $set = array_fill_keys($haystack, true);
        foreach ($needles as $needle) {
            if (isset($set[$needle])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extracts a binding tuple from the capture-producing patterns, combined
     * per the template's match mode:
     *
     * - {@see MatchMode::Any}: first matching pattern wins; the tuple
     *   contains its captures. Variables not bound by that single match
     *   pass through unbound — they would only be relevant if a later
     *   pattern matches, but {@code any} short-circuits on first hit.
     *
     * - {@see MatchMode::All}: every capture-producing pattern must match,
     *   and the union of bindings must be consistent (same variable mapped
     *   to the same value across patterns).
     *
     * Returns null when no tuple can be produced.
     *
     * @param list<CapturePattern> $patterns
     *
     * @return array<string, string>|null
     */
    private static function extractTuple(array $patterns, string $fqn, MatchMode $mode): ?array
    {
        if ($mode === MatchMode::Any) {
            foreach ($patterns as $pattern) {
                $bindings = $pattern->match($fqn);
                if ($bindings !== null) {
                    return $bindings;
                }
            }

            return null;
        }

        // MatchMode::All
        $union = [];
        foreach ($patterns as $pattern) {
            $bindings = $pattern->match($fqn);
            if ($bindings === null) {
                return null;
            }

            foreach ($bindings as $name => $value) {
                if (isset($union[$name]) && $union[$name] !== $value) {
                    // Conflicting bindings — pattern set is inconsistent for this FQN.
                    return null;
                }
                $union[$name] = $value;
            }
        }

        return $union;
    }

    /**
     * Builds a deterministic string key for tuple deduplication. The
     * delimiter is unlikely to appear in any sane binding value (PHP FQN
     * segments) but is fine even if it does — the only requirement is that
     * (value list, variable order) is uniquely encoded.
     *
     * @param array<string, string> $tuple
     */
    private static function tupleKey(array $tuple): string
    {
        ksort($tuple);

        return implode("\x1F", array_map(
            static fn(string $name, string $value): string => $name . "\x00" . $value,
            array_keys($tuple),
            array_values($tuple),
        ));
    }

    /**
     * Sorts the tuple list lexicographically by the template's variable
     * order. {@see TemplateLayerDefinition::$variables} is already sorted
     * alphabetically at construction, so callers see a deterministic
     * order regardless of declaration form.
     *
     * @param list<array<string, string>> $tuples
     * @param list<string> $variableOrder
     *
     * @return list<array<string, string>>
     */
    private static function sortTuplesLexicographically(array $tuples, array $variableOrder): array
    {
        usort($tuples, static function (array $a, array $b) use ($variableOrder): int {
            foreach ($variableOrder as $variable) {
                $cmp = strcmp($a[$variable] ?? '', $b[$variable] ?? '');
                if ($cmp !== 0) {
                    return $cmp;
                }
            }

            return 0;
        });

        return $tuples;
    }

    /**
     * @param array<string, string> $bindings
     */
    private static function instantiateLayer(TemplateLayerDefinition $template, array $bindings): LayerDefinition
    {
        // Pre-flight: every variable referenced by the template (in name OR
        // in any capture-producing pattern) must be bound. Under
        // {@see MatchMode::Any} with patterns that each bind a different
        // variable, the first matching pattern can return a partial tuple —
        // we surface that as an explicit, actionable error rather than
        // letting it surface as "invalid name format" (the substituted
        // name retains literal `{var}` placeholders).
        $missing = array_values(array_diff($template->variables(), array_keys($bindings)));
        if ($missing !== []) {
            throw new LayerExpansionException(\sprintf(
                'LayerExpansionStage: template "%s" produced an incomplete binding tuple %s — variable(s) "%s" '
                . 'were not bound by any matching capture-producing pattern. '
                . 'This typically happens when "match: any" is combined with multiple capture-producing patterns '
                . 'that each bind a different variable. Switch to "match: all" so every pattern is required and '
                . 'their bindings union, or ensure each capture-producing pattern binds every variable referenced '
                . 'in the name template.',
                $template->nameTemplate(),
                self::renderBindings($bindings),
                implode('", "', $missing),
            ));
        }

        $concreteName = CapturePattern::applySubstitution($template->nameTemplate(), $bindings);
        $concreteMembership = self::substituteMembership($template->membership(), $bindings);

        try {
            return LayerDefinition::expanded($concreteName, $concreteMembership);
        } catch (InvalidLayerDefinitionException $e) {
            throw new LayerExpansionException(\sprintf(
                'LayerExpansionStage: template "%s" produced invalid concrete layer name "%s" for bindings %s — %s. '
                . 'Binding values must consist of letters, digits, hyphens, and underscores, starting with a letter.',
                $template->nameTemplate(),
                $concreteName,
                self::renderBindings($bindings),
                $e->getMessage(),
            ), 0, $e);
        }
    }

    /**
     * @param array<string, string> $bindings
     */
    private static function substituteMembership(MembershipSpec $membership, array $bindings): MembershipSpec
    {
        $patterns = array_map(
            static fn(string $pattern): string => CapturePattern::applySubstitution($pattern, $bindings),
            $membership->patterns,
        );

        $exclude = $membership->exclude !== null
            ? new ExcludeSpec(
                patterns: array_map(
                    static fn(string $pattern): string => CapturePattern::applySubstitution($pattern, $bindings),
                    $membership->exclude->patterns,
                ),
                suffix: $membership->exclude->suffix,
                attributes: $membership->exclude->attributes,
                implements: $membership->exclude->implements,
                extends: $membership->exclude->extends,
                mode: $membership->exclude->mode,
            )
            : null;

        // Non-pattern criteria do not currently support captures; they pass
        // through verbatim. If/when captures are added to suffix or FQN
        // criteria, swap this for the same applySubstitution call.
        return new MembershipSpec(
            patterns: $patterns,
            suffix: $membership->suffix,
            attributes: $membership->attributes,
            implements: $membership->implements,
            extends: $membership->extends,
            mode: $membership->mode,
            exclude: $exclude,
        );
    }

    /**
     * @param array<string, array{source: string, origin: string}> $seenNames
     *
     * @param-out array<string, array{source: string, origin: string}> $seenNames
     */
    private static function recordName(array &$seenNames, string $name, string $source, string $origin): void
    {
        if (isset($seenNames[$name])) {
            throw new LayerExpansionException(\sprintf(
                'LayerExpansionStage: layer name "%s" produced by %s "%s" collides with %s "%s". '
                . 'Each expanded layer name must be unique — rename one of the templates or static layers.',
                $name,
                $source,
                $origin,
                $seenNames[$name]['source'],
                $seenNames[$name]['origin'],
            ));
        }

        $seenNames[$name] = ['source' => $source, 'origin' => $origin];
    }

    /**
     * @param array<string, string> $bindings
     */
    private static function renderBindings(array $bindings): string
    {
        $parts = [];
        foreach ($bindings as $name => $value) {
            $parts[] = $name . '=' . $value;
        }

        return '{' . implode(', ', $parts) . '}';
    }
}
