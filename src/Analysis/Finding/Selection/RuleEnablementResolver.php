<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Selection;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\ConfigurationDiagnostic;
use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedMapInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedWriteHistoryInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Finding\Contract\ChannelSelectionRole;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\EnablementDecision;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\ResolvedRuleOptions;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelLevelSelector;
use Qualimetrix\Analysis\Finding\Contract\Rule\FrameworkOptionKeys;
use Qualimetrix\Analysis\Finding\Contract\Rule\ModeGatedOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleEnablement;
use Qualimetrix\Analysis\Finding\Contract\SelectionFilter;
use Qualimetrix\Core\Symbol\SymbolLevel;

/** Resolves authored cell statements, then adds final option activity. */
final readonly class RuleEnablementResolver
{
    public function decide(ResolvedDocument $document, ChannelUniverseInterface $channels): StatedEnablement
    {
        $diagnostics = self::judgeNames($document, $channels);
        $statements = self::statements($document);
        $filter = self::filter($document);
        $cells = [];

        foreach ($channels->ruleNames() as $producer) {
            $produced = $channels->channelsProducedBy($producer);
            if ($produced === []) {
                $produced = [new FindingChannel($producer)];
            }
            foreach ($produced as $channel) {
                $levels = $channels->levelsOf($channel->code);
                foreach ($levels === [] ? [null] : $levels as $level) {
                    $applicable = [];
                    foreach ($statements as $statement) {
                        $specificity = self::specificity($statement['selector'], $producer, $channel, $level, $statement['exactEnable']);
                        if ($specificity !== null) {
                            $applicable[] = [...$statement, 'specificity' => $specificity];
                        }
                    }
                    self::refuseContradictions($applicable, $producer);
                    $winner = self::winner($applicable);
                    $decisive = $winner === null ? [] : array_values(array_filter(
                        $applicable,
                        static fn(array $statement): bool => $statement['provenance']->layerIndex === $winner['provenance']->layerIndex
                            && $statement['specificity'] === $winner['specificity'],
                    ));
                    usort($decisive, static fn(array $left, array $right): int => strcmp($left['text'], $right['text']));
                    $declaration = $channels->declarationFor($channel);
                    $role = $declaration === null ? ChannelSelectionRole::Selectable : $declaration->selectionRole;
                    $cells[] = new EnablementDecision(
                        $producer,
                        $channel,
                        $level,
                        $decisive[0]['enabled'] ?? true,
                        self::direct($filter, $producer, $channel, $level),
                        $role,
                        $decisive[0]['text'] ?? null,
                        $decisive[0]['provenance'] ?? null,
                        decisiveStatements: array_map(static fn(array $statement): array => [
                            'text' => $statement['text'], 'provenance' => $statement['provenance'],
                        ], $decisive),
                    );
                }
            }
        }

        return new StatedEnablement($cells, $filter, $diagnostics);
    }

    public function conclude(StatedEnablement $stated, ResolvedRuleOptions $options): RuleEnablement
    {
        $cells = [];
        foreach ($stated->decisions() as $decision) {
            $cells[] = new EnablementDecision(
                $decision->producer,
                $decision->channel,
                $decision->level,
                $decision->on,
                $decision->direct,
                $decision->role,
                $decision->statement,
                $decision->provenance,
                $options->activityOf($decision->producer, $decision->level),
                $decision->decisiveStatements,
            );
        }
        $final = new RuleEnablement($cells, $stated->filter());
        self::refuseEmptyFilter($final, $options);
        self::refuseDeadSelectors($final, $options);
        self::refuseFilteredEnables($final);
        self::refuseMutedEnables($final, $options);
        return $final;
    }

    /** @return list<array{selector: string, enabled: bool, exactEnable: bool, text: string, provenance: Provenance}> */
    private static function statements(ResolvedDocument $document): array
    {
        $statements = [];
        $rules = $document->get('rules');
        if ($rules instanceof ResolvedMapInterface) {
            foreach ($rules->entries() as $producer => $node) {
                $enabled = $node instanceof ResolvedMapInterface ? $node->get('enabled') : $node;
                if (!$enabled instanceof ResolvedWriteHistoryInterface) {
                    continue;
                }
                foreach ($enabled->writes() as $write) {
                    if (!\is_bool($write['value'])) {
                        continue;
                    }
                    $provenance = $write['provenance'];
                    $statements[] = [
                        'selector' => $producer,
                        'enabled' => $write['value'],
                        'exactEnable' => true,
                        'text' => self::authored($provenance, $write['value']),
                        'provenance' => $provenance,
                    ];
                }
            }
        }
        $disabled = $document->get('disabled_rules');
        if ($disabled instanceof ResolvedWriteHistoryInterface) {
            foreach ($disabled->writes() as $write) {
                if (!\is_array($write['value'])) {
                    continue;
                }
                foreach ($write['value'] as $index => $selector) {
                    if (!\is_string($selector)) {
                        throw new LogicException('A disabled rule selector must be a string.');
                    }
                    $statements[] = [
                        'selector' => $selector,
                        'enabled' => false,
                        'exactEnable' => false,
                        'text' => self::authoredList($write['provenance'], (int) $index, $selector),
                        'provenance' => $write['provenance'],
                    ];
                }
            }
        }

        return $statements;
    }

    private static function filter(ResolvedDocument $document): ?SelectionFilter
    {
        $node = $document->get('only_rules');
        if (!$node instanceof ResolvedWriteHistoryInterface) {
            return null;
        }
        $writes = $node->writes();
        $last = $writes[\count($writes) - 1];
        if (!\is_array($last['value'])) {
            throw new LogicException('A rule filter must be a list.');
        }
        $selectors = [];
        foreach ($last['value'] as $selector) {
            if (!\is_string($selector)) {
                throw new LogicException('A rule filter selector must be a string.');
            }
            $selectors[] = $selector;
        }
        return $selectors === [] ? null : new SelectionFilter($selectors, $last['provenance']);
    }

    private static function authored(Provenance $provenance, bool $value): string
    {
        if ($provenance->path === null) {
            return ($provenance->origin->locator() ?? '--rule-opt') . '=' . ($value ? 'true' : 'false');
        }
        return $provenance->displayPath() . ': ' . ($value ? 'true' : 'false');
    }

    private static function authoredList(Provenance $provenance, int $index, string $value): string
    {
        if ($provenance->path === null) {
            return ($provenance->origin->locator() ?? '--disable-rule') . '=' . $value;
        }
        return $provenance->displayPath() . '[' . $index . ']: ' . $value;
    }

    private static function specificity(string $raw, string $producer, FindingChannel $channel, ?SymbolLevel $level, bool $exactEnable): ?int
    {
        if ($exactEnable) {
            return $raw === $producer ? 3 : null;
        }
        $selector = ChannelLevelSelector::tryParse($raw);
        if ($selector === null || !$selector->matches($channel->code, $level)) {
            if ($selector?->level() === null && $selector?->channel()->matches($producer) === true) {
                return $selector->channel()->selectsDescendantsOnly() ? 1 : 3;
            }
            return null;
        }
        if ($selector->channel()->selectsDescendantsOnly()) {
            return $selector->level() === null ? 1 : 2;
        }
        if ($selector->level() !== null) {
            return 5;
        }
        return $channel->code === $producer ? 3 : 4;
    }

    private static function direct(?SelectionFilter $filter, string $producer, FindingChannel $channel, ?SymbolLevel $level): bool
    {
        if ($filter === null) {
            return true;
        }
        foreach ($filter->selectors as $selector) {
            if (self::specificity($selector, $producer, $channel, $level, false) !== null) {
                return true;
            }
        }
        return false;
    }

    /** @param list<array{selector: string, enabled: bool, exactEnable: bool, text: string, provenance: Provenance, specificity: int}> $applicable */
    private static function refuseContradictions(array $applicable, string $producer): void
    {
        $byLayer = [];
        foreach ($applicable as $statement) {
            $byLayer[$statement['provenance']->layerIndex][] = $statement;
        }
        ksort($byLayer);
        foreach ($byLayer as $statements) {
            $highest = max(array_column($statements, 'specificity'));
            $strongest = array_values(array_filter($statements, static fn(array $statement): bool => $statement['specificity'] === $highest));
            $enabled = array_values(array_filter($strongest, static fn(array $statement): bool => $statement['enabled']));
            $disabled = array_values(array_filter($strongest, static fn(array $statement): bool => !$statement['enabled']));
            if ($enabled === [] || $disabled === []) {
                continue;
            }
            $first = $strongest[0]['provenance'];
            $enabledTexts = array_column($enabled, 'text');
            $disabledTexts = array_column($disabled, 'text');
            sort($enabledTexts);
            sort($disabledTexts);
            $texts = [...$enabledTexts, ...$disabledTexts];
            $writers = [$first];
            foreach (\array_slice($strongest, 1) as $statement) {
                $writers[] = $statement['provenance'];
            }
            throw Provenance::refusalOf(
                $writers,
                \sprintf('Layer %s both enables and disables "%s": %s.', self::layer($first), $producer, implode('; ', $texts)),
            );
        }
    }

    /** @param list<array{selector: string, enabled: bool, exactEnable: bool, text: string, provenance: Provenance, specificity: int}> $applicable
     * @return ?array{selector: string, enabled: bool, exactEnable: bool, text: string, provenance: Provenance, specificity: int}
     */
    private static function winner(array $applicable): ?array
    {
        usort($applicable, static fn(array $left, array $right): int =>
            [$right['provenance']->layerIndex, $right['specificity']] <=> [$left['provenance']->layerIndex, $left['specificity']]);
        return $applicable[0] ?? null;
    }

    /** @return list<ConfigurationDiagnostic> */
    private static function judgeNames(ResolvedDocument $document, ChannelUniverseInterface $channels): array
    {
        $judge = new RuleNameJudge($channels->ruleNames());
        $diagnosticSources = [];
        foreach (['only_rules', 'disabled_rules'] as $root) {
            $node = $document->get($root);
            if (!$node instanceof ResolvedWriteHistoryInterface) {
                continue;
            }
            foreach ($node->writes() as $write) {
                if (!\is_array($write['value']) || !array_is_list($write['value'])) {
                    throw new LogicException('A rule selector write must be a declared list.');
                }
                foreach ($write['value'] as $index => $selector) {
                    if (!\is_string($selector)) {
                        throw new LogicException('A rule selector write must contain strings.');
                    }
                    $writer = self::atIndex($write['provenance'], $index);
                    $problem = $judge->selector($selector, $channels);
                    if ($problem !== null) {
                        throw Provenance::refusalOf([$writer], $problem->summary);
                    }
                    $message = RetiredRuleNames::diagnosticFor($selector);
                    if ($message !== null) {
                        $diagnosticSources[$message][] = $writer;
                    }
                }
            }
        }
        $rules = $document->get('rules');
        if ($rules instanceof ResolvedMapInterface) {
            foreach ($rules->entries() as $producer => $rule) {
                if (!$rule instanceof ResolvedMapInterface) {
                    continue;
                }
                $keys = $rule->get(FrameworkOptionKeys::NAMESPACE_CHANNELS);
                if (!$keys instanceof ResolvedMapInterface) {
                    continue;
                }
                foreach ($keys->entries() as $selector => $patterns) {
                    if (!$patterns instanceof ResolvedWriteHistoryInterface) {
                        throw new LogicException('A namespace channel key must carry its selector-list writers.');
                    }
                    $problem = $judge->namespaceChannel($producer, $selector, $channels);
                    if ($problem !== null) {
                        $writers = array_map(static fn(array $write): Provenance => $write['provenance'], $patterns->writes());
                        throw Provenance::refusalOf($writers, $problem->summary);
                    }
                }
            }
        }
        $diagnostics = [];
        foreach ($diagnosticSources as $message => $writers) {
            usort($writers, static fn(Provenance $a, Provenance $b): int => $a->layerIndex <=> $b->layerIndex);
            $diagnostics[] = new ConfigurationDiagnostic($message, $writers);
        }
        return $diagnostics;
    }

    private static function atIndex(Provenance $writer, int $index): Provenance
    {
        return new Provenance($writer->origin, $writer->path === null ? null : [...$writer->path, (string) $index], $writer->layerIndex, $writer->line);
    }

    private static function refuseEmptyFilter(RuleEnablement $final, ResolvedRuleOptions $options): void
    {
        $filter = $final->filter();
        if ($filter === null) {
            return;
        }
        foreach ($final->decisions() as $cell) {
            if ($cell->live() && $cell->direct) {
                return;
            }
        }
        $reasons = [];
        foreach ($filter->selectors as $selector) {
            $covered = array_values(array_filter(
                $final->decisions(),
                static fn(EnablementDecision $cell): bool => self::specificity($selector, $cell->producer, $cell->channel, $cell->level, false) !== null,
            ));
            $reasons[] = \sprintf('"%s": %s', $selector, self::causes($covered, $options));
        }
        throw Provenance::refusalOf(
            [$filter->provenance],
            'Rule selection is empty: ' . implode('; ', $reasons) . '; only_rules / --only-rule narrows and does not enable.',
        );
    }

    private static function refuseDeadSelectors(RuleEnablement $final, ResolvedRuleOptions $options): void
    {
        $filter = $final->filter();
        if ($filter === null) {
            return;
        }
        foreach ($filter->selectors as $selector) {
            $parsed = ChannelLevelSelector::tryParse($selector);
            if ($parsed === null || $parsed->channel()->selectsDescendantsOnly()) {
                continue;
            }
            $covered = [];
            foreach ($final->decisions() as $cell) {
                if (self::specificity($selector, $cell->producer, $cell->channel, $cell->level, false) !== null) {
                    $covered[] = $cell;
                }
            }
            if ($covered === [] || array_any($covered, static fn(EnablementDecision $cell): bool => $cell->live())) {
                continue;
            }
            foreach ($covered as $cell) {
                $offRank = $cell->on ? $cell->activity->rank() : $cell->rank();
                if ($offRank > $filter->rank()) {
                    continue 2;
                }
            }
            throw Provenance::refusalOf([$filter->provenance], \sprintf(
                'Filter selector "%s" selects nothing that can report: %s.',
                $selector,
                self::causes($covered, $options),
            ));
        }
    }

    private static function refuseFilteredEnables(RuleEnablement $final): void
    {
        $filter = $final->filter();
        if ($filter === null) {
            return;
        }
        $byProducer = [];
        foreach ($final->decisions() as $cell) {
            $byProducer[$cell->producer][] = $cell;
        }
        foreach ($byProducer as $producer => $cells) {
            if (array_any($cells, static fn(EnablementDecision $cell): bool => $cell->direct || $cell->role !== ChannelSelectionRole::Selectable)) {
                continue;
            }
            foreach ($cells as $cell) {
                if ($cell->statement === null || !$cell->on || $cell->rank() < $filter->rank()) {
                    continue;
                }
                $writers = [$filter->provenance, $cell->provenance ?? throw new LogicException('An authored enable must have provenance.')];
                throw Provenance::refusalOf($writers, \sprintf(
                    '"%s" is enabled by %s (%s) but excluded by the rule filter of %s.',
                    $producer,
                    $cell->statement,
                    self::layer($cell->provenance),
                    self::layer($filter->provenance),
                ));
            }
        }
    }

    private static function refuseMutedEnables(RuleEnablement $final, ResolvedRuleOptions $options): void
    {
        foreach ($final->decisions() as $cell) {
            if ($cell->statement === null || !$cell->on || $cell->activity->active) {
                continue;
            }
            $option = $options->for($cell->producer);
            if (!$option instanceof ModeGatedOptionsInterface || !$option->isMuted()) {
                continue;
            }
            $enable = $cell->provenance ?? throw new LogicException('An authored enable must have provenance.');
            $write = $cell->activity->decidedBy;
            $mode = $write === null
                ? \sprintf('mode of "%s" is ignore by default', $cell->producer)
                : \sprintf('%s: ignore (%s)', $write->path === null
                    ? ($write->origin->locator() ?? '--rule-opt') : $write->displayPath(), self::layer($write));
            throw Provenance::refusalOf($write === null ? [$enable] : [$enable, $write], \sprintf(
                '"%s" is enabled by %s (%s) but its mode is ignore: %s.',
                $cell->producer,
                $cell->statement,
                self::layer($enable),
                $mode,
            ));
        }
    }

    /** @param list<EnablementDecision> $cells */
    private static function causes(array $cells, ResolvedRuleOptions $options): string
    {
        $reasons = [];
        foreach ($cells as $cell) {
            if (!$cell->on && $cell->decisiveStatements !== []) {
                foreach ($cell->decisiveStatements as $statement) {
                    $reasons[] = [
                        'rank' => $statement['provenance']->layerIndex,
                        'text' => \sprintf('%s (%s)', $statement['text'], self::layer($statement['provenance'])),
                    ];
                }
                continue;
            }
            $reasons[] = [
                'rank' => $cell->on ? $cell->activity->rank() : $cell->rank(),
                'text' => self::cause($cell, $options),
            ];
        }
        usort($reasons, static fn(array $left, array $right): int => [$left['rank'], $left['text']] <=> [$right['rank'], $right['text']]);
        return implode(', ', array_values(array_unique(array_column($reasons, 'text'))));
    }

    private static function cause(EnablementDecision $cell, ResolvedRuleOptions $options): string
    {
        if (!$cell->on) {
            return $cell->statement === null ? \sprintf('"%s" is disabled by default', $cell->producer)
                : \sprintf('%s (%s)', $cell->statement, self::layer($cell->provenance));
        }
        if (!$cell->activity->active) {
            $mode = $options->for($cell->producer) instanceof ModeGatedOptionsInterface;
            return $cell->activity->decidedBy === null
                ? ($mode
                    ? \sprintf('mode of "%s" is ignore by default', $cell->producer)
                    : \sprintf('level %s of "%s" is inactive by default', $cell->level === null ? 'project' : $cell->level->value, $cell->producer))
                : \sprintf('%s: %s (%s)', $cell->activity->decidedBy->displayPath(), $mode ? 'ignore' : 'false', self::layer($cell->activity->decidedBy));
        }
        return 'filtered';
    }

    private static function layer(?Provenance $provenance): string
    {
        if ($provenance === null) {
            return 'default';
        }
        return $provenance->origin->source() === ConfigurationSource::CommandLine
            ? 'the command line'
            : $provenance->origin->describe();
    }
}
