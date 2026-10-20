<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Contract;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Core\Symbol\SymbolPath;

interface LayerAssignmentInspectorInterface
{
    /** @param iterable<SymbolPath> $classUniverse */
    /**
     * @param iterable<SymbolPath> $classUniverse
     *
     * @qmx-ignore code-smell.boolean-argument -- policyDisabled is a required resolved policy fact in the returned assignment.
     */
    public function inspect(
        DependencyGraphInterface $graph,
        iterable $classUniverse,
        SymbolPath $subject,
        bool $policyDisabled,
    ): LayerAssignment;
}
