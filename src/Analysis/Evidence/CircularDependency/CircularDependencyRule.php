<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CircularDependency;

use Qualimetrix\Analysis\Evidence\CircularDependency\Contract\CircularDependencyPreparationInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Population\GateInput;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
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
    public const string DOCS_PAGE = 'rules/architecture.md';

    public const int REMEDIATION_MINUTES = 120;

    public const ChannelShape SHAPE = ChannelShape::Magnitude;
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

    public static function getDescription(): string
    {
        return 'Detects circular dependencies between classes';
    }

    /**
     * @return list<Finding>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options->isEnabled()) {
            return [];
        }

        \assert($this->options instanceof CircularDependencyOptions);

        $declaration = self::channelDeclarations()[self::NAME];
        $findings = [];
        $projectSubject = MetricSubject::aggregate(SymbolPath::forProject());

        foreach ($this->analysis->all() as $cycle) {
            $classes = $cycle->getClasses();
            \assert($classes !== [], 'CircularDependencyRule invariant: cycle has at least one class');
            $memberCanonicals = array_map(static fn(SymbolPath $class): string => $class->toCanonical(), $classes);
            sort($memberCanonicals);

            if (!$context->admit(
                self::NAME,
                new FindingChannel(self::NAME),
                SymbolLevel::Project,
                PopulationIdentity::cycle($memberCanonicals),
                $declaration,
                [GateInput::ruleNumber('max-cycle-size', $cycle->getSize(), $this->options->maxCycleSize)],
            )) {
                continue;
            }
            $severity = $this->getEffectiveSeverity($context, $this->options, $projectSubject, $cycle->getSize());
            if ($severity === null) {
                continue;
            }

            $findings[] = CycleFinding::of($cycle, $projectSubject, $severity, $memberCanonicals);
        }

        return $findings;
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
     * twelve-class cycle is only `Warning`. The declared population ceiling
     * excludes larger cycles before severity is evaluated. Declaring `higher`
     * says a cycle that gains a member is worse debt, independent of that severity ladder; it
     * does not change the rule's own cutoff, which stays exactly as
     * configured.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            self::NAME => ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Project)->readingRunEvidence()->withGates(
                self::populationGate('max-cycle-size', self::NAME, SymbolLevel::Project, 'cycle', self::ruleValueThreshold('max-cycle-size', '<=', 'max-cycle-size', true), 'The cycle exceeds the configured population ceiling.'),
            ),
        ];
    }
}
