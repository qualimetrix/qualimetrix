<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition;

use InvalidArgumentException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevelProjection;
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

    /**
     * The levels this metric reports at.
     *
     * `$levels` names the declaration kinds the rule enumerates subjects
     * over; a consumer asking what the metric *reports* at should not have
     * to know that and re-derive the projection itself.
     *
     * @return list<SymbolLevel>
     */
    public function reportingLevels(): array
    {
        return array_map(SymbolLevelProjection::ofDeclaration(...), $this->levels);
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
