<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Selection;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\ConfigurationDiagnostic;
use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedMapInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedWriteHistoryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\FrameworkOptionKeys;
use Qualimetrix\Analysis\Finding\Contract\Selection\RuleNameJudge;
use Qualimetrix\Analysis\Finding\Contract\SelectionFilter;

/** Reads every authored selector writer before resolving a cell. */
final class AuthoredSelection
{
    /** @return list<array{selector: string, enabled: bool, exactEnable: bool, text: string, provenance: Provenance}> */
    public static function statements(ResolvedDocument $document): array
    {
        $statements = [];
        $rules = $document->get('rules');
        if ($rules instanceof ResolvedMapInterface) {
            foreach ($rules->entries() as $producer => $node) {
                array_push($statements, ...self::producerStatements($producer, $node));
            }
        }
        $disabled = $document->get('disabled_rules');
        if ($disabled instanceof ResolvedWriteHistoryInterface) {
            foreach ($disabled->writes() as $write) {
                array_push($statements, ...self::disabledStatements($write['value'], $write['provenance']));
            }
        }
        return $statements;
    }

    /** @return list<array{selector: string, enabled: bool, exactEnable: bool, text: string, provenance: Provenance}> */
    private static function producerStatements(string $producer, ResolvedValueInterface $node): array
    {
        $enabled = $node instanceof ResolvedMapInterface ? $node->get('enabled') : $node;
        if (!$enabled instanceof ResolvedWriteHistoryInterface) {
            return [];
        }
        $statements = [];
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
        return $statements;
    }

    /** @return list<array{selector: string, enabled: bool, exactEnable: bool, text: string, provenance: Provenance}> */
    private static function disabledStatements(mixed $value, Provenance $writer): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $statements = [];
        foreach ($value as $index => $selector) {
            if (!\is_string($selector)) {
                throw new LogicException('A disabled rule selector must be a string.');
            }
            $statements[] = [
                'selector' => $selector,
                'enabled' => false,
                'exactEnable' => false,
                'text' => self::authoredList($writer, (int) $index, $selector),
                'provenance' => $writer,
            ];
        }
        return $statements;
    }

    public static function filter(ResolvedDocument $document): ?SelectionFilter
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
        if (!array_all($last['value'], static fn(mixed $selector): bool => \is_string($selector))) {
            throw new LogicException('A rule filter selector must be a string.');
        }
        $selectors = array_values($last['value']);
        return $selectors === [] ? null : new SelectionFilter($selectors, $last['provenance']);
    }

    /** @qmx-ignore code-smell.boolean-argument -- The boolean is an authored value rendered as true or false. */
    private static function authored(Provenance $provenance, bool $value): string
    {
        return self::written($provenance, '--rule-opt', $provenance->displayPath(), $value ? 'true' : 'false');
    }

    private static function authoredList(Provenance $provenance, int $index, string $value): string
    {
        return self::written($provenance, '--disable-rule', $provenance->displayPath() . '[' . $index . ']', $value);
    }

    private static function written(Provenance $provenance, string $fallback, string $path, string $value): string
    {
        return $provenance->path === null
            ? ($provenance->origin->locator() ?? $fallback) . '=' . $value
            : $path . ': ' . $value;
    }

    /** @return list<ConfigurationDiagnostic> */
    public static function diagnostics(ResolvedDocument $document, ChannelUniverseInterface $channels): array
    {
        $judge = new RuleNameJudge($channels->ruleNames());
        $diagnosticSources = self::selectorDiagnostics($document, $judge, $channels);
        self::judgeNamespaceChannels($document, $judge, $channels);
        $diagnostics = [];
        foreach ($diagnosticSources as $message => $writers) {
            usort($writers, static fn(Provenance $a, Provenance $b): int => $a->layerIndex <=> $b->layerIndex);
            $diagnostics[] = new ConfigurationDiagnostic($message, $writers);
        }
        return $diagnostics;
    }

    /** @return array<string, non-empty-list<Provenance>> */
    private static function selectorDiagnostics(ResolvedDocument $document, RuleNameJudge $judge, ChannelUniverseInterface $channels): array
    {
        $sources = [];
        foreach (['only_rules', 'disabled_rules'] as $root) {
            $node = $document->get($root);
            if (!$node instanceof ResolvedWriteHistoryInterface) {
                continue;
            }
            foreach ($node->writes() as $write) {
                $sources[] = self::selectorListDiagnostics($write, $judge, $channels);
            }
        }
        return array_merge_recursive(...$sources);
    }

    /**
     * @param array{value: mixed, provenance: Provenance} $write
     *
     * @return array<string, non-empty-list<Provenance>>
     */
    private static function selectorListDiagnostics(array $write, RuleNameJudge $judge, ChannelUniverseInterface $channels): array
    {
        if (!\is_array($write['value']) || !array_is_list($write['value'])) {
            throw new LogicException('A rule selector write must be a declared list.');
        }
        $sources = [];
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
                $sources[$message][] = $writer;
            }
        }
        return $sources;
    }

    private static function judgeNamespaceChannels(ResolvedDocument $document, RuleNameJudge $judge, ChannelUniverseInterface $channels): void
    {
        $rules = $document->get('rules');
        if (!$rules instanceof ResolvedMapInterface) {
            return;
        }
        foreach ($rules->entries() as $producer => $rule) {
            if (!$rule instanceof ResolvedMapInterface) {
                continue;
            }
            $keys = $rule->get(FrameworkOptionKeys::NAMESPACE_CHANNELS);
            if ($keys instanceof ResolvedMapInterface) {
                self::judgeNamespaceKeys($producer, $keys, $judge, $channels);
            }
        }
    }

    private static function judgeNamespaceKeys(string $producer, ResolvedMapInterface $keys, RuleNameJudge $judge, ChannelUniverseInterface $channels): void
    {
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

    private static function atIndex(Provenance $writer, int $index): Provenance
    {
        return new Provenance($writer->origin, $writer->path === null ? null : [...$writer->path, (string) $index], $writer->layerIndex, $writer->line);
    }
}
