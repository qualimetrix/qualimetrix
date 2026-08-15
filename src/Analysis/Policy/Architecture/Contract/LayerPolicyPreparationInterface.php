<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Contract;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;

interface LayerPolicyPreparationInterface
{
    public const string PRODUCER_RULE_NAME = 'architecture.layer-violation';

    /** @param iterable<\Qualimetrix\Core\Symbol\SymbolPath> $classUniverse */
    public function prepare(DependencyGraphInterface $graph, iterable $classUniverse): void;

    /** Clears prepared run state without traversing the class universe. */
    public function reset(): void;
}
