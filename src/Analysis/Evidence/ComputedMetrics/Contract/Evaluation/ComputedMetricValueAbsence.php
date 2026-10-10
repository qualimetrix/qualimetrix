<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation;

use LogicException;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;

/** Nonfailure absences for one computed metric and reporting level. */
final readonly class ComputedMetricValueAbsence
{
    /** @var list<string> */
    public array $missingKeys;
    /** @var list<MetricSubject> */
    public array $subjects;

    /**
     * @param list<string> $missingKeys
     * @param list<MetricSubject> $subjects Exact identities, bounded independently of the subject count
     */
    public function __construct(
        public string $metricName,
        public SymbolLevel $level,
        public int $missingKeysCount = 0,
        public int $noValueCount = 0,
        array $missingKeys = [],
        array $subjects = [],
    ) {
        $keys = array_values(array_unique($missingKeys));
        sort($keys, \SORT_STRING);
        $this->missingKeys = $keys;
        $exact = [];
        foreach ($subjects as $subject) {
            $exact[$subject->toCanonical()] = $subject;
        }
        ksort($exact, \SORT_STRING);
        $this->subjects = array_values(\array_slice($exact, 0, 3));
    }

    public function merge(self $other): self
    {
        if ($this->metricName !== $other->metricName || $this->level !== $other->level) {
            throw new LogicException('Computed absence groups must name the same metric and level.');
        }

        return new self(
            $this->metricName,
            $this->level,
            $this->missingKeysCount + $other->missingKeysCount,
            $this->noValueCount + $other->noValueCount,
            [...$this->missingKeys, ...$other->missingKeys],
            [...$this->subjects, ...$other->subjects],
        );
    }
}
