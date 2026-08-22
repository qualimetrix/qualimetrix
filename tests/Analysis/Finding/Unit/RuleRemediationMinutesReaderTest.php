<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\AbstractCodeSmellRule;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleRemediationMinutesReader;

#[CoversClass(RuleRemediationMinutesReader::class)]
final class RuleRemediationMinutesReaderTest extends TestCase
{
    #[Test]
    public function itReadsTheRemediationMinutesConstantWithoutInstantiation(): void
    {
        self::assertSame(ComplexityRule::REMEDIATION_MINUTES, RuleRemediationMinutesReader::read(ComplexityRule::class));
    }

    #[Test]
    public function itRejectsRulesWithoutARemediationMinutesConstant(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('must declare an int REMEDIATION_MINUTES constant');

        RuleRemediationMinutesReader::read(FixtureRuleWithoutRemediationMinutes::class);
    }

    #[Test]
    public function itRejectsAClassThatCannotBeLoaded(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('does not exist or cannot be autoloaded');

        RuleRemediationMinutesReader::read('Qualimetrix\Analysis\Evidence\Complexity\NoSuchRule');
    }

    #[Test]
    public function itRejectsANonIntRemediationMinutesConstant(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('must declare a positive int REMEDIATION_MINUTES constant');

        RuleRemediationMinutesReader::read(FixtureRuleWithNonIntRemediationMinutes::class);
    }

    #[Test]
    public function itRejectsAZeroRemediationMinutesConstant(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('must declare a positive int REMEDIATION_MINUTES constant');

        RuleRemediationMinutesReader::read(FixtureRuleWithZeroRemediationMinutes::class);
    }

    #[Test]
    public function itRejectsANegativeRemediationMinutesConstant(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('must declare a positive int REMEDIATION_MINUTES constant');

        RuleRemediationMinutesReader::read(FixtureRuleWithNegativeRemediationMinutes::class);
    }

    /**
     * The regression this reader exists to prevent: a code-smell rule that
     * forgets to declare its own estimate must not silently pass with
     * {@see AbstractCodeSmellRule}'s placeholder `0`.
     */
    #[Test]
    public function itRejectsARemediationMinutesConstantOnlyInheritedFromAnAbstractAncestor(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('must declare its own REMEDIATION_MINUTES constant');

        RuleRemediationMinutesReader::read(FixtureCodeSmellRuleWithoutOwnRemediationMinutes::class);
    }
}

/**
 * @internal
 */
final class FixtureRuleWithoutRemediationMinutes {}

/**
 * @internal
 */
final class FixtureRuleWithNonIntRemediationMinutes
{
    public const string REMEDIATION_MINUTES = 'thirty';
}

/**
 * @internal
 */
final class FixtureRuleWithZeroRemediationMinutes
{
    public const int REMEDIATION_MINUTES = 0;
}

/**
 * @internal
 */
final class FixtureRuleWithNegativeRemediationMinutes
{
    public const int REMEDIATION_MINUTES = -5;
}

/**
 * @internal
 *
 * Deliberately declares no NAME nor REMEDIATION_MINUTES of its own:
 * exercises the "inherited placeholder is not a declaration" path against a
 * real ancestor rather than a fixture that merely simulates one.
 */
final class FixtureCodeSmellRuleWithoutOwnRemediationMinutes extends AbstractCodeSmellRule {}
