<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedWriteHistoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\EnablementDecision;
use Qualimetrix\Analysis\Finding\Contract\OptionActivity;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ModeGatedOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;

/** Resolves one cell's option activity and its latest authored cause. */
final class OptionActivityResolution
{
    public static function forCell(FindingConfiguration $configuration, EnablementDecision $decision, RuleOptionsInterface $option): OptionActivity
    {
        $path = self::path($decision, $option);
        $node = $path === [] ? null : $configuration->document->get(...$path);
        $writes = $node instanceof ResolvedWriteHistoryInterface ? $node->writes() : [];
        $last = $writes === [] ? null : $writes[\count($writes) - 1];
        return new OptionActivity(
            self::active($decision, $option),
            $last === null ? null : self::authoredSwitch($last['provenance'], $last['value']),
            $last['provenance'] ?? null,
        );
    }

    private static function active(EnablementDecision $decision, RuleOptionsInterface $option): bool
    {
        if ($option instanceof HierarchicalRuleOptionsInterface && $decision->level !== null) {
            return $option->isLevelEnabled($decision->level);
        }
        return !($option instanceof ModeGatedOptionsInterface && $option->isMuted());
    }

    /** @return list<string> */
    private static function path(EnablementDecision $decision, RuleOptionsInterface $option): array
    {
        if ($option instanceof ModeGatedOptionsInterface) {
            return ['rules', $decision->producer, 'mode'];
        }
        if ($option instanceof HierarchicalRuleOptionsInterface && $decision->level !== null) {
            return ['rules', $decision->producer, $decision->level->value, 'enabled'];
        }
        return [];
    }

    private static function authoredSwitch(Provenance $writer, mixed $value): string
    {
        if (!\is_bool($value) && !\is_string($value)) {
            throw new LogicException('A decided rule option switch must be a scalar boolean or mode.');
        }
        $text = \is_bool($value) ? ($value ? 'true' : 'false') : $value;
        return $writer->path === null
            ? ($writer->origin->locator() ?? '--rule-opt') . '=' . $text
            : $writer->displayPath() . ': ' . $text;
    }
}
