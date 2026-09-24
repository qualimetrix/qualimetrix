<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Security\Credential;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Property;
use Qualimetrix\Analysis\Evidence\Security\SensitiveNameMatcher;

/**
 * Classifies the node shapes that store a string literal under a name; the
 * literal itself is judged by {@see CredentialValue}.
 */
final class CredentialLiterals
{
    private readonly CredentialValue $value;

    public function __construct(private readonly SensitiveNameMatcher $matcher, int $minValueLength)
    {
        $this->value = new CredentialValue($minValueLength);
    }

    /** @return list<CredentialLocation> */
    public function locations(Node $node, string $subjectId): array
    {
        return match (true) {
            $node instanceof Assign, $node instanceof AssignOp\Coalesce => $this->assignment($node, $subjectId),
            $node instanceof Node\ArrayItem => $this->arrayItem($node, $subjectId),
            $node instanceof ClassConst => $this->constants($node, $subjectId),
            $node instanceof FuncCall => $this->define($node, $subjectId),
            $node instanceof Property => $this->properties($node, $subjectId),
            $node instanceof Param => $this->parameter($node, $subjectId),
            $node instanceof EnumCase => $this->enumCase($node, $subjectId),
            default => [],
        };
    }

    /**
     * The name judged is the one the literal is stored under: the variable,
     * the property of `$object->name`/`Class::$name`, or the string key of
     * `$array['key']`.
     *
     * @return list<CredentialLocation>
     */
    private function assignment(Assign|AssignOp\Coalesce $node, string $subject): array
    {
        if (!$node->expr instanceof String_) {
            return [];
        }

        $named = $this->assignedName($node->var);

        return $named !== null ? $this->match($named[0], $node->expr->value, $node->getStartLine(), $named[1], $subject) : [];
    }

    /** @return array{string, string}|null the name and the pattern it is reported under */
    private function assignedName(Node\Expr $target): ?array
    {
        if ($target instanceof Variable) {
            return \is_string($target->name) ? [$target->name, 'variable'] : null;
        }

        if ($target instanceof PropertyFetch || $target instanceof StaticPropertyFetch) {
            return $target->name instanceof Node\Expr ? null : [$target->name->toString(), 'property_assignment'];
        }

        return $target instanceof ArrayDimFetch && $target->dim instanceof String_ ? [$target->dim->value, 'array_key'] : null;
    }
    /** @return list<CredentialLocation> */ private function arrayItem(Node\ArrayItem $node, string $subject): array
    {
        return $node->key instanceof String_ && $node->value instanceof String_ ? $this->match($node->key->value, $node->value->value, $node->getStartLine(), 'array_key', $subject) : [];
    }
    /** @return list<CredentialLocation> */ private function constants(ClassConst $node, string $subject): array
    {
        $locations = [];
        foreach ($node->consts as $const) {
            if ($const->value instanceof String_) {
                array_push($locations, ...$this->match($const->name->toString(), $const->value->value, $const->getStartLine(), 'class_const', $subject));
            }
        } return $locations;
    }
    /** @return list<CredentialLocation> */ private function properties(Property $node, string $subject): array
    {
        $locations = [];
        foreach ($node->props as $property) {
            if ($property->default instanceof String_) {
                array_push($locations, ...$this->match($property->name->toString(), $property->default->value, $property->getStartLine(), 'property', $subject));
            }
        } return $locations;
    }
    /** @return list<CredentialLocation> */ private function parameter(Param $node, string $subject): array
    {
        return $node->var instanceof Variable && \is_string($node->var->name) && $node->default instanceof String_ ? $this->match($node->var->name, $node->default->value, $node->getStartLine(), 'parameter', $subject) : [];
    }
    /** @return list<CredentialLocation> */ private function enumCase(EnumCase $node, string $subject): array
    {
        return $node->expr instanceof String_ ? $this->match($node->name->toString(), $node->expr->value, $node->getStartLine(), 'enum_case', $subject) : [];
    }
    /** @return list<CredentialLocation> */ private function define(FuncCall $node, string $subject): array
    {
        if ($node->name instanceof Node\Expr || $node->isFirstClassCallable() || $node->name->toLowerString() !== 'define') {
            return [];
        }

        $args = $node->getArgs();

        return \count($args) >= 2 && $args[0]->value instanceof String_ && $args[1]->value instanceof String_
            ? $this->match($args[0]->value->value, $args[1]->value->value, $node->getStartLine(), 'define', $subject)
            : [];
    }
    /** @return list<CredentialLocation> */ private function match(string $name, string $value, int $line, string $pattern, string $subject): array
    {
        return $this->matcher->isSensitive($name) && $this->value->isCredential($value) ? [new CredentialLocation($line, $pattern, $subject)] : [];
    }
}
