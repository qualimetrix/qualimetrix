<?php

declare(strict_types=1);

namespace Qualimetrix\Architecture\Domain\Layer;

use InvalidArgumentException;
use RuntimeException;

/**
 * Compiles an FQN glob pattern containing capture variables (D4 grammar)
 * into a PCRE regex with named subpatterns. Used by
 * {@see \Qualimetrix\Analysis\Architecture\LayerExpansionStage} to extract
 * observed binding tuples from the project's class set when expanding a
 * {@see TemplateLayerDefinition}, and by {@see TemplateLayerDefinition}
 * itself for the construction-time "variable in name → variable in some
 * capture-producing pattern" invariant.
 *
 * **Grammar (Phase 2 direction 2 — template layers).**
 *
 * | Source                | Regex                                                                   | Semantics                                                                                    |
 * | --------------------- | ----------------------------------------------------------------------- | -------------------------------------------------------------------------------------------- |
 * | {@code {var}}         | {@code (?P<var>[^\\]+)}                                                 | Captures exactly one namespace segment (between backslashes), at least one char.             |
 * | {@code {var:**}}      | {@code (?P<var>[^\\]+(?:\\[^\\]+)*)}                                    | Captures one or more namespace segments, separator-aware.                                    |
 * | {@code **}            | {@code .+}                                                              | Matches one or more characters, including separators (cross-segment wildcard).               |
 * | {@code *}             | {@code [^\\]*}                                                          | Matches any chars within one segment.                                                        |
 * | {@code ?}             | {@code [^\\]}                                                           | Matches one char within one segment.                                                         |
 * | {@code \}             | {@code \\}                                                              | Namespace separator — always literal; no escape semantics.                                   |
 * | other                 | {@code preg_quote()}                                                    | Literal char.                                                                                |
 *
 * Unlike the {@see \Qualimetrix\Architecture\Domain\Allow\LayerSelectorParser}
 * grammar (which DOES treat {@code \{} / {@code \}} as escaped literal braces),
 * FQN patterns reserve {@code \} as the namespace separator only. PHP FQNs
 * never contain literal {@code &#123;} / {@code &#125;}, so the escape
 * affordance has no use case and is omitted to keep the grammar one-to-one
 * with {@see \Qualimetrix\Core\Util\NamespaceMatcher}'s glob syntax for any
 * pattern without capture variables.
 *
 * **Semantic note vs {@see \Qualimetrix\Core\Util\NamespaceMatcher}.** For
 * patterns containing glob metacharacters ({@code *}, {@code ?}, {@code [}),
 * both engines produce equivalent results (CapturePattern's regex is a
 * straightforward translation of {@code fnmatch()} semantics for FQN-shaped
 * input). For NON-glob, non-capture patterns the two engines diverge:
 * {@see \Qualimetrix\Core\Util\NamespaceMatcher} treats {@code App\Foo} as
 * a namespace PREFIX (matches {@code App\Foo} and {@code App\Foo\Bar}),
 * whereas {@see CapturePattern} compiles {@code App\Foo} to an exact-match
 * regex ({@code /^App\\Foo$/}) — there is no prefix-expansion fallback
 * because a substituted concrete pattern produced by template expansion is
 * already a full pattern in its own right. Call sites that need Phase-1
 * prefix semantics for non-capture filter patterns route through
 * {@see \Qualimetrix\Core\Util\NamespaceMatcher} directly (see
 * {@see \Qualimetrix\Analysis\Architecture\LayerExpansionStage}).
 *
 * **Variable name regex** ({@see VARIABLE_NAME_REGEX}) intentionally mirrors
 * {@see \Qualimetrix\Architecture\Domain\Allow\LayerSelectorParser::VARIABLE_NAME_REGEX}.
 * The two grammars share the same identifier rules but live in independent
 * namespaces so {@code Layer/} need not depend on {@code Allow/} — the
 * duplication is local and intentional.
 *
 * @internal Consumed by {@see TemplateLayerDefinition} and {@see \Qualimetrix\Analysis\Architecture\LayerExpansionStage}.
 */
final readonly class CapturePattern
{
    /**
     * Regex defining a capture-variable identifier — same shape as
     * {@see \Qualimetrix\Architecture\Domain\Allow\LayerSelectorParser::VARIABLE_NAME_REGEX}.
     */
    public const string VARIABLE_NAME_REGEX = '[A-Za-z_][A-Za-z0-9_]*';

    /**
     * @param string $rawPattern Source pattern (as written by the user).
     * @param string $regex Compiled PCRE pattern with delimiters and anchors.
     * @param list<string> $variableNames Capture variables referenced in
     *                                    {@code rawPattern}, in first-occurrence order.
     */
    private function __construct(
        public string $rawPattern,
        public string $regex,
        public array $variableNames,
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

        $regex = '/^';
        $variables = [];
        $seenVariables = [];

        $cursor = 0;
        $length = \strlen($rawPattern);

        while ($cursor < $length) {
            $char = $rawPattern[$cursor];

            if ($char === '\\') {
                // `\` is always the namespace separator in FQN patterns —
                // no escape semantics for `{` / `}` (PHP FQNs cannot contain
                // braces anyway, and treating `\{` as escaped would collide
                // with the natural `App\{var}` shape).
                $regex .= preg_quote('\\', '/');
                $cursor++;

                continue;
            }

            if ($char === '}') {
                throw new InvalidArgumentException(\sprintf(
                    "CapturePattern: unbalanced '}' at offset %d in pattern \"%s\".",
                    $cursor,
                    $rawPattern,
                ));
            }

            if ($char === '{') {
                [$variableName, $multiSegment, $advance] = self::consumeCapture($rawPattern, $cursor);

                if (isset($seenVariables[$variableName])) {
                    throw new InvalidArgumentException(\sprintf(
                        "CapturePattern: duplicate capture name '%s' in pattern \"%s\" — each variable may only appear once.",
                        $variableName,
                        $rawPattern,
                    ));
                }

                // Adjacent captures without a separator (`{a}{b}`) produce
                // ambiguous greedy/backtracking splits — almost certainly a
                // typo for `{a}\{b}`. Reject explicitly so the user sees a
                // clear config error instead of unstable expansion output.
                if ($advance < $length && $rawPattern[$advance] === '{') {
                    throw new InvalidArgumentException(\sprintf(
                        "CapturePattern: adjacent captures '{%s}{...}' at offset %d in pattern \"%s\" — "
                        . 'insert a namespace separator (\'\\\\\') between consecutive captures.',
                        $variableName,
                        $advance,
                        $rawPattern,
                    ));
                }

                $seenVariables[$variableName] = true;
                $variables[] = $variableName;

                $regex .= $multiSegment
                    ? '(?P<' . $variableName . '>[^\\\\]+(?:\\\\[^\\\\]+)*)'
                    : '(?P<' . $variableName . '>[^\\\\]+)';
                $cursor = $advance;

                continue;
            }

            if ($char === '*') {
                if ($cursor + 1 < $length && $rawPattern[$cursor + 1] === '*') {
                    $regex .= '.+';
                    $cursor += 2;
                } else {
                    $regex .= '[^\\\\]*';
                    $cursor++;
                }

                continue;
            }

            if ($char === '?') {
                $regex .= '[^\\\\]';
                $cursor++;

                continue;
            }

            $regex .= preg_quote($char, '/');
            $cursor++;
        }

        $regex .= '$/';

        return new self($rawPattern, $regex, $variables);
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

    /**
     * Returns true if the pattern references at least one capture variable.
     */
    public static function isCaptureProducing(string $rawPattern): bool
    {
        return self::extractVariables($rawPattern) !== [];
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
     * Substitutes the bindings into the raw pattern, producing a concrete
     * pattern string suitable for {@see \Qualimetrix\Core\Util\NamespaceMatcher::matchesSingle()}.
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
                [$variableName, , $advance] = self::consumeCapture($template, $cursor);
                $result .= $bindings[$variableName] ?? '{' . $variableName . '}';
                $cursor = $advance;

                continue;
            }

            $result .= $char;
            $cursor++;
        }

        return $result;
    }

    /**
     * Reads a `{name}` or `{name:**}` capture starting at {@code $cursor}
     * (which must point at the opening `{`). Returns the parsed name, the
     * multi-segment flag, and the cursor position after the closing `}`.
     *
     * @return array{0: string, 1: bool, 2: int}
     */
    private static function consumeCapture(string $template, int $openAt): array
    {
        $length = \strlen($template);
        $closeAt = -1;
        for ($i = $openAt + 1; $i < $length; $i++) {
            $char = $template[$i];
            if ($char === '{') {
                throw new InvalidArgumentException(\sprintf(
                    "CapturePattern: nested '{' at offset %d in pattern \"%s\" — captures cannot contain other captures.",
                    $i,
                    $template,
                ));
            }
            if ($char === '}') {
                $closeAt = $i;
                break;
            }
        }

        if ($closeAt === -1) {
            throw new InvalidArgumentException(\sprintf(
                "CapturePattern: unbalanced '{' at offset %d in pattern \"%s\".",
                $openAt,
                $template,
            ));
        }

        $body = substr($template, $openAt + 1, $closeAt - $openAt - 1);
        if ($body === '') {
            throw new InvalidArgumentException(\sprintf(
                "CapturePattern: empty capture '{}' at offset %d in pattern \"%s\".",
                $openAt,
                $template,
            ));
        }

        $multiSegment = false;
        $name = $body;
        if (str_contains($body, ':')) {
            [$name, $quantifier] = explode(':', $body, 2);
            if ($quantifier !== '**') {
                throw new InvalidArgumentException(\sprintf(
                    "CapturePattern: unknown capture quantifier ':%s' in pattern \"%s\" (only ':**' is supported).",
                    $quantifier,
                    $template,
                ));
            }
            $multiSegment = true;
        }

        if (preg_match('/^' . self::VARIABLE_NAME_REGEX . '$/', $name) !== 1) {
            throw new InvalidArgumentException(\sprintf(
                "CapturePattern: invalid capture name '%s' in pattern \"%s\" (must match %s).",
                $name,
                $template,
                self::VARIABLE_NAME_REGEX,
            ));
        }

        return [$name, $multiSegment, $closeAt + 1];
    }
}
