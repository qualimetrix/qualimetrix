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
     * `$source` holds the exact bytes `$ast` was parsed from. The AST alone
     * does not carry every comment: php-parser attaches none to a comment
     * written between a declaration's attributes and the declaration.
     *
     * @param array<Node> $ast
     * @param list<CallableWithMetrics> $callableMetrics
     * @param array<string, array{subject: MetricSubject, metrics: MetricBag, line: int, start: int}> $classMetrics
     */
    public function extract(array $ast, string $source, RelativePath $file, array $callableMetrics, array $classMetrics): SourceControls;
}
