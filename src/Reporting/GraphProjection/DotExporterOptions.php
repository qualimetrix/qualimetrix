<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\GraphProjection;

use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Reporting\GraphProjection\Contract\GraphDirection;

/**
 * Configuration options for DotExporter.
 *
 * @param GraphDirection $direction Graph layout direction
 * @param bool $groupByNamespace Whether to group nodes by namespace using subgraphs
 * @param bool $shortLabels Whether to use short class names instead of full FQN
 * @param bool $colorByInstability Whether to color nodes by instability metric (green=stable, red=unstable)
 * @param list<NamespacePattern>|null $includeNamespaces Only include classes from these namespaces (null = all)
 * @param list<NamespacePattern> $excludeNamespaces Exclude classes from these namespaces
 */
final readonly class DotExporterOptions
{
    /**
     * @param list<NamespacePattern>|null $includeNamespaces
     * @param list<NamespacePattern> $excludeNamespaces
     */
    public function __construct(
        public GraphDirection $direction = GraphDirection::LR,
        public bool $groupByNamespace = true,
        public bool $shortLabels = true,
        public bool $colorByInstability = true,
        public ?array $includeNamespaces = null,
        public array $excludeNamespaces = [],
    ) {}
}
