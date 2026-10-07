<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Security\Credential;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\String_;
use Qualimetrix\Analysis\Evidence\Security\SensitiveNameMatcher;

/**
 * Classifies the node shapes that store a string literal under a name; the
 * literal itself is judged by {@see CredentialValue}.
 */
final class CredentialLiterals
{
    private readonly CredentialValue $value;
    private readonly CredentialDeclarations $declarations;

    public function __construct(private readonly SensitiveNameMatcher $matcher, int $minValueLength)
    {
        $this->value = new CredentialValue($minValueLength);
        $this->declarations = new CredentialDeclarations($matcher, $this->value);
    }

    /** @return list<CredentialLocation> */
    public function locations(Node $node, string $subjectId): array
    {
        return match (true) {
            $node instanceof Assign, $node instanceof AssignOp\Coalesce => $this->assignment($node, $subjectId),
            $node instanceof Node\ArrayItem => $this->arrayItem($node, $subjectId),
            default => $this->declarations->locations($node, $subjectId),
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
    /** @return list<CredentialLocation> */ private function match(string $name, string $value, int $line, string $pattern, string $subject): array
    {
        return $this->matcher->isSensitive($name) && $this->value->isCredential($value) ? [new CredentialLocation($line, $pattern, $subject)] : [];
    }
}
