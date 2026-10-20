<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Core\Symbol\ClassNameSpelling;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;

final readonly class OffenderPopulation
{
    /** @var array<string, list<SymbolInfo>> */
    private array $classesByNamespace;

    /** @param list<Finding> $findings */
    public function __construct(public MetricRepositoryInterface $repository, public NamespaceTree $tree, public array $findings)
    {
        $classesByNamespace = [];
        foreach ($repository->allClassDeclarations() as $type) {
            if ($type->symbolPath->type !== null && $type->symbolPath->member === null) {
                $classesByNamespace[ClassNameSpelling::fold($type->symbolPath->namespace ?? '')][] = $type;
            }
        }
        $this->classesByNamespace = $classesByNamespace;
    }

    /** @return iterable<SymbolInfo> */
    public function symbols(SymbolLevel $level): iterable
    {
        if ($level !== SymbolLevel::Class_) {
            yield from $this->repository->all($level);
            return;
        }
        foreach ($this->classesByNamespace as $classes) {
            yield from $classes;
        }
    }

    public function declaresType(string $namespace): bool
    {
        return isset($this->classesByNamespace[ClassNameSpelling::fold($namespace)]);
    }
}
