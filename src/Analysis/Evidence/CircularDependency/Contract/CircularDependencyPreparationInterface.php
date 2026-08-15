<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CircularDependency\Contract;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;

/**
 * Prepares circular-dependency evidence for one analysis run.
 *
 * The named consumer is Analysis.Run, which selects this preparation by the
 * producer rule name but never receives the prepared cycle result.
 */
interface CircularDependencyPreparationInterface
{
    public const string PRODUCER_RULE_NAME = 'architecture.circular-dependency';

    public function prepare(DependencyGraphInterface $graph): void;

    public function reset(): void;
}
