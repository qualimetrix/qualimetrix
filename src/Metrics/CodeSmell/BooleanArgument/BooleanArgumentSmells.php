<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\CodeSmell\BooleanArgument;

use PhpParser\Node;

use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use Qualimetrix\Metrics\CodeSmell\CodeSmellLocation;

/** Evaluates boolean-argument smells, including promoted-property semantics. */
final class BooleanArgumentSmells
{
    /** @return list<CodeSmellLocation> */
    public function locations(ClassMethod|Function_|Closure|ArrowFunction $node, string $subjectId): array
    {
        $locations = [];
        foreach ($node->params as $parameter) {
            if ($parameter->type !== null && $this->isBoolType($parameter->type)) {
                $name = $parameter->var instanceof Variable && \is_string($parameter->var->name) ? $parameter->var->name : '?';
                $locations[] = new CodeSmellLocation('boolean_argument', $parameter->getStartLine(), $parameter->getStartTokenPos(), $subjectId, $name, $parameter->flags !== 0);
            }
        }

        return $locations;
    }

    private function isBoolType(Node $type): bool
    {
        if ($type instanceof Node\Identifier) {
            return $type->toLowerString() === 'bool';
        }
        if ($type instanceof Node\NullableType) {
            return $type->type instanceof Node\Identifier && $type->type->toLowerString() === 'bool';
        }
        if ($type instanceof Node\UnionType) {
            foreach ($type->types as $member) {
                if ($member instanceof Node\Identifier && $member->toLowerString() === 'bool') {
                    return true;
                }
            }
        }

        return false;
    }
}
