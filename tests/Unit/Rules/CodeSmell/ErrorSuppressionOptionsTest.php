<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\CodeSmell;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\CodeSmell\ErrorSuppressionOptions;

#[CoversClass(ErrorSuppressionOptions::class)]
final class ErrorSuppressionOptionsTest extends TestCase
{
    #[Test]
    public function itDefaultsToEnabledWithNoAllowedFunctions(): void
    {
        $options = new ErrorSuppressionOptions();

        self::assertTrue($options->isEnabled());
        self::assertSame([], $options->allowedFunctions);
    }

    #[Test]
    public function itFromArrayEmpty(): void
    {
        $options = ErrorSuppressionOptions::fromArray([]);

        self::assertTrue($options->isEnabled());
        self::assertSame([], $options->allowedFunctions);
    }

    #[Test]
    public function itFromArrayWithAllowedFunctions(): void
    {
        $options = ErrorSuppressionOptions::fromArray([
            'allowed_functions' => ['fopen', 'UNLINK', 'json_decode'],
        ]);

        self::assertSame(['fopen', 'unlink', 'json_decode'], $options->allowedFunctions);
    }

    #[Test]
    public function itFromArrayWithCamelCaseKey(): void
    {
        $options = ErrorSuppressionOptions::fromArray([
            'allowedFunctions' => ['mkdir'],
        ]);

        self::assertSame(['mkdir'], $options->allowedFunctions);
    }

    #[Test]
    public function itFromArrayDisabled(): void
    {
        $options = ErrorSuppressionOptions::fromArray([
            'enabled' => false,
        ]);

        self::assertFalse($options->isEnabled());
    }

    #[Test]
    public function itIsFunctionAllowed(): void
    {
        $options = new ErrorSuppressionOptions(allowedFunctions: ['fopen', 'unlink']);

        self::assertTrue($options->isFunctionAllowed('fopen'));
        self::assertTrue($options->isFunctionAllowed('FOPEN'));
        self::assertTrue($options->isFunctionAllowed('unlink'));
        self::assertFalse($options->isFunctionAllowed('exec'));
        self::assertFalse($options->isFunctionAllowed(''));
    }

    #[Test]
    public function itIsFunctionAllowedWithEmptyList(): void
    {
        $options = new ErrorSuppressionOptions(allowedFunctions: []);

        self::assertFalse($options->isFunctionAllowed('fopen'));
    }

    #[Test]
    public function itGetSeverity(): void
    {
        $options = new ErrorSuppressionOptions();

        self::assertSame(Severity::Warning, $options->getSeverity(1));
        self::assertNull($options->getSeverity(0));
    }
}
