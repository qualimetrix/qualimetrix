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
     * Every `@qmx-` tag an author can write, whether or not this class reads
     * it. What the suppression grammars did not consume is a form nobody
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
    public const string TAG_PREFIX = SuppressionSyntax::TAG_PREFIX;

    public static function mayCarryDirective(string $text): bool
    {
        return SuppressionSyntax::mayCarryDirective($text);
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
        return $this->extractNode($node, new DeclarationBinding($subject, $controlScope, $reach), self::MODE_FULL, $thresholdRead);
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
        return $this->extractNode($node, null, self::MODE_PHYSICAL, $thresholdRead, $unboundReason);
    }

    /**
     * Extracts file-level suppressions from node's docblock and regular comments.
     *
     * @return list<Suppression>
     */
    public function extractFileLevelSuppressions(Node $node): array
    {
        return $this->extractNode($node, null, self::MODE_FILE_ONLY, static fn(): bool => true);
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
        ?DeclarationBinding $binding,
        string $mode,
        Closure $thresholdRead,
        DirectiveRefusalReason $unboundReason = DirectiveRefusalReason::NoDeclarationToBind,
    ): array {
        $suppressions = [];

        foreach (SuppressionSyntax::commentsOf($node) as $comment) {
            $text = DocumentationRegions::mask($comment->getText());
            $typos = SuppressionSyntax::misspelledForms($comment, $text);
            foreach ($typos as $typo) {
                $offset = $typo->position - $comment->getStartFilePos();
                $refusal = $typo->refusal ?? throw new LogicException('A misspelled directive must carry its refusal');
                $length = \strlen($refusal->tag);
                $text = substr_replace($text, str_repeat(' ', $length), $offset, $length);
            }
            $read = [];

            foreach (SuppressionSyntax::matchText($text, $comment->getText()) as $match) {
                $read[] = $match['offset'];
                $suppression = $this->projectMatch(
                    $match,
                    SuppressionSyntax::lineAtOffset($comment->getText(), $comment->getStartLine(), $match['offset']),
                    $comment->getEndLine(),
                    SuppressionSyntax::positionAtOffset($comment, $match['offset']),
                    $binding,
                    $mode,
                    $unboundReason,
                );

                if ($suppression !== null) {
                    $suppressions[] = $suppression;
                }
            }

            if ($mode !== self::MODE_FILE_ONLY) {
                array_push($suppressions, ...$typos, ...self::unreadableForms($comment, $text, $read, $thresholdRead, $unboundReason));
                array_push($suppressions, ...self::mentionRefusals($comment));
            }
        }

        return $suppressions;
    }

    /** @return list<Suppression> */
    private static function mentionRefusals(Comment $comment): array
    {
        $refused = [];
        foreach (DocumentationRegions::mentions($comment->getText()) as $mention) {
            $refusal = $mention['fenceLine'] === null
                ? DirectiveRefusal::notAtLineStart($mention['tag'])
                : DirectiveRefusal::insideUnclosedFence($mention['tag'], $comment->getStartLine() + $mention['fenceLine'] - 1);
            preg_match('/^[^\S\n\r]+(?!\*+\/)([\w.*#:-]+)/', substr($comment->getText(), $mention['offset'] + \strlen($mention['tag'])), $argument);
            $refused[] = new Suppression(
                rule: $argument[1] ?? '',
                reason: null,
                line: SuppressionSyntax::lineAtOffset($comment->getText(), $comment->getStartLine(), $mention['offset']),
                type: SuppressionType::Symbol,
                position: SuppressionSyntax::positionAtOffset($comment, $mention['offset']),
                refusal: $refusal,
            );
        }

        return $refused;
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

            $refusal = self::unreadTagRefusal($comment, $tag, $argument, $offset, $thresholdRead, $unboundReason);
            if ($refusal === null) {
                continue;
            }

            $refused[] = new Suppression(
                rule: $argument,
                reason: null,
                line: SuppressionSyntax::lineAtOffset($text, $comment->getStartLine(), $offset),
                type: SuppressionType::Symbol,
                position: SuppressionSyntax::positionAtOffset($comment, $offset),
                refusal: $refusal,
            );
        }

        return $refused;
    }

    /** @param non-empty-string $tag */
    private static function unreadTagRefusal(
        Comment $comment,
        string $tag,
        string $argument,
        int $offset,
        Closure $thresholdRead,
        DirectiveRefusalReason $unboundReason,
    ): ?DirectiveRefusal {
        $isThreshold = $tag === self::THRESHOLD_TAG_NAME;
        if ($isThreshold && $thresholdRead($comment, $offset)) {
            return null;
        }

        $refusal = $isThreshold && !$comment instanceof Doc
            ? DirectiveRefusal::thresholdOutsideDocblock()
            : DirectiveRefusal::ofUnreadTag($tag, $argument);
        if ($unboundReason === DirectiveRefusalReason::ClosureNotDirectValue
            && $refusal->reason === DirectiveRefusalReason::NoDeclarationToBind) {
            $refusal = DirectiveRefusal::closureNotDirectValue($refusal->form);
        }

        return $refusal;
    }

    /**
     * @param array{type: SuppressionType, rule: non-empty-string, reason: ?string, offset: int} $match
     * @param 'full'|'physical'|'file-only' $mode
     */
    private function projectMatch(
        array $match,
        int $tagLine,
        int $endLine,
        int $position,
        ?DeclarationBinding $binding,
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
                line: $tagLine,
                type: SuppressionType::Symbol,
                position: $position,
                refusal: $unboundReason === DirectiveRefusalReason::ClosureNotDirectValue
                    ? DirectiveRefusal::closureNotDirectValue(SuppressionType::Symbol->value)
                    : DirectiveRefusal::noDeclarationToBind(),
            );
        }

        if ($match['type'] === SuppressionType::Symbol) {
            if ($binding === null) {
                throw new LogicException('Symbol suppression requires an explicit declaration binding');
            }

            return new Suppression(
                rule: $match['rule'],
                reason: $match['reason'],
                line: $tagLine,
                type: SuppressionType::Symbol,
                position: $position,
                binding: $binding,
            );
        }

        return new Suppression(
            rule: $match['rule'],
            reason: $match['reason'],
            line: $tagLine,
            type: $match['type'],
            position: $position,
            silencedLine: $match['type'] === SuppressionType::NextLine ? $endLine + 1 : null,
        );
    }

}
