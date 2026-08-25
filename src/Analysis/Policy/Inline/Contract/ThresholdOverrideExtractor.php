<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract;

use PhpParser\Node;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\OverrideValidatorInterface;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Inline\Contract\Threshold\ThresholdDiagnostic;
use Qualimetrix\Analysis\Policy\Inline\ThresholdOverrideExtractionResult;
use Qualimetrix\Core\Symbol\MetricSubject;

/**
 * Extracts `@qmx-threshold` annotations from docblock comments.
 *
 * Supported syntaxes:
 * - Shorthand: `@qmx-threshold complexity.cyclomatic 15`
 * - Explicit: `@qmx-threshold complexity.cyclomatic warning=15 error=25`
 * - Partial: `@qmx-threshold complexity.cyclomatic warning=15`
 * - Float: `@qmx-threshold coupling.instability 0.8`
 *
 * Invalid annotations produce diagnostics instead of being silently ignored:
 * - Unparseable value syntax
 * - Rule-specific override findings enforced via {@see OverrideValidatorInterface}
 *   (e.g. warning > error for standard rules, warning < error for inverted rules,
 *   explicit error= for warning-only rules)
 * - Duplicate rule annotations on the same symbol
 *
 * The `$validators` map is keyed by rule name. Annotations targeting
 * unknown rule names (or wildcard / prefix patterns) skip per-rule
 * validation; the existing `annotation.unsupported-threshold` diagnostic
 * surfaces these post-analysis.
 */
final readonly class ThresholdOverrideExtractor
{
    /**
     * Pattern matches: `@qmx-threshold <rule-pattern> [<rest-of-line>]`
     * Capture group 1: rule pattern (alphanumeric, dots, asterisks, hyphens,
     *                  and the retired channel-pair separator)
     * Capture group 2: threshold values (rest of line)
     *
     * `#` is admitted so that the retired `rule#code` spelling is *captured*
     * and then refused by name
     * ({@see \Qualimetrix\Analysis\Policy\Inline\Directive\DirectiveAddressability::problemWithThreshold()}).
     * Without it the pattern stops at the separator and silently retunes the
     * left half, which is the one outcome worse than either a match or a
     * refusal.
     */
    private const PATTERN = '/@qmx-threshold\s+([\w.*#-]+)(?:[ \t]+([^\n\r]*))?/';

    /**
     * @param array<string, OverrideValidatorInterface> $validators rule name => validator strategy
     */
    public function __construct(
        private array $validators = [],
    ) {}

    /**
     * Extracts threshold override annotations from node's docblock.
     *
     * @return list<ThresholdOverride>
     */
    public function extract(Node $node, MetricSubject $subject, ControlScope $controlScope): array
    {
        return $this->extractWithDiagnostics($node, $subject, $controlScope)->overrides;
    }

    /**
     * Extracts threshold override annotations with validation diagnostics.
     *
     * Returns both valid overrides and diagnostics for invalid annotations.
     */
    public function extractWithDiagnostics(
        Node $node,
        MetricSubject $subject,
        ControlScope $controlScope,
    ): ThresholdOverrideExtractionResult {
        $docComment = $node->getDocComment();
        if ($docComment === null) {
            return new ThresholdOverrideExtractionResult([], []);
        }

        $text = self::stripBacktickRegions($docComment->getText());
        if (!str_contains($text, '@qmx-threshold')) {
            return new ThresholdOverrideExtractionResult([], []);
        }

        $overrides = [];
        $diagnostics = [];
        /** @var array<string, true> $seenRules track rule patterns to detect duplicates */
        $seenRules = [];

        $flags = \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL;
        if (preg_match_all(self::PATTERN, $text, $matches, $flags) !== 0) {
            foreach ($matches as $match) {
                $rulePattern = $match[1][0];
                if (!\is_string($rulePattern)) {
                    continue;
                }

                $valueString = self::cleanTrailingDocblock($match[2][0] ?? '');
                $line = self::lineAtOffset($text, $docComment->getStartLine(), $match[0][1]);

                $parsed = self::parseValues($valueString);
                if ($parsed === null) {
                    $diagnostics[] = new ThresholdDiagnostic(
                        line: $line,
                        subject: $subject,
                        message: \sprintf(
                            '@qmx-threshold %s: invalid syntax "%s" — expected a number or warning=N error=N',
                            $rulePattern,
                            $valueString,
                        ),
                    );

                    continue;
                }

                [$warning, $error, $errorWasExplicit] = $parsed;

                // Delegate validation to per-rule strategy.
                // Unknown rule names (or wildcard / prefix patterns) skip validation —
                // the post-analysis `annotation.unsupported-threshold` diagnostic
                // surfaces those instead.
                $validator = $this->validators[$rulePattern] ?? null;
                if ($validator !== null) {
                    $failure = $validator->validate($warning, $error, $errorWasExplicit);
                    if ($failure !== null) {
                        $diagnostics[] = new ThresholdDiagnostic(
                            line: $line,
                            subject: $subject,
                            message: \sprintf('@qmx-threshold %s: %s', $rulePattern, $failure->message),
                            code: $failure->code,
                            hint: $failure->hint,
                        );

                        continue;
                    }
                }

                // Validate: duplicate rule pattern on the same symbol
                if (isset($seenRules[$rulePattern])) {
                    $diagnostics[] = new ThresholdDiagnostic(
                        line: $line,
                        subject: $subject,
                        message: \sprintf(
                            '@qmx-threshold %s: duplicate annotation — rule "%s" already has a threshold override on this symbol',
                            $rulePattern,
                            $rulePattern,
                        ),
                    );

                    continue;
                }

                $seenRules[$rulePattern] = true;

                $overrides[] = new ThresholdOverride(
                    rulePattern: $rulePattern,
                    warning: $warning,
                    error: $error,
                    line: $line,
                    subject: $subject,
                    controlScope: $controlScope,
                    endLine: $node->getEndLine() > 0 ? $node->getEndLine() : null,
                );
            }
        }

        return new ThresholdOverrideExtractionResult($overrides, $diagnostics);
    }

    /**
     * Parses the value portion of a `@qmx-threshold` annotation.
     *
     * A human-readable reason is accepted only after `--` or `—`. The
     * value portion itself must be entirely one shorthand number or one or
     * two distinct explicit `warning=N` / `error=N` tokens.
     *
     * Returns [warning, error, errorWasExplicit] or null if unparseable.
     * `errorWasExplicit` distinguishes the shorthand form
     * (`@qmx-threshold X N`, parsed as W=N, E=N, errorWasExplicit=false)
     * from the explicit form (`@qmx-threshold X warning=N error=M`,
     * errorWasExplicit=true) so warning-only rules can keep the shorthand
     * working while rejecting deliberate error= values.
     *
     * @return array{int|float|null, int|float|null, bool}|null
     */
    private static function parseValues(string $valueString): ?array
    {
        $values = self::extractValuesBeforeReason($valueString);
        if ($values === null) {
            return null;
        }

        return self::parseShorthand($values) ?? self::parseExplicitValues($values);
    }

    /**
     * Returns the value portion after validating an optional reason separator.
     */
    private static function extractValuesBeforeReason(string $valueString): ?string
    {
        $valueString = trim($valueString);

        if ($valueString === '') {
            return null;
        }

        $parts = preg_split('/\s+(?:--|—)\s*/u', $valueString, 2);
        if ($parts === false) {
            return null;
        }

        $values = trim($parts[0]);
        if ($values === '' || (isset($parts[1]) && trim($parts[1]) === '')) {
            return null;
        }

        return $values;
    }

    /**
     * Parses a shorthand number, which applies to both thresholds.
     *
     * @return array{int|float, int|float, false}|null
     */
    private static function parseShorthand(string $values): ?array
    {
        if (preg_match('/^(\d+(?:\.\d+)?)$/', $values, $match) === 1) {
            $value = self::parseNumber($match[1]);

            return [$value, $value, false];
        }

        return null;
    }

    /**
     * Parses one or two distinct explicit warning=N / error=N tokens.
     *
     * @return array{int|float|null, int|float|null, bool}|null
     */
    private static function parseExplicitValues(string $values): ?array
    {
        $tokens = preg_split('/\s+/', $values);
        if ($tokens === false || \count($tokens) < 1 || \count($tokens) > 2) {
            return null;
        }

        /** @var array<'warning'|'error', int|float> $thresholds */
        $thresholds = [];
        foreach ($tokens as $token) {
            $parsedToken = self::parseExplicitToken($token);
            if ($parsedToken === null) {
                return null;
            }

            [$name, $value] = $parsedToken;
            if (isset($thresholds[$name])) {
                return null;
            }

            $thresholds[$name] = $value;
        }

        return [
            $thresholds['warning'] ?? null,
            $thresholds['error'] ?? null,
            isset($thresholds['error']),
        ];
    }

    /**
     * @return array{'warning'|'error', int|float}|null
     */
    private static function parseExplicitToken(string $token): ?array
    {
        if (preg_match('/^(warning|error)=(\d+(?:\.\d+)?)$/', $token, $match) !== 1) {
            return null;
        }

        return [$match[1], self::parseNumber($match[2])];
    }

    /**
     * Parses a numeric string into int or float.
     */
    private static function parseNumber(string $value): int|float
    {
        if (str_contains($value, '.')) {
            return (float) $value;
        }

        return (int) $value;
    }

    /**
     * Strips a terminal docblock marker and its surrounding whitespace.
     */
    private static function cleanTrailingDocblock(string $raw): string
    {
        return preg_replace('/\s*\*\/\s*$/', '', $raw) ?? $raw;
    }

    /**
     * Strips backtick-delimited regions from text to avoid matching documentation references.
     */
    private static function stripBacktickRegions(string $text): string
    {
        return preg_replace_callback(
            '/`[^`]*`/',
            static fn(array $match): string => preg_replace('/[^\r\n]/', ' ', $match[0]) ?? $match[0],
            $text,
        ) ?? $text;
    }

    private static function lineAtOffset(string $text, int $startLine, int $offset): int
    {
        return $startLine + substr_count(substr($text, 0, $offset), "\n");
    }
}
