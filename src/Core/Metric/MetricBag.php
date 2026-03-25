<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Metric;

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
     * Restores MetricBag from storage format (includes both metrics and structured data).
     *
     * @param array<string, mixed> $data
     */
    public static function fromStorageArray(array $data): self
    {
        $result = new self();

        if (isset($data['__entries']) && \is_array($data['__entries'])) {
            /** @var array<string, list<array<string, scalar>>> $entries */
            $entries = $data['__entries'];
            $result->data = DataBag::fromArray($entries);
            unset($data['__entries']);
        }

        /** @var array<string, int|float> $data */
        $result->metrics = $data;

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

    public function dataBag(): DataBag
    {
        return $this->data;
    }

    /**
     * @return array<string, int|float>
     */
    public function all(): array
    {
        return $this->metrics;
    }

    /**
     * Returns storage-friendly array including both metrics and structured data.
     * Uses '__entries' as a reserved key for DataBag data — metric names must not use this key.
     *
     * @return array<string, mixed>
     */
    public function toStorageArray(): array
    {
        $result = $this->metrics;

        if (!$this->data->isEmpty()) {
            $result['__entries'] = $this->data->all();
        }

        return $result;
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
