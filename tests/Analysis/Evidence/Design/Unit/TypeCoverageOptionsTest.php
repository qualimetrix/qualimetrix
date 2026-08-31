<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Design\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Design\TypeCoverage\TypeCoverageOptions;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\InvertedOverrideValidator;
use Qualimetrix\Analysis\Finding\Contract\Severity;

#[CoversClass(TypeCoverageOptions::class)]
final class TypeCoverageOptionsTest extends TestCase
{
    #[Test]
    public function itReadsTheBareThresholdPair(): void
    {
        $options = TypeCoverageOptions::fromArray(['warning' => 90.0, 'error' => 60.0]);

        self::assertSame(90.0, $options->warning);
        self::assertSame(60.0, $options->error);
    }

    /**
     * The dimension prefix moved into the rule name, so a key that still
     * carries it configures nothing here.
     */
    #[Test]
    public function itDoesNotAnswerToTheOldPrefixedKeys(): void
    {
        $options = TypeCoverageOptions::fromArray(['param_warning' => 30.0, 'paramWarning' => 30.0]);

        self::assertSame(80.0, $options->warning);
    }

    #[Test]
    public function itDisablesOnAnEmptyConfig(): void
    {
        self::assertFalse(TypeCoverageOptions::fromArray([])->isEnabled());
    }

    #[Test]
    public function itDefaultsToEightyAndFifty(): void
    {
        $options = TypeCoverageOptions::fromArray(['enabled' => true]);

        self::assertTrue($options->isEnabled());
        self::assertSame(80.0, $options->warning);
        self::assertSame(50.0, $options->error);
    }

    #[Test]
    public function itReadsLessCoverageAsWorse(): void
    {
        $options = new TypeCoverageOptions();

        self::assertNull($options->getSeverity(90.0));
        self::assertNull($options->getSeverity(80.0));
        self::assertSame(Severity::Warning, $options->getSeverity(79.9));
        self::assertSame(Severity::Warning, $options->getSeverity(50.0));
        self::assertSame(Severity::Error, $options->getSeverity(49.9));
    }

    #[Test]
    public function itLetsTheBareThresholdShorthandSetBothBoundaries(): void
    {
        $options = TypeCoverageOptions::fromArray(['threshold' => 90.0]);

        self::assertSame(90.0, $options->warning);
        self::assertSame(90.0, $options->error);
    }

    #[Test]
    public function itRefusesAThresholdMixedWithAGraduatedKey(): void
    {
        self::expectException(InvalidArgumentException::class);

        TypeCoverageOptions::fromArray(['threshold' => 90.0, 'warning' => 80.0]);
    }

    /**
     * An override that raises the minimum must not be read as a relaxation:
     * this is the one rule family where a higher number is stricter.
     */
    #[Test]
    public function itValidatesOverridesInInvertedDirection(): void
    {
        self::assertInstanceOf(InvertedOverrideValidator::class, TypeCoverageOptions::getOverrideValidator());
    }

    #[Test]
    public function itAppliesAnOverridePerBoundary(): void
    {
        $options = (new TypeCoverageOptions())->withOverride(95.0, null);

        self::assertSame(95.0, $options->warning);
        self::assertSame(50.0, $options->error);
    }

    #[Test]
    public function itAdvertisesOnlyTheBareShorthand(): void
    {
        self::assertSame(['threshold'], TypeCoverageOptions::getShorthandOptionKeys());
    }
}
