<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

use PhpParser\Node;

use Qualimetrix\Analysis\Evidence\Measurement\Visitor\VisitorMethodContext;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\FileDeclarationIndex;

/** Routes shared lexical and callable identity through one resettable context. */
trait VisitorMethodTrackingTrait
{
    private ?VisitorMethodContext $visitorMethodContext = null;

    private ?FileDeclarationIndex $declarationIndex = null;

    /**
     * The index arrives once per file from the traversal owner and outlives the
     * per-file reset of the tracking context, so a context rebuilt after
     * delivery still numbers against the same index.
     */
    public function useDeclarationIndex(FileDeclarationIndex $index): void
    {
        $this->declarationIndex = $index;
        $this->visitorMethodContext()->useDeclarationIndex($index);
    }

    private function resetVisitorMethodContext(): void
    {
        $this->visitorMethodContext = null;
    }

    private function visitorMethodContext(): VisitorMethodContext
    {
        if ($this->visitorMethodContext === null) {
            $this->visitorMethodContext = new VisitorMethodContext();
            if ($this->declarationIndex !== null) {
                $this->visitorMethodContext->useDeclarationIndex($this->declarationIndex);
            }
        }

        return $this->visitorMethodContext;
    }

    private function enterVisitorMethodContext(Node $node): ?VisitorCallableScope
    {
        return $this->visitorMethodContext()->enter($node);
    }

    private function leaveVisitorMethodContext(Node $node): ?VisitorCallableScope
    {
        return $this->visitorMethodContext()->leave($node);
    }

    private function createCallableWithMetrics(VisitorCallableScope $scope, RelativePath $file, MetricBag $metrics): CallableWithMetrics
    {
        return $this->visitorMethodContext()->createCallableWithMetrics($scope, $file, $metrics);
    }

    /**
     * @param array<string, mixed> $metrics
     * @param array<string, VisitorCallableScope> $scopes
     *
     * @return array<string, mixed>
     */
    private function projectLogicalMetricMap(array $metrics, array $scopes): array
    {
        return $this->visitorMethodContext()->projectLogicalMetricMap($metrics, $scopes);
    }

    private function currentFileEntrySubjectId(): string
    {
        return $this->visitorMethodContext()->currentFileEntrySubjectId();
    }

    /** @return array<string, int|string> */
    protected function fileEntrySubjectComponents(string $subjectId): array
    {
        return $this->visitorMethodContext()->fileEntrySubjectComponents($subjectId);
    }
}
