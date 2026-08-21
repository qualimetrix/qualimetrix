<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\AbstractCodeSmellRule;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleDocsPageReader;

#[CoversClass(RuleDocsPageReader::class)]
final class RuleDocsPageReaderTest extends TestCase
{
    #[Test]
    public function itReadsTheDocsPageConstantWithoutInstantiation(): void
    {
        self::assertSame(ComplexityRule::DOCS_PAGE, RuleDocsPageReader::read(ComplexityRule::class));
    }

    #[Test]
    public function itRejectsRulesWithoutADocsPageConstant(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('must declare a string DOCS_PAGE constant');

        RuleDocsPageReader::read(FixtureRuleWithoutDocsPage::class);
    }

    #[Test]
    public function itRejectsAClassThatCannotBeLoaded(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('does not exist or cannot be autoloaded');

        RuleDocsPageReader::read('Qualimetrix\Analysis\Evidence\Complexity\NoSuchRule');
    }

    #[Test]
    public function itRejectsANonStringDocsPageConstant(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('must declare a non-empty string DOCS_PAGE constant');

        RuleDocsPageReader::read(FixtureRuleWithNonStringDocsPage::class);
    }

    #[Test]
    public function itRejectsAnEmptyStringDocsPageConstant(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('must declare a non-empty string DOCS_PAGE constant');

        RuleDocsPageReader::read(FixtureRuleWithEmptyDocsPage::class);
    }

    /**
     * The regression this reader exists to prevent: a code-smell rule that
     * forgets to declare its own page must not silently pass with
     * {@see AbstractCodeSmellRule}'s placeholder empty string.
     */
    #[Test]
    public function itRejectsADocsPageConstantOnlyInheritedFromAnAbstractAncestor(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('must declare its own DOCS_PAGE constant');

        RuleDocsPageReader::read(FixtureCodeSmellRuleWithoutOwnDocsPage::class);
    }
}

/**
 * @internal
 */
final class FixtureRuleWithoutDocsPage {}

/**
 * @internal
 */
final class FixtureRuleWithNonStringDocsPage
{
    public const int DOCS_PAGE = 42;
}

/**
 * @internal
 */
final class FixtureRuleWithEmptyDocsPage
{
    public const string DOCS_PAGE = '';
}

/**
 * @internal
 *
 * Deliberately declares no NAME nor DOCS_PAGE of its own: exercises the
 * "inherited placeholder is not a declaration" path against a real ancestor
 * rather than a fixture that merely simulates one.
 */
final class FixtureCodeSmellRuleWithoutOwnDocsPage extends AbstractCodeSmellRule {}
