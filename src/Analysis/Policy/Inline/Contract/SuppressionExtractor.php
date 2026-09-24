<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract;

use Closure;
use LogicException;
use PhpParser\Comment;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DeclarationBinding;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveRefusal;
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
 * The argument names a **channel**: an exact `code`, or `X.*` for the strict
 * descendants of `X`, either optionally narrowed to one level of the
 * aggregation tree with `:level` (`coupling.cbo:namespace`).
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
    /**
     * The three grammars admit `:` and `#` so that both spellings a channel
     * can be *mis*addressed by are **captured** and then refused by name —
     * `#` for the retired pair, `:` for a level that the channel does not
     * report at. Without them the pattern stops at the separator and silences
     * the whole channel instead of one level of it, which is a suppression
     * quietly wider than the one that was written.
     *
     * The argument stands on the tag's own line — horizontal space
     * separates them, never a newline — and it may not *begin* with the
     * comment's closing delimiter. Both guards exist because `*` is a
     * legitimate argument, the one spelling of "no rule filter", and a
     * comment writes `*` in two places its author did not: the closing
     * delimiter, and the leading asterisk of every docblock line. Reading
     * either as the argument turns a directive that named no channel into the
     * widest suppression there is, and nothing downstream can tell: the tag
     * parsed, so nothing refuses it, and it silenced something, so it is not
     * unused either. An argument that merely *ends* against the closing
     * delimiter is still an argument — a block comment holding
     * `@qmx-ignore complexity.*` with no space before the delimiter means the
     * selector it looks like.
     */
    private const PATTERN_SYMBOL = '/@qmx-ignore(?!-next-line|-file)(?![\w-])[^\S\n\r]+(?!\*+\/)([\w.*#:-]+)(?:[^\S\n\r]+([^\n\r]+))?/';
    private const PATTERN_NEXT_LINE = '/@qmx-ignore-next-line(?![\w-])[^\S\n\r]+(?!\*+\/)([\w.*#:-]+)(?:[^\S\n\r]+([^\n\r]+))?/';
    private const PATTERN_FILE = '/@qmx-ignore-file(?![\w-])(?:[^\S\n\r]+(?!\*+\/)([\w.*#:-]+)(?:[^\S\n\r]+([^\n\r]+))?)?/';

    /**
     * Every `@qmx-` tag an author can write, whether or not this class reads
     * it. What the three grammars above did not consume is a form nobody
     * reads, and it is refused by name instead of being left where it fell:
     * the tags below extraction judge the directives they are handed, so a
     * misspelling that never becomes one is invisible to all of them.
     *
     * The argument is captured under the same two guards the grammars carry,
     * so a tag with nothing on its own line after it is told apart from a tag
     * whose name is wrong.
     *
     * A letter is required after the prefix so that prose about the family
     * ("the @qmx- tags") is not read as a tag of its own.
     */
    private const PATTERN_ANY_TAG = '/@qmx-([a-zA-Z][\w-]*)(?:[^\S\n\r]+(?!\*+\/)([\w.*#:-]+))?/';

    /** The one family this class does not read; {@see ThresholdOverrideExtractor} does. */
    private const string THRESHOLD_TAG_NAME = 'threshold';

    /**
     * Public so that the one place deciding which nodes to read can ask for
     * the family by name instead of spelling the prefix a second time.
     */
    public const string TAG_PREFIX = '@qmx-';

    private const MODE_FULL = 'full';
    private const MODE_PHYSICAL = 'physical';
    private const MODE_FILE_ONLY = 'file-only';

    /**
     * Extracts suppression tags from node's docblock and regular comments.
     *
     * `$thresholdRead` answers whether {@see ThresholdOverrideExtractor} carried
     * the `@qmx-threshold` tag at an offset of a comment — as an override or
     * as a diagnostic. That family is read there, not here, and only a tag the
     * other reader answered for is left to it: every other one — in a line or
     * block comment, over a node no threshold binds to, or with no rule on its
     * line — is refused here, because nothing else would ever say so.
     *
     * @param Closure(Comment, int): bool $thresholdRead
     *
     * @return list<Suppression>
     */
    public function extract(Node $node, MetricSubject $subject, ControlScope $controlScope, Closure $thresholdRead): array
    {
        return $this->extractNode($node, $subject, $controlScope, self::MODE_FULL, $thresholdRead);
    }

    /**
     * Extracts the physical file and next-line controls of a node that binds
     * to no measured declaration, plus the directives written there that
     * cannot be carried out.
     *
     * A declaration control here has nothing to bind to, and it comes back as
     * a refusal rather than as a control or as an exception: the tag is real,
     * the placement is the mistake, and the author is the only one who can fix
     * it. Throwing instead cost the whole file — the processing failure took
     * every metric and every finding in it down with the annotation.
     *
     * @param Closure(Comment, int): bool $thresholdRead see {@see self::extract()}
     *
     * @return list<Suppression>
     */
    public function extractPhysical(Node $node, Closure $thresholdRead): array
    {
        return $this->extractNode($node, null, null, self::MODE_PHYSICAL, $thresholdRead);
    }

    /**
     * Extracts file-level suppressions from node's docblock and regular comments.
     *
     * @return list<Suppression>
     */
    public function extractFileLevelSuppressions(Node $node): array
    {
        return $this->extractNode($node, null, null, self::MODE_FILE_ONLY, static fn(): bool => true);
    }

    /**
     * Extracts suppressions from a comment text block.
     *
     * @param 'full'|'physical'|'file-only' $mode
     * @param Closure(Comment, int): bool $thresholdRead
     *
     * @return list<Suppression>
     */
    private function extractNode(
        Node $node,
        ?MetricSubject $subject,
        ?ControlScope $controlScope,
        string $mode,
        Closure $thresholdRead,
    ): array {
        $suppressions = [];
        $nodeEndLine = $node->getEndLine() > 0 ? $node->getEndLine() : null;

        foreach (self::commentsOf($node) as $comment) {
            $text = DocumentationRegions::mask($comment->getText());
            $read = [];

            foreach ($this->matchText($text) as $match) {
                $read[] = $match['offset'];
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

            if ($mode !== self::MODE_FILE_ONLY) {
                array_push($suppressions, ...self::unreadableForms($comment, $text, $read, $thresholdRead));
            }
        }

        return $suppressions;
    }

    /**
     * Every comment attached to the node, docblocks first.
     *
     * `Node::getDocComment()` returns the **last** docblock attached, so
     * reading it and then the non-`Doc` comments loses the first of two
     * adjacent docblocks entirely — it is neither the one returned nor one of
     * the others. The set an author sees above a declaration is the set that
     * is read. Docblocks keep their former position in the order so that a
     * declaration carrying one docblock and one line comment still reports its
     * controls in the order it always did.
     *
     * @return list<Comment>
     */
    private static function commentsOf(Node $node): array
    {
        $docs = [];
        $others = [];

        foreach ($node->getComments() as $comment) {
            if ($comment instanceof Doc) {
                $docs[] = $comment;
            } else {
                $others[] = $comment;
            }
        }

        return [...$docs, ...$others];
    }

    /**
     * @return list<array{type: SuppressionType, rule: non-empty-string, reason: ?string, offset: int}>
     */
    private function matchText(string $text): array
    {
        $matches = [];

        foreach ([
            [SuppressionType::File, self::PATTERN_FILE],
            [SuppressionType::NextLine, self::PATTERN_NEXT_LINE],
            [SuppressionType::Symbol, self::PATTERN_SYMBOL],
        ] as [$type, $pattern]) {
            if (preg_match_all($pattern, $text, $patternMatches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE) <= 0) {
                continue;
            }

            foreach ($patternMatches as $match) {
                $authored = self::authoredArgument($type, $match[1][0] ?? '', $match[2][0] ?? null);

                if ($authored !== null) {
                    $matches[] = [...$authored, 'offset' => $match[0][1]];
                }
            }
        }

        return $matches;
    }

    /**
     * The `@qmx-` tags in this comment that no grammar read.
     *
     * The line is computed from the tag's own offset rather than from the
     * comment's, because a refusal an author cannot find on the line it names
     * is only half an answer — and because
     * {@see DocumentationRegions} blanks quoted prose in place, every offset
     * still addresses the character that was written.
     *
     * @param string $text the comment with its quoted regions blanked
     * @param list<int> $read offsets the three grammars consumed
     * @param Closure(Comment, int): bool $thresholdRead
     *
     * @return list<Suppression>
     */
    private static function unreadableForms(Comment $comment, string $text, array $read, Closure $thresholdRead): array
    {
        if (preg_match_all(self::PATTERN_ANY_TAG, $text, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE) <= 0) {
            return [];
        }

        $refused = [];

        foreach ($matches as $match) {
            $offset = $match[0][1];
            $tag = $match[1][0];
            $argument = $match[2][0] ?? '';

            if (\in_array($offset, $read, true)) {
                continue;
            }

            $isThreshold = $tag === self::THRESHOLD_TAG_NAME;
            if ($isThreshold && $thresholdRead($comment, $offset)) {
                continue;
            }

            $refused[] = new Suppression(
                rule: $argument,
                reason: null,
                line: self::lineAtOffset($text, $comment->getStartLine(), $offset),
                type: SuppressionType::Symbol,
                refusal: $isThreshold && !$comment instanceof Doc
                    ? DirectiveRefusal::thresholdOutsideDocblock()
                    : DirectiveRefusal::ofUnreadTag($tag, $argument),
            );
        }

        return $refused;
    }

    private static function lineAtOffset(string $text, int $startLine, int $offset): int
    {
        return $startLine + substr_count(substr($text, 0, $offset), "\n");
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
     * @param array{type: SuppressionType, rule: non-empty-string, reason: ?string, offset: int} $match
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
            return new Suppression(
                rule: $match['rule'],
                reason: $match['reason'],
                line: $startLine,
                type: SuppressionType::Symbol,
                refusal: DirectiveRefusal::noDeclarationToBind(),
            );
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
                binding: new DeclarationBinding($subject, $controlScope, $nodeEndLine),
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
