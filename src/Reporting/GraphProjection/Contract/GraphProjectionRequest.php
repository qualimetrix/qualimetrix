<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\GraphProjection\Contract;

/**
 * Describes the graph representation requested by a delivery adapter.
 *
 * @param array<string>|null $includeNamespaces
 * @param array<string> $excludeNamespaces
 */
final readonly class GraphProjectionRequest
{
    /**
     * @param array<string>|null $includeNamespaces
     * @param array<string> $excludeNamespaces
     */
    public function __construct(
        public string $format = 'dot',
        public string $direction = 'LR',
        public bool $groupByNamespace = true,
        public ?array $includeNamespaces = null,
        public array $excludeNamespaces = [],
    ) {}
}
