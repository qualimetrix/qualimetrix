<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ErrorSuppress;
use PhpParser\Node\Expr\Eval_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Analysis\Evidence\CodeSmell\BooleanArgument\BooleanArgumentSmells;
use Qualimetrix\Analysis\Evidence\CodeSmell\ControlFlow\ControlFlowSmells;
use Qualimetrix\Analysis\Evidence\CodeSmell\Debug\DebugCodeSmells;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationIndexAwareInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ResettableVisitorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\VisitorMethodTrackingTrait;

/** Traverses AST nodes and delegates code-smell semantics to subject companions. */
final class CodeSmellVisitor extends NodeVisitorAbstract implements DeclarationIndexAwareInterface, ResettableVisitorInterface
{
    use VisitorMethodTrackingTrait;

    /** @var list<CodeSmellLocation> */
    private array $locations = [];

    /** @var list<string> */
    private array $methodStack = [];

    private int $foreachDepth = 0;

    public function __construct(
        private readonly ControlFlowSmells $controlFlowSmells = new ControlFlowSmells(),
        private readonly DebugCodeSmells $debugCodeSmells = new DebugCodeSmells(),
        private readonly BooleanArgumentSmells $booleanArgumentSmells = new BooleanArgumentSmells(),
    ) {}

    public function reset(): void
    {
        $this->locations = [];
        $this->methodStack = [];
        $this->foreachDepth = 0;
        $this->resetVisitorMethodContext();
    }

    public function enterNode(Node $node): ?int
    {
        $this->enterVisitorMethodContext($node);
        $this->trackMethod($node);
        if ($node instanceof Foreach_) {
            ++$this->foreachDepth;
        }

        $subjectId = $this->currentFileEntrySubjectId();
        $this->append($this->controlFlowSmells->locations($node, $subjectId, $this->foreachDepth));
        if ($node instanceof FuncCall) {
            $location = $this->debugCodeSmells->location($node, $this->currentMethod(), $subjectId);
            if ($location !== null) {
                $this->append([$location]);
            }
        }
        if ($node instanceof ClassMethod || $node instanceof Function_ || $node instanceof Closure || $node instanceof ArrowFunction) {
            $this->append($this->booleanArgumentSmells->locations($node, $subjectId));
        }
        $this->recordResidual($node, $subjectId);

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof ClassMethod || $node instanceof Function_) {
            array_pop($this->methodStack);
        }
        if ($node instanceof Foreach_) {
            --$this->foreachDepth;
        }
        $this->leaveVisitorMethodContext($node);

        return null;
    }

    /** @return list<CodeSmellLocation> */
    public function getLocations(): array
    {
        return $this->locations;
    }

    /** @return list<CodeSmellLocation> */
    public function getLocationsByType(string $type): array
    {
        return array_values(array_filter($this->locations, static fn(CodeSmellLocation $location): bool => $location->type === $type));
    }

    public function getCountByType(string $type): int
    {
        return \count($this->getLocationsByType($type));
    }

    /** @return array<string, int|string> */
    public function getSubjectComponents(CodeSmellLocation $location): array
    {
        return $this->fileEntrySubjectComponents($location->subjectId);
    }

    private function trackMethod(Node $node): void
    {
        if ($node instanceof ClassMethod || $node instanceof Function_) {
            $this->methodStack[] = $node->name->toLowerString();
        }
    }

    private function currentMethod(): ?string
    {
        return $this->methodStack === [] ? null : $this->methodStack[array_key_last($this->methodStack)];
    }

    /** @param list<CodeSmellLocation> $locations */
    private function append(array $locations): void
    {
        array_push($this->locations, ...$locations);
    }

    private function recordResidual(Node $node, string $subjectId): void
    {
        if ($node instanceof Eval_) {
            $this->append([new CodeSmellLocation('eval', $node->getStartLine(), $node->getStartTokenPos(), $subjectId)]);
        } elseif ($node instanceof ErrorSuppress) {
            $name = $node->expr instanceof FuncCall && $node->expr->name instanceof Name ? $node->expr->name->toLowerString() : null;
            $this->append([new CodeSmellLocation('error_suppression', $node->getStartLine(), $node->getStartTokenPos(), $subjectId, $name)]);
        } elseif ($node instanceof Variable && \is_string($node->name) && \in_array($node->name, ['_GET', '_POST', '_REQUEST', '_COOKIE', '_SESSION', '_SERVER', '_FILES', '_ENV', 'GLOBALS'], true)) {
            $this->append([new CodeSmellLocation('superglobals', $node->getStartLine(), $node->getStartTokenPos(), $subjectId, $node->name)]);
        }
    }
}
