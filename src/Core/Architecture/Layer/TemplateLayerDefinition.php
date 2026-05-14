<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Architecture\Layer;

use InvalidArgumentException;

/**
 * A layer entry parameterised by capture variables. Expanded into one or more
 * concrete {@see LayerDefinition} instances by
 * {@see \Qualimetrix\Analysis\Architecture\LayerExpansionStage} based on the
 * observed binding tuples in the project's class set.
 *
 * **Construction-time invariant.** Every variable referenced in
 * {@see nameTemplate} must also appear in at least one capture-producing
 * pattern (a pattern in {@see MembershipSpec::$patterns} that itself
 * contains a `{var}` placeholder). A variable that appears only in the name
 * has no source of binding values — expansion would be non-deterministic.
 *
 * **D7 carve-out (locked in ADR 0007).** Capture-producing criteria are
 * combined per {@see MembershipSpec::$mode} ({@code match: any|all}).
 * Non-capturing criteria (suffix, attributes, implements, extends, and any
 * non-capture patterns) ALWAYS act as an AND-filter, regardless of the
 * declared match mode. The mode controls only how capture-producing criteria
 * combine to produce binding tuples.
 *
 * **Captures are allowed only in patterns and the name template.** Suffix,
 * attribute, implements, and extends entries are fixed strings — adding
 * captures to them is out of scope for Step D and would require a new
 * grammar for short-name suffixes, which is not motivated by any documented
 * test case.
 *
 * Variables are sorted in alphabetic order at construction so the
 * {@see \Qualimetrix\Analysis\Architecture\LayerExpansionStage} can produce
 * stable tuple ordering regardless of declaration order in the YAML.
 */
final readonly class TemplateLayerDefinition
{
    /**
     * @var list<string> Sorted, distinct list of every capture variable
     *                   referenced by either {@see nameTemplate} or some
     *                   pattern in {@see membership}.
     */
    public array $variables;

    /**
     * @throws InvalidArgumentException If the name template is empty, the
     *                                  membership references no capture
     *                                  variables (templates must be
     *                                  parameterised), a variable appears
     *                                  in the name but in no
     *                                  capture-producing pattern, or the
     *                                  exclude clause references a variable
     *                                  not declared by the template's
     *                                  name/pattern variables (Step F).
     */
    public function __construct(
        public string $nameTemplate,
        public MembershipSpec $membership,
    ) {
        if ($nameTemplate === '') {
            throw new InvalidArgumentException(
                'TemplateLayerDefinition: name template must not be empty.',
            );
        }

        $nameVariables = self::collectVariablesFromString($nameTemplate, 'name template');
        if ($nameVariables === []) {
            throw new InvalidArgumentException(\sprintf(
                'TemplateLayerDefinition: name template "%s" references no capture variables — declare a plain LayerDefinition instead.',
                $nameTemplate,
            ));
        }

        $patternVariables = self::collectCaptureProducingPatternVariables($membership->patterns);

        $missingFromPatterns = array_values(array_diff($nameVariables, $patternVariables));
        if ($missingFromPatterns !== []) {
            throw new InvalidArgumentException(\sprintf(
                'TemplateLayerDefinition: variable(s) "%s" referenced in name template "%s" but not bound by any capture-producing pattern. Add a pattern containing "{%s}" or remove the variable from the name template.',
                implode('", "', $missingFromPatterns),
                $nameTemplate,
                $missingFromPatterns[0],
            ));
        }

        $allVariables = array_values(array_unique(array_merge($nameVariables, $patternVariables)));
        sort($allVariables);

        $this->variables = $allVariables;

        if ($membership->exclude !== null) {
            self::validateExcludeVariables($nameTemplate, $allVariables, $membership->exclude);
        }
    }

    public function nameTemplate(): string
    {
        return $this->nameTemplate;
    }

    public function membership(): MembershipSpec
    {
        return $this->membership;
    }

    /**
     * @return list<string>
     */
    public function variables(): array
    {
        return $this->variables;
    }

    /**
     * Cheap structural check: returns true if {@code $rawString} contains
     * either a {@code &#123;} or a {@code &#125;} brace. Used by config-layer
     * validators to decide whether to take the template-construction path.
     *
     * Does NOT compile the pattern — that is left to the
     * {@see TemplateLayerDefinition} constructor (for name templates) and
     * {@see \Qualimetrix\Analysis\Architecture\LayerExpansionStage} (for
     * runtime pattern matching). Strings containing unbalanced or malformed
     * braces still return true here, so the call site surfaces a
     * config-load error (via the constructor) instead of a silent classify
     * fallback to a static-layer code path.
     */
    public static function containsCaptureVariable(string $rawString): bool
    {
        return str_contains($rawString, '{') || str_contains($rawString, '}');
    }

    /**
     * @return list<string>
     */
    private static function collectVariablesFromString(string $rawString, string $contextLabel): array
    {
        try {
            return CapturePattern::extractVariables($rawString);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException(\sprintf(
                'TemplateLayerDefinition: %s "%s" has invalid capture grammar — %s',
                $contextLabel,
                $rawString,
                $e->getMessage(),
            ), 0, $e);
        }
    }

    /**
     * Walks the membership's pattern list and collects every variable name
     * declared by patterns that contain at least one capture. Patterns
     * without captures are ignored — they act as non-capturing filters.
     *
     * @param list<string> $patterns
     *
     * @return list<string>
     */
    private static function collectCaptureProducingPatternVariables(array $patterns): array
    {
        $collected = [];
        foreach ($patterns as $pattern) {
            $vars = self::collectVariablesFromString($pattern, 'pattern');
            foreach ($vars as $var) {
                $collected[$var] = true;
            }
        }

        return array_keys($collected);
    }

    /**
     * Enforces the Step F invariant: every variable referenced in the
     * exclude clause's patterns must already be declared by the template's
     * name or capture-producing patterns. Exclude cannot introduce new
     * variables — it has no binding source of its own, so an unknown
     * variable in exclude would never substitute and the rule would silently
     * misbehave.
     *
     * Captures are accepted only in {@see ExcludeSpec::$patterns}; the
     * other criterion kinds (suffix/attributes/implements/extends) are
     * fixed strings, mirroring the positive-criteria carve-out documented
     * above.
     *
     * @param list<string> $templateVariables Sorted union of name + capture-producing pattern variables.
     */
    private static function validateExcludeVariables(string $nameTemplate, array $templateVariables, ExcludeSpec $exclude): void
    {
        $excludeVariables = [];
        foreach ($exclude->patterns as $pattern) {
            foreach (self::collectVariablesFromString($pattern, 'exclude pattern') as $var) {
                $excludeVariables[$var] = true;
            }
        }

        if ($excludeVariables === []) {
            return;
        }

        $unknown = array_values(array_diff(array_keys($excludeVariables), $templateVariables));
        if ($unknown === []) {
            return;
        }

        throw new InvalidArgumentException(\sprintf(
            'TemplateLayerDefinition: exclude clause references undeclared variable(s) "%s" not bound by the template "%s" (declared variables: "%s"). Exclude can only refer to variables already declared in the name template or capture-producing patterns.',
            implode('", "', $unknown),
            $nameTemplate,
            $templateVariables === [] ? '' : implode('", "', $templateVariables),
        ));
    }
}
