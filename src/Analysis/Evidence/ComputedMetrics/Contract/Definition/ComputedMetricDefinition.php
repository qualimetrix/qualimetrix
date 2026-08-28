<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition;

use InvalidArgumentException;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Finding\ComputedMetricChannelFamily;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Core\Symbol\SymbolLevel;

final readonly class ComputedMetricDefinition
{
    /** `health.` or `computed.`, then one or more lower-case kebab segments. */
    private const string NAME_TEMPLATE = '/^(?:health|computed)(?:\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*)+$/';

    /**
     * @param array<string, string> $formulas Keys: 'class', 'namespace', 'project'
     * @param list<SymbolLevel> $levels
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
    public function getFormulaForLevel(SymbolLevel $level): ?string
    {
        // A formula key is the level word itself; the levels this capability
        // has no formula for are the ones it does not report at.
        $key = match ($level) {
            SymbolLevel::Class_, SymbolLevel::Namespace_, SymbolLevel::Project => $level->value,
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
        if ($level === SymbolLevel::Project && isset($this->formulas[SymbolLevel::Namespace_->value])) {
            return $this->formulas[SymbolLevel::Namespace_->value];
        }

        return null;
    }

    /**
     * The levels this metric reports at.
     *
     * Kept as an operation rather than left to the property so that the
     * question a consumer asks — what does this metric report at? — has one
     * answer regardless of how the definition happens to store it.
     *
     * @return list<SymbolLevel>
     */
    public function reportingLevels(): array
    {
        return $this->levels;
    }

    public function hasLevel(SymbolLevel $level): bool
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
        $values = array_map(static fn(SymbolLevel $level): string => $level->value, $this->levels);

        if (\count(array_unique($values)) !== \count($values)) {
            throw new InvalidArgumentException(
                \sprintf('Computed metric "%s" declares the same level more than once', $this->name),
            );
        }
    }

    /**
     * The whole name is checked against one template, not the family alone.
     *
     * `_` is gone from the grammar with the encoding that required it: a name
     * had to double as an Expression Language identifier, where a dot is
     * illegal, so `a.b` travelled as `a__b` and the segment grammar had to
     * admit `_`. A formula now addresses a metric by its published key, and
     * that key is kebab like every other name the product publishes.
     *
     * Checking the template as a whole is what closes the Ш5e2 gap: a name
     * whose family was right and whose leaf was not — `computed.Branch_Load` —
     * used to pass and then fall out of its own group in the report.
     */
    private function validateName(): void
    {
        if (preg_match(self::NAME_TEMPLATE, $this->name) === 1 && !self::endsInAnAggregationStrategy($this->name)) {
            return;
        }

        throw new InvalidArgumentException(\sprintf(
            'Computed metric name "%s" must be "health.<name>" or "computed.<name>", where every segment is'
            . ' lower-case kebab (%s) and the last segment is not the name of an aggregation strategy',
            $this->name,
            self::NAME_TEMPLATE,
        ));
    }

    /**
     * A user-chosen name is the one key the product does not declare, so the
     * invariant every declared key is held to has to be held here too:
     * `computed.sum` would be indistinguishable from an aggregated spelling of
     * `computed`, and `MetricName::base()` would cut the last segment off it.
     * Asked through `base()` itself, so the strategy list has one reader.
     */
    private static function endsInAnAggregationStrategy(string $name): bool
    {
        return MetricName::base($name) !== $name;
    }

}
