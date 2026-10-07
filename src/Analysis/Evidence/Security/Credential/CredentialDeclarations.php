<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Security\Credential;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\Const_;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Property;
use Qualimetrix\Analysis\Evidence\Security\SensitiveNameMatcher;

/** Classifies literals in named declaration defaults and define() calls. */
final readonly class CredentialDeclarations
{
    public function __construct(
        private SensitiveNameMatcher $matcher,
        private CredentialValue $value,
    ) {}

    /** @return list<CredentialLocation> */
    public function locations(Node $node, string $subject): array
    {
        return match (true) {
            $node instanceof ClassConst => $this->constants($node, $subject),
            $node instanceof Const_ => $this->fileConstants($node, $subject),
            $node instanceof FuncCall => $this->define($node, $subject),
            $node instanceof Property => $this->properties($node, $subject),
            $node instanceof Param => $this->parameter($node, $subject),
            $node instanceof EnumCase => $this->enumCase($node, $subject),
            default => [],
        };
    }

    /** @return list<CredentialLocation> */
    private function constants(ClassConst $node, string $subject): array
    {
        $locations = [];
        foreach ($node->consts as $const) {
            if ($const->value instanceof String_) {
                array_push($locations, ...$this->match($const->name->toString(), $const->value->value, $const->getStartLine(), 'class_const', $subject));
            }
        }

        return $locations;
    }

    /** @return list<CredentialLocation> */
    private function fileConstants(Const_ $node, string $subject): array
    {
        $locations = [];
        foreach ($node->consts as $const) {
            if ($const->value instanceof String_) {
                array_push($locations, ...$this->match($const->name->toString(), $const->value->value, $const->getStartLine(), 'file_const', $subject));
            }
        }

        return $locations;
    }

    /** @return list<CredentialLocation> */
    private function properties(Property $node, string $subject): array
    {
        $locations = [];
        foreach ($node->props as $property) {
            if ($property->default instanceof String_) {
                array_push($locations, ...$this->match($property->name->toString(), $property->default->value, $property->getStartLine(), 'property', $subject));
            }
        }

        return $locations;
    }

    /** @return list<CredentialLocation> */
    private function parameter(Param $node, string $subject): array
    {
        return $node->var instanceof Variable && \is_string($node->var->name) && $node->default instanceof String_
            ? $this->match($node->var->name, $node->default->value, $node->getStartLine(), 'parameter', $subject)
            : [];
    }

    /** @return list<CredentialLocation> */
    private function enumCase(EnumCase $node, string $subject): array
    {
        return $node->expr instanceof String_
            ? $this->match($node->name->toString(), $node->expr->value, $node->getStartLine(), 'enum_case', $subject)
            : [];
    }

    /** @return list<CredentialLocation> */
    private function define(FuncCall $node, string $subject): array
    {
        if ($node->name instanceof Node\Expr || $node->isFirstClassCallable() || $node->name->toLowerString() !== 'define') {
            return [];
        }

        $args = $node->getArgs();

        return \count($args) >= 2 && $args[0]->value instanceof String_ && $args[1]->value instanceof String_
            ? $this->match($args[0]->value->value, $args[1]->value->value, $node->getStartLine(), 'define', $subject)
            : [];
    }

    /** @return list<CredentialLocation> */
    private function match(string $name, string $value, int $line, string $pattern, string $subject): array
    {
        return $this->matcher->isSensitive($name) && $this->value->isCredential($value)
            ? [new CredentialLocation($line, $pattern, $subject)]
            : [];
    }
}
