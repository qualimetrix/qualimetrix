<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract;

use PhpParser\Node;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;

interface SourceControlExtractorInterface
{
    /**
     * @param array<Node> $ast
     * @param list<CallableWithMetrics> $callableMetrics
     * @param array<string, array{subject: MetricSubject, metrics: MetricBag, line: int, start: int}> $classMetrics
     */
    public function extract(array $ast, RelativePath $file, array $callableMetrics, array $classMetrics): SourceControls;
}
