<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit\Rules;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationOptions;

#[CoversClass(LayerViolationOptions::class)]
final class LayerViolationOptionsTest extends TestCase
{
    #[Test]
    public function defaultsAreEnabledAndWarning(): void
    {
        $options = new LayerViolationOptions();

        self::assertTrue($options->isEnabled());
        self::assertSame(Severity::Warning, $options->severity);
        self::assertSame(Severity::Info, $options->unreachableLayerSeverity);
        self::assertSame(Severity::Info, $options->potentialShadowSeverity);
        self::assertSame(Severity::Warning, $options->emptyTemplateSeverity);
    }

    #[Test]
    public function fromArrayDefaultsMatchConstructorDefaults(): void
    {
        $options = LayerViolationOptions::fromArray([]);

        self::assertTrue($options->isEnabled());
        self::assertSame(Severity::Warning, $options->severity);
        self::assertSame(Severity::Info, $options->unreachableLayerSeverity);
        self::assertSame(Severity::Info, $options->potentialShadowSeverity);
        self::assertSame(Severity::Warning, $options->emptyTemplateSeverity);
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
    public function itParsesUnreachableLayerSeverityFromASnakeCaseKey(): void
    {
        $options = LayerViolationOptions::fromArray(['unreachable_layer_severity' => 'error']);

        self::assertSame(Severity::Error, $options->unreachableLayerSeverity);
        // Sibling severities stay at their own defaults — knobs are independent.
        self::assertSame(Severity::Info, $options->potentialShadowSeverity);
        self::assertSame(Severity::Warning, $options->emptyTemplateSeverity);
    }

    #[Test]
    public function itParsesUnreachableLayerSeverityFromACamelCaseKey(): void
    {
        $options = LayerViolationOptions::fromArray(['unreachableLayerSeverity' => 'error']);

        self::assertSame(Severity::Error, $options->unreachableLayerSeverity);
    }

    #[Test]
    public function itRejectsAnUnknownUnreachableLayerSeverity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unreachable_layer_severity');

        LayerViolationOptions::fromArray(['unreachable_layer_severity' => 'bogus']);
    }

    #[Test]
    public function itParsesPotentialShadowSeverityFromASnakeCaseKey(): void
    {
        $options = LayerViolationOptions::fromArray(['potential_shadow_severity' => 'error']);

        self::assertSame(Severity::Error, $options->potentialShadowSeverity);
    }

    #[Test]
    public function itRejectsAnUnknownPotentialShadowSeverity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('potential_shadow_severity');

        LayerViolationOptions::fromArray(['potential_shadow_severity' => 'bogus']);
    }

    #[Test]
    public function itParsesEmptyTemplateSeverityFromASnakeCaseKey(): void
    {
        $options = LayerViolationOptions::fromArray(['empty_template_severity' => 'info']);

        self::assertSame(Severity::Info, $options->emptyTemplateSeverity);
    }

    #[Test]
    public function itRejectsAnUnknownEmptyTemplateSeverity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('empty_template_severity');

        LayerViolationOptions::fromArray(['empty_template_severity' => 'bogus']);
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
