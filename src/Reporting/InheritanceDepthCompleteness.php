<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;

/** A publication snapshot of incomplete collected DIT values, independent of rule judgement. */
final readonly class InheritanceDepthCompleteness
{
    /** @param list<array{status: string, count: int, examples: list<string>}> $outcomes */
    private function __construct(public array $outcomes) {}

    public static function fromRepository(?MetricRepositoryInterface $metrics): self
    {
        if ($metrics === null) {
            return new self([]);
        }
        $subjects = ['lower-bound' => [], 'absent-depth' => []];
        foreach ($metrics->allClassDeclarations() as $class) {
            $subject = $class->subject ?? throw new LogicException('Class measurements require an exact declaration subject');
            $bag = $metrics->getSubject($subject);
            if ($bag->get(MetricName::DESIGN_DIT_UNRESOLVED) !== 1) {
                continue;
            }
            $status = $bag->get(MetricName::DESIGN_DIT) === null ? 'absent-depth' : 'lower-bound';
            $subjects[$status][$subject->toCanonical()] = true;
        }
        $outcomes = [];
        foreach ($subjects as $status => $members) {
            if ($members === []) {
                continue;
            }
            $examples = array_keys($members);
            sort($examples, \SORT_STRING);
            $outcomes[] = ['status' => $status, 'count' => \count($members), 'examples' => \array_slice($examples, 0, 5)];
        }

        return new self($outcomes);
    }

    /** @return list<string> */
    public function lines(): array
    {
        return array_map(static fn(array $outcome): string => \sprintf(
            'DIT measurement incomplete: %s for %d class declaration(s); examples: %s',
            $outcome['status'],
            $outcome['count'],
            implode(', ', $outcome['examples']),
        ), $this->outcomes);
    }
}
