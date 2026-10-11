<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Cohesion;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\TraitUse;

/** Captures declaration and direct instance-call facts for class cohesion. */
final class CohesionAstFacts
{
    public static function captureMember(TraitUse|Property $node, ?LcomClassData $data): void
    {
        if ($data === null) {
            return;
        }
        if ($node instanceof TraitUse) {
            $data->declaration()->addTraitUse(CohesionTraitUses::fromNode($node));

            return;
        }
        if (!$node->isStatic()) {
            $data->declaration()->incrementInstancePropertyCount(\count($node->props));
        }
    }

    public static function captureMethod(ClassMethod $node, ?LcomClassData $data): void
    {
        if ($data === null) {
            return;
        }
        $name = $node->name->toString();
        $visibility = $node->isPrivate() ? 'private' : ($node->isProtected() ? 'protected' : 'public');
        $data->declaration()->declareMethod($name, $visibility, $node->isAbstract());
        if (strtolower($name) !== '__construct') {
            return;
        }
        foreach ($node->params as $param) {
            if ($param->flags !== 0) {
                $data->declaration()->incrementInstancePropertyCount();
            }
        }
    }

    public static function captureCall(Node $node, LcomClassData $data, string $caller): void
    {
        if ($node instanceof MethodCall
            && $node->var instanceof Variable
            && $node->var->name === 'this'
            && $node->name instanceof Identifier
        ) {
            $data->addMethodCall($caller, $node->name->toString());

            return;
        }
        if ($node instanceof StaticCall
            && $node->class instanceof Name
            && \in_array($node->class->toLowerString(), ['self', 'static'], true)
            && $node->name instanceof Identifier
        ) {
            $data->addMethodCall($caller, $node->name->toString());
        }
    }
}
