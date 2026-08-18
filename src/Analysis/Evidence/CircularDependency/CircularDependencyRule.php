<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CircularDependency;

use Qualimetrix\Analysis\Evidence\CircularDependency\Contract\CircularDependencyPreparationInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Detects circular dependencies between classes.
 *
 * Circular dependencies (A depends on B, B depends on C, C depends on A) are
 * architectural anti-patterns that make code harder to test, understand, and maintain.
 *
 * This rule reads the prepared cycle result owned by this capability.
 */
#[CliAlias('circular-deps', 'enabled')]
#[CliAlias('max-cycle-size', 'maxCycleSize')]
final class CircularDependencyRule extends AbstractRule
{
    public const string NAME = CircularDependencyPreparationInterface::PRODUCER_RULE_NAME;

    public function __construct(
        RuleOptionsInterface $options,
        private readonly CircularDependencyAnalysis $analysis,
    ) {
        parent::__construct($options);
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Detects circular dependencies between classes';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Architecture;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return []; // Requires dependency graph, not metrics
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options->isEnabled()) {
            return [];
        }

        \assert($this->options instanceof CircularDependencyOptions);

        $violations = [];
        $projectSubject = MetricSubject::aggregate(SymbolPath::forProject());

        foreach ($this->analysis->all() as $cycle) {
            $severity = $this->getEffectiveSeverity($context, $this->options, $projectSubject, $cycle->getSize());
            if ($severity === null) {
                continue; // Cycle too large or filtered out
            }

            $classes = $cycle->getClasses();
            \assert($classes !== [], 'CircularDependencyRule invariant: cycle has at least one class');
            $memberCanonicals = array_map(static fn(SymbolPath $class): string => $class->toCanonical(), $classes);
            sort($memberCanonicals);

            $category = $cycle->getSizeCategory();
            $size = $cycle->getSize();

            // Truncate path display for large cycles
            $pathDisplay = $category === 'large'
                ? $cycle->toTruncatedShortString(5)
                : $cycle->toShortString();

            $message = \sprintf(
                'Circular dependency (%d classes): %s',
                $size,
                $pathDisplay,
            );

            $recommendation = $this->buildRecommendation($cycle, $category);

            $violations[] = new Violation(
                location: Location::none(),
                subject: $projectSubject,
                symbolPath: SymbolPath::forProject(),
                ruleName: $this->getName(),
                violationCode: self::NAME,
                message: $message,
                severity: $severity,
                metricValue: $size,
                recommendation: $recommendation,
                occurrenceKey: OccurrenceKey::semantic(self::NAME, [
                    'members' => implode(',', $memberCanonicals),
                ]),
            );
        }

        return $violations;
    }

    /**
     * Builds an actionable recommendation based on cycle size category.
     *
     * For small/medium cycles, provides specific guidance.
     * For large cycles, emphasizes that the cycle is too large to fix at once
     * and suggests focusing on entry-point classes.
     *
     * Includes structured cycle data (JSON) for AI agent consumption.
     *
     * @param 'small'|'medium'|'large' $category
     */
    private function buildRecommendation(
        Cycle $cycle,
        string $category,
    ): string {
        $structuredData = $cycle->toStructuredData();
        $jsonData = json_encode($structuredData, \JSON_UNESCAPED_SLASHES);

        $guidance = match ($category) {
            'small' => \sprintf(
                'Cycle path: %s (%d classes). Break by introducing an interface to invert one dependency.',
                $cycle->toShortString(),
                $cycle->getSize(),
            ),
            'medium' => \sprintf(
                'Cycle path: %s (%d classes). Consider extracting a shared abstraction layer or splitting into smaller modules.',
                $cycle->toShortString(),
                $cycle->getSize(),
            ),
            'large' => \sprintf(
                'Large cycle (%d classes) — focus on the entry-point classes: %s. '
                . 'Break the cycle incrementally by introducing interfaces at key boundaries.',
                $cycle->getSize(),
                $cycle->toTruncatedShortString(3),
            ),
        };

        return $guidance . "\n" . 'Cycle data: ' . $jsonData;
    }

    /**
     * @return class-string<CircularDependencyOptions>
     */
    public static function getOptionsClass(): string
    {
        return CircularDependencyOptions::class;
    }

    /**
     * `architecture.circular-dependency` reports the cycle's class count
     * (`$size` — see the emission above) as `metricValue`. Declared
     * `magnitude` / `higher` is a **decision, not a derivation**
     * (ADR 0017): {@see CircularDependencyOptions::getSeverity()}
     * is not monotone in `$size` — a direct two-class cycle is `Error` while a
     * twelve-class cycle is only `Warning`, and any cycle whose size exceeds
     * `maxCycleSize` is dropped before a `Violation` is ever built (`$size >
     * $this->maxCycleSize` — line 56). Declaring `higher` says a cycle that
     * gains a member is worse debt, independent of that severity ladder; it
     * does not change the rule's own cutoff, which stays exactly as
     * configured.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            (new ViolationChannel(self::NAME, self::NAME))->toKey() => ChannelDeclaration::magnitude(WorseDirection::Higher),
        ];
    }
}
