<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarInterface;
use Qualimetrix\Core\Symbol\FileDeclarationIndex;

/**
 * Registers every declaration of one file in that file's declaration index.
 *
 * It is added to the traverser before every other visitor and registers the
 * whole file in a walk of its own before that traversal visits any node, so a
 * producer asking about a declaration gets its final rank. Registering on the
 * shared walk would not do: the parser visits an anonymous class body before
 * its constructor arguments, and a rank computed from the positions met so far
 * is then too low for a declaration met out of source order — two declarations
 * of one key would share a number. Registration reuses the shared lexical
 * traversal scope rather than a second implementation of identity.
 */
final class DeclarationRegistrarVisitor extends NodeVisitorAbstract implements DeclarationRegistrarInterface
{
    private readonly VisitorMethodContext $context;

    private bool $registering = false;

    public function __construct(private readonly FileDeclarationIndex $index)
    {
        $this->context = new VisitorMethodContext();
        $this->context->useDeclarationIndex($this->index);
    }

    public function index(): FileDeclarationIndex
    {
        return $this->index;
    }

    /** @param array<Node> $nodes */
    public function beforeTraverse(array $nodes): ?array
    {
        if ($this->registering) {
            return null;
        }

        $this->context->reset();
        $this->registering = true;
        try {
            (new NodeTraverser($this))->traverse($nodes);
        } finally {
            $this->registering = false;
            $this->context->reset();
        }

        return null;
    }

    public function enterNode(Node $node): ?int
    {
        if ($this->registering) {
            $this->context->enter($node);
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($this->registering) {
            $this->context->leave($node);
        }

        return null;
    }
}
