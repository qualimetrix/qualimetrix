<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

use PhpParser\Node;
use Qualimetrix\Core\Path\RelativePath;
use SplFileInfo;

interface FileMeasurementCollectorInterface
{
    public function reset(): void;

    /** @param array<Node> $nodes */
    public function collect(SplFileInfo $file, array $nodes, RelativePath $filePath): CollectionOutput;

    /** @return list<MetricCollectorInterface> */
    public function getCollectors(): array;

    /** @return list<DerivedCollectorInterface> */
    public function getDerivedCollectors(): array;
}
