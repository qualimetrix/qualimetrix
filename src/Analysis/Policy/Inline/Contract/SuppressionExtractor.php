<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract;

use LogicException;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionTarget;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Core\Symbol\MetricSubject;

/**
 * Extracts suppression tags from docblock comments and regular PHP comments.
 *
 * Supported comment styles:
 *
 * - PHPDoc docblocks: /** `@qmx-ignore ...` * /
 * - Line comments: // `@qmx-ignore ...`
 * - Block comments: /* `@qmx-ignore ...` * /
 *
 * Supported tags:
 * - `@qmx-ignore <channel> [-- reason]`
 * - `@qmx-ignore-next-line <channel> [-- reason]`
 * - `@qmx-ignore-file [channel] [-- reason]`
 *
 * The argument names a **channel**: an exact `code`, the explicit
 * or `X.*` for the strict descendants of `X`.
 * The two "everything here" spellings survive unchanged: `*` on the symbol
 * and next-line forms, and an omitted argument on the file form. Both mean
 * "no rule filter", not "a wildcard selector"; see {@see SuppressionTarget}.
 *
 * A reason may be introduced by {@see Suppression::REASON_SEPARATOR}. On the file form,
 * where the channel is optional, that is the only way to write a reason
 * without the first word of it being read as the channel.
 *
 * Note: inline same-line comments (e.g., `$x = foo(); // @qmx-ignore rule`) are not supported.
 * Only separate-line comments are recognized.
 */
final readonly class SuppressionExtractor
{
    private const PATTERN_SYMBOL = '/@qmx-ignore(?!-next-line|-file)(?![\w-])\s+([\w.*#-]+)(?:[^\S\n\r]+([^\n\r]+))?/';
    private const PATTERN_NEXT_LINE = '/@qmx-ignore-next-line(?![\w-])\s+([\w.*#-]+)(?:[^\S\n\r]+([^\n\r]+))?/';
    private const PATTERN_FILE = '/@qmx-ignore-file(?![\w-])(?:\s+([\w.*#-]+)(?:[^\S\n\r]+([^\n\r]+))?)?/';

    private const MODE_FULL = 'full';
    private const MODE_PHYSICAL = 'physical';
    private const MODE_FILE_ONLY = 'file-only';

    /**
     * Extracts suppression tags from node's docblock and regular comments.
     *
     * @return list<Suppression>
     */
    public function extract(Node $node, MetricSubject $subject, ControlScope $controlScope): array
    {
        return $this->extractNode($node, $subject, $controlScope, self::MODE_FULL);
    }

    /**
     * Extracts only physical file and next-line controls from a node.
     *
     * Symbol controls intentionally require {@see extract()} so a caller cannot
     * silently discard an `@qmx-ignore` declaration control without its binding.
     *
     * @return list<Suppression>
     */
    public function extractPhysical(Node $node): array
    {
        return $this->extractNode($node, null, null, self::MODE_PHYSICAL);
    }

    /**
     * Extracts file-level suppressions from node's docblock and regular comments.
     *
     * @return list<Suppression>
     */
    public function extractFileLevelSuppressions(Node $node): array
    {
        return $this->extractNode($node, null, null, self::MODE_FILE_ONLY);
    }

    /**
     * Extracts suppressions from a comment text block.
     *
     * @param 'full'|'physical'|'file-only' $mode
     *
     * @return list<Suppression>
     */
    private function extractNode(
        Node $node,
        ?MetricSubject $subject,
        ?ControlScope $controlScope,
        string $mode,
    ): array {
        $suppressions = [];
        $nodeEndLine = $node->getEndLine() > 0 ? $node->getEndLine() : null;
        $comments = [];
        $docComment = $node->getDocComment();
        if ($docComment !== null) {
            $comments[] = $docComment;
        }

        foreach ($node->getComments() as $comment) {
            if (!$comment instanceof Doc) {
                $comments[] = $comment;
            }
        }

        foreach ($comments as $comment) {
            foreach ($this->matchText($comment->getText()) as $match) {
                $suppression = $this->projectMatch(
                    $match,
                    $comment->getStartLine(),
                    $comment->getEndLine(),
                    $nodeEndLine,
                    $subject,
                    $controlScope,
                    $mode,
                );

                if ($suppression !== null) {
                    $suppressions[] = $suppression;
                }
            }
        }

        return $suppressions;
    }

    /** @return list<array{type: SuppressionType, rule: non-empty-string, reason: ?string}> */
    private function matchText(string $text): array
    {
        $text = self::stripBacktickRegions($text);
        $matches = [];

        foreach ([
            [SuppressionType::File, self::PATTERN_FILE],
            [SuppressionType::NextLine, self::PATTERN_NEXT_LINE],
            [SuppressionType::Symbol, self::PATTERN_SYMBOL],
        ] as [$type, $pattern]) {
            if (preg_match_all($pattern, $text, $patternMatches, \PREG_SET_ORDER) <= 0) {
                continue;
            }

            foreach ($patternMatches as $match) {
                $authored = self::authoredArgument($type, $match[1] ?? '', $match[2] ?? null);

                if ($authored !== null) {
                    $matches[] = $authored;
                }
            }
        }

        return $matches;
    }

    /**
     * One directive's two authored halves, normalised.
     *
     * The file form is the only one whose channel is optional, and both ways
     * of leaving it out — no argument at all, and the separator standing in
     * the channel position — desugar to the same "no rule filter" spelling
     * the symbol and next-line forms use. All three then converge on one
     * {@see SuppressionTarget} case rather than on a wildcard selector; see
     * that type for why the distinction matters.
     *
     * The other two forms keep whatever was written, the separator included,
     * so a directive that named no channel is reported for what it is rather
     * than silently widened.
     *
     * @return ?array{type: SuppressionType, rule: non-empty-string, reason: ?string} `null` when
     *                                                                                nothing was authored
     */
    private static function authoredArgument(SuppressionType $type, string $rule, ?string $reason): ?array
    {
        $channelIsOptional = $type === SuppressionType::File;

        if ($channelIsOptional && ($rule === '' || $rule === Suppression::REASON_SEPARATOR)) {
            $rule = SuppressionTarget::NO_RULE_FILTER;
        } elseif ($reason !== null) {
            $reason = self::stripReasonSeparator($reason);
        }

        if ($rule === '') {
            return null;
        }

        return [
            'type' => $type,
            'rule' => $rule,
            'reason' => self::extractReason($reason),
        ];
    }

    /**
     * @param array{type: SuppressionType, rule: non-empty-string, reason: ?string} $match
     * @param 'full'|'physical'|'file-only' $mode
     */
    private function projectMatch(
        array $match,
        int $startLine,
        int $endLine,
        ?int $nodeEndLine,
        ?MetricSubject $subject,
        ?ControlScope $controlScope,
        string $mode,
    ): ?Suppression {
        if ($mode === self::MODE_FILE_ONLY && $match['type'] !== SuppressionType::File) {
            return null;
        }

        if ($mode === self::MODE_PHYSICAL && $match['type'] === SuppressionType::Symbol) {
            throw new LogicException('Symbol suppression requires an explicit declaration binding');
        }

        if ($match['type'] === SuppressionType::Symbol) {
            if ($subject === null || $controlScope === null) {
                throw new LogicException('Symbol suppression requires an explicit declaration binding');
            }

            return new Suppression(
                rule: $match['rule'],
                reason: $match['reason'],
                line: $startLine,
                type: SuppressionType::Symbol,
                endLine: $nodeEndLine,
                subject: $subject,
                controlScope: $controlScope,
            );
        }

        return new Suppression(
            rule: $match['rule'],
            reason: $match['reason'],
            line: $match['type'] === SuppressionType::File ? $startLine : $endLine,
            type: $match['type'],
        );
    }

    /**
     * Strips backtick-delimited regions from text to avoid matching documentation references.
     *
     * Docblocks that mention `@qmx-ignore` as examples (e.g., format descriptions)
     * should not be interpreted as real suppression tags.
     */
    private static function stripBacktickRegions(string $text): string
    {
        return preg_replace('/`[^`]*`/', '', $text) ?? $text;
    }

    /**
     * Drops a leading {@see Suppression::REASON_SEPARATOR} so the separator does not end up
     * inside the prose it introduces.
     */
    private static function stripReasonSeparator(string $reason): string
    {
        if (!str_starts_with($reason, Suppression::REASON_SEPARATOR)) {
            return $reason;
        }

        return ltrim(substr($reason, \strlen(Suppression::REASON_SEPARATOR)));
    }

    private static function extractReason(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        // Strip trailing docblock closing characters (e.g., "*/") and whitespace
        $trimmed = rtrim($raw, " \t*/");

        return $trimmed !== '' ? $trimmed : null;
    }
}
