<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Selection;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\ConfigurationDiagnostic;
use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedMapInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedWriteHistoryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\FrameworkOptionKeys;
use Qualimetrix\Analysis\Finding\Contract\Selection\RuleNameJudge;

/** Judges authored selector and namespace-channel spelling with its writers. */
final class AuthoredSelectionDiagnostics
{
    /** @return list<ConfigurationDiagnostic> */
    public static function forDocument(ResolvedDocument $document, ChannelUniverseInterface $channels): array
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
