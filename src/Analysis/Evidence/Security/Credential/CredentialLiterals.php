<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Security\Credential;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Property;
use Qualimetrix\Analysis\Evidence\Security\SensitiveNameMatcher;

/**
 * Classifies credential-like literal declarations and their safe exclusions.
 *
 * @qmx-ignore health.cohesion -- Stateless credential-literal shapes share one classification policy and location boundary.
 */
final class CredentialLiterals
{
    public function __construct(private readonly SensitiveNameMatcher $matcher, private readonly int $minValueLength) {}

    /** @return list<CredentialLocation> */
    public function locations(Node $node, string $subjectId): array
    {
        return match (true) {
            $node instanceof Assign => $this->assignment($node, $subjectId),
            $node instanceof Node\ArrayItem => $this->arrayItem($node, $subjectId),
            $node instanceof ClassConst => $this->constants($node, $subjectId),
            $node instanceof FuncCall => $this->define($node, $subjectId),
            $node instanceof Property => $this->properties($node, $subjectId),
            $node instanceof Param => $this->parameter($node, $subjectId),
            $node instanceof EnumCase => $this->enumCase($node, $subjectId),
            default => [],
        };
    }

    /** @return list<CredentialLocation> */ private function assignment(Assign $node, string $subject): array
    {
        return $node->var instanceof Variable && \is_string($node->var->name) && $node->expr instanceof String_ ? $this->match($node->var->name, $node->expr->value, $node->getStartLine(), 'variable', $subject) : [];
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
        if (!$node->name instanceof Name || $node->isFirstClassCallable() || $node->name->toLowerString() !== 'define') {
            return [];
        }

        $args = $node->getArgs();

        return \count($args) >= 2 && $args[0]->value instanceof String_ && $args[1]->value instanceof String_
            ? $this->match($args[0]->value->value, $args[1]->value->value, $node->getStartLine(), 'define', $subject)
            : [];
    }
    /** @return list<CredentialLocation> */ private function match(string $name, string $value, int $line, string $pattern, string $subject): array
    {
        return $this->matcher->isSensitive($name) && $this->isCredential($value) ? [new CredentialLocation($line, $pattern, $subject)] : [];
    }
    private function isCredential(string $value): bool
    {
        return $value !== '' && \strlen($value) >= $this->minValueLength && \strlen($value) !== substr_count($value, $value[0]) && !$this->dotIdentifier($value) && !$this->humanMessage($value);
    }
    private function dotIdentifier(string $value): bool
    {
        return (bool) preg_match('/^[a-zA-Z_]\w*(\.[a-zA-Z_]\w*)+$/', $value);
    }
    private function humanMessage(string $value): bool
    {
        return \strlen($value) > 20 && preg_match_all('/\b\w{3,}\b/', $value) >= 3;
    }
}
