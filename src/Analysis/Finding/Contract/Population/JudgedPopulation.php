<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Population;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\Channel\FindingChannel;
use Qualimetrix\Core\Symbol\SymbolLevel;
use stdClass;

final readonly class JudgedPopulation
{
    /** @param list<array{token: object, judged: array<string, array{producer: string, channel: FindingChannel, level: SymbolLevel, unit: string, count: int}>, abstentions: list<RuleAbstention>}> $partitions */
    private function __construct(private array $partitions) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @internal Finding accounting only
     *
     * @param array{judged: array<string, array{producer: string, channel: FindingChannel, level: SymbolLevel, unit: string, count: int}>, abstentions: list<RuleAbstention>} $summary
     */
    public static function fromSummary(array $summary): self
    {
        if ($summary['judged'] === [] && $summary['abstentions'] === []) {
            return self::empty();
        }
        return new self([['token' => new stdClass(), ...$summary]]);
    }

    public function merge(self $other): self
    {
        $partitions = $this->partitions;
        foreach ($other->partitions as $incoming) {
            foreach ($partitions as $existing) {
                if ($existing['token'] === $incoming['token']) {
                    if ($existing !== $incoming) {
                        throw new LogicException('Conflicting immutable population partition.');
                    }
                    continue 2;
                }
            }
            $partitions[] = $incoming;
        }
        return new self($partitions);
    }

    /** @return list<RuleAbstention> */
    public function abstentions(): array
    {
        $groups = [];
        foreach ($this->partitions as $partition) {
            foreach ($partition['abstentions'] as $absence) {
                $key = \strlen($absence->producer) . ":" . $absence->producer . \strlen($absence->channel->code) . ":" . $absence->channel->code . "\0" . $absence->level->value . "\0" . \strlen($absence->gate) . ":" . $absence->gate . "\0" . $absence->unit . "\0" . ($absence->dataGap ? "1" : "0") . $absence->reason;
                $previous = $groups[$key] ?? null;
                $examples = array_values(array_unique([...($previous->examples ?? []), ...$absence->examples]));
                sort($examples, \SORT_STRING);
                $groups[$key] = new RuleAbstention($absence->producer, $absence->channel, $absence->level, $absence->gate, $absence->reason, $absence->unit, ($previous->count ?? 0) + $absence->count, \array_slice($examples, 0, 5), $absence->dataGap);
            }
        }
        $ordered = array_values($groups);
        usort($ordered, static fn(RuleAbstention $left, RuleAbstention $right): int => self::compareCoordinates(
            [$left->producer, $left->channel->code, $left->level->value, $left->gate, $left->reason, $left->unit, $left->dataGap ? '1' : '0'],
            [$right->producer, $right->channel->code, $right->level->value, $right->gate, $right->reason, $right->unit, $right->dataGap ? '1' : '0'],
        ));
        return $ordered;
    }

    /** @return list<array{producer: string, channel: FindingChannel, level: SymbolLevel, unit: string, count: int}> */
    public function judgedCounts(): array
    {
        $groups = [];
        foreach ($this->partitions as $partition) {
            foreach ($partition['judged'] as $key => $group) {
                $group['count'] += $groups[$key]['count'] ?? 0;
                $groups[$key] = $group;
            }
        }
        $ordered = array_values($groups);
        usort($ordered, static fn(array $left, array $right): int => self::compareCoordinates(
            [$left['producer'], $left['channel']->code, $left['level']->value, $left['unit']],
            [$right['producer'], $right['channel']->code, $right['level']->value, $right['unit']],
        ));
        return $ordered;
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     */
    private static function compareCoordinates(array $left, array $right): int
    {
        foreach ($left as $index => $field) {
            $comparison = strcmp($field, $right[$index]);
            if ($comparison !== 0) {
                return $comparison;
            }
        }
        return 0;
    }

    public function judgedCount(): int
    {
        return array_sum(array_column($this->judgedCounts(), 'count'));
    }

    public function unjudgedCount(): int
    {
        return array_sum(array_map(static fn(RuleAbstention $group): int => $group->count, $this->abstentions()));
    }

    public function isEmpty(): bool
    {
        return $this->partitions === [];
    }
}
