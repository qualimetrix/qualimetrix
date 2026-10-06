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
            ? ($provenance->origin->authoredExpression() ?? (($provenance->origin->locator() ?? $fallback) . '=' . $value))
            : $path . ': ' . $value;
    }

    /** @return list<ConfigurationDiagnostic> */
    public static function diagnostics(ResolvedDocument $document, ChannelUniverseInterface $channels): array
    {
        return AuthoredSelectionDiagnostics::forDocument($document, $channels);
    }
}
