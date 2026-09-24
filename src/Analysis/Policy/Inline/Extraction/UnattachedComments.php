<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Extraction;

use PhpParser\Comment;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\AttributeGroup;
use PhpParser\NodeFinder;
use PhpToken;

/**
 * The directive comments of a file that php-parser attached to no node, and
 * the declaration each one belongs to.
 *
 * php-parser hands a comment to the node that starts at the next token, so a
 * comment followed by a modifier, a keyword or a closing bracket reaches no
 * node at all. The one such place an author writes on purpose is between the
 * attributes of a declaration and the declaration itself, and PHP's own
 * reflection gives that docblock to the declaration: it is owned here by that
 * declaration, so it reads exactly as the same comment written above the
 * attributes. A comment anywhere else has no declaration and stays unowned;
 * it is read the way a comment on a statement is.
 */
final readonly class UnattachedComments
{
    /**
     * @param array<int, list<Comment>> $owned keyed by the owning node's object id
     * @param list<Comment> $unowned
     */
    private function __construct(
        private array $owned,
        private array $unowned,
    ) {}

    /**
     * @param array<Node> $ast parsed from exactly `$source`
     * @param non-empty-string $tagPrefix what makes a comment a directive comment
     */
    public static function find(array $ast, string $source, string $tagPrefix): self
    {
        if (!str_contains($source, $tagPrefix)) {
            return new self([], []);
        }

        [$carried, $gaps] = self::carriedCommentsAndAttributeGaps($ast);

        $owned = [];
        $unowned = [];
        foreach (self::uncarriedTagComments($source, $tagPrefix, $carried) as $comment) {
            $owner = self::ownerOf($gaps, $comment->getStartFilePos());
            if ($owner === null) {
                $unowned[] = $comment;
            } else {
                $owned[spl_object_id($owner)][] = $comment;
            }
        }

        return new self($owned, $unowned);
    }

    public function owns(Node $node): bool
    {
        return isset($this->owned[spl_object_id($node)]);
    }

    /**
     * The node as its author sees it: carrying the comments written after its
     * attributes as well as the ones php-parser gave it.
     *
     * A copy, so that an AST shared with another reader — a cache hit returns
     * the same tree to whoever asks — is never changed by being read.
     */
    public function withOwnedComments(Node $node): Node
    {
        $owned = $this->owned[spl_object_id($node)] ?? [];
        if ($owned === []) {
            return $node;
        }

        $copy = clone $node;
        $copy->setAttribute('comments', [...$node->getComments(), ...$owned]);

        return $copy;
    }

    /** @return list<Comment> */
    public function unowned(): array
    {
        return $this->unowned;
    }

    /**
     * @param array<Node> $ast
     *
     * @return array{array<int, true>, list<array{node: Node, from: int, to: int}>}
     */
    private static function carriedCommentsAndAttributeGaps(array $ast): array
    {
        $carried = [];
        $gaps = [];
        foreach ((new NodeFinder())->find($ast, static fn(): bool => true) as $node) {
            foreach ($node->getComments() as $comment) {
                $carried[$comment->getStartFilePos()] = true;
            }

            $gap = self::attributeGap($node);
            if ($gap !== null) {
                $gaps[] = $gap;
            }
        }

        return [$carried, $gaps];
    }

    /**
     * @param array<int, true> $carried start positions of the comments some node carries
     *
     * @return list<Comment>
     */
    private static function uncarriedTagComments(string $source, string $tagPrefix, array $carried): array
    {
        $comments = [];
        foreach (PhpToken::tokenize($source) as $token) {
            if ($token->is([\T_COMMENT, \T_DOC_COMMENT])
                && str_contains($token->text, $tagPrefix)
                && !isset($carried[$token->pos])
            ) {
                $comments[] = self::commentOf($token);
            }
        }

        return $comments;
    }

    /**
     * The source range between a declaration's last attribute group and the
     * first part of it that is a node of its own — its name, its type, its
     * first parameter — or its end when it has none.
     *
     * @return ?array{node: Node, from: int, to: int}
     */
    private static function attributeGap(Node $node): ?array
    {
        $groups = property_exists($node, 'attrGroups') ? $node->attrGroups : [];
        if (!\is_array($groups) || $groups === []) {
            return null;
        }

        $last = end($groups);
        if (!$last instanceof AttributeGroup) {
            return null;
        }

        return ['node' => $node, 'from' => $last->getEndFilePos(), 'to' => self::firstOwnPartStart($node)];
    }

    private static function firstOwnPartStart(Node $node): int
    {
        $start = $node->getEndFilePos();
        $subNodes = get_object_vars($node);
        foreach ($node->getSubNodeNames() as $name) {
            $subNode = $name === 'attrGroups' ? null : $subNodes[$name] ?? null;
            foreach (\is_array($subNode) ? $subNode : [$subNode] as $child) {
                if ($child instanceof Node && $child->getStartFilePos() >= 0) {
                    $start = min($start, $child->getStartFilePos());
                }
            }
        }

        return $start;
    }

    /**
     * The innermost declaration whose attribute gap holds the position.
     *
     * @param list<array{node: Node, from: int, to: int}> $gaps
     */
    private static function ownerOf(array $gaps, int $position): ?Node
    {
        $owner = null;
        $from = -1;
        foreach ($gaps as $gap) {
            if ($position > $gap['from'] && $position < $gap['to'] && $gap['from'] > $from) {
                $owner = $gap['node'];
                $from = $gap['from'];
            }
        }

        return $owner;
    }

    private static function commentOf(PhpToken $token): Comment
    {
        $endLine = $token->line + substr_count($token->text, "\n");
        $endPos = $token->pos + \strlen($token->text) - 1;

        return $token->id === \T_DOC_COMMENT
            ? new Doc($token->text, $token->line, $token->pos, -1, $endLine, $endPos)
            : new Comment($token->text, $token->line, $token->pos, -1, $endLine, $endPos);
    }
}
