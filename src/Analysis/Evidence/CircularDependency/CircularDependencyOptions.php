<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CircularDependency;

use LogicException;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyRelationTokens;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\ResolvedRuleOptionValues;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionWordSet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Options for CircularDependencyRule.
 *
 * @qmx-threshold health.cohesion 40.0 -- Independent immutable observations or stateless declaration operations intentionally have no shared instance-field graph; this finite class boundary keeps a further cohesion loss visible.
 */
final readonly class CircularDependencyOptions implements RuleOptionsInterface
{
    /**
     * The kinds `ignore-relations` names, aliases expanded, each once, in the
     * order first named.
     *
     * @var list<DependencyType>
     */
    public array $ignoredRelations;

    /**
     * @param bool $enabled Whether the rule is enabled
     * @param int $maxCycleSize Maximum cycle size to report (0 = report all)
     * @param bool $directAsError Treat direct cycles (A→B→A) as errors
     * @param list<DependencyType> $ignoredRelations Dependency kinds whose records do not form cycles
     */
    public function __construct(
        public bool $enabled = true,
        public int $maxCycleSize = 0,
        public bool $directAsError = true,
        array $ignoredRelations = [],
    ) {
        $unique = [];
        foreach ($ignoredRelations as $kind) {
            $unique[$kind->value] ??= $kind;
        }
        $this->ignoredRelations = array_values($unique);
    }

    public static function fromResolved(ResolvedRuleOptionValues $config): self
    {
        return new self(
            enabled: $config->boolean('enabled', true),
            maxCycleSize: $config->integer('max-cycle-size', 0),
            directAsError: $config->boolean('direct-as-error', true),
            ignoredRelations: self::expandRelations($config->strings('ignore-relations')),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Returns severity based on cycle size.
     *
     * Small cycles (2-5 classes): error for direct (size ≤2), warning otherwise
     * Medium cycles (6-20 classes): warning
     * Large cycles (21+ classes): warning (too large for error-level urgency)
     */
    public function getSeverity(int|float $value): Severity
    {
        $cycleSize = (int) $value;

        // Direct cycle (A→B→A) is typically more severe
        if ($cycleSize <= 2 && $this->directAsError) {
            return Severity::Error;
        }

        // Small transitive cycles (3-5) are still actionable warnings
        // Medium and large cycles are always warnings
        return Severity::Warning;
    }

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'direct-as-error' => RuleOptionShape::boolean()->orNull(),
            'ignore-relations' => RuleOptionShape::listOf(
                RuleOptionShape::words(RuleOptionWordSet::of(...DependencyRelationTokens::acceptedTokens())),
            )->orNull(),
            'max-cycle-size' => RuleOptionShape::integer()->orNull(),
        ]);
    }

    /**
     * @param list<string> $tokens
     *
     * @return list<DependencyType>
     */
    private static function expandRelations(array $tokens): array
    {
        $kinds = [];
        foreach ($tokens as $token) {
            // The declared word set refuses an unknown token before options are built.
            $kinds = [...$kinds, ...(DependencyRelationTokens::expand($token)
                ?? throw new LogicException(\sprintf('Undeclared relation token "%s" reached circular-dependency options.', $token)))];
        }

        return $kinds;
    }
}
