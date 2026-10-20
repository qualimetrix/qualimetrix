<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Core\Symbol\SymbolPath;

/** A class's boundary-crossing dependencies, dependents and their distinct degrees. */
final readonly class ClassCouplings
{
    /** @param array<string, array{dependencies: array<Dependency>, dependents: array<Dependency>, ce: int, ca: int}> $byClass */
    private function __construct(private array $byClass) {}

    /**
     * @param array<string, array<Dependency>> $bySource
     * @param array<string, array<Dependency>> $byTarget
     * @param array<string, int> $classCe
     * @param array<string, int> $classCa
     */
    public static function fromClassViews(array $bySource, array $byTarget, array $classCe, array $classCa): self
    {
        $rows = [];
        foreach ([$bySource, $byTarget, $classCe, $classCa] as $view) {
            foreach (array_keys($view) as $canonical) {
                $rows[$canonical] ??= [
                    'dependencies' => $bySource[$canonical] ?? [],
                    'dependents' => $byTarget[$canonical] ?? [],
                    'ce' => $classCe[$canonical] ?? 0,
                    'ca' => $classCa[$canonical] ?? 0,
                ];
            }
        }

        return new self($rows);
    }

    /** @return array<Dependency> */
    public function dependencies(SymbolPath $class): array
    {
        return $this->byClass[$class->toCanonical()]['dependencies'] ?? [];
    }

    /** @return array<Dependency> */
    public function dependents(SymbolPath $class): array
    {
        return $this->byClass[$class->toCanonical()]['dependents'] ?? [];
    }

    public function ce(SymbolPath $class): int
    {
        return $this->byClass[$class->toCanonical()]['ce'] ?? 0;
    }

    public function ca(SymbolPath $class): int
    {
        return $this->byClass[$class->toCanonical()]['ca'] ?? 0;
    }
}
