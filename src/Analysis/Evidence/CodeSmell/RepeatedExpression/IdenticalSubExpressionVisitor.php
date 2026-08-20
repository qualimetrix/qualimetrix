<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell\RepeatedExpression;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationIndexAwareInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ResettableVisitorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\VisitorMethodTrackingTrait;

/** Traverses nodes and delegates repeated-expression policy to its subjects. */
final class IdenticalSubExpressionVisitor extends NodeVisitorAbstract implements DeclarationIndexAwareInterface, ResettableVisitorInterface
{
    use VisitorMethodTrackingTrait;

    /** @var list<IdenticalSubExpressionFinding> */
    private array $findings = [];

    public function __construct(
        private readonly RepeatedExpressions $repeatedExpressions = new RepeatedExpressions(),
        private readonly RepeatedConditions $repeatedConditions = new RepeatedConditions(),
    ) {}

    public function reset(): void
    {
        $this->findings = [];
        $this->resetVisitorMethodContext();
    }

    /** @return list<IdenticalSubExpressionFinding> */
    public function getFindings(): array
    {
        return $this->findings;
    }

    public function enterNode(Node $node): ?int
    {
        $this->enterVisitorMethodContext($node);
        $subjectId = $this->currentFileEntrySubjectId();
        if ($node instanceof BinaryOp || $node instanceof Ternary) {
            $this->append($this->repeatedExpressions->findings($node, $subjectId));
        }
        if ($node instanceof Stmt\If_ || $node instanceof Expr\Match_ || $node instanceof Stmt\Switch_) {
            $this->append($this->repeatedConditions->findings($node, $subjectId));
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        $this->leaveVisitorMethodContext($node);

        return null;
    }

    /** @return array<string, int|string> */
    public function getSubjectComponents(IdenticalSubExpressionFinding $finding): array
    {
        return $this->fileEntrySubjectComponents($finding->subjectId);
    }

    /** @param list<IdenticalSubExpressionFinding> $findings */
    private function append(array $findings): void
    {
        array_push($this->findings, ...$findings);
    }
}
