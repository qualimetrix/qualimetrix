<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Unit\Directive;

use PHPUnit\Framework\Attributes\CoversClass;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Inline\Directive\InlineDirectiveOptions;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

/**
 * The one configurable severity in this family, and the reason it is strict.
 *
 * A rule whose entire job is to report annotations that say one thing and do
 * another cannot itself accept `unused_directive_severity: Warning` and
 * quietly run at `info`. `Severity::tryFrom()` is case-sensitive, so the
 * previous `tryFrom($raw) ?? Info` did exactly that — and did it identically
 * for the typo `warnin`, which is the case that matters: the config file said
 * one thing, the run did another, and nothing anywhere said so.
 */
#[CoversClass(InlineDirectiveOptions::class)]
final class InlineDirectiveOptionsTest extends TestCase
{
    #[Test]
    public function itDefaultsToInfoWhenNoSeverityIsGiven(): void
    {
        self::assertSame(Severity::Info, InlineDirectiveOptions::fromResolved(ResolvedOptionsFixture::values(InlineDirectiveOptions::class, []))->unusedDirectiveSeverity);
    }

    /**
     * A written `~` is left to the reader's own default, the same as an
     * unwritten key — it is not a narrower declaration refusing a legal value.
     */
    #[Test]
    public function itLeavesTheDefaultWhenSeverityIsWrittenNull(): void
    {
        self::assertSame(
            Severity::Info,
            InlineDirectiveOptions::fromResolved(ResolvedOptionsFixture::values(InlineDirectiveOptions::class, ['unused_directive_severity' => null]))->unusedDirectiveSeverity,
        );
    }

    #[Test]
    public function itAcceptsTheDocumentedSpelling(): void
    {
        self::assertSame(
            Severity::Warning,
            InlineDirectiveOptions::fromResolved(ResolvedOptionsFixture::values(InlineDirectiveOptions::class, ['unused_directive_severity' => 'warning']))->unusedDirectiveSeverity,
        );
    }

    /**
     * The enum's own casing is an implementation detail, so a capitalised
     * value is honoured rather than refused.
     */
    #[Test]
    public function itAcceptsAValueWhoseCaseDiffersFromTheEnum(): void
    {
        self::assertSame(
            Severity::Warning,
            InlineDirectiveOptions::fromResolved(ResolvedOptionsFixture::values(InlineDirectiveOptions::class, ['unused_directive_severity' => 'Warning']))->unusedDirectiveSeverity,
        );
    }

    #[Test]
    public function itRefusesAValueItCannotHonour(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('"rules.fixture.unused_directive_severity" in configuration file "/project/qmx.yaml" must be one of info, warning, error, got "warnin".');

        InlineDirectiveOptions::fromResolved(ResolvedOptionsFixture::values(InlineDirectiveOptions::class, ['unused_directive_severity' => 'warnin']));
    }

    #[Test]
    public function itRefusesANonStringValue(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('"rules.fixture.unused_directive_severity" in configuration file "/project/qmx.yaml" must be string (one of info, warning, error, case-insensitive), got int.');

        InlineDirectiveOptions::fromResolved(ResolvedOptionsFixture::values(InlineDirectiveOptions::class, ['unused_directive_severity' => 2]));
    }

    #[Test]
    public function itResolvesAnAuthoredWordIntoTheTypedSeverity(): void
    {
        self::assertSame(
            Severity::Error,
            InlineDirectiveOptions::fromResolved(ResolvedOptionsFixture::values(InlineDirectiveOptions::class, ['unusedDirectiveSeverity' => 'error']))->unusedDirectiveSeverity,
        );
    }
}
