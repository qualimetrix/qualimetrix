<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Extraction;

use Generator;
use PhpParser\Comment;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpToken;
use Qualimetrix\Analysis\Policy\Inline\Contract\SuppressionExtractor;

/**
 * The directive comments of a file that php-parser attached to no node, and
 * the declaration each one belongs to.
 *
 * php-parser hands a comment to the node that starts at the next token. Inside
 * a declaration header that may be an attribute group, identifier, property
 * item, constant or parameter variable, or no node at all. The comment is
 * owned here by the innermost declaration whose header contains it, so it
 * reads like the same comment above that declaration. A comment outside every
 * declaration header stays unowned and is read as a comment on a statement.
 */
final readonly class UnattachedComments
{
    /**
     * @param array<int, list<Comment>> $owned keyed by the owning node's object id
     * @param array<int, int> $rehomed comment start => owning node object id
     * @param list<Comment> $unowned
     */
    private function __construct(
        private array $owned,
        private array $rehomed,
        private array $unowned,
    ) {}

    /**
     * @param array<Node> $ast parsed from exactly `$source`
     */
    public static function find(array $ast, string $source): self
    {
        if (!SuppressionExtractor::mayCarryDirective($source)) {
            return new self([], [], []);
        }

        [$carried, $gaps, $nodes] = self::carriedCommentsAndHeaderGaps($ast);

        [$owned, $rehomed] = self::rehomedComments($nodes, $gaps);

        $unowned = [];
        foreach (self::uncarriedTagComments($source, $carried) as $comment) {
            $owner = self::ownerOf($gaps, $comment->getStartFilePos());
            if ($owner === null) {
                $unowned[] = $comment;
            } else {
                $owned[spl_object_id($owner)][] = $comment;
            }
        }

        return new self($owned, $rehomed, $unowned);
    }

    /**
     * @param list<Node> $nodes
     * @param list<array{node: Node, from: int, to: int}> $gaps
     *
     * @return array{array<int, list<Comment>>, array<int, int>}
     */
    private static function rehomedComments(array $nodes, array $gaps): array
    {
        $owned = [];
        $rehomed = [];
        foreach (self::carriedDirectiveComments($nodes) as [$node, $comment]) {
            $owner = self::ownerOf($gaps, $comment->getStartFilePos());
            if (\in_array($owner, [null, $node], true)) {
                continue;
            }

            $ownerId = spl_object_id($owner);
            $owned[$ownerId][] = $comment;
            $rehomed[$comment->getStartFilePos()] = $ownerId;
        }

        return [$owned, $rehomed];
    }

    /**
     * @param list<Node> $nodes
     *
     * @return Generator<int, array{Node, Comment}>
     */
    private static function carriedDirectiveComments(array $nodes): Generator
    {
        foreach ($nodes as $node) {
            foreach ($node->getComments() as $comment) {
                if (SuppressionExtractor::mayCarryDirective($comment->getText())) {
                    yield [$node, $comment];
                }
            }
        }
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
        $nodeId = spl_object_id($node);
        $owned = $this->owned[$nodeId] ?? [];
        $comments = array_values(array_filter(
            $node->getComments(),
            fn(Comment $comment): bool => !isset($this->rehomed[$comment->getStartFilePos()])
                || $this->rehomed[$comment->getStartFilePos()] === $nodeId,
        ));
        if ($owned === [] && $comments === $node->getComments()) {
            return $node;
        }

        array_push($comments, ...$owned);
        usort($comments, static fn(Comment $left, Comment $right): int => $left->getStartFilePos() <=> $right->getStartFilePos());
        $copy = clone $node;
        $copy->setAttribute('comments', $comments);

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
     * @return array{array<int, true>, list<array{node: Node, from: int, to: int}>, list<Node>}
     */
    private static function carriedCommentsAndHeaderGaps(array $ast): array
    {
        $carried = [];
        $gaps = [];
        $nodes = array_values((new NodeFinder())->find($ast, static fn(): bool => true));
        foreach ($nodes as $node) {
            foreach ($node->getComments() as $comment) {
                $carried[$comment->getStartFilePos()] = true;
            }

            $gap = self::headerGap($node);
            if ($gap !== null) {
                $gaps[] = $gap;
            }
        }

        return [$carried, $gaps, $nodes];
    }

    /**
     * @param array<int, true> $carried start positions of the comments some node carries
     *
     * @return list<Comment>
     */
    private static function uncarriedTagComments(string $source, array $carried): array
    {
        $comments = [];
        foreach (PhpToken::tokenize($source) as $token) {
            if ($token->is([\T_COMMENT, \T_DOC_COMMENT])
                && SuppressionExtractor::mayCarryDirective($token->text)
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
    private static function headerGap(Node $node): ?array
    {
        $name = self::headerName($node);
        if (!$name instanceof Node) {
            return null;
        }

        $from = $node->getStartFilePos();
        $to = $name->getStartFilePos();
        if ($from < 0 || $to <= $from) {
            return null;
        }

        return ['node' => $node, 'from' => $from, 'to' => $to];
    }

    private static function namedHeader(Node $node): ?Node
    {
        return $node instanceof Node\Stmt\ClassLike
            || $node instanceof Node\Stmt\ClassMethod
            || $node instanceof Node\Stmt\Function_
            || $node instanceof Node\Stmt\EnumCase
            ? $node->name
            : null;
    }

    private static function headerName(Node $node): ?Node
    {
        $name = self::namedHeader($node);
        if ($name !== null) {
            return $name;
        }

        $members = match (true) {
            $node instanceof Node\Stmt\Property => $node->props,
            $node instanceof Node\Stmt\ClassConst => $node->consts,
            default => [],
        };

        return $members[0] ?? ($node instanceof Node\Param ? $node->var : null);
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
