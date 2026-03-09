<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Security;

use AiMessDetector\Metrics\ResettableVisitorInterface;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeVisitorAbstract;

/**
 * AST visitor that detects parameters with sensitive names missing #[\SensitiveParameter].
 *
 * Checks parameters of ClassMethod, Function_, Closure, and ArrowFunction nodes.
 * Uses SensitiveNameMatcher to identify credential-related parameter names.
 */
final class SensitiveParameterVisitor extends NodeVisitorAbstract implements ResettableVisitorInterface
{
    /** @var list<SensitiveParameterLocation> */
    private array $locations = [];

    public function __construct(
        private readonly SensitiveNameMatcher $matcher = new SensitiveNameMatcher(),
    ) {}

    public function reset(): void
    {
        $this->locations = [];
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof ClassMethod
            || $node instanceof Function_
            || $node instanceof Closure
            || $node instanceof ArrowFunction
        ) {
            $this->checkParams($node->params);
        }

        return null;
    }

    /**
     * @return list<SensitiveParameterLocation>
     */
    public function getLocations(): array
    {
        return $this->locations;
    }

    /**
     * @param array<Param> $params
     */
    private function checkParams(array $params): void
    {
        foreach ($params as $param) {
            if (!$param->var instanceof Variable || !\is_string($param->var->name)) {
                continue;
            }

            $name = $param->var->name;

            if (!$this->matcher->isSensitive($name)) {
                continue;
            }

            if ($this->hasSensitiveParameterAttribute($param)) {
                continue;
            }

            $this->locations[] = new SensitiveParameterLocation(
                line: $param->getStartLine(),
                paramName: $name,
            );
        }
    }

    /**
     * Check if a parameter has the #[\SensitiveParameter] attribute.
     */
    private function hasSensitiveParameterAttribute(Param $param): bool
    {
        foreach ($param->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $attrName = $attr->name->toString();

                if ($attrName === 'SensitiveParameter' || $attrName === '\\SensitiveParameter') {
                    return true;
                }
            }
        }

        return false;
    }
}
