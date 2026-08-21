<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Visitor;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarInterface;
use Qualimetrix\Core\Symbol\FileDeclarationIndex;

/**
 * Registers every declaration of one file in that file's declaration index.
 *
 * It is added to the traverser before every other visitor, so a producer that
 * asks about the node it is currently entering always finds it registered.
 * Registration reuses the shared lexical traversal scope rather than a second
 * implementation of identity.
 */
final class DeclarationRegistrarVisitor extends NodeVisitorAbstract implements DeclarationRegistrarInterface
{
    private readonly VisitorMethodContext $context;

    public function __construct(private readonly FileDeclarationIndex $index)
    {
        $this->context = new VisitorMethodContext();
        $this->context->useDeclarationIndex($this->index);
    }

    public function index(): FileDeclarationIndex
    {
        return $this->index;
    }

    public function enterNode(Node $node): ?int
    {
        $this->context->enter($node);

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        $this->context->leave($node);

        return null;
    }
}
