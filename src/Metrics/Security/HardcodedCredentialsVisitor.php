<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Security;

use AiMessDetector\Metrics\ResettableVisitorInterface;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeVisitorAbstract;

/**
 * AST visitor that detects hardcoded credentials.
 *
 * Detects patterns:
 * - Variable assignment with sensitive name and string value
 * - Array items with sensitive key and string value
 * - Class constants with sensitive name and string value
 * - define() calls with sensitive name and string value
 * - Property defaults with sensitive name and string value
 * - Parameter defaults with sensitive name and string value
 */
final class HardcodedCredentialsVisitor extends NodeVisitorAbstract implements ResettableVisitorInterface
{
    /** @var list<CredentialLocation> */
    private array $locations = [];

    public function __construct(
        private readonly SensitiveNameMatcher $matcher,
        private readonly int $minValueLength = 4,
    ) {}

    public function reset(): void
    {
        $this->locations = [];
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Assign) {
            $this->checkVariableAssignment($node);

            return null;
        }

        if ($node instanceof Node\ArrayItem) {
            $this->checkArrayItem($node);

            return null;
        }

        if ($node instanceof ClassConst) {
            $this->checkClassConst($node);

            return null;
        }

        if ($node instanceof FuncCall) {
            $this->checkDefineCall($node);

            return null;
        }

        if ($node instanceof Property) {
            $this->checkPropertyDefault($node);

            return null;
        }

        if ($node instanceof Param) {
            $this->checkParameterDefault($node);

            return null;
        }

        return null;
    }

    /**
     * @return list<CredentialLocation>
     */
    public function getLocations(): array
    {
        return $this->locations;
    }

    private function checkVariableAssignment(Assign $node): void
    {
        if (!$node->var instanceof Variable || !\is_string($node->var->name)) {
            return;
        }

        if (!$node->expr instanceof String_) {
            return;
        }

        if ($this->matcher->isSensitive($node->var->name) && $this->isCredentialValue($node->expr->value)) {
            $this->locations[] = new CredentialLocation(
                line: $node->getStartLine(),
                pattern: 'variable',
            );
        }
    }

    private function checkArrayItem(Node\ArrayItem $node): void
    {
        if (!$node->key instanceof String_ || !$node->value instanceof String_) {
            return;
        }

        if ($this->matcher->isSensitive($node->key->value) && $this->isCredentialValue($node->value->value)) {
            $this->locations[] = new CredentialLocation(
                line: $node->getStartLine(),
                pattern: 'array_key',
            );
        }
    }

    private function checkClassConst(ClassConst $node): void
    {
        foreach ($node->consts as $const) {
            if (!$const->value instanceof String_) {
                continue;
            }

            if ($this->matcher->isSensitive($const->name->toString()) && $this->isCredentialValue($const->value->value)) {
                $this->locations[] = new CredentialLocation(
                    line: $const->getStartLine(),
                    pattern: 'class_const',
                );
            }
        }
    }

    private function checkDefineCall(FuncCall $node): void
    {
        if (!$node->name instanceof Name) {
            return;
        }

        if ($node->name->toLowerString() !== 'define') {
            return;
        }

        $args = $node->getArgs();

        if (\count($args) < 2) {
            return;
        }

        if (!$args[0]->value instanceof String_ || !$args[1]->value instanceof String_) {
            return;
        }

        if ($this->matcher->isSensitive($args[0]->value->value) && $this->isCredentialValue($args[1]->value->value)) {
            $this->locations[] = new CredentialLocation(
                line: $node->getStartLine(),
                pattern: 'define',
            );
        }
    }

    private function checkPropertyDefault(Property $node): void
    {
        foreach ($node->props as $prop) {
            if (!$prop->default instanceof String_) {
                continue;
            }

            if ($this->matcher->isSensitive($prop->name->toString()) && $this->isCredentialValue($prop->default->value)) {
                $this->locations[] = new CredentialLocation(
                    line: $prop->getStartLine(),
                    pattern: 'property',
                );
            }
        }
    }

    private function checkParameterDefault(Param $node): void
    {
        if (!$node->var instanceof Variable || !\is_string($node->var->name)) {
            return;
        }

        if (!$node->default instanceof String_) {
            return;
        }

        if ($this->matcher->isSensitive($node->var->name) && $this->isCredentialValue($node->default->value)) {
            $this->locations[] = new CredentialLocation(
                line: $node->getStartLine(),
                pattern: 'parameter',
            );
        }
    }

    private function isCredentialValue(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        if (\strlen($value) < $this->minValueLength) {
            return false;
        }

        // All characters are the same (e.g., "***", "xxx", "----")
        if (\strlen($value) === substr_count($value, $value[0])) {
            return false;
        }

        return true;
    }
}
