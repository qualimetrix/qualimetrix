<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Selection;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\ConfigurationDiagnostic;
use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedMapInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedWriteHistoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Channel\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\FrameworkOptionKeys;
use Qualimetrix\Analysis\Finding\RuleConfiguration\Selection\RuleNameJudge;
use Qualimetrix\Core\ProductIdentity;

/** Judges authored selector and namespace-channel spelling with its writers. */
final class AuthoredSelectionDiagnostics
{
    /**
     * @param array<string, ?string> $projectOnlyProducerDocsPages producer whose every channel is project-scoped => its docs page
     *
     * @return list<ConfigurationDiagnostic>
     */
    public static function forDocument(
        ResolvedDocument $document,
        ChannelUniverseInterface $channels,
        array $projectOnlyProducerDocsPages,
    ): array {
        $judge = new RuleNameJudge($channels->ruleNames());
        $diagnosticSources = self::selectorDiagnostics($document, $judge, $channels);
        self::judgeNamespaceChannels($document, $judge, $channels);
        self::judgeProjectScopedSuppression($document, $projectOnlyProducerDocsPages);
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

    /**
     * The global filters already skip a project-scoped channel; a per-rule
     * selector on a producer whose every channel is project-scoped would be
     * accepted and then match nothing a finding could be addressed by.
     *
     * @param array<string, ?string> $projectOnlyProducerDocsPages
     */
    private static function judgeProjectScopedSuppression(ResolvedDocument $document, array $projectOnlyProducerDocsPages): void
    {
        $rules = $document->get('rules');
        if (!$rules instanceof ResolvedMapInterface) {
            return;
        }
        foreach ($rules->entries() as $producer => $rule) {
            if (!$rule instanceof ResolvedMapInterface || !\array_key_exists($producer, $projectOnlyProducerDocsPages)) {
                continue;
            }
            foreach ([FrameworkOptionKeys::NAMESPACES, FrameworkOptionKeys::PATHS] as $key) {
                $selectors = $rule->get($key);
                if ($selectors === null || \in_array($selectors->plain(), [[], null], true)) {
                    continue;
                }
                $page = $projectOnlyProducerDocsPages[$producer];
                throw Provenance::refusalOf($selectors->contributors(), \sprintf(
                    'Option "%s" for rule "%s" can never apply: findings of this rule are project-level and cannot be addressed by path or namespace.'
                    . ' Remove the option; to accept known findings, use a baseline.%s',
                    str_replace('-', '_', $key),
                    $producer,
                    $page === null ? '' : ' See ' . ProductIdentity::docsPageUrl($page) . '.',
                ));
            }
        }
    }

    private static function atIndex(Provenance $writer, int $index): Provenance
    {
        return new Provenance($writer->origin, $writer->path === null ? null : [...$writer->path, (string) $index], $writer->layerIndex, $writer->line);
    }
}
