<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use Closure;
use InvalidArgumentException;
use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\JudgedMetrics;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Population\ContextGuard;
use Qualimetrix\Analysis\Finding\Contract\Population\FlagExcludes;
use Qualimetrix\Analysis\Finding\Contract\Population\GateInput;
use Qualimetrix\Analysis\Finding\Contract\Population\GatePredicate;
use Qualimetrix\Analysis\Finding\Contract\Population\KeyPresent;
use Qualimetrix\Analysis\Finding\Contract\Population\KeyThreshold;
use Qualimetrix\Analysis\Finding\Contract\Population\KindIn;
use Qualimetrix\Analysis\Finding\Contract\Population\NameMatches;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationGate;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity;
use Qualimetrix\Analysis\Finding\Contract\Population\RuleValueThreshold;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\ThresholdCrossing;
use Qualimetrix\Analysis\Finding\Rule\RuleInterface;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\ClassType;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Base class for all analysis rules.
 *
 * Provides common functionality and protected access to options.
 * Validates that the options instance matches the expected class from getOptionsClass().
 *
 * @qmx-threshold health.cohesion warning=40 -- Shared rule assembly combines stateless population declarations and threshold finding construction; extracting their field-free methods would transfer this field-cohesion signal to another utility.
 */
abstract class AbstractRule implements RuleInterface
{
    /**
     * Never the real answer for any concrete rule — every subclass reachable
     * from a container declares its own `SHAPE` (ADR 0031), directly or
     * through one of the three shared abstract bases
     * ({@see \Qualimetrix\Analysis\Evidence\CodeSmell\AbstractCodeSmellRule},
     * {@see \Qualimetrix\Analysis\Evidence\Security\AbstractSecurityPatternRule},
     * {@see \Qualimetrix\Analysis\Evidence\Design\TypeCoverage\AbstractTypeCoverageRule}).
     * `ChannelDeclarationCompilerPass` refuses a rule class whose `SHAPE`
     * constant resolves to this one — the same "declaring class" check
     * {@see \Qualimetrix\Analysis\Finding\Contract\Rule\RuleDocsPageReader}
     * already applies to an omitted `DOCS_PAGE`, aimed at a constant instead
     * of a method. This placeholder exists only so `shape()` below has
     * something to bind `static::SHAPE` to; PHP has no abstract class
     * constant to declare the intent directly.
     */
    public const ChannelShape SHAPE = ChannelShape::Occurrence;

    /**
     * Shared by every concrete rule, so that "read the declared shape" is
     * written once instead of once per rule class — {@see UnusedPrivateRule},
     * to name one, repeated exactly this body before this method existed, and
     * `duplication.clone` said so first. A rule expresses its own
     * answer entirely through the `SHAPE` constant it declares; this method
     * never varies.
     */
    public static function shape(): ChannelShape
    {
        return static::SHAPE;
    }

    /**
     * @param RuleOptionsInterface $options Rule options
     */
    public function __construct(
        protected readonly RuleOptionsInterface $options,
    ) {
        $expected = static::getOptionsClass();
        if (!$options instanceof $expected) {
            throw new InvalidArgumentException(
                \sprintf('Expected %s, got %s', $expected, $options::class),
            );
        }
    }

    abstract public function getName(): string;

    abstract public static function getDescription(): string;

    /**
     * Returns options with `@qmx-threshold` overrides applied for a specific symbol.
     *
     * Use this when the rule needs to read threshold fields from the options
     * (e.g., to build messages or determine which threshold was exceeded).
     *
     * @template T of RuleOptionsInterface|LevelOptionsInterface
     *
     * @param T $options The options to apply overrides to
     * @param MetricSubject $subject Exact or aggregate subject under evaluation
     *
     * @return T
     */
    protected function getEffectiveOptions(
        AnalysisContext $context,
        RuleOptionsInterface|LevelOptionsInterface $options,
        MetricSubject $subject,
    ): RuleOptionsInterface|LevelOptionsInterface {
        $override = $context->getThresholdOverride($this->getName(), $subject);

        if ($override !== null && $options instanceof ThresholdAwareOptionsInterface) {
            return $options->withOverride($override->warning, $override->error);
        }

        return $options;
    }

    /**
     * Returns the effective severity for a metric value, applying `@qmx-threshold` overrides.
     *
     * Rules should call this instead of $options->getSeverity() directly to support
     * per-symbol threshold overrides via `@qmx-threshold` annotations.
     *
     * @param RuleOptionsInterface|LevelOptionsInterface $options The options to use for severity check
     * @param MetricSubject $subject Exact or aggregate subject under evaluation
     * @param int|float $value The metric value to check
     */
    protected function getEffectiveSeverity(
        AnalysisContext $context,
        RuleOptionsInterface|LevelOptionsInterface $options,
        MetricSubject $subject,
        int|float $value,
    ): ?Severity {
        $effectiveOptions = $this->getEffectiveOptions($context, $options, $subject);

        return $effectiveOptions->getSeverity($value);
    }

    /**
     * @param array{warning: int|float, error: int|float} $band
     * @param Closure(int|float, ThresholdCrossing): array{string, string} $wording
     */
    protected function thresholdFinding(SymbolInfo $info, int|float $value, ?Severity $severity, array $band, Closure $wording): ?Finding
    {
        if ($severity === null) {
            return null;
        }
        $subject = $info->subject ?? throw new LogicException('Threshold findings require an exact declaration subject');
        $threshold = $band[$severity === Severity::Error ? 'error' : 'warning'];
        [$message, $recommendation] = $wording($threshold, ThresholdCrossing::of($value, $threshold));

        return new Finding(
            location: new Location($info->file, $info->line),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: $this->getName(),
            code: $this->getName(),
            message: $message,
            severity: $severity,
            metricValue: $value,
            recommendation: $recommendation,
            threshold: $threshold,
        );
    }

    protected static function populationGate(string $id, FindingChannel|string $channel, SymbolLevel $level, string $unit, GatePredicate $predicate, string $reason, ?string $failureUnit = null): PopulationGate
    {
        return new PopulationGate($id, \is_string($channel) ? new FindingChannel($channel) : $channel, $level, $unit, $predicate, $reason, $failureUnit);
    }

    /** @param non-empty-list<string> $keys */
    protected static function judgingHigher(array $keys, SymbolLevel $level, SymbolLevel ...$moreLevels): ChannelDeclaration
    {
        return ChannelDeclaration::judging(WorseDirection::Higher, JudgedMetrics::of(...$keys), $level, ...$moreLevels);
    }

    /** @param non-empty-list<string> $keys */
    protected static function judgingLower(array $keys, SymbolLevel $level, SymbolLevel ...$moreLevels): ChannelDeclaration
    {
        return ChannelDeclaration::judging(WorseDirection::Lower, JudgedMetrics::of(...$keys), $level, ...$moreLevels);
    }

    /**
     * @param non-empty-list<string>|non-empty-array<string, string> $keys
     *
     * @qmx-ignore code-smell.boolean-argument -- These values declare eligibility facts and effective-option equality; they do not select execution modes.
     */
    protected static function keyPresent(string $source, array $keys, ?bool $activeWhen = null): KeyPresent
    {
        return new KeyPresent($source, $keys, $activeWhen);
    }

    /**
     * @param non-empty-list<string> $keys
     *
     * @qmx-ignore code-smell.boolean-argument -- These values declare eligibility facts and effective-option equality; they do not select execution modes.
     */
    protected static function keyThreshold(string $source, array $keys, string $comparison, int|float|string $boundary, string $missing = 'exclude', bool $nonnegative = false): KeyThreshold
    {
        return new KeyThreshold($source, $keys, $comparison, $boundary, $missing, $nonnegative);
    }

    /**
     * @qmx-ignore code-smell.boolean-argument -- These values declare eligibility facts and effective-option equality; they do not select execution modes.
     */
    protected static function flagExcludes(string $source, ?string $key, int|float|bool $forbidden = 1, ?bool $activeWhen = null, bool $nonzero = false): FlagExcludes
    {
        return new FlagExcludes($source, $key, $forbidden, $activeWhen, $nonzero);
    }

    /** @param non-empty-list<ClassType>|non-empty-list<SymbolType> $kinds */
    protected static function kindIn(string $source, array $kinds): KindIn
    {
        return new KindIn($source, $kinds);
    }

    /**
     * @qmx-ignore code-smell.boolean-argument -- These values declare eligibility facts and effective-option equality; they do not select execution modes.
     */
    protected static function nameMatches(string $source, ?bool $activeWhen = null): NameMatches
    {
        return new NameMatches($source, $activeWhen);
    }

    /**
     * @qmx-ignore code-smell.boolean-argument -- These values declare eligibility facts and effective-option equality; they do not select execution modes.
     */
    protected static function ruleValueThreshold(string $source, string $comparison, int|float|string $boundary, bool $nonpositiveBypasses = false): RuleValueThreshold
    {
        return new RuleValueThreshold($source, $comparison, $boundary, $nonpositiveBypasses);
    }

    /**
     * @qmx-ignore code-smell.boolean-argument -- These values declare eligibility facts and effective-option equality; they do not select execution modes.
     */
    protected static function contextGuard(string $source, bool $allowOppositeSuppressionTag = false): ContextGuard
    {
        return new ContextGuard($source, $allowOppositeSuppressionTag);
    }

    /**
     * @param Closure(MetricBag): iterable<GateInput> $inputs
     * @param iterable<GateInput> $before
     */
    protected function admittedMetrics(AnalysisContext $context, MetricSubject $subject, ChannelDeclaration $declaration, Closure $inputs, iterable $before = [], string $unit = 'declaration', ?SymbolLevel $level = null): ?MetricBag
    {
        $metrics = null;
        $admitted = $this->admitSubject(
            $context,
            $subject,
            $declaration,
            (static function () use ($context, $subject, $inputs, $before, &$metrics): iterable {
                yield from $before;
                $metrics = $context->metrics->getSubject($subject);
                yield from $inputs($metrics);
            })(),
            $level ?? MetricSubject::levelOfCanonical($subject->toCanonical()),
            $unit,
        );
        return $admitted ? $metrics : null;
    }

    /** @param iterable<GateInput> $inputs */
    protected function admitSubject(AnalysisContext $context, MetricSubject $subject, ChannelDeclaration $declaration, iterable $inputs, SymbolLevel $level, string $unit = 'declaration'): bool
    {
        return $context->admit(
            $this->getName(),
            new FindingChannel($this->getName()),
            $level,
            PopulationIdentity::subject($subject, $unit),
            $declaration,
            $inputs,
        );
    }

    /** @param iterable<GateInput> $inputs */
    protected function admitOccurrence(AnalysisContext $context, MetricSubject $subject, int $ordinal, ChannelDeclaration $declaration, iterable $inputs): bool
    {
        return $context->admit(
            $this->getName(),
            new FindingChannel($this->getName()),
            MetricSubject::levelOfCanonical($subject->toCanonical()),
            PopulationIdentity::occurrence($subject->toCanonical(), $ordinal),
            $declaration,
            $inputs,
        );
    }

    /**
     * @param iterable<SymbolInfo> $roster
     *
     * @return iterable<array{SymbolInfo, MetricSubject, MetricBag}>
     */
    protected function admittedDeclarations(AnalysisContext $context, ChannelDeclaration $declaration, iterable $roster, SymbolLevel $level, string $valueSource, ?string $kindSource = null, string $unit = 'declaration'): iterable
    {
        foreach ($roster as $info) {
            $subject = $info->subject ?? throw new LogicException('Metric judgement requires an exact declaration subject');
            $before = $kindSource === null ? [] : [GateInput::kind($kindSource, $subject->toSymbolPath()->getType())];
            $metrics = $this->admittedMetrics($context, $subject, $declaration, static fn(MetricBag $metrics): array => [GateInput::metrics($valueSource, $metrics)], $before, $unit, $level);
            if ($metrics !== null) {
                yield [$info, $subject, $metrics];
            }
        }
    }
}
