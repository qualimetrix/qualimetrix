<?php

declare(strict_types=1);

namespace Qualimetrix\Core\ComputedMetric;

use InvalidArgumentException;
use Qualimetrix\Core\Symbol\SymbolType;

final readonly class ComputedMetricDefinition
{
    /**
     * @param array<string, string> $formulas Keys: 'class', 'namespace', 'project'
     * @param list<SymbolType> $levels
     */
    public function __construct(
        public string $name,
        public array $formulas,
        public string $description,
        public array $levels,
        public bool $inverted = false,
        public ?float $warningThreshold = null,
        public ?float $errorThreshold = null,
    ) {
        $this->validateName($name);
    }

    /**
     * Gets formula for the given level.
     * Project inherits from namespace if not explicitly set.
     * Class must have explicit formula.
     */
    public function getFormulaForLevel(SymbolType $level): ?string
    {
        $key = match ($level) {
            SymbolType::Class_ => 'class',
            SymbolType::Namespace_ => 'namespace',
            SymbolType::Project => 'project',
            default => null,
        };

        if ($key === null) {
            return null;
        }

        // Direct lookup
        if (isset($this->formulas[$key])) {
            return $this->formulas[$key];
        }

        // Project inherits from namespace
        if ($key === 'project' && isset($this->formulas['namespace'])) {
            return $this->formulas['namespace'];
        }

        return null;
    }

    public function hasLevel(SymbolType $level): bool
    {
        return \in_array($level, $this->levels, true);
    }

    private function validateName(string $name): void
    {
        // No double underscores (reserved for variable mapping)
        if (str_contains($name, '__')) {
            throw new InvalidArgumentException(
                \sprintf('Computed metric name "%s" must not contain "__" (reserved for variable mapping)', $name),
            );
        }

        // Must match health.* or computed.* prefix
        if (!str_starts_with($name, 'health.') && !str_starts_with($name, 'computed.')) {
            throw new InvalidArgumentException(
                \sprintf('Computed metric name "%s" must start with "health." or "computed."', $name),
            );
        }

        // Validate segment grammar: prefix.identifier(.identifier)*
        // Each identifier: [a-zA-Z][a-zA-Z0-9_]*
        $segments = explode('.', $name);
        foreach ($segments as $i => $segment) {
            if ($i === 0) {
                // First segment is prefix -- already validated above
                continue;
            }
            if (preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $segment) !== 1) {
                throw new InvalidArgumentException(
                    \sprintf(
                        'Computed metric name segment "%s" in "%s" must match [a-zA-Z][a-zA-Z0-9_]*',
                        $segment,
                        $name,
                    ),
                );
            }
        }
    }
}
