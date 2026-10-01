<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CodeSmell\Unit;

use PHPUnit\Framework\Attributes\CoversClass;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\ErrorSuppressionOptions;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

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
        $options = ErrorSuppressionOptions::fromResolved(ResolvedOptionsFixture::values(ErrorSuppressionOptions::class, []));

        self::assertTrue($options->isEnabled());
        self::assertSame([], $options->allowedFunctions);
    }

    #[Test]
    public function itFromArrayWithAllowedFunctions(): void
    {
        $options = ErrorSuppressionOptions::fromResolved(ResolvedOptionsFixture::values(ErrorSuppressionOptions::class, [
            'allowed_functions' => ['fopen', 'UNLINK', 'json_decode'],
        ]));

        self::assertSame(['fopen', 'unlink', 'json_decode'], $options->allowedFunctions);
    }

    #[Test]
    public function itFromArrayWithCamelCaseKey(): void
    {
        $options = ErrorSuppressionOptions::fromResolved(ResolvedOptionsFixture::values(ErrorSuppressionOptions::class, [
            'allowedFunctions' => ['mkdir'],
        ]));

        self::assertSame(['mkdir'], $options->allowedFunctions);
    }

    #[Test]
    public function itFromArrayDisabled(): void
    {
        $options = ErrorSuppressionOptions::fromResolved(ResolvedOptionsFixture::values(ErrorSuppressionOptions::class, [
            'enabled' => false,
        ]));

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
