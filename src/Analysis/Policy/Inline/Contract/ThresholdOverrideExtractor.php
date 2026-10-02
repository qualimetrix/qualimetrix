<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract;

use LogicException;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\OverrideValidatorInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\ThresholdOverrideRequest;
use Qualimetrix\Analysis\Finding\Contract\RuleOptionForms;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Inline\Contract\Threshold\ThresholdDiagnostic;
use Qualimetrix\Analysis\Policy\Inline\Threshold\DeclaredOverrideForms;
use Qualimetrix\Analysis\Policy\Inline\Threshold\ThresholdOverrideValueParser;
use Qualimetrix\Analysis\Policy\Inline\ThresholdOverrideExtractionResult;
use Qualimetrix\Core\Symbol\MetricSubject;

/**
 * Extracts `@qmx-threshold` annotations from docblock comments.
 *
 * Supported syntaxes:
 * - Shorthand: `@qmx-threshold complexity.ccn 15`
 * - Explicit: `@qmx-threshold complexity.ccn warning=15 error=25`
 * - Partial: `@qmx-threshold complexity.ccn warning=15`
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
     *                  the retired channel-pair separator, and the level
     *                  separator)
     * Capture group 2: threshold values (rest of line)
     *
     * The rule stands on the tag's own line and may not begin with the
     * comment's closing delimiter, for the reason the suppression grammars
     * give: with a separator that crossed a line break, a tag written with
     * nothing after it read the next line's leading asterisk — or the
     * delimiter — as the rule `*`, and was reported as a directive its author
     * never wrote. Such a tag is not read here; the suppression sweep refuses
     * it as one that names no rule.
     *
     * `#` and `:` are admitted so that the retired `rule#code` spelling and a
     * `channel:level` pair — which a threshold never addresses, ADR 0024 §2 —
     * are *captured* and then refused by name
     * ({@see \Qualimetrix\Analysis\Policy\Inline\Directive\DirectiveAddressability::problemWithThreshold()}).
     * Without it the pattern stops at the separator and silently retunes the
     * left half, which is the one outcome worse than either a match or a
     * refusal.
     */
    private const PATTERN = '/@qmx-threshold[^\S\n\r]+(?!\*+\/)([\w.*#:-]+)(?:[ \t]+([^\n\r]*))?/';

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
        $read = ['overrides' => [], 'diagnostics' => [], 'overrideTags' => [], 'diagnosticTags' => []];
        /** @var array<string, true> $seenRules track rule patterns to detect duplicates */
        $seenRules = [];

        foreach (self::docblocksOf($node) as $docComment) {
            $this->readDocblock($docComment, $node, $subject, $controlScope, $read, $seenRules);
        }

        return new ThresholdOverrideExtractionResult(
            $read['overrides'],
            $read['diagnostics'],
            $read['overrideTags'],
            $read['diagnosticTags'],
        );
    }

    /**
     * Every docblock attached to the declaration, not the last one.
     *
     * `Node::getDocComment()` answers with the last, so a directive written in
     * the first of two adjacent docblocks — an annotation added beside a
     * generated block, a description left behind by a rewrite — was read by
     * nothing at all. A threshold is still a docblock form: line and block
     * comments are not searched here, and the tags written in them are
     * refused by the suppression sweep instead, since this reader never
     * reports them as carried.
     *
     * @return list<Doc>
     */
    private static function docblocksOf(Node $node): array
    {
        $docblocks = [];

        foreach ($node->getComments() as $comment) {
            if ($comment instanceof Doc) {
                $docblocks[] = $comment;
            }
        }

        return $docblocks;
    }

    /**
     * Duplicate detection spans the declaration rather than one docblock:
     * `$seenRules` is threaded through every block, because two annotations of
     * one rule are the same mistake whether or not the author split them.
     *
     * @param array{
     *     overrides: list<ThresholdOverride>,
     *     diagnostics: list<ThresholdDiagnostic>,
     *     overrideTags: list<array{Doc, int}>,
     *     diagnosticTags: list<array{Doc, int}>,
     * } $read
     * @param array<string, true> $seenRules
     */
    private function readDocblock(
        Doc $docComment,
        Node $node,
        MetricSubject $subject,
        ControlScope $controlScope,
        array &$read,
        array &$seenRules,
    ): void {
        $text = DocumentationRegions::mask($docComment->getText());
        if (!str_contains($text, '@qmx-threshold')) {
            return;
        }

        $flags = \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL;
        if (preg_match_all(self::PATTERN, $text, $matches, $flags) === 0) {
            return;
        }

        foreach ($matches as $candidate) {
            $match = self::authoredMatch($docComment, $candidate[0][1]);
            if ($match === null) {
                continue;
            }
            $rulePattern = $match['rule'];

            $valueString = self::cleanTrailingDocblock($match['values'] ?? '');
            $line = self::lineAtOffset($text, $docComment->getStartLine(), $match['offset']);
            $position = self::positionAtOffset($docComment, $match['offset']);
            $parsed = new ThresholdOverrideValueParser()->parse($valueString);

            $problem = $this->problemWith($rulePattern, $valueString, $parsed, $line, $position, $subject, $seenRules);
            if ($problem !== null) {
                $read['diagnostics'][] = $problem;
                $read['diagnosticTags'][] = [$docComment, $match['offset']];

                continue;
            }

            $seenRules[$rulePattern] = true;
            $request = $parsed ?? throw new LogicException('An admitted threshold annotation requires parsed values.');

            $read['overrideTags'][] = [$docComment, $match['offset']];
            $read['overrides'][] = new ThresholdOverride(
                rulePattern: $rulePattern,
                warning: $request->warning,
                error: $request->error,
                line: $line,
                subject: $subject,
                controlScope: $controlScope,
                endLine: $node->getEndLine() > 0 ? $node->getEndLine() : null,
            );
        }
    }

    /** @return array{offset: int, rule: string, values: ?string}|null */
    private static function authoredMatch(Doc $docComment, int $offset): ?array
    {
        if (preg_match(self::PATTERN, $docComment->getText(), $match, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL, $offset) !== 1) {
            return null;
        }
        if ($match[0][1] !== $offset) {
            return null;
        }
        $rulePattern = $match[1][0];
        if (!\is_string($rulePattern)) {
            return null;
        }

        return ['offset' => $offset, 'rule' => $rulePattern, 'values' => $match[2][0]];
    }

    /**
     * Why this annotation cannot be applied, or `null` when it can.
     *
     * The three refusals are one method because they are one decision with one
     * outcome: values that do not parse, values a rule's own validator
     * rejects, and a rule already retuned on this declaration.
     *
     * @param array<string, true> $seenRules
     */
    private function problemWith(
        string $rulePattern,
        string $valueString,
        ?ThresholdOverrideRequest $parsed,
        int $line,
        int $position,
        MetricSubject $subject,
        array $seenRules,
    ): ?ThresholdDiagnostic {
        if ($parsed === null) {
            return new ThresholdDiagnostic(
                line: $line,
                subject: $subject,
                rulePattern: $rulePattern,
                message: \sprintf(
                    '@qmx-threshold %s: invalid syntax "%s" — expected a number or warning=N error=N',
                    $rulePattern,
                    $valueString,
                ),
                position: $position,
            );
        }

        // Unknown rule names (or wildcard / prefix patterns) skip validation —
        // the post-analysis `annotation.unsupported-threshold` diagnostic
        // surfaces those instead.
        $validator = $this->validators[$rulePattern] ?? null;
        $failure = $validator?->validate($parsed);
        if ($failure !== null) {
            return new ThresholdDiagnostic(
                line: $line,
                subject: $subject,
                rulePattern: $rulePattern,
                message: \sprintf('@qmx-threshold %s: %s', $rulePattern, $failure->message),
                position: $position,
                code: $failure->code,
                hint: $failure->hint,
            );
        }

        if ($validator instanceof RuleOptionForms) {
            $formProblem = new DeclaredOverrideForms($validator, $rulePattern, $line, $position, $subject)->problem($parsed);
            if ($formProblem !== null) {
                return $formProblem;
            }
        }

        if (!isset($seenRules[$rulePattern])) {
            return null;
        }

        return new ThresholdDiagnostic(
            line: $line,
            subject: $subject,
            rulePattern: $rulePattern,
            message: \sprintf(
                '@qmx-threshold %s: duplicate annotation — rule "%s" already has a threshold override on this symbol',
                $rulePattern,
                $rulePattern,
            ),
            position: $position,
        );
    }

    /**
     * Strips a terminal docblock marker and its surrounding whitespace.
     */
    private static function cleanTrailingDocblock(string $raw): string
    {
        return preg_replace('/\s*\*\/\s*$/', '', $raw) ?? $raw;
    }

    private static function lineAtOffset(string $text, int $startLine, int $offset): int
    {
        return $startLine + substr_count(substr($text, 0, $offset), "\n");
    }

    private static function positionAtOffset(Doc $comment, int $offset): int
    {
        $start = $comment->getStartFilePos();
        if ($start < 0) {
            throw new LogicException('A comment without a file position cannot name a directive site');
        }

        return $start + $offset;
    }
}
