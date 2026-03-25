<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Collection\Dependency\Export;

/**
 * Configuration options for DotExporter.
 *
 * @param string $direction Graph layout direction: LR (left-to-right), TB (top-to-bottom), RL, BT
 * @param bool $groupByNamespace Whether to group nodes by namespace using subgraphs
 * @param bool $shortLabels Whether to use short class names instead of full FQN
 * @param bool $colorByInstability Whether to color nodes by instability metric (green=stable, red=unstable)
 * @param array<string>|null $includeNamespaces Only include classes from these namespaces (null = all)
 * @param array<string> $excludeNamespaces Exclude classes from these namespaces
 */
final readonly class DotExporterOptions
{
    /**
     * @param array<string>|null $includeNamespaces
     * @param array<string> $excludeNamespaces
     */
    public function __construct(
        public string $direction = 'LR',
        public bool $groupByNamespace = true,
        public bool $shortLabels = true,
        public bool $colorByInstability = true,
        public ?array $includeNamespaces = null,
        public array $excludeNamespaces = [],
    ) {}
}
