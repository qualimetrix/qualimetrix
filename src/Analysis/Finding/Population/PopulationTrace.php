<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Population;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Population\JudgedPopulation;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity;
use Qualimetrix\Analysis\Finding\Contract\Population\RuleAbstention;
use Qualimetrix\Core\Symbol\SymbolLevel;

final class PopulationTrace
{
    /** @var array<string, array{producer: string, channel: FindingChannel, level: SymbolLevel, unit: string, count: int}> */
    private array $judged = [];

    /** @var array<string, RuleAbstention> */
    private array $abstentions = [];

    private ?JudgedPopulation $frozen = null;

    public function record(string $producer, FindingChannel $channel, SymbolLevel $level, PopulationIdentity $identity, ?string $gate, ?string $reason): void
    {
        if ($this->frozen !== null) {
            throw new LogicException('Cannot record into a frozen population.');
        }
        if ($gate === null) {
            $key = json_encode([$producer, $channel->code, $level->value, $identity->unit], \JSON_THROW_ON_ERROR);
            $this->judged[$key] = ['producer' => $producer, 'channel' => $channel, 'level' => $level, 'unit' => $identity->unit, 'count' => ($this->judged[$key]['count'] ?? 0) + 1];
            return;
        }
        if ($reason === null) {
            throw new LogicException('An unjudged population member requires its declared reason.');
        }
        $key = json_encode([$producer, $channel->code, $level->value, $gate, $reason, $identity->unit], \JSON_THROW_ON_ERROR);
        $previous = $this->abstentions[$key] ?? null;
        $examples = array_values(array_unique([...($previous->examples ?? []), $identity->canonical]));
        sort($examples, \SORT_STRING);
        $this->abstentions[$key] = new RuleAbstention($producer, $channel, $level, $gate, $reason, $identity->unit, ($previous->count ?? 0) + 1, \array_slice($examples, 0, 5));
    }

    /** @return array{judged: array<string, array{producer: string, channel: FindingChannel, level: SymbolLevel, unit: string, count: int}>, abstentions: list<RuleAbstention>} */
    public function summary(): array
    {
        return ['judged' => $this->judged, 'abstentions' => array_values($this->abstentions)];
    }

    public function freeze(): JudgedPopulation
    {
        return $this->frozen ??= JudgedPopulation::fromSummary($this->summary());
    }
}
