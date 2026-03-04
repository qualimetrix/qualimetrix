<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Metric;

final class MetricBag
{
    /** @var array<string, int|float> */
    private array $metrics = [];

    /**
     * Creates a MetricBag from an array of metrics.
     *
     * @param array<string, int|float> $metrics
     */
    public static function fromArray(array $metrics): self
    {
        $result = new self();
        $result->metrics = $metrics;

        return $result;
    }

    /**
     * Returns a new MetricBag with the given metric set.
     */
    public function with(string $name, int|float $value): self
    {
        $result = new self();
        $result->metrics = $this->metrics;
        $result->metrics[$name] = $value;

        return $result;
    }

    public function get(string $name): int|float|null
    {
        return $this->metrics[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->metrics[$name]);
    }

    /**
     * @return array<string, int|float>
     */
    public function all(): array
    {
        return $this->metrics;
    }

    /**
     * Merges metrics from another bag.
     * Values from $other override values in this bag on key conflict.
     */
    public function merge(self $other): self
    {
        $result = new self();
        $result->metrics = array_merge($this->metrics, $other->metrics);

        return $result;
    }

    /**
     * Returns new MetricBag with prefixed metric names.
     */
    public function withPrefix(string $prefix): self
    {
        $result = new self();

        foreach ($this->metrics as $name => $value) {
            $result->metrics[$prefix . $name] = $value;
        }

        return $result;
    }

    /**
     * @return array{metrics: array<string, int|float>}
     */
    public function __serialize(): array
    {
        return ['metrics' => $this->metrics];
    }

    /**
     * @param array{metrics: array<string, int|float>} $data
     */
    public function __unserialize(array $data): void
    {
        $this->metrics = $data['metrics'];
    }
}
