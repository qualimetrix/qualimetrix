<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition;

use InvalidArgumentException;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Finding\ComputedMetricChannelFamily;
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
        $this->validateName();
        $this->validateLevels();
    }

    /**
     * The producer this metric's findings are published under.
     *
     * Answered by the definition rather than looked up by each consumer: a
     * built-in health dimension is its own producer and a user-defined metric
     * belongs to the open one, and every seam that needs the distinction —
     * emission, the channel universe's forward and reverse lookups, the
     * run-time name-collision guard — would otherwise apply
     * {@see ComputedMetricChannelFamily::producerFor()} itself and take a
     * dependency on this capability's naming rule to do it.
     */
    public function producerRuleName(): string
    {
        return ComputedMetricChannelFamily::producerFor($this->name);
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

    /**
     * A metric reports at a level once or not at all.
     *
     * Checked here, beside {@see validateName()}, because it is an invariant
     * of the definition rather than of any one consumer: a repeat is a
     * mistake in the configuration that produced it, and the channel
     * declaration built downstream has no way to express "class, twice". It
     * used to reach that declaration and throw from a lookup every finding
     * makes.
     *
     * Reads the object's own state rather than taking it as arguments: both
     * validators are statements about *this* definition, and promoted
     * properties are already assigned when the constructor body runs.
     */
    private function validateLevels(): void
    {
        $values = array_map(static fn(SymbolType $level): string => $level->value, $this->levels);

        if (\count(array_unique($values)) !== \count($values)) {
            throw new InvalidArgumentException(
                \sprintf('Computed metric "%s" declares the same level more than once', $this->name),
            );
        }
    }

    private function validateName(): void
    {
        $name = $this->name;

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
