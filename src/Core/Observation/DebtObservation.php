<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Observation;

use InvalidArgumentException;

/**
 * The measured debt behind one emitted finding.
 *
 * A rule attaches one of these to every {@see \Qualimetrix\Core\Violation\Violation}
 * it emits. It answers "how much debt is this, on which dimensions, under
 * which contract, and which of several findings on this symbol is it" — the
 * questions `Violation::$metricValue` cannot answer, because different rules
 * put different things in it (a criteria count, a cycle size, a
 * display-rounded value, one axis of a multi-axis decision).
 *
 * This type names measured debt, not the feature that consumes it. A rule
 * does not know about baselines; it reports what it measured.
 *
 * Axes are stored keyed by name and sorted, so two observations built from
 * the same measurements in different orders are byte-identical once
 * serialized.
 */
final readonly class DebtObservation
{
    /**
     * Axis name → observation, sorted by name.
     *
     * @var array<string, AxisObservation>
     */
    public array $axes;

    /**
     * @param list<AxisObservation> $axes Unique by name; order is irrelevant.
     * @param ?OccurrenceKey $occurrenceKey Null means "this channel offers no
     *                                      stable discriminator" — see
     *                                      {@see OccurrenceKey} for why that is
     *                                      the only absence that exists.
     */
    public function __construct(
        public ContractReference $contract,
        public ObservationKind $kind,
        array $axes = [],
        public ?OccurrenceKey $occurrenceKey = null,
    ) {
        $byName = [];
        foreach ($axes as $axis) {
            if (isset($byName[$axis->name])) {
                throw new InvalidArgumentException(
                    \sprintf(
                        'DebtObservation for contract "%s" declares axis "%s" more than once.',
                        $contract->id,
                        $axis->name,
                    ),
                );
            }

            $byName[$axis->name] = $axis;
        }

        ksort($byName, \SORT_STRING);

        $this->assertAxisCount($byName);

        if ($kind->requiresOccurrenceKey() && $occurrenceKey === null) {
            throw new InvalidArgumentException(
                \sprintf(
                    'DebtObservation for contract "%s" of kind "%s" requires an occurrence key: its identity '
                    . 'spans several symbols and must be canonical and traversal-order independent.',
                    $contract->id,
                    $kind->value,
                ),
            );
        }

        if (!$kind->permitsOccurrenceKey() && $occurrenceKey !== null) {
            throw new InvalidArgumentException(
                \sprintf(
                    'DebtObservation for contract "%s" of kind "%s" must not carry an occurrence key: '
                    . 'its comparison never consults one.',
                    $contract->id,
                    $kind->value,
                ),
            );
        }

        $this->axes = $byName;
    }

    public static function scalar(
        ContractReference $contract,
        AxisObservation $axis,
        ?OccurrenceKey $occurrenceKey = null,
    ): self {
        return new self($contract, ObservationKind::Scalar, [$axis], $occurrenceKey);
    }

    /**
     * @param list<AxisObservation> $axes At least two.
     */
    public static function vector(
        ContractReference $contract,
        array $axes,
        ?OccurrenceKey $occurrenceKey = null,
    ): self {
        return new self($contract, ObservationKind::Vector, $axes, $occurrenceKey);
    }

    /**
     * @param list<AxisObservation> $axes Optional: an occurrence may also carry a magnitude.
     */
    public static function occurrence(
        ContractReference $contract,
        ?OccurrenceKey $occurrenceKey = null,
        array $axes = [],
    ): self {
        return new self($contract, ObservationKind::Occurrence, $axes, $occurrenceKey);
    }

    /**
     * A `Presence` observation carries neither axes nor an occurrence key:
     * §7.3 of the ratchet-baseline plan compares presence findings by
     * identity present / new / missing, never by consulting either. There is
     * no parameter to hide here, unlike {@see occurrence()} and
     * {@see graph()} — the shape genuinely has nothing beyond the contract.
     */
    public static function presence(ContractReference $contract): self
    {
        return new self($contract, ObservationKind::Presence, [], null);
    }

    /**
     * @param list<AxisObservation> $axes Optional magnitude alongside the graph identity.
     */
    public static function graph(
        ContractReference $contract,
        OccurrenceKey $occurrenceKey,
        array $axes = [],
    ): self {
        return new self($contract, ObservationKind::Graph, $axes, $occurrenceKey);
    }

    public function axis(string $name): ?AxisObservation
    {
        return $this->axes[$name] ?? null;
    }

    /**
     * @return list<string> Axis names in canonical (sorted) order.
     */
    public function axisNames(): array
    {
        return array_keys($this->axes);
    }

    /**
     * Whether this observation is individually addressable across runs.
     *
     * False means its channel offers no stable discriminator, so it is
     * matched as part of a counted bucket under its symbol.
     */
    public function hasOccurrenceKey(): bool
    {
        return $this->occurrenceKey !== null;
    }

    /**
     * @param array<string, AxisObservation> $axes
     */
    private function assertAxisCount(array $axes): void
    {
        $count = \count($axes);
        $minimum = $this->kind->minimumAxes();
        $maximum = $this->kind->maximumAxes();

        if ($count < $minimum) {
            throw new InvalidArgumentException(
                \sprintf(
                    'DebtObservation for contract "%s" of kind "%s" requires at least %d axis/axes, got %d.',
                    $this->contract->id,
                    $this->kind->value,
                    $minimum,
                    $count,
                ),
            );
        }

        if ($maximum !== null && $count > $maximum) {
            throw new InvalidArgumentException(
                \sprintf(
                    'DebtObservation for contract "%s" of kind "%s" allows at most %d axis/axes, got %d.',
                    $this->contract->id,
                    $this->kind->value,
                    $maximum,
                    $count,
                ),
            );
        }
    }
}
