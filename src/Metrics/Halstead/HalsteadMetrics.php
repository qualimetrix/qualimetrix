<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Halstead;

/**
 * Value object containing Halstead complexity metrics.
 *
 * Halstead metrics measure program complexity based on the count of
 * distinct and total operators/operands in the code.
 *
 * Core measurements:
 * - n1: number of unique operators
 * - n2: number of unique operands
 * - N1: total count of operators
 * - N2: total count of operands
 *
 * Derived metrics are calculated from these base values using
 * established formulas from Maurice Halstead's Science of Software.
 */
final readonly class HalsteadMetrics
{
    /**
     * @param int $n1 Number of unique operators
     * @param int $n2 Number of unique operands
     * @param int $N1 Total count of operators
     * @param int $N2 Total count of operands
     */
    public function __construct(
        public int $n1,
        public int $n2,
        public int $N1,
        public int $N2,
    ) {}

    /**
     * Program vocabulary: total number of unique elements.
     *
     * η = n1 + n2
     */
    public function vocabulary(): int
    {
        return $this->n1 + $this->n2;
    }

    /**
     * Program length: total number of elements.
     *
     * N = N1 + N2
     */
    public function length(): int
    {
        return $this->N1 + $this->N2;
    }

    /**
     * Program volume: information content.
     *
     * V = N × log₂(η)
     *
     * Represents the minimum number of bits needed to encode the program.
     * Returns 0 if vocabulary is 0 (empty method).
     */
    public function volume(): float
    {
        $vocabulary = $this->vocabulary();

        if ($vocabulary <= 0) {
            return 0.0;
        }

        return $this->length() * log($vocabulary, 2);
    }

    /**
     * Program difficulty: error-proneness.
     *
     * D = (n1/2) × (N2/n2)
     *
     * Measures how difficult the program is to write and understand.
     * Returns 0 if n2 is 0 (no operands).
     */
    public function difficulty(): float
    {
        if ($this->n2 <= 0 || $this->n1 <= 0) {
            return 0.0;
        }

        return ($this->n1 / 2.0) * ($this->N2 / $this->n2);
    }

    /**
     * Program effort: mental effort required.
     *
     * E = D × V
     *
     * Represents the total mental effort required to implement the program.
     */
    public function effort(): float
    {
        return $this->difficulty() * $this->volume();
    }

    /**
     * Estimated number of bugs.
     *
     * B = V / 3000
     *
     * Based on industry studies, approximately one bug per 3000 volume units.
     */
    public function bugs(): float
    {
        return $this->volume() / 3000.0;
    }

    /**
     * Estimated time to implement (seconds).
     *
     * T = E / 18
     *
     * Based on psychological studies of programmer cognition.
     * Stroud number: 18 elementary mental discriminations per second.
     */
    public function time(): float
    {
        return $this->effort() / 18.0;
    }

    /**
     * Creates metrics for an empty method.
     */
    public static function empty(): self
    {
        return new self(0, 0, 0, 0);
    }

    /**
     * Checks if this represents an empty method (no operators or operands).
     */
    public function isEmpty(): bool
    {
        return $this->N1 === 0 && $this->N2 === 0;
    }
}
