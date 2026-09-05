<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

use Qualimetrix\Analysis\Evidence\Measurement\DataBag;

use RuntimeException;

/**
 * Provider-owned metric value bag shared across collection, aggregation, and rules.
 *
 * @qmx-threshold coupling.cbo 68 -- Stable Measurement contract fan-in has raw CBO 67 after the declaration-index delivery edge and one-edge headroom.
 * @qmx-threshold coupling.class-rank warning=0.035 error=0.035 -- Intentional Measurement
 *                contract hub: MetricBag is the shared metric-value carrier nearly every
 *                collector and rule reads, so ClassRank scoring it high is structural, not a
 *                defect (ADR 0017 point 5: a project-normalized rank stands for centrality, not
 *                a ratchetable ceiling -- `coupling.cbo` owns that job). Re-measured at 902
 *                classes: raw rank 0.0095125, scale factor sqrt(902/100)=3.0033. The unscaled
 *                default (0.02) already flags this class -- its scaled warning 0.0066593 is
 *                below the raw rank. This directive's own scaled threshold is 0.0116537, an
 *                18.4% margin over the raw rank, deliberately wider than the class-rank
 *                directive above because MetricBag's rank has room the tightest hub does not.
 *                Both sides move with class count; re-measure rather than trusting either
 *                figure.
 */
final class MetricBag
{
    /** @var array<string, int|float> */
    private array $metrics = [];

    private DataBag $data;

    public function __construct()
    {
        $this->data = DataBag::empty();
    }

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
        $result->data = $this->data;
        $result->metrics[$name] = $value;

        return $result;
    }

    /**
     * Returns a new MetricBag with a structured data entry appended.
     *
     * @param array<string, scalar> $entry
     */
    public function withEntry(string $key, array $entry): self
    {
        $result = new self();
        $result->metrics = $this->metrics;
        $result->data = $this->data->add($key, $entry);

        return $result;
    }

    public function get(string $name): int|float|null
    {
        return $this->metrics[$name] ?? null;
    }

    /**
     * Returns a metric value, throwing if the key does not exist.
     *
     * Use this instead of get() when the metric is expected to always be present
     * (e.g., after aggregation). A missing key indicates a pipeline bug, not an
     * optional metric.
     *
     * @throws RuntimeException if the metric key is not found
     */
    public function require(string $name): int|float
    {
        return $this->metrics[$name] ?? throw new RuntimeException(\sprintf(
            'Required metric "%s" not found in MetricBag. Available keys: %s',
            $name,
            (array_keys($this->metrics) !== [] ? implode(', ', array_keys($this->metrics)) : '(empty)'),
        ));
    }

    public function has(string $name): bool
    {
        return isset($this->metrics[$name]);
    }

    /**
     * Returns structured data entries for the given key.
     *
     * @return list<array<string, scalar>>
     */
    public function entries(string $key): array
    {
        return $this->data->get($key);
    }

    public function entryCount(string $key): int
    {
        return $this->data->count($key);
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
        $result->data = $this->data->merge($other->data);

        return $result;
    }

    /**
     * Returns new MetricBag with prefixed metric names.
     */
    public function withPrefix(string $prefix): self
    {
        $result = new self();
        $result->data = $this->data;

        foreach ($this->metrics as $name => $value) {
            $result->metrics[$prefix . $name] = $value;
        }

        return $result;
    }

    /**
     * @return array{metrics: array<string, int|float>, data?: array<string, list<array<string, scalar>>>}
     */
    public function __serialize(): array
    {
        $result = ['metrics' => $this->metrics];

        if (!$this->data->isEmpty()) {
            $result['data'] = $this->data->all();
        }

        return $result;
    }

    /**
     * @param array{metrics: array<string, int|float>, data?: array<string, list<array<string, scalar>>>} $data
     */
    public function __unserialize(array $data): void
    {
        $this->metrics = $data['metrics'];
        $this->data = isset($data['data']) ? DataBag::fromArray($data['data']) : DataBag::empty();
    }
}
