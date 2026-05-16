<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline\Suppression;

use PhpParser\Node;
use Qualimetrix\Core\Rule\Override\OverrideValidatorInterface;
use Qualimetrix\Core\Suppression\ThresholdDiagnostic;
use Qualimetrix\Core\Suppression\ThresholdOverride;

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
 * - Rule-specific override violations enforced via {@see OverrideValidatorInterface}
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
     * Pattern matches: `@qmx-threshold <rule-pattern> <rest-of-line>`
     * Capture group 1: rule pattern (alphanumeric, dots, asterisks, hyphens)
     * Capture group 2: threshold values (rest of line)
     */
    private const PATTERN = '/@qmx-threshold\s+([\w.*-]+)\s+([^\n\r]+)/';

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
    public function extract(Node $node): array
    {
        return $this->extractWithDiagnostics($node)->overrides;
    }

    /**
     * Extracts threshold override annotations with validation diagnostics.
     *
     * Returns both valid overrides and diagnostics for invalid annotations.
     */
    public function extractWithDiagnostics(Node $node): ThresholdOverrideExtractionResult
    {
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

        if (preg_match_all(self::PATTERN, $text, $matches, \PREG_SET_ORDER) !== 0) {
            foreach ($matches as $match) {
                $rulePattern = $match[1];
                $valueString = self::cleanTrailingDocblock($match[2]);
                $line = $docComment->getStartLine();

                $parsed = self::parseValues($valueString);
                if ($parsed === null) {
                    $diagnostics[] = new ThresholdDiagnostic(
                        line: $line,
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
                            message: \sprintf('@qmx-threshold %s: %s', $rulePattern, $failure->message),
                            code: $failure->code,
                        );

                        continue;
                    }
                }

                // Validate: duplicate rule pattern on the same symbol
                if (isset($seenRules[$rulePattern])) {
                    $diagnostics[] = new ThresholdDiagnostic(
                        line: $line,
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
                    endLine: $node->getEndLine() > 0 ? $node->getEndLine() : null,
                );
            }
        }

        return new ThresholdOverrideExtractionResult($overrides, $diagnostics);
    }

    /**
     * Parses the value portion of a `@qmx-threshold` annotation.
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
        $valueString = trim($valueString);

        if ($valueString === '') {
            return null;
        }

        // Try shorthand: just a number (sets both warning and error)
        if (preg_match('/^(\d+(?:\.\d+)?)$/', $valueString, $match) === 1) {
            $value = self::parseNumber($match[1]);

            return [$value, $value, false];
        }

        // Try explicit: warning=W and/or error=E
        $warning = null;
        $error = null;
        $errorWasExplicit = false;

        if (preg_match('/warning=(\d+(?:\.\d+)?)/', $valueString, $match) === 1) {
            $warning = self::parseNumber($match[1]);
        }

        if (preg_match('/error=(\d+(?:\.\d+)?)/', $valueString, $match) === 1) {
            $error = self::parseNumber($match[1]);
            $errorWasExplicit = true;
        }

        // If neither was found, syntax is invalid
        if ($warning === null && $error === null) {
            return null;
        }

        return [$warning, $error, $errorWasExplicit];
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
     * Strips trailing docblock closing characters and whitespace.
     */
    private static function cleanTrailingDocblock(string $raw): string
    {
        return rtrim($raw, " \t*/");
    }

    /**
     * Strips backtick-delimited regions from text to avoid matching documentation references.
     */
    private static function stripBacktickRegions(string $text): string
    {
        return preg_replace('/`[^`]*`/', '', $text) ?? $text;
    }
}
