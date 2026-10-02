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
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DeclarationReach;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveRefusal;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveRefusalReason;
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
 * A trailing comment such as `// @qmx-ignore-next-line rule` is read from
 * the start of the comment. Next-line still addresses the line after its end.
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

    private const string UNICODE_SPACE = '(?:\xC2[\x85\xA0]|\xE1\x9A\x80|\xE2\x80[\x80-\x8A\xA8\xA9\xAF]|\xE2\x81\x9F|\xE3\x80\x80)';

    private const string NEAR_PREFIX = '/(?:@qmx(?:[-_ \t]|' . self::UNICODE_SPACE . ')*(?:ignore|threshold)[\w-]*|qmx[-_](?:ignore|threshold)[\w-]*)/i';

    public static function mayCarryDirective(string $text): bool
    {
        return str_contains($text, self::TAG_PREFIX) || self::regexResult(preg_match(self::NEAR_PREFIX, $text)) === 1;
    }

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
    public function extract(
        Node $node,
        MetricSubject $subject,
        ControlScope $controlScope,
        DeclarationReach $reach,
        Closure $thresholdRead,
    ): array {
        return $this->extractNode($node, $subject, $controlScope, $reach, self::MODE_FULL, $thresholdRead);
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
    public function extractPhysical(
        Node $node,
        Closure $thresholdRead,
        DirectiveRefusalReason $unboundReason = DirectiveRefusalReason::NoDeclarationToBind,
    ): array {
        return $this->extractNode($node, null, null, null, self::MODE_PHYSICAL, $thresholdRead, $unboundReason);
    }

    /**
     * Extracts file-level suppressions from node's docblock and regular comments.
     *
     * @return list<Suppression>
     */
    public function extractFileLevelSuppressions(Node $node): array
    {
        return $this->extractNode($node, null, null, null, self::MODE_FILE_ONLY, static fn(): bool => true);
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
        ?DeclarationReach $reach,
        string $mode,
        Closure $thresholdRead,
        DirectiveRefusalReason $unboundReason = DirectiveRefusalReason::NoDeclarationToBind,
    ): array {
        $suppressions = [];

        foreach (self::commentsOf($node) as $comment) {
            $text = DocumentationRegions::mask($comment->getText());
            $typos = self::misspelledForms($comment, $text);
            foreach ($typos as $typo) {
                $offset = $typo->position - $comment->getStartFilePos();
                $refusal = $typo->refusal ?? throw new LogicException('A misspelled directive must carry its refusal');
                $length = \strlen($refusal->tag);
                $text = substr_replace($text, str_repeat(' ', $length), $offset, $length);
            }
            $read = [];

            foreach ($this->matchText($text, $comment->getText()) as $match) {
                $read[] = $match['offset'];
                $suppression = $this->projectMatch(
                    $match,
                    $comment->getStartLine(),
                    $comment->getEndLine(),
                    self::positionAtOffset($comment, $match['offset']),
                    $reach,
                    $subject,
                    $controlScope,
                    $mode,
                    $unboundReason,
                );

                if ($suppression !== null) {
                    $suppressions[] = $suppression;
                }
            }

            if ($mode !== self::MODE_FILE_ONLY) {
                array_push($suppressions, ...$typos, ...self::unreadableForms($comment, $text, $read, $thresholdRead, $unboundReason));
                foreach (DocumentationRegions::mentions($comment->getText()) as $mention) {
                    $refusal = $mention['fenceLine'] === null
                        ? DirectiveRefusal::notAtLineStart($mention['tag'])
                        : DirectiveRefusal::insideUnclosedFence($mention['tag'], $comment->getStartLine() + $mention['fenceLine'] - 1);
                    preg_match('/^[^\S\n\r]+(?!\*+\/)([\w.*#:-]+)/', substr($comment->getText(), $mention['offset'] + \strlen($mention['tag'])), $argument);
                    $suppressions[] = new Suppression(
                        rule: $argument[1] ?? '',
                        reason: null,
                        line: self::lineAtOffset($comment->getText(), $comment->getStartLine(), $mention['offset']),
                        type: SuppressionType::Symbol,
                        position: self::positionAtOffset($comment, $mention['offset']),
                        refusal: $refusal,
                    );
                }
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
    private function matchText(string $text, string $authoredText): array
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

            foreach ($patternMatches as $candidate) {
                if (preg_match($pattern, $authoredText, $match, \PREG_OFFSET_CAPTURE, $candidate[0][1]) !== 1) {
                    continue;
                }
                if ($match[0][1] !== $candidate[0][1]) {
                    continue;
                }
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
    private static function unreadableForms(
        Comment $comment,
        string $text,
        array $read,
        Closure $thresholdRead,
        DirectiveRefusalReason $unboundReason,
    ): array {
        if (preg_match_all(self::PATTERN_ANY_TAG, $text, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE) <= 0) {
            return [];
        }

        $refused = [];

        foreach ($matches as $candidate) {
            if (preg_match(self::PATTERN_ANY_TAG, $comment->getText(), $match, \PREG_OFFSET_CAPTURE, $candidate[0][1]) !== 1) {
                continue;
            }
            if ($match[0][1] !== $candidate[0][1]) {
                continue;
            }
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

            $refusal = $isThreshold && !$comment instanceof Doc
                ? DirectiveRefusal::thresholdOutsideDocblock()
                : DirectiveRefusal::ofUnreadTag($tag, $argument);
            if ($unboundReason === DirectiveRefusalReason::ClosureNotDirectValue
                && $refusal->reason === DirectiveRefusalReason::NoDeclarationToBind) {
                $refusal = DirectiveRefusal::closureNotDirectValue($isThreshold);
            }

            $refused[] = new Suppression(
                rule: $argument,
                reason: null,
                line: self::lineAtOffset($text, $comment->getStartLine(), $offset),
                type: SuppressionType::Symbol,
                position: self::positionAtOffset($comment, $offset),
                refusal: $refusal,
            );
        }

        return $refused;
    }

    /** @return list<Suppression> */
    private static function misspelledForms(Comment $comment, string $text): array
    {
        self::regexResult(preg_match_all(self::NEAR_PREFIX, $text, $matches, \PREG_OFFSET_CAPTURE));
        $refused = [];
        foreach ($matches[0] as [$written, $offset]) {
            $intended = strtolower(preg_replace('/^(?:@qmx(?:[-_ \t]|' . self::UNICODE_SPACE . ')*|qmx[-_])/i', '', $written)
                ?? throw new LogicException('Cannot normalize a directive prefix: ' . preg_last_error_msg()));
            $after = substr($comment->getText(), $offset + \strlen($written));
            self::regexResult(preg_match('/^' . self::UNICODE_SPACE . '/', $after, $space));
            $unicodeSpace = $space[0] ?? null;
            if ($written === self::TAG_PREFIX . $intended && $unicodeSpace === null) {
                continue;
            }
            $refused[] = new Suppression(
                rule: '',
                reason: null,
                line: self::lineAtOffset($text, $comment->getStartLine(), $offset),
                type: SuppressionType::Symbol,
                position: self::positionAtOffset($comment, $offset),
                refusal: DirectiveRefusal::misspelledPrefix(
                    $written,
                    $intended,
                    $unicodeSpace === null ? null : self::spaceName($unicodeSpace),
                ),
            );
        }

        return $refused;
    }

    private static function regexResult(int|false $result): int
    {
        if ($result === false) {
            throw new LogicException('Cannot read directive grammar: ' . preg_last_error_msg());
        }

        return $result;
    }

    private static function spaceName(string $space): string
    {
        $name = match ($space) {
            "\u{00A0}" => 'NO-BREAK SPACE',
            "\u{1680}" => 'OGHAM SPACE MARK',
            "\u{2000}" => 'EN QUAD',
            "\u{2001}" => 'EM QUAD',
            "\u{2002}" => 'EN SPACE',
            "\u{2003}" => 'EM SPACE',
            "\u{2004}" => 'THREE-PER-EM SPACE',
            "\u{2005}" => 'FOUR-PER-EM SPACE',
            "\u{2006}" => 'SIX-PER-EM SPACE',
            "\u{2007}" => 'FIGURE SPACE',
            "\u{2008}" => 'PUNCTUATION SPACE',
            "\u{2009}" => 'THIN SPACE',
            "\u{200A}" => 'HAIR SPACE',
            "\u{202F}" => 'NARROW NO-BREAK SPACE',
            "\u{205F}" => 'MEDIUM MATHEMATICAL SPACE',
            "\u{3000}" => 'IDEOGRAPHIC SPACE',
            "\u{2028}" => 'LINE SEPARATOR',
            "\u{2029}" => 'PARAGRAPH SEPARATOR',
            default => 'NEXT LINE',
        };

        return \sprintf('U+%04X %s', mb_ord($space), $name);
    }

    private static function lineAtOffset(string $text, int $startLine, int $offset): int
    {
        return $startLine + substr_count(substr($text, 0, $offset), "\n");
    }

    private static function positionAtOffset(Comment $comment, int $offset): int
    {
        $start = $comment->getStartFilePos();
        if ($start < 0) {
            throw new LogicException('A comment without a file position cannot name a directive site');
        }

        return $start + $offset;
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
        int $position,
        ?DeclarationReach $reach,
        ?MetricSubject $subject,
        ?ControlScope $controlScope,
        string $mode,
        DirectiveRefusalReason $unboundReason,
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
                position: $position,
                refusal: $unboundReason === DirectiveRefusalReason::ClosureNotDirectValue
                    ? DirectiveRefusal::closureNotDirectValue(false)
                    : DirectiveRefusal::noDeclarationToBind(),
            );
        }

        if ($match['type'] === SuppressionType::Symbol) {
            if ($subject === null || $controlScope === null || $reach === null) {
                throw new LogicException('Symbol suppression requires an explicit declaration binding');
            }

            return new Suppression(
                rule: $match['rule'],
                reason: $match['reason'],
                line: $startLine,
                type: SuppressionType::Symbol,
                position: $position,
                binding: new DeclarationBinding($subject, $controlScope, $reach),
            );
        }

        return new Suppression(
            rule: $match['rule'],
            reason: $match['reason'],
            line: $match['type'] === SuppressionType::File ? $startLine : $endLine,
            type: $match['type'],
            position: $position,
            silencedLine: $match['type'] === SuppressionType::NextLine ? $endLine + 1 : null,
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
