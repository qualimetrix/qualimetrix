<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Population;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\Channel\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Population\JudgedPopulation;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity;
use Qualimetrix\Analysis\Finding\Contract\Population\RuleAbstention;
use Qualimetrix\Core\Symbol\SymbolLevel;

final class PopulationTrace
{
    /** @var array<string, array{producer: string, channel: FindingChannel, level: SymbolLevel, unit: string, count: int}> */
    private array $judged = [];

    /** @var array<string, array{producer: string, channel: FindingChannel, level: SymbolLevel, gate: string, reason: string, unit: string, count: int, examples: array<array-key, true>, dataGap: bool}> */
    private array $abstentions = [];

    private ?JudgedPopulation $frozen = null;

    public function record(string $producer, FindingChannel $channel, SymbolLevel $level, PopulationIdentity $identity, ?string $gate, ?string $reason, bool $dataGap = true): void
    {
        if ($this->frozen !== null) {
            throw new LogicException('Cannot record into a frozen population.');
        }
        if ($gate === null) {
            $key = \strlen($producer) . ":" . $producer . \strlen($channel->code) . ":" . $channel->code . "\0" . $level->value . "\0" . $identity->unit;
            $this->judged[$key] = ['producer' => $producer, 'channel' => $channel, 'level' => $level, 'unit' => $identity->unit, 'count' => ($this->judged[$key]['count'] ?? 0) + 1];
            return;
        }
        if ($reason === null) {
            throw new LogicException('An unjudged population member requires its declared reason.');
        }
        $key = \strlen($producer) . ":" . $producer . \strlen($channel->code) . ":" . $channel->code . "\0" . $level->value . "\0" . \strlen($gate) . ":" . $gate . "\0" . $identity->unit . "\0" . ($dataGap ? "1" : "0") . $reason;
        if (!isset($this->abstentions[$key])) {
            $this->abstentions[$key] = ['producer' => $producer, 'channel' => $channel, 'level' => $level, 'gate' => $gate,
                'reason' => $reason, 'unit' => $identity->unit, 'count' => 0, 'examples' => [], 'dataGap' => $dataGap];
        }
        $group = &$this->abstentions[$key];
        ++$group['count'];
        if (!isset($group['examples'][$identity->canonical])
            && (\count($group['examples']) < 5 || strcmp($identity->canonical, (string) array_key_last($group['examples'])) < 0)) {
            $group['examples'][$identity->canonical] = true;
            ksort($group['examples'], \SORT_STRING);
            if (\count($group['examples']) > 5) {
                array_pop($group['examples']);
            }
        }
    }

    /** @return array{judged: array<string, array{producer: string, channel: FindingChannel, level: SymbolLevel, unit: string, count: int}>, abstentions: list<RuleAbstention>} */
    public function summary(): array
    {
        $abstentions = [];
        foreach ($this->abstentions as $group) {
            $examples = array_map(static fn(string|int $key): string => (string) $key, array_keys($group['examples']));
            $abstentions[] = new RuleAbstention($group['producer'], $group['channel'], $group['level'], $group['gate'], $group['reason'], $group['unit'], $group['count'], $examples, $group['dataGap']);
        }
        return ['judged' => $this->judged, 'abstentions' => $abstentions];
    }

    public function freeze(): JudgedPopulation
    {
        return $this->frozen ??= JudgedPopulation::fromSummary($this->summary());
    }
}
