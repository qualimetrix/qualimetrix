<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Population;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelPublication;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Population\PopulationSession;
use Qualimetrix\Analysis\Finding\Population\PopulationTrace;
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

    /** @internal Finding accounting only */
    public static function fromTrace(PopulationTrace $trace): self
    {
        $summary = $trace->summary();
        if ($summary['judged'] === [] && $summary['abstentions'] === []) {
            return self::empty();
        }
        return new self([['token' => new stdClass(), ...$summary]]);
    }

    /** @param iterable<array{identity: PopulationIdentity, inputs: iterable<GateInput>}> $members */
    public static function measure(
        ChannelPublication $publication,
        string $producer,
        FindingChannel $channel,
        SymbolLevel $level,
        ChannelDeclaration $declaration,
        iterable $members,
        ?string $addressedProducer = null,
    ): self {
        if (!$publication->publishes($producer, $channel, $level, $addressedProducer)) {
            return self::empty();
        }
        $session = new PopulationSession($publication, $addressedProducer);
        foreach ($members as $member) {
            $failed = $declaration->populationFailure($channel, $level, $member['identity'], $member['inputs']);
            $session->record($producer, $channel, $level, $member['identity'], $declaration, $failed['gate'] ?? null, $failed['reason'] ?? null);
            unset($member);
        }
        unset($members);
        return $session->freeze();
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
                $key = json_encode([$absence->producer, $absence->channel->code, $absence->level->value, $absence->gate, $absence->reason, $absence->unit], \JSON_THROW_ON_ERROR);
                $previous = $groups[$key] ?? null;
                $examples = array_values(array_unique([...($previous->examples ?? []), ...$absence->examples]));
                sort($examples, \SORT_STRING);
                $groups[$key] = new RuleAbstention($absence->producer, $absence->channel, $absence->level, $absence->gate, $absence->reason, $absence->unit, ($previous->count ?? 0) + $absence->count, \array_slice($examples, 0, 5));
            }
        }
        ksort($groups, \SORT_STRING);
        return array_values($groups);
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
        ksort($groups, \SORT_STRING);
        return array_values($groups);
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
