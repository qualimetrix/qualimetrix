<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Rule;

use Qualimetrix\Analysis\Finding\Contract\Channel\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Channel\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Channel\JudgedMetrics;
use Qualimetrix\Analysis\Finding\Contract\Population\ContextGuard;
use Qualimetrix\Analysis\Finding\Contract\Population\FlagExcludes;
use Qualimetrix\Analysis\Finding\Contract\Population\GatePredicate;
use Qualimetrix\Analysis\Finding\Contract\Population\KeyPresent;
use Qualimetrix\Analysis\Finding\Contract\Population\KeyThreshold;
use Qualimetrix\Analysis\Finding\Contract\Population\KindIn;
use Qualimetrix\Analysis\Finding\Contract\Population\NameMatches;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationGate;
use Qualimetrix\Analysis\Finding\Contract\Population\RuleValueThreshold;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\ClassType;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolType;

/** The protected vocabulary for declaring rule eligibility, separate from runtime admission. */
trait RulePopulationDeclarations
{
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
     * @param array<string, int|float> $absenceBoundaries
     *
     * @qmx-ignore code-smell.boolean-argument -- These values declare eligibility facts and effective-option equality; they do not select execution modes.
     */
    protected static function keyPresent(string $source, array $keys, ?bool $activeWhen = null, array $absenceBoundaries = []): KeyPresent
    {
        return new KeyPresent($source, $keys, $activeWhen, $absenceBoundaries);
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

}
