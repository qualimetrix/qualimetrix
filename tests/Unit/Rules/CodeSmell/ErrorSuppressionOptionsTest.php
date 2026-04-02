<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\CodeSmell;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\CodeSmell\ErrorSuppressionOptions;

#[CoversClass(ErrorSuppressionOptions::class)]
final class ErrorSuppressionOptionsTest extends TestCase
{
    public function testDefaultsToEnabledWithNoAllowedFunctions(): void
    {
        $options = new ErrorSuppressionOptions();

        self::assertTrue($options->isEnabled());
        self::assertSame([], $options->allowedFunctions);
    }

    public function testFromArrayEmpty(): void
    {
        $options = ErrorSuppressionOptions::fromArray([]);

        self::assertTrue($options->isEnabled());
        self::assertSame([], $options->allowedFunctions);
    }

    public function testFromArrayWithAllowedFunctions(): void
    {
        $options = ErrorSuppressionOptions::fromArray([
            'allowed_functions' => ['fopen', 'UNLINK', 'json_decode'],
        ]);

        self::assertSame(['fopen', 'unlink', 'json_decode'], $options->allowedFunctions);
    }

    public function testFromArrayWithCamelCaseKey(): void
    {
        $options = ErrorSuppressionOptions::fromArray([
            'allowedFunctions' => ['mkdir'],
        ]);

        self::assertSame(['mkdir'], $options->allowedFunctions);
    }

    public function testFromArrayDisabled(): void
    {
        $options = ErrorSuppressionOptions::fromArray([
            'enabled' => false,
        ]);

        self::assertFalse($options->isEnabled());
    }

    public function testIsFunctionAllowed(): void
    {
        $options = new ErrorSuppressionOptions(allowedFunctions: ['fopen', 'unlink']);

        self::assertTrue($options->isFunctionAllowed('fopen'));
        self::assertTrue($options->isFunctionAllowed('FOPEN'));
        self::assertTrue($options->isFunctionAllowed('unlink'));
        self::assertFalse($options->isFunctionAllowed('exec'));
        self::assertFalse($options->isFunctionAllowed(''));
    }

    public function testIsFunctionAllowedWithEmptyList(): void
    {
        $options = new ErrorSuppressionOptions(allowedFunctions: []);

        self::assertFalse($options->isFunctionAllowed('fopen'));
    }

    public function testGetSeverity(): void
    {
        $options = new ErrorSuppressionOptions();

        self::assertSame(Severity::Warning, $options->getSeverity(1));
        self::assertNull($options->getSeverity(0));
    }
}
