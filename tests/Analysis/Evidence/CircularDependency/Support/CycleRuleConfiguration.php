<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support;

use LogicException;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyOptions;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyRule;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

/**
 * A run's rule configuration with the circular-dependency rule's options
 * resolved from authored values, the way preparation reads them in a run.
 */
final class CycleRuleConfiguration
{
    /** @param array<string, mixed> $ruleValues authored `rules.architecture.circular-dependency` values */
    public static function authored(array $ruleValues = []): RuleConfigurationInterface
    {
        $metadata = [new RuleMetadata(CircularDependencyRule::NAME, CircularDependencyOptions::class, '', [], false)];
        $configuration = ResolvedOptionsFixture::authoredConfiguration(
            ['rules' => [CircularDependencyRule::NAME => $ruleValues]],
            $metadata,
        );
        $registry = new RuleOptionsRegistry();
        $registry->replace(ResolvedOptionsFixture::ready($configuration, $metadata));

        return $registry;
    }

    public static function options(RuleConfigurationInterface $configuration): CircularDependencyOptions
    {
        $options = $configuration->resolvedOptions()->for(CircularDependencyRule::NAME);

        return $options instanceof CircularDependencyOptions
            ? $options
            : throw new LogicException('The circular-dependency rule resolved foreign options.');
    }
}
