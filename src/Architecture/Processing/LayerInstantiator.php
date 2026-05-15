<?php

declare(strict_types=1);

namespace Qualimetrix\Architecture\Processing;

use Qualimetrix\Architecture\Domain\Layer\CapturePattern;
use Qualimetrix\Architecture\Domain\Layer\ExcludeSpec;
use Qualimetrix\Architecture\Domain\Layer\InvalidLayerDefinitionException;
use Qualimetrix\Architecture\Domain\Layer\LayerDefinition;
use Qualimetrix\Architecture\Domain\Layer\MembershipSpec;
use Qualimetrix\Architecture\Domain\Layer\TemplateLayerDefinition;

/**
 * Instantiates a concrete {@see LayerDefinition} from a
 * {@see TemplateLayerDefinition} and a single observed binding tuple.
 *
 * Extracted from {@see LayerExpansionStage} in Phase 4.1 of the remediation
 * (ADR 0008). Behavior-preserving — the substitution rules, validation, and
 * error messages are unchanged.
 *
 * Surfaces a {@see LayerExpansionException} when:
 * - The tuple is incomplete (a variable referenced by the template is not
 *   bound). This typically happens under {@see \Qualimetrix\Architecture\Domain\Layer\MatchMode::Any}
 *   with multiple capture-producing patterns binding different variables.
 * - The substituted name violates the layer-name grammar (binding values
 *   must consist of letters, digits, hyphens, and underscores, starting
 *   with a letter).
 */
final class LayerInstantiator
{
    /**
     * @param array<string, string> $bindings
     *
     * @throws LayerExpansionException
     */
    public function instantiate(TemplateLayerDefinition $template, array $bindings): LayerDefinition
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
