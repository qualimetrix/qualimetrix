<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Architecture\Unit\Rules;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Architecture\Rules\LayerViolationOptions;
use Qualimetrix\Core\Violation\Severity;

#[CoversClass(LayerViolationOptions::class)]
final class LayerViolationOptionsTest extends TestCase
{
    #[Test]
    public function defaultsAreEnabledAndWarning(): void
    {
        $options = new LayerViolationOptions();

        self::assertTrue($options->isEnabled());
        self::assertSame(Severity::Warning, $options->severity);
    }

    #[Test]
    public function fromArrayDefaultsMatchConstructorDefaults(): void
    {
        $options = LayerViolationOptions::fromArray([]);

        self::assertTrue($options->isEnabled());
        self::assertSame(Severity::Warning, $options->severity);
    }

    #[Test]
    public function fromArrayHonoursEnabledFalse(): void
    {
        $options = LayerViolationOptions::fromArray(['enabled' => false]);

        self::assertFalse($options->isEnabled());
    }

    #[Test]
    public function fromArrayParsesSeverityError(): void
    {
        $options = LayerViolationOptions::fromArray(['severity' => 'error']);

        self::assertSame(Severity::Error, $options->severity);
    }

    #[Test]
    public function fromArrayParsesSeverityWarningExplicit(): void
    {
        $options = LayerViolationOptions::fromArray(['severity' => 'warning']);

        self::assertSame(Severity::Warning, $options->severity);
    }

    #[Test]
    public function fromArrayIsCaseInsensitiveOnSeverity(): void
    {
        $options = LayerViolationOptions::fromArray(['severity' => 'ERROR']);

        self::assertSame(Severity::Error, $options->severity);
    }

    #[Test]
    public function fromArrayRejectsUnknownSeverity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('severity');

        LayerViolationOptions::fromArray(['severity' => 'bogus']);
    }

    #[Test]
    public function fromArrayRejectsNonStringSeverity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('severity');

        LayerViolationOptions::fromArray(['severity' => 42]);
    }

    #[Test]
    public function getSeverityReturnsConfiguredSeverityForAnyValueWhenEnabled(): void
    {
        $options = new LayerViolationOptions(severity: Severity::Error);

        self::assertSame(Severity::Error, $options->getSeverity(0));
        self::assertSame(Severity::Error, $options->getSeverity(1));
        self::assertSame(Severity::Error, $options->getSeverity(1000));
        self::assertSame(Severity::Error, $options->getSeverity(3.14));
    }

    #[Test]
    public function getSeverityReturnsNullWhenDisabled(): void
    {
        $options = new LayerViolationOptions(enabled: false, severity: Severity::Error);

        self::assertNull($options->getSeverity(0));
        self::assertNull($options->getSeverity(1000));
    }
}
