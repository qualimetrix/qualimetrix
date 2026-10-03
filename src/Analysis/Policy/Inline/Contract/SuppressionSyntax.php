<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract;

use LogicException;
use PhpParser\Comment;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveRefusal;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionTarget;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;

/** Reads suppression spelling and authored arguments without declaration binding. */
final readonly class SuppressionSyntax
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

    public const string TAG_PREFIX = '@qmx-';

    private const string UNICODE_SPACE = '(?:\xC2[\x85\xA0]|\xE1\x9A\x80|\xE2\x80[\x80-\x8A\xA8\xA9\xAF]|\xE2\x81\x9F|\xE3\x80\x80)';

    private const string NEAR_PREFIX = '/(?:@qmx(?:[-_ \t]|' . self::UNICODE_SPACE . ')*(?:ignore|threshold)[\w-]*|qmx[-_](?:ignore|threshold)[\w-]*)/i';

    private const array SPACE_NAMES = [
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
    ];

    public static function mayCarryDirective(string $text): bool
    {
        return str_contains($text, self::TAG_PREFIX) || self::regexResult(preg_match(self::NEAR_PREFIX, $text)) === 1;
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
    public static function commentsOf(Node $node): array
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
    public static function matchText(string $text, string $authoredText): array
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

    /** @return list<Suppression> */
    public static function misspelledForms(Comment $comment, string $text): array
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
        $name = self::SPACE_NAMES[$space] ?? 'NEXT LINE';

        return \sprintf('U+%04X %s', mb_ord($space), $name);
    }

    public static function lineAtOffset(string $text, int $startLine, int $offset): int
    {
        return $startLine + substr_count(substr($text, 0, $offset), "\n");
    }

    public static function positionAtOffset(Comment $comment, int $offset): int
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
