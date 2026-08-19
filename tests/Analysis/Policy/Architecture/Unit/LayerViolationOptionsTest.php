<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit\Rules;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
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

    /**
     * The three per-diagnostic severity keys are gone, and their removal is
     * loud on purpose.
     *
     * The channels they used to tune report a configuration error: they fail
     * the run without consulting `fail_on` and no baseline can accept them,
     * so there is no severity left to choose. Ignoring the key would leave a
     * config file saying `info` while the tool does the opposite — the exact
     * shape of lie the removal exists to end — so `info`, the value that
     * would have been silently overridden, is the value each case uses.
     */
    #[Test]
    #[TestWith(['unreachable_layer_severity'])]
    #[TestWith(['unreachableLayerSeverity'])]
    #[TestWith(['potential_shadow_severity'])]
    #[TestWith(['potentialShadowSeverity'])]
    #[TestWith(['empty_template_severity'])]
    #[TestWith(['emptyTemplateSeverity'])]
    public function itRejectsARemovedPerDiagnosticSeverityKey(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no longer exists');

        LayerViolationOptions::fromArray([$key => 'info']);
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
