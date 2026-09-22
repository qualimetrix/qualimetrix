<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer;

use InvalidArgumentException;
use RuntimeException;

/**
 * Compiles the Architecture namespace-pattern DSL into an anchored PCRE
 * expression with optional named captures. Used by
 * {@see \Qualimetrix\Analysis\Policy\Architecture\Layer\Expansion\LayerExpansionStage} to extract
 * observed binding tuples from the project's class set when expanding a
 * {@see TemplateLayerDefinition}, and by {@see TemplateLayerDefinition}
 * itself for the construction-time "variable in name → variable in some
 * capture-producing pattern" invariant.
 *
 * **Grammar.**
 *
 * | Source                | Regex                                                                   | Semantics                                                                                    |
 * | --------------------- | ----------------------------------------------------------------------- | -------------------------------------------------------------------------------------------- |
 * | {@code {var}}         | {@code (?P<var>[^\\]+)}                                                 | Captures exactly one namespace segment (between backslashes), at least one char.             |
 * | {@code {var:**}}      | {@code (?P<var>[^\\]+(?:\\[^\\]+)*)}                                    | Captures one or more namespace segments, separator-aware.                                    |
 * | {@code **}            | contextual                                                              | Cross-segment wildcard; a trailing {@code \**} selects strict descendants.                   |
 * | {@code *}             | {@code [^\\]*}                                                          | Matches any chars within one segment.                                                        |
 * | {@code ?}             | {@code [^\\]}                                                           | Matches one char within one segment.                                                         |
 * | {@code \}             | {@code \\}                                                              | Namespace separator — always literal; no escape semantics.                                   |
 * | other                 | {@code preg_quote()}                                                    | Literal char.                                                                                |
 *
 * A wildcard-free pattern denotes an inclusive namespace subtree: both the
 * named namespace and its descendants match. Wildcard patterns are anchored
 * and must match the complete FQN. Character classes and raw PCRE syntax are
 * deliberately outside this DSL.
 *
 * Unlike the {@see \Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\LayerSelectorParser}
 * grammar (which treats {@code \{} / {@code \}} as escaped literal braces),
 * FQN patterns reserve {@code \} as the namespace separator only. PHP FQNs
 * never contain literal {@code &#123;} / {@code &#125;}, so the escape
 * affordance has no use case and is omitted. This is an intentionally
 * independent, closed DSL; it is not a Core selector and does not accept the
 * public {@code exact/subtree/regex} selector shape.
 *
 * **Variable name regex** ({@see VARIABLE_NAME_REGEX}) intentionally mirrors
 * {@see \Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\LayerSelectorParser::VARIABLE_NAME_REGEX}.
 * The two grammars share the same identifier rules but live in independent
 * namespaces so {@code Layer/} need not depend on {@code Allow/} — the
 * duplication is local and intentional.
 *
 * @internal Consumed by {@see TemplateLayerDefinition} and {@see \Qualimetrix\Analysis\Policy\Architecture\Layer\Expansion\LayerExpansionStage}.
 */
final readonly class CapturePattern
{
    /**
     * Regex defining a capture-variable identifier — same shape as
     * {@see \Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\LayerSelectorParser::VARIABLE_NAME_REGEX}.
     */
    public const string VARIABLE_NAME_REGEX = CapturePatternCompiler::VARIABLE_NAME_REGEX;

    /**
     * @param string $rawPattern Source pattern (as written by the user).
     * @param string $regex Compiled PCRE pattern with delimiters and anchors.
     * @param list<string> $variableNames Capture variables referenced in
     *                                    {@code rawPattern}, in first-occurrence order.
     * @param list<string> $multiSegmentVariableNames Variables declared with {@code :**}.
     */
    private function __construct(
        public string $rawPattern,
        public string $regex,
        public array $variableNames,
        public array $multiSegmentVariableNames,
    ) {}

    /**
     * Compiles a pattern string into a {@see CapturePattern}.
     *
     * @throws InvalidArgumentException If the grammar is violated (unbalanced
     *                                  braces, empty capture, duplicate name,
     *                                  unknown quantifier, invalid identifier,
     *                                  dangling backslash).
     */
    public static function compile(string $rawPattern): self
    {
        if ($rawPattern === '') {
            throw new InvalidArgumentException('CapturePattern: source pattern must not be empty.');
        }

        self::rejectUnsupportedSyntax($rawPattern);

        $hasWildcardOrCapture = strpbrk($rawPattern, '*?{}') !== false;
        if (!$hasWildcardOrCapture) {
            return new self(
                $rawPattern,
                '~\\A(?:' . preg_quote($rawPattern, '~') . ')(?:\\\\.+)?\\z~',
                [],
                [],
            );
        }

        [$regex, $variables, $multiSegmentVariables] = (new CapturePatternCompiler($rawPattern))->compile();

        return new self($rawPattern, $regex, $variables, $multiSegmentVariables);
    }

    /**
     * Returns the list of variable names referenced by a pattern, without
     * compiling the regex. Used by {@see TemplateLayerDefinition} for the
     * construction-time invariant check.
     *
     * @return list<string>
     */
    public static function extractVariables(string $rawPattern): array
    {
        return self::compile($rawPattern)->variableNames;
    }

    /** @return list<string> */
    public static function extractMultiSegmentVariables(string $rawPattern): array
    {
        return self::compile($rawPattern)->multiSegmentVariableNames;
    }

    /**
     * Returns true if the pattern references at least one capture variable.
     */
    public static function isCaptureProducing(string $rawPattern): bool
    {
        return self::extractVariables($rawPattern) !== [];
    }

    public static function matches(string $rawPattern, string $fqn): bool
    {
        /** @var array<string, self> $compiled */
        static $compiled = [];

        return ($compiled[$rawPattern] ??= self::compile($rawPattern))->match($fqn) !== null;
    }

    /**
     * Attempts to match the FQN. Returns the captured bindings (variable name →
     * captured value) on success, or null if the pattern does not match.
     *
     * For a non-capturing pattern (no variables), an empty array signals a
     * match; null signals a non-match.
     *
     * @return array<string, string>|null
     */
    public function match(string $fqn): ?array
    {
        if ($fqn === '') {
            return null;
        }

        $matched = preg_match($this->regex, $fqn, $matches);
        if ($matched === false) {
            throw new RuntimeException(\sprintf(
                'CapturePattern: PCRE compile/match failure for compiled regex %s (source pattern %s).',
                $this->regex,
                $this->rawPattern,
            ));
        }

        if ($matched !== 1) {
            return null;
        }

        $bindings = [];
        foreach ($this->variableNames as $name) {
            // Named captures are always present in $matches on a successful
            // preg_match when the pattern requires them (no optional groups
            // in our grammar).
            $bindings[$name] = $matches[$name];
        }

        return $bindings;
    }

    /**
     * Substitutes the bindings into the raw pattern, producing another
     * Architecture namespace pattern.
     *
     * The substitution preserves any non-capture glob metacharacters
     * ({@code **}, {@code *}, {@code ?}) verbatim — the result is still a
     * glob pattern, just with the variables resolved.
     *
     * @param array<string, string> $bindings
     */
    public function substitute(array $bindings): string
    {
        return self::applySubstitution($this->rawPattern, $bindings);
    }

    /**
     * Stateless substitution helper. Walks the pattern char-by-char, replacing
     * {@code {var}} / {@code {var:**}} occurrences with their binding values.
     * The {@code \} character is always a literal namespace separator.
     *
     * Bindings not present in {@code $bindings} pass through verbatim — the
     * call site is responsible for providing every variable; this helper is
     * defensive about partial substitutions only insofar as it does not crash.
     *
     * @param array<string, string> $bindings
     */
    public static function applySubstitution(string $template, array $bindings): string
    {
        $result = '';
        $cursor = 0;
        $length = \strlen($template);

        while ($cursor < $length) {
            $char = $template[$cursor];

            if ($char === '{') {
                [$variableName, , $advance] = CapturePatternCompiler::parseCapture($template, $cursor);
                $result .= $bindings[$variableName] ?? '{' . $variableName . '}';
                $cursor = $advance;

                continue;
            }

            $result .= $char;
            $cursor++;
        }

        return $result;
    }

    private static function rejectUnsupportedSyntax(string $rawPattern): void
    {
        self::rejectInvalidSegments($rawPattern);
        self::rejectRawRegexSyntax($rawPattern);
    }

    private static function rejectInvalidSegments(string $rawPattern): void
    {
        if (str_starts_with($rawPattern, '\\')
            || str_ends_with($rawPattern, '\\')
            || str_contains($rawPattern, '\\\\')) {
            throw new InvalidArgumentException(\sprintf(
                'CapturePattern: pattern "%s" must not have leading, trailing, or empty namespace segments.',
                $rawPattern,
            ));
        }

    }

    private static function rejectRawRegexSyntax(string $rawPattern): void
    {
        $insideCapture = false;
        for ($offset = 0, $length = \strlen($rawPattern); $offset < $length; $offset++) {
            $char = $rawPattern[$offset];
            if ($char === '[' || $char === ']') {
                throw new InvalidArgumentException(\sprintf(
                    'CapturePattern: character classes are not supported; found "%s" at offset %d in pattern "%s".',
                    $char,
                    $offset,
                    $rawPattern,
                ));
            }
            if ($char === '{') {
                $insideCapture = true;
            } elseif ($char === '}') {
                $insideCapture = false;
            } elseif (!$insideCapture && str_contains('()|+^$', $char)) {
                throw new InvalidArgumentException(\sprintf(
                    'CapturePattern: raw PCRE syntax is not supported; found "%s" at offset %d in pattern "%s".',
                    $char,
                    $offset,
                    $rawPattern,
                ));
            }
        }
    }
}
