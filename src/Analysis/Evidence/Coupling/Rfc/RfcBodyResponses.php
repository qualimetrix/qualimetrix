<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling\Rfc;

use PhpParser\Node;

/** Extracts direct responses with callable-local, conservative receiver facts. */
final class RfcBodyResponses
{
    /** @var list<array<string, scalar>> */
    private array $responses = [];

    /** @param array<Node> $body
     * @param array<string, string> $variables
     *
     * @return list<array<string, scalar>>
     */
    public function collect(array $body, array $variables): array
    {
        $this->responses = [];
        $this->walk($body, $variables);

        return $this->responses;
    }

    /** @param array<Node> $nodes
     * @param array<string, string> $variables
     */
    private function walk(array $nodes, array &$variables): void
    {
        foreach ($nodes as $node) {
            $this->visit($node, $variables);
        }
    }

    /** @param array<string, string> $variables */
    private function visit(Node $node, array &$variables): void
    {
        if ($this->specialScope($node, $variables)) {
            return;
        }
        RfcReceiverTypes::invalidate($node, $variables);
        $uncertain = RfcLocalFlow::uncertain($node);
        if ($uncertain) {
            RfcLocalFlow::forgetWrittenVariables($node, $variables);
        }
        $this->response($node, $variables);
        $before = $variables;
        foreach (RfcLocalFlow::paths($node) as $path) {
            $branch = $variables;
            $this->walk($path, $variables);
            if (RfcLocalFlow::alternatives($node)) {
                $variables = $branch;
            }
        }
        if ($uncertain) {
            $variables = array_intersect_assoc($before, $variables);
        }
    }

    /** @param array<string, string> $variables */
    private function specialScope(Node $node, array &$variables): bool
    {
        if ($node instanceof Node\Stmt\ClassLike || $node instanceof Node\Stmt\Function_) {
            return true;
        }
        if ($node instanceof Node\Stmt\If_) {
            $this->branch($node, $variables);

            return true;
        }
        if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            $this->nestedCallable($node, $variables);
            return true;
        }
        if ($node instanceof Node\Expr\Assign) {
            $this->assignment($node, $variables);
            return true;
        }

        return false;
    }

    /** @param array<string, string> $variables */
    private function nestedCallable(Node\Expr\Closure|Node\Expr\ArrowFunction $node, array $variables): void
    {
        $nested = $node instanceof Node\Expr\ArrowFunction ? $variables : [];
        if ($node instanceof Node\Expr\Closure) {
            foreach ($node->uses as $use) {
                if (\is_string($use->var->name) && isset($variables[$use->var->name])) {
                    $nested[$use->var->name] = $variables[$use->var->name];
                }
            }
        }
        RfcReceiverTypes::parameters($node->params, $nested);
        $this->walk($node instanceof Node\Expr\Closure ? $node->stmts : [$node->expr], $nested);
    }

    /** @param array<string, string> $variables */
    private function assignment(Node\Expr\Assign $node, array &$variables): void
    {
        $this->visit($node->var, $variables);
        $this->visit($node->expr, $variables);
        RfcReceiverTypes::bind($node, $variables);
    }

    /** @param array<string, string> $variables */
    private function branch(Node\Stmt\If_ $node, array &$variables): void
    {
        $this->visit($node->cond, $variables);
        $then = $variables;
        RfcReceiverTypes::positiveInstanceof($node->cond, $then);
        $this->walk($node->stmts, $then);
        $other = $variables;
        foreach ($node->elseifs as $elseif) {
            $path = $variables;
            $this->visit($elseif->cond, $path);
            $this->walk($elseif->stmts, $path);
            $other = array_intersect_assoc($other, $path);
        }
        if ($node->else !== null) {
            $path = $variables;
            $this->walk($node->else->stmts, $path);
            $other = array_intersect_assoc($other, $path);
        }
        $variables = array_intersect_assoc($then, $other);
    }

    /** @param array<string, string> $variables */
    private function response(Node $node, array $variables): void
    {
        if ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall) {
            if ($node->name instanceof Node\Identifier && !$node->isFirstClassCallable()) {
                $this->add('method', $node->name->toString(), RfcReceiverTypes::receiver($node->var, $variables));
            }
        } elseif ($node instanceof Node\Expr\StaticCall) {
            if ($node->name instanceof Node\Identifier && !$node->isFirstClassCallable()) {
                $this->add('method', $node->name->toString(), RfcReceiverTypes::receiver($node->class, $variables));
            }
        } else {
            $this->otherResponse($node);
        }
    }

    private function otherResponse(Node $node): void
    {
        if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
            $this->add('method', '__construct', RfcReceiverTypes::typeName($node->class));
        } elseif ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && !$node->isFirstClassCallable()) {
            $this->functionResponse($node->name);
        }
    }

    private function functionResponse(Node\Name $name): void
    {
        $resolved = $name->getAttribute('resolvedName');
        $name = ($resolved instanceof Node\Name ? $resolved : $name)->toString();
        if (strtolower($name) !== 'clone') {
            $this->add('function', $name, null);
        }
    }

    private function add(string $kind, string $method, ?string $class): void
    {
        $row = ['kind' => $kind, 'method' => strtolower($method)];
        if ($class !== null) {
            $row['receiver'] = $class;
        }
        $this->responses[] = $row;
    }
}
