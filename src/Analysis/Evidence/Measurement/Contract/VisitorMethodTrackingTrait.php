<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

use PhpParser\Node;

use Qualimetrix\Analysis\Evidence\Measurement\Visitor\VisitorMethodContext;
use Qualimetrix\Core\Path\RelativePath;

/** Routes shared lexical and callable identity through one resettable context. */
trait VisitorMethodTrackingTrait
{
    private ?VisitorMethodContext $visitorMethodContext = null;

    private function resetVisitorMethodContext(): void
    {
        $this->visitorMethodContext = new VisitorMethodContext();
        $this->visitorMethodContext->reset();
    }

    private function visitorMethodContext(): VisitorMethodContext
    {
        return $this->visitorMethodContext ??= new VisitorMethodContext();
    }

    private function enterVisitorMethodContext(Node $node): ?VisitorCallableScope
    {
        return $this->visitorMethodContext()->enter($node);
    }

    private function leaveVisitorMethodContext(Node $node): ?VisitorCallableScope
    {
        return $this->visitorMethodContext()->leave($node);
    }

    private function createCallableWithMetrics(VisitorCallableScope $scope, RelativePath $file, MetricBag $metrics, ?int $ordinal = null): CallableWithMetrics
    {
        return $this->visitorMethodContext()->createCallableWithMetrics($scope, $file, $metrics, $ordinal);
    }

    /**
     * @param array<string, VisitorCallableScope> $scopes
     *
     * @return array<string, int|null>
     */
    private function callableCollisionOrdinals(array $scopes): array
    {
        return $this->visitorMethodContext()->callableCollisionOrdinals($scopes);
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
