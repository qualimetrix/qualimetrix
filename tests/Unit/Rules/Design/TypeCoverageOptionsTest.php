<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Rules\Design;

use AiMessDetector\Rules\Design\TypeCoverageOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TypeCoverageOptions::class)]
final class TypeCoverageOptionsTest extends TestCase
{
    #[Test]
    public function fromArray_withCamelCaseKeys_appliesCorrectly(): void
    {
        $options = TypeCoverageOptions::fromArray([
            'paramWarning' => 90.0,
            'paramError' => 60.0,
            'returnWarning' => 85.0,
            'returnError' => 55.0,
            'propertyWarning' => 75.0,
            'propertyError' => 45.0,
        ]);

        self::assertSame(90.0, $options->paramWarning);
        self::assertSame(60.0, $options->paramError);
        self::assertSame(85.0, $options->returnWarning);
        self::assertSame(55.0, $options->returnError);
        self::assertSame(75.0, $options->propertyWarning);
        self::assertSame(45.0, $options->propertyError);
    }

    #[Test]
    public function fromArray_withSnakeCaseKeys_appliesCorrectly(): void
    {
        $options = TypeCoverageOptions::fromArray([
            'param_warning' => 90.0,
            'param_error' => 60.0,
            'return_warning' => 85.0,
            'return_error' => 55.0,
            'property_warning' => 75.0,
            'property_error' => 45.0,
        ]);

        self::assertSame(90.0, $options->paramWarning);
        self::assertSame(60.0, $options->paramError);
        self::assertSame(85.0, $options->returnWarning);
        self::assertSame(55.0, $options->returnError);
        self::assertSame(75.0, $options->propertyWarning);
        self::assertSame(45.0, $options->propertyError);
    }

    #[Test]
    public function fromArray_camelCaseKeysTakePrecedenceOverSnakeCase(): void
    {
        $options = TypeCoverageOptions::fromArray([
            'paramWarning' => 95.0,
            'param_warning' => 70.0, // should be ignored
        ]);

        self::assertSame(95.0, $options->paramWarning);
    }

    #[Test]
    public function fromArray_withEmptyArray_disablesRule(): void
    {
        $options = TypeCoverageOptions::fromArray([]);

        self::assertFalse($options->isEnabled());
    }

    #[Test]
    public function fromArray_withDefaults_usesDefaultValues(): void
    {
        $options = TypeCoverageOptions::fromArray(['enabled' => true]);

        self::assertTrue($options->isEnabled());
        self::assertSame(80.0, $options->paramWarning);
        self::assertSame(50.0, $options->paramError);
        self::assertSame(80.0, $options->returnWarning);
        self::assertSame(50.0, $options->returnError);
        self::assertSame(80.0, $options->propertyWarning);
        self::assertSame(50.0, $options->propertyError);
    }

    #[Test]
    public function getParamSeverity_returnsCorrectSeverity(): void
    {
        $options = new TypeCoverageOptions();

        self::assertNull($options->getParamSeverity(90.0));
        self::assertNotNull($options->getParamSeverity(70.0));
        self::assertNotNull($options->getParamSeverity(30.0));
    }

    #[Test]
    public function getReturnSeverity_returnsCorrectSeverity(): void
    {
        $options = new TypeCoverageOptions();

        self::assertNull($options->getReturnSeverity(90.0));
        self::assertNotNull($options->getReturnSeverity(70.0));
        self::assertNotNull($options->getReturnSeverity(30.0));
    }

    #[Test]
    public function getPropertySeverity_returnsCorrectSeverity(): void
    {
        $options = new TypeCoverageOptions();

        self::assertNull($options->getPropertySeverity(90.0));
        self::assertNotNull($options->getPropertySeverity(70.0));
        self::assertNotNull($options->getPropertySeverity(30.0));
    }
}
