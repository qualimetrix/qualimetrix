<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Repository;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassKeyScope;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Core\Symbol\SymbolLevel;

/** Finite class-key scopes shared by the exact and logical class stores of one repository. */
final class ClassMetricScopeRegistry
{
    /** @var array<string, ClassKeyScope> */
    private array $scopes = [];

    /** @param list<MetricDefinition> $definitions */
    public function __construct(array $definitions = [])
    {
        foreach ($definitions as $definition) {
            if (\in_array(SymbolLevel::Class_, $definition->publicationLevels(), true)) {
                $this->register($definition->name, $definition->classKeyScope ?? ClassKeyScope::Declaration);
            }
            if ($definition->hasAggregationsForLevel(SymbolLevel::Class_)) {
                foreach ($definition->publishedSuffixes(SymbolLevel::Class_) as $suffix) {
                    $this->register($definition->name . '.' . $suffix, ClassKeyScope::Declaration);
                }
            }
        }
    }

    public function assertKey(string $key, ClassKeyScope $scope): void
    {
        if (($this->scopes[$key] ?? null) !== $scope) {
            throw new LogicException(\sprintf('Metric "%s" is not declared for the requested class scope', $key));
        }
    }

    public function assertBag(MetricBag $bag, ClassKeyScope $scope): void
    {
        foreach (array_keys($bag->all()) as $key) {
            $this->assertKey($key, $scope);
        }
    }

    public function mergeWith(self $other): self
    {
        $merged = new self();
        foreach ($this->scopes as $key => $scope) {
            $merged->register($key, $scope);
        }
        foreach ($other->scopes as $key => $scope) {
            $merged->register($key, $scope);
        }

        return $merged;
    }

    private function register(string $key, ClassKeyScope $scope): void
    {
        if (isset($this->scopes[$key]) && $this->scopes[$key] !== $scope) {
            throw new LogicException(\sprintf('Conflicting class scope for metric "%s"', $key));
        }
        $this->scopes[$key] = $scope;
    }
}
